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

namespace local_crosstenant\task;

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;

/**
 * Scheduled task: varre autorizações cross-tenant expiradas.
 *
 * Toda a lógica já existia e já estava testada desde a Fase 2/4
 * (authorization_manager::expire_due()) — esta classe é só o encaixe no
 * agendador do Totara (secção 27). A própria expire_due() já era
 * idempotente antes desta fase; correr esta task duas vezes seguidas
 * continua sem problema nenhum.
 *
 * Nota de segurança: mesmo que esta task nunca corresse, o sistema não
 * ficaria inseguro — get_active_authorization() (usada pela decisão de
 * acesso) já trata em tempo real uma linha "active" com timeexpires no
 * passado como não autorizada. Esta task existe para arrumação (marcar
 * o estado como "expired" e remover a inscrição real), não para decidir
 * acesso.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_expirations extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_process_expirations', 'local_crosstenant');
    }

    public function execute(): void {
        mtrace('local_crosstenant: a procurar autorizações cross-tenant expiradas...');

        $count = authorization_manager::expire_due();

        mtrace("local_crosstenant: {$count} autorização(ões) marcada(s) como expired, inscrição(ões) removida(s).");
    }
}
