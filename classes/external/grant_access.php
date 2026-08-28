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
 * Ponto de entrada capability-gated para conceder acesso cross-tenant.
 *
 * ESTA é a fronteira de segurança — não authorization_manager::grant()
 * (secção 33/34: "Todos os endpoints devem validar capability no
 * servidor. A existência de um botão na interface nunca pode ser
 * considerada mecanismo de segurança.").
 *
 * Nota estrutural sobre spoofing de tenant (secção 42): repara que os
 * únicos inputs aceites são userid, courseid, roleid, reason e
 * timeexpires. Não existe parâmetro source_tenantid nem target_tenantid
 * — não é apenas validado, é estruturalmente impossível o chamador
 * influenciar esses valores por esta via, porque authorization_manager
 * os deriva sempre do utilizador e do curso reais.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grant_access extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(
                PARAM_INT, 'Utilizador a autorizar (tem de pertencer a um tenant diferente do curso)'
            ),
            'courseid' => new external_value(PARAM_INT, 'Curso alvo'),
            'roleid' => new external_value(
                PARAM_INT, 'Papel a conceder — tem de ser student ou editingteacher'
            ),
            'reason' => new external_value(PARAM_TEXT, 'Motivo da autorização', VALUE_DEFAULT, ''),
            'timeexpires' => new external_value(
                PARAM_INT, 'Unix timestamp de expiração, 0 = sem expiração', VALUE_DEFAULT, 0
            ),
        ]);
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @param int $roleid
     * @param string $reason
     * @param int $timeexpires
     * @return array
     */
    public static function execute(
        int $userid,
        int $courseid,
        int $roleid,
        string $reason = '',
        int $timeexpires = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
            'roleid' => $roleid,
            'reason' => $reason,
            'timeexpires' => $timeexpires,
        ]);

        // validate_context() cobre login + acesso do CHAMADOR a este
        // contexto (não do utilizador a autorizar) — é o próprio
        // require_login()/is_user_access_prevented() do core a correr
        // aqui para o ator, tal como em qualquer página normal do site.
        $context = \context_course::instance($params['courseid'], MUST_EXIST);
        self::validate_context($context);
        require_capability('local/crosstenant:grantaccess', $context);

        $record = authorization_manager::grant(
            $params['userid'],
            $params['courseid'],
            $params['roleid'],
            $USER->id,
            $params['reason'] !== '' ? $params['reason'] : null,
            $params['timeexpires'] > 0 ? $params['timeexpires'] : null
        );

        return [
            'id' => $record->id,
            'status' => $record->status,
            'timecreated' => $record->timecreated,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id da autorização'),
            'status' => new external_value(PARAM_TEXT, 'active | revoked | expired'),
            'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
        ]);
    }
}
