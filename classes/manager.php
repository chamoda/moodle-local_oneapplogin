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
 * This class runs from the \core\hook\after_config hook, dispatched from lib/setup.php on every
 * entry point (4.5: setup.php:1213), well before /login/token.php or
 * /admin/tool/mobile/launch.php look the token up. Deleting the row there forces Moodle to
 * generate a fresh token for the device that is logging in, which invalidates whatever the
 * previously logged in device still holds.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /**
     * Permanent token type.
     *
     * Mirrors EXTERNAL_TOKEN_PERMANENT, which is not guaranteed to be loaded this early in the
     * bootstrap.
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

    /** @var string|null Session id to spare when ending the user's web sessions. */
    protected static $keepsid = null;

    /**
     * Entry point, called only once hook_callbacks has confirmed this is an app login request.
     *
     * Never lets an exception escape, because a fatal here would take down every page of the site.
     *
     * @param string $script the matched script path, relative to wwwroot
     */
    public static function bootstrap(string $script): void {
        if (self::$ran) {
            return;
        }
        self::$ran = true;

        try {
            self::process_request($script);
        } catch (\Throwable $e) {
            self::log_error('failed to process the request', $e);
        }
    }

    /**
     * Clears the user's current tokens for the service this login is asking for.
     *
     * @param string $script the matched script path, relative to wwwroot
     */
    protected static function process_request(string $script): void {
        global $CFG, $DB, $USER;

        $istokenrequest = ($script === hook_callbacks::SCRIPT_TOKEN);

        // The hook is dispatched during upgrades too, unlike the legacy callback path, and
        // token.php refuses to issue anything when web services are off site-wide.
        if (during_initial_install() || isset($CFG->upgraderunning) || empty($CFG->enablewebservices)) {
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
        $killsessions = (bool)self::get_setting('killwebsessions', 0);

        if (!$removed && !$killsessions) {
            return;
        }

        // Both remaining jobs depend on whether Moodle actually issues a token, which is not known
        // until the request is over, so they run at shutdown. The password was already verified,
        // but token.php can still refuse afterwards: maintenance mode, an unconfirmed account, an
        // expired password, a restricted service, a missing moodle/webservice:createmobiletoken.
        self::$removed = self::get_setting('restoreonfailedlogin', 1) ? $removed : [];
        self::$userid = $userid;
        self::$serviceid = (int)$service->id;

        // launch.php is itself driven by a browser session, and re-entering it after an OAuth or
        // confirmation redirect needs that session to still be there, so spare the current one.
        self::$keepsid = $istokenrequest ? null : session_id();

        \core_shutdown_manager::register_function([self::class, 'finalise']);
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
     * Finishes the job once the outcome of the request is known.
     *
     * Registered as a shutdown function, so it runs whether the script returned a token, died with
     * an error or threw. A new token in the table means the login completed; no token means Moodle
     * refused it, and the user should be left exactly as they were.
     */
    public static function finalise(): void {
        global $DB;

        try {
            $issued = $DB->record_exists('external_tokens', [
                'userid' => self::$userid,
                'externalserviceid' => self::$serviceid,
                'tokentype' => self::TOKEN_PERMANENT,
            ]);

            if (!$issued) {
                self::restore_tokens();
                return;
            }

            if (self::get_setting('killwebsessions', 0)) {
                // kill_user_sessions() is deprecated as of 4.5; this is the current name.
                \core\session\manager::destroy_user_sessions(self::$userid, self::$keepsid);
            }
        } catch (\Throwable $e) {
            self::log_error('failed to finalise the login', $e);
        }
    }

    /**
     * Puts the revoked tokens back, ids and all, so the existing device keeps working.
     */
    protected static function restore_tokens(): void {
        global $DB;

        $removed = self::$removed;
        self::$removed = [];

        foreach ($removed as $token) {
            if ($DB->record_exists('external_tokens', ['id' => $token->id])) {
                continue;
            }
            // Keep the original id so anything referencing it stays consistent.
            $DB->insert_record_raw('external_tokens', $token, false, false, true);
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
            self::log_error('failed to log a revocation', $e);
        }
    }

    /**
     * Logs an internal failure without ever writing to the response body.
     *
     * debugging() echoes when $CFG->debugdisplay is on and the script has not set NO_DEBUG_DISPLAY.
     * launch.php sets nothing, so output here would arrive before its redirect() and turn a broken
     * plugin into a broken app login. The error log is the only safe destination this early.
     *
     * @param string $context
     * @param \Throwable $e
     */
    protected static function log_error(string $context, \Throwable $e): void {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.Found
        error_log('local_oneapplogin ' . $context . ': ' . $e->getMessage());
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
