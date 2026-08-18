<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oneapplogin;

defined('MOODLE_INTERNAL') || die();

/**
 * Revokes a user's existing web service tokens when a new app login is detected.
 *
 * Moodle hands out one shared token per user/service pair. \core_external\util::
 * generate_token_for_current_user() (external_generate_token_for_login() before Moodle 4.2) looks
 * for an existing external_tokens row and, in its own words, "if some valid tokens exist then use
 * the most recent" instead of minting a new one. Every device therefore ends up holding the same
 * string, and there is no setting or API that makes it issue one token per device.
 *
 * This class runs from the after_config callback, which fires from lib/setup.php on every
 * entry point, well before /login/token.php or /admin/tool/mobile/launch.php look the token up.
 * Deleting the row there forces Moodle to generate a fresh token for the device that is logging
 * in, which invalidates whatever the previously logged in device still holds.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /**
     * Permanent token type.
     *
     * Mirrors EXTERNAL_TOKEN_PERMANENT / \core_external\token::TYPE_PERMANENT, neither of which is
     * guaranteed to be loaded this early in the bootstrap.
     */
    const TOKEN_PERMANENT = 0;

    /** @var bool Guard so the logic runs at most once per request. */
    protected static $ran = false;

    /** @var \stdClass[] Token records removed during this request, keyed by id. */
    protected static $removed = [];

    /** @var int User the removed tokens belonged to. */
    protected static $userid = 0;

    /** @var int Service the removed tokens belonged to. */
    protected static $serviceid = 0;

    /**
     * Entry point called from the after_config callback and the \core\hook\after_config hook.
     *
     * Whichever of the two fires first wins; the other becomes a no-op. Never lets an exception
     * escape, because a fatal here would take down every page of the site.
     */
    public static function bootstrap(): void {
        if (self::$ran) {
            return;
        }
        self::$ran = true;

        try {
            self::process_request();
        } catch (\Throwable $e) {
            debugging('local_oneapplogin failed to process the request: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Works out whether this request is an app login and, if so, clears the user's current tokens.
     */
    protected static function process_request(): void {
        global $CFG, $DB, $USER;

        // Cheapest possible check first: this runs on every single page load.
        $istokenrequest = self::script_is('/login/token.php');
        $islaunchrequest = self::script_is('/admin/tool/mobile/launch.php');
        if (!$istokenrequest && !$islaunchrequest) {
            return;
        }

        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }

        if (!self::get_setting('enabled', 1)) {
            return;
        }

        // Both scripts name the service they want with the same parameter.
        $shortname = optional_param('service', '', PARAM_ALPHANUMEXT);
        if ($shortname === '' || !self::service_is_enforced($shortname)) {
            return;
        }

        $service = $DB->get_record('external_services', ['shortname' => $shortname, 'enabled' => 1]);
        if (!$service) {
            return;
        }

        if ($istokenrequest) {
            // No authentication has happened yet: token.php resolves the user and checks the
            // password further down. Anyone can POST a username to this endpoint, so verify the
            // credentials here before touching anything, otherwise knowing a username would be
            // enough to log that user's device out at will.
            $username = \core_text::strtolower(trim(optional_param('username', '', PARAM_USERNAME)));
            $password = optional_param('password', '', PARAM_RAW);
            if ($username === '' || $password === '') {
                return;
            }

            $user = self::resolve_user($username);
            if (!$user || !self::credentials_are_valid($user, $password)) {
                return;
            }
            $userid = (int)$user->id;
        } else {
            // launch.php runs inside a browser session, so the user is already authenticated.
            if (empty($USER->id) || isguestuser()) {
                return;
            }
            $userid = (int)$USER->id;
        }

        $removed = self::revoke_tokens($userid, $service);
        if (!$removed) {
            return;
        }

        // The password was already verified, but token.php can still refuse to issue a token:
        // maintenance mode, an unconfirmed account, an expired password, a restricted service or a
        // missing moodle/webservice:createmobiletoken capability. Put the rows back in those cases
        // so a user who cannot obtain a new token is not left logged out of the device they had.
        if ($istokenrequest && self::get_setting('restoreonfailedlogin', 1)) {
            self::$removed = $removed;
            self::$userid = $userid;
            self::$serviceid = (int)$service->id;
            \core_shutdown_manager::register_function([self::class, 'restore_on_failed_login']);
        }
    }

    /**
     * Resolves the account a /login/token.php request is for.
     *
     * Mirrors the lookup authenticate_user_login() performs, including login by email address
     * when $CFG->authloginviaemail is on, so that the two agree on which account is in play.
     *
     * @param string $username username or, when allowed, email address
     * @return \stdClass|null user record, or null if there is no single unambiguous match
     */
    protected static function resolve_user(string $username) {
        global $CFG, $DB;

        $user = $DB->get_record('user',
            ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
        if ($user) {
            return $user;
        }

        if (empty($CFG->authloginviaemail)) {
            return null;
        }

        $email = clean_param($username, PARAM_EMAIL);
        if (!$email) {
            return null;
        }

        // Only usable when the address is unique, exactly as core requires.
        $select = 'mnethostid = :mnethostid AND LOWER(email) = LOWER(:email) AND deleted = 0';
        $users = $DB->get_records_select('user', $select,
            ['mnethostid' => $CFG->mnet_localhost_id, 'email' => $email], 'id', 'id', 0, 2);
        if (count($users) !== 1) {
            return null;
        }

        $found = reset($users);
        return $DB->get_record('user', ['id' => $found->id]);
    }

    /**
     * Checks the supplied password without any of the bookkeeping authenticate_user_login() does.
     *
     * Core verifies the password with $authplugin->user_login(), then separately calls
     * login_attempt_failed() on failure. Calling only the first half gives the same verdict while
     * leaving the lockout counter, the login failure events and the error log alone, so this
     * pre-check cannot lock anybody out or pollute the logs. token.php still performs its own full
     * authentication immediately afterwards.
     *
     * @param \stdClass $user
     * @param string $password
     * @return bool
     */
    protected static function credentials_are_valid(\stdClass $user, string $password): bool {
        global $CFG;

        require_once($CFG->libdir . '/authlib.php');

        if (!empty($user->suspended) || isguestuser($user)) {
            return false;
        }

        $auth = empty($user->auth) ? 'manual' : $user->auth;
        if ($auth === 'nologin' || !is_enabled_auth($auth)) {
            return false;
        }

        // A locked out account will be refused by token.php, so leave its tokens alone.
        if (login_is_lockedout($user)) {
            return false;
        }

        $authplugin = get_auth_plugin($auth);

        return (bool)$authplugin->user_login($user->username, $password);
    }

    /**
     * Deletes every permanent token the user holds for the given service.
     *
     * @param int $userid
     * @param \stdClass $service external_services record
     * @return \stdClass[] the deleted records, keyed by id
     */
    protected static function revoke_tokens(int $userid, \stdClass $service): array {
        global $DB;

        $conditions = [
            'userid' => $userid,
            'externalserviceid' => $service->id,
            'tokentype' => self::TOKEN_PERMANENT,
        ];

        $tokens = $DB->get_records('external_tokens', $conditions);
        if (!$tokens) {
            return [];
        }

        $DB->delete_records('external_tokens', $conditions);

        foreach ($tokens as $token) {
            self::log_revocation($token, $service);
        }

        return $tokens;
    }

    /**
     * Restores the deleted tokens when the request did not produce a replacement.
     *
     * Registered as a shutdown function, so it runs whether token.php returned a token, died with
     * an error or threw. A new token in the table means the login completed and the device that
     * just logged in owns the session now.
     */
    public static function restore_on_failed_login(): void {
        global $DB;

        try {
            if (empty(self::$removed)) {
                return;
            }
            $removed = self::$removed;
            self::$removed = [];

            $issued = $DB->record_exists('external_tokens', [
                'userid' => self::$userid,
                'externalserviceid' => self::$serviceid,
                'tokentype' => self::TOKEN_PERMANENT,
            ]);
            if ($issued) {
                return;
            }

            foreach ($removed as $token) {
                if ($DB->record_exists('external_tokens', ['id' => $token->id])) {
                    continue;
                }
                // Keep the original id so anything referencing it stays consistent.
                $DB->insert_record_raw('external_tokens', $token, false, false, true);
            }
        } catch (\Throwable $e) {
            debugging('local_oneapplogin failed to restore tokens: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Records the revocation in the standard log.
     *
     * @param \stdClass $token the token record, already deleted
     * @param \stdClass $service
     */
    protected static function log_revocation(\stdClass $token, \stdClass $service): void {
        try {
            $event = \local_oneapplogin\event\token_revoked::create([
                'objectid' => $token->id,
                'relateduserid' => $token->userid,
                'context' => \context_system::instance(),
                'other' => ['service' => $service->shortname],
            ]);
            $event->add_record_snapshot('external_tokens', $token);
            $event->trigger();
        } catch (\Throwable $e) {
            debugging('local_oneapplogin failed to log a revocation: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Checks whether the current request is for the given Moodle script.
     *
     * @param string $path path relative to wwwroot, e.g. '/login/token.php'
     * @return bool
     */
    protected static function script_is(string $path): bool {
        global $SCRIPT;

        $candidates = [];
        if (!empty($SCRIPT)) {
            $candidates[] = $SCRIPT;
        }
        foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates[] = $_SERVER[$key];
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = str_replace('\\', '/', $candidate);
            if (substr($candidate, -strlen($path)) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether single session enforcement applies to the requested service.
     *
     * @param string $shortname
     * @return bool
     */
    protected static function service_is_enforced(string $shortname): bool {
        $configured = array_filter(array_map('trim', explode(',', (string)self::get_setting('services', 'moodle_mobile_app'))));
        if (!$configured) {
            return false;
        }
        if (in_array('*', $configured, true)) {
            return true;
        }
        return in_array($shortname, $configured, true);
    }

    /**
     * Reads a plugin setting, falling back to the default when it has never been saved.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected static function get_setting(string $name, $default) {
        $value = get_config('local_oneapplogin', $name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}
