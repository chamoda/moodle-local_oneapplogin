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

/**
 * Callbacks for local_oneapplogin.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fired from lib/setup.php on every request, before any page logic runs.
 *
 * Moodle 4.4 and later route this through \core\hook\after_config, which db/hooks.php also
 * subscribes to. The manager guards against running twice, so it does not matter which path
 * a given Moodle version takes.
 */
function local_oneapplogin_after_config() {
    \local_oneapplogin\manager::bootstrap();
}
