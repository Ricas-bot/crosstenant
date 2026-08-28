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

namespace local_crosstenant\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Cross-tenant course access — access decision service.
 *
 * Esta é a ÚNICA classe do plugin com quem o extension point do core
 * fala (via o callback em lib.php). Responde a exatamente uma pergunta:
 * "este utilizador está explicitamente autorizado a aceder a este
 * contexto específico?"
 *
 * ÂMBITO (secção 21/26/60/61, revisto na Fase 5): a autorização é
 * sempre User -> Course, mas "aceder ao curso" na prática do Totara
 * significa mais do que só o contexto do curso em si — inclui os
 * módulos/atividades LÁ DENTRO, porque é aí que vivem os ficheiros
 * (lib/filelib.php serve a maioria dos recursos a partir de
 * context_module, não context_course) e a maior parte das verificações
 * de capability por atividade. Contextos de módulo herdam o tenantid do
 * curso pai (confirmado em accesslib.php:
 * "context::insert_context_record(CONTEXT_MODULE, ..., $parentcontext->tenantid)"),
 * por isso o core bloquearia esse acesso exatamente da mesma forma — e
 * sem este alargamento, um utilizador autorizado ficaria com acesso ao
 * curso mas não conseguiria abrir um único ficheiro lá dentro.
 *
 * Deliberadamente NÃO alargado a blocos, comentários/reações, categoria,
 * tenant ou sistema — só curso e módulo. Isto mantém a garantia da
 * secção 21 ("a V1 não deve tornar o curso visível para todo o tenant
 * de origem, nem abrir mais do que o especificamente autorizado").
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_manager {

    /**
     * @param int $userid
     * @param \context $context
     * @return bool true apenas se o contexto for de curso, ou de um
     *              módulo dentro de um curso, com uma autorização válida,
     *              ativa e não expirada para este utilizador
     */
    public static function can_access(int $userid, \context $context): bool {
        $courseid = self::resolve_courseid($context);

        if ($courseid === null) {
            // Qualquer outro nível de contexto (tenant, categoria,
            // sistema, bloco, ...) nunca é autorizado por este plugin —
            // sem exceções (secção 21/26).
            return false;
        }

        return authorization_manager::is_authorized($userid, $courseid);
    }

    /**
     * Resolve o courseid relevante para a decisão, ou null se este
     * contexto estiver fora do âmbito da V1.
     *
     * @param \context $context
     * @return int|null
     */
    protected static function resolve_courseid(\context $context): ?int {
        if ($context instanceof \context_course) {
            return (int) $context->instanceid;
        }

        if ($context instanceof \context_module) {
            $coursecontext = $context->get_course_context(false);
            return $coursecontext ? (int) $coursecontext->instanceid : null;
        }

        return null;
    }
}