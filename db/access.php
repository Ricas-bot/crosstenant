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
 * Capability definitions for local_crosstenant.
 *
 * Contextlevel é CONTEXT_COURSE (secção 31/34 — "Course permission +
 * Cross-tenant permission", tudo decidido no contexto do curso alvo).
 *
 * Arquétipos por defeito são deliberadamente conservadores: só 'manager'
 * pode conceder/revogar. Ajustável via overrides de role no site, sem
 * precisar de alterar código — não é uma decisão definitiva, é só o
 * ponto de partida mais seguro para um sistema do governo (secção 22
 * descreve o fluxo como acionado por um "Administrator").
 *
 * @package     local_crosstenant
 * @copyright   2026 EDUdigital
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/crosstenant:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    'local/crosstenant:grantaccess' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/crosstenant:revokeaccess' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
