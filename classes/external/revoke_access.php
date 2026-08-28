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
use external_single_structure;
use external_value;
use local_crosstenant\service\authorization_manager;

/**
 * Ponto de entrada capability-gated para revogar acesso cross-tenant.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_access extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Utilizador cujo acesso cross-tenant está a ser revogado'),
            'courseid' => new external_value(PARAM_INT, 'Curso'),
        ]);
    }

    public static function execute(int $userid, int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('local/crosstenant:revokeaccess', $context);

        $revoked = authorization_manager::revoke($params['userid'], $params['courseid'], $USER->id);

        return ['revoked' => $revoked];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'revoked' => new external_value(
                PARAM_BOOL, 'true se uma autorização ativa foi de facto revogada, false se não havia nada ativo'
            ),
        ]);
    }
}
