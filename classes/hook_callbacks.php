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
 * Hook callbacks for Moodle 4.4 and later.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /** Script that issues tokens from a username and password. */
    const SCRIPT_TOKEN = '/login/token.php';

    /** Script that issues tokens to a browser authenticated session. */
    const SCRIPT_LAUNCH = '/admin/tool/mobile/launch.php';

    /**
     * Runs on every request, so it does as little as possible.
     *
     * The check lives here rather than in the manager so that manager.php, which is an order of
     * magnitude larger, is only autoloaded on the handful of requests that are actually app logins.
     * $SCRIPT is the request path relative to wwwroot, set by initialise_fullme() at setup.php:840,
     * well before this hook is dispatched at setup.php:1213. It is null under CLI, which never
     * serves either endpoint.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $SCRIPT;

        if ($SCRIPT !== self::SCRIPT_TOKEN && $SCRIPT !== self::SCRIPT_LAUNCH) {
            return;
        }

        manager::bootstrap($SCRIPT);
    }
}
