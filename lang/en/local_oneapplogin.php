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
 * Strings for local_oneapplogin.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'One app login';
$string['privacy:metadata'] = 'The One app login plugin does not store any personal data. It only deletes existing web service tokens so that a new app login replaces the previous one.';

$string['enabled'] = 'Enable single app session';
$string['enabled_desc'] = 'When enabled, logging in from the mobile app revokes the token issued to any device that was logged in before, so only the most recent device stays signed in.';

$string['services'] = 'Web services';
$string['services_desc'] = 'Comma separated list of web service short names to enforce this on. The Moodle app uses <code>moodle_mobile_app</code>. Use <code>*</code> to enforce it for every service, which will also affect any custom integration that authenticates through /login/token.php.';

$string['killwebsessions'] = 'End web sessions too';
$string['killwebsessions_desc'] = 'Also sign the user out of the site in their browsers when they log in from the app, so the account is active in one place only. Sessions are ended after the login succeeds, never on a failed attempt. One exception: a browser based app login (the SSO flow through launch.php) keeps the session it is itself running in, because that flow can re-enter and would otherwise break. Off by default, since it signs people out of work in progress.';

$string['restoreonfailedlogin'] = 'Restore token on failed login';
$string['restoreonfailedlogin_desc'] = 'The password is verified before anything is deleted, but Moodle can still refuse to issue a token afterwards, for example during maintenance mode, for an unconfirmed account, for an expired password or when the user lacks the capability to create app tokens. With this enabled the previous token is put back whenever the request does not produce a new one, so a user who cannot obtain a token is not left signed out of the device they already had. Leave this on unless you have a reason not to.';

$string['eventtokenrevoked'] = 'App token revoked';
