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

namespace local_oneapplogin\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Triggered when a previous app token is revoked because of a new login.
 *
 * @package    local_oneapplogin
 * @copyright  2026 Xaventra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_revoked extends \core\event\base {

    /**
     * Initialise the event data.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'external_tokens';
    }

    /**
     * Human readable event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtokenrevoked', 'local_oneapplogin');
    }

    /**
     * Human readable description.
     *
     * @return string
     */
    public function get_description() {
        $service = isset($this->other['service']) ? $this->other['service'] : '';
        return "The web service token with id '{$this->objectid}' for the service '{$service}' belonging to " .
            "the user with id '{$this->relateduserid}' was revoked because a new app login was detected.";
    }
}
