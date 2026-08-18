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
 * Admin settings.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_oneapplogin', get_string('pluginname', 'local_oneapplogin'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_oneapplogin/enabled',
        get_string('enabled', 'local_oneapplogin'),
        get_string('enabled_desc', 'local_oneapplogin'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_oneapplogin/services',
        get_string('services', 'local_oneapplogin'),
        get_string('services_desc', 'local_oneapplogin'),
        'moodle_mobile_app',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_oneapplogin/restoreonfailedlogin',
        get_string('restoreonfailedlogin', 'local_oneapplogin'),
        get_string('restoreonfailedlogin_desc', 'local_oneapplogin'),
        1
    ));
}
