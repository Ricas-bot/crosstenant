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
 * Pesquisa de utilizadores candidatos para o painel "Add user" (secção 16/32).
 *
 * Mostra exclusivamente utilizadores de OUTROS tenants - nunca do
 * próprio tenant do curso, que devem usar o mecanismo normal de
 * inscrição do Totara (secção 17/32).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Curso alvo'),
            'query' => new external_value(PARAM_TEXT, 'Texto de pesquisa (nome ou email)'),
        ]);
    }

    public static function execute(int $courseid, string $query): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'query' => $query,
        ]);

        $context = \context_course::instance($params['courseid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('local/crosstenant:grantaccess', $context);

        $targettenantid = $context->tenantid ? (int) $context->tenantid : null;
        if (!$targettenantid) {
            // Curso sem tenant próprio - este plugin não se aplica (secção 21).
            return ['users' => []];
        }

        $query = trim($params['query']);
        if (\core_text::strlen($query) < 2) {
            return ['users' => []];
        }

        $searchparam = '%' . $DB->sql_like_escape($query) . '%';
        $like1 = $DB->sql_like('u.firstname', ':q1', false, false);
        $like2 = $DB->sql_like('u.lastname', ':q2', false, false);
        $like3 = $DB->sql_like('u.email', ':q3', false, false);

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.tenantid
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id <> :guestid
                   AND (u.tenantid IS NULL OR u.tenantid <> :targettenantid)
                   AND ($like1 OR $like2 OR $like3)
              ORDER BY u.lastname ASC, u.firstname ASC";

        $records = $DB->get_records_sql($sql, [
            'guestid' => (int) $CFG->siteguest,
            'targettenantid' => $targettenantid,
            'q1' => $searchparam,
            'q2' => $searchparam,
            'q3' => $searchparam,
        ], 0, 15);

        $users = [];
        foreach ($records as $record) {
            $tenantname = get_string('notenant', 'local_crosstenant');
            if ($record->tenantid) {
                $name = $DB->get_field('tenant', 'name', ['id' => $record->tenantid]);
                if ($name) {
                    $tenantname = format_string($name);
                }
            }
            $users[] = [
                'id' => (int) $record->id,
                'fullname' => trim($record->firstname . ' ' . $record->lastname),
                'tenantname' => $tenantname,
            ];
        }

        return ['users' => $users];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT),
                    'fullname' => new external_value(PARAM_TEXT),
                    'tenantname' => new external_value(PARAM_TEXT),
                ])
            ),
        ]);
    }
}
