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
 * local_crosstenant lib.php
 *
 * Implementa o contrato do extension point documentado em
 * deployment/patches/totara-19/CORE_PATCH.md. Esta é a ÚNICA ligação
 * entre o core e o plugin, e é inteiramente por convenção de nome — o
 * core nunca faz require_once nem verifica a existência deste plugin
 * diretamente (secção 8/48 do prompt de implementação).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Callback do extension point de cross-tenant access.
 *
 * Chamado por context::crosstenant_extension_allows() (lib/accesslib.php)
 * exclusivamente quando o core já determinou que bloquearia o acesso por
 * diferença de tenant. Nunca é chamado no caminho same-tenant — ver
 * CORE_PATCH.md secção 7.
 *
 * Contrato de retorno:
 *   true  => ALLOW explícito para este utilizador + este contexto
 *   null  => NO_DECISION, aplica-se o comportamento original do core
 *
 * Nunca devolver false explicitamente como "decisão de negar" — o
 * vocabulário do contrato é ALLOW ou NO_DECISION, nunca DENY explícito
 * vindo do plugin (secção 9 do prompt de implementação). O deny-by-default
 * é sempre uma propriedade do core, não uma decisão ativa do plugin.
 *
 * @param int $userid
 * @param \context $context
 * @return bool|null
 */
function local_crosstenant_crosstenant_access_check(int $userid, \context $context): ?bool {
    $allowed = \local_crosstenant\service\access_manager::can_access($userid, $context);
    return $allowed ? true : null;
}

/**
 * Injeta a ligação "Cross-Tenant Access" no menu de administração do
 * curso (secção 31: "Course administration → Cross-Tenant Access"),
 * apenas para quem tem local/crosstenant:view, e apenas em cursos que
 * pertençam a um tenant (secção 21 — o plugin não se aplica de outra
 * forma).
 *
 * @param \global_navigation|\navigation_node $navigation
 * @param \stdClass $course
 * @param \context_course $context
 */
function local_crosstenant_extend_navigation_course($navigation, $course, $context): void {
    if (empty($context->tenantid)) {
        return;
    }

    if (!has_capability('local/crosstenant:view', $context)) {
        return;
    }

    $url = new \moodle_url('/local/crosstenant/course.php', ['id' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'local_crosstenant'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'local_crosstenant',
        new \pix_icon('i/settings', '')
    );
}
