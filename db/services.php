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

/**
 * External function registration for local_crosstenant.
 *
 * 'ajax' => true regista estas funções no dispatcher nativo de AJAX do
 * Moodle/Totara (lib/ajax/service.php), que já valida sesskey por si só
 * para qualquer chamada autenticada — é por isto que nenhuma das classes
 * em classes/external/ faz a sua própria validação de sesskey (secção 39
 * do prompt de implementação: "validar sesskey"). Isto é herdado
 * gratuitamente por usar a External API oficial, em vez de reinventado.
 *
 * A Fase 8 (UI) vai chamar exatamente estas funções via JS — nenhuma
 * lógica de segurança nova precisa de ser escrita nessa fase.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_crosstenant_grant_access' => [
        'classname'    => 'local_crosstenant\external\grant_access',
        'methodname'   => 'execute',
        'description'  => 'Concede acesso cross-tenant explícito User -> Course (secções 22, 33, 34).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/crosstenant:grantaccess',
    ],
    'local_crosstenant_revoke_access' => [
        'classname'    => 'local_crosstenant\external\revoke_access',
        'methodname'   => 'execute',
        'description'  => 'Revoga uma autorização cross-tenant ativa (secção 23).',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/crosstenant:revokeaccess',
    ],
    'local_crosstenant_get_authorizations' => [
        'classname'    => 'local_crosstenant\external\get_authorizations',
        'methodname'   => 'execute',
        'description'  => 'Lista as autorizações cross-tenant de um curso (secção 31).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/crosstenant:view',
    ],
    'local_crosstenant_search_users' => [
        'classname'    => 'local_crosstenant\external\search_users',
        'methodname'   => 'execute',
        'description'  => 'Pesquisa utilizadores candidatos a acesso cross-tenant (secção 32).',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/crosstenant:searchusers',
    ],
];
