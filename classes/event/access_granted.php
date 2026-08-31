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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace local_crosstenant\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Evento: acesso cross-tenant concedido (secção 25/35).
 *
 * userid          = quem concedeu (createdby)
 * relateduserid   = quem foi autorizado
 * objectid        = id em local_crosstenant_access
 * other           = source_tenantid, target_tenantid, roleid
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_granted extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_crosstenant_access';
    }

    public static function get_name() {
        return get_string('event_access_granted', 'local_crosstenant');
    }

    public function get_description() {
        return "O utilizador com id '{$this->userid}' concedeu acesso cross-tenant ao curso " .
            "'{$this->courseid}' para o utilizador com id '{$this->relateduserid}' " .
            "(tenant origem {$this->other['source_tenantid']} -> tenant destino {$this->other['target_tenantid']}, " .
            "roleid {$this->other['roleid']}).";
    }

    public function get_url() {
        return new \moodle_url('/local/crosstenant/course.php', ['id' => $this->courseid]);
    }

    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('relateduserid tem de estar definido (utilizador autorizado).');
        }
        if (!isset($this->other['source_tenantid'])) {
            throw new \coding_exception('other[source_tenantid] tem de estar definido.');
        }
        if (!isset($this->other['target_tenantid'])) {
            throw new \coding_exception('other[target_tenantid] tem de estar definido.');
        }
        if (!isset($this->other['roleid'])) {
            throw new \coding_exception('other[roleid] tem de estar definido.');
        }
    }

    public static function get_objectid_mapping() {
        return ['db' => 'local_crosstenant_access', 'restore' => \core\event\base::NOT_MAPPED];
    }

    public static function get_other_mapping() {
        return false;
    }
}
