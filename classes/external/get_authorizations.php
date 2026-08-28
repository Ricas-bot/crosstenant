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

namespace local_crosstenant\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Ponto de entrada capability-gated para listar autorizações de um curso.
 *
 * Consulta direta à tabela porque é só leitura para apresentação (secção
 * 31 — tabela "Authorized users"); a lógica de negócio (o que conta como
 * válido agora mesmo) continua exclusivamente em authorization_manager.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_authorizations extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Curso'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = \context_course::instance($params['courseid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('local/crosstenant:view', $context);

        $records = $DB->get_records(
            'local_crosstenant_access',
            ['courseid' => $params['courseid']],
            'timecreated DESC'
        );

        $authorizations = [];
        foreach ($records as $record) {
            $authorizations[] = [
                'id' => (int) $record->id,
                'userid' => (int) $record->userid,
                'source_tenantid' => (int) $record->source_tenantid,
                'roleid' => (int) $record->roleid,
                'status' => $record->status,
                'timecreated' => (int) $record->timecreated,
                'timeexpires' => $record->timeexpires !== null ? (int) $record->timeexpires : 0,
                'reason' => $record->reason ?? '',
            ];
        }

        return ['authorizations' => $authorizations];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'authorizations' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT),
                    'userid' => new external_value(PARAM_INT),
                    'source_tenantid' => new external_value(PARAM_INT),
                    'roleid' => new external_value(PARAM_INT),
                    'status' => new external_value(PARAM_TEXT),
                    'timecreated' => new external_value(PARAM_INT),
                    'timeexpires' => new external_value(PARAM_INT, '0 = sem expiração'),
                    'reason' => new external_value(PARAM_RAW),
                ])
            ),
        ]);
    }
}
