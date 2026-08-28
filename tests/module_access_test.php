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
 * EDUdigital — Cross-Tenant Course Access (V1)
 * Fase 5 — validação de acesso real dentro do curso: atividades e
 * ficheiros (secção 60), e confirmação de que o âmbito continua
 * deliberadamente restrito a curso + módulo (secção 21/26).
 *
 * Notas (README): download real de ficheiro via pluginfile.php e
 * fluxo completo de quiz/SCORM/notas ficam para o smoke test manual no
 * browser — o que se prova aqui é o ponto de decisão real
 * (is_user_access_prevented) ao nível de contexto de módulo, que é a
 * causa raiz identificada na análise inicial para lib/filelib.php.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;

class local_crosstenant_module_access_test extends \core_phpunit\testcase {

    /** @var \stdClass */
    protected $tenant1;
    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $course2;
    /** @var \stdClass cm_info-like record do módulo de teste */
    protected $page;
    /** @var \context_module */
    protected $modulecontext;
    /** @var int */
    protected $studentroleid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var \totara_tenant\testing\generator $tenantgenerator */
        $tenantgenerator = \totara_tenant\testing\generator::instance();
        $tenantgenerator->enable_tenants();
        $this->setAdminUser();

        $this->tenant1 = $tenantgenerator->create_tenant();
        $this->tenant2 = $tenantgenerator->create_tenant();

        $this->user1 = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant1->id]);

        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $this->course2 = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);

        // Um recurso "page" simples é suficiente para testar o contexto
        // de módulo — o mecanismo de decisão é o mesmo independentemente
        // do tipo de atividade (secção 60: Page, PDF, Video, File, SCORM
        // dependem todos do mesmo is_user_access_prevented()).
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->course2->id]);
        $this->modulecontext = \context_module::instance($this->page->cmid);

        global $DB;
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }

    /** Secção 60 — sem autorização, também o módulo fica bloqueado (comportamento nativo). */
    public function test_module_context_denied_without_authorization(): void {
        $this->assertTrue(
            $this->modulecontext->is_user_access_prevented($this->user1->id),
            'Sem autorização, o contexto do módulo também deve ficar bloqueado (herda o tenant do curso).'
        );
    }

    /** O teste que a análise inicial pedia: com autorização, o módulo (e por extensão, os seus ficheiros) abre. */
    public function test_module_context_allowed_with_active_course_authorization(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertFalse(
            $this->modulecontext->is_user_access_prevented($this->user1->id),
            'Com autorização ativa no curso, o contexto do módulo lá dentro também deve estar liberto — ' .
            'é aqui que lib/filelib.php decide se serve um ficheiro.'
        );
    }

    public function test_module_context_denied_again_after_revoke(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );
        $this->assertFalse($this->modulecontext->is_user_access_prevented($this->user1->id));

        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertTrue(
            $this->modulecontext->is_user_access_prevented($this->user1->id),
            'Depois de revogado, o módulo também deve voltar a DENY.'
        );
    }

    /** Secção 58 — a autorização a um curso não deve abrir módulos de OUTRO curso do mesmo tenant. */
    public function test_module_of_a_different_authorized_course_is_not_leaked(): void {
        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $othercourse = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $othermodule = $this->getDataGenerator()->create_module('page', ['course' => $othercourse->id]);
        $othercontext = \context_module::instance($othermodule->cmid);

        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertFalse($this->modulecontext->is_user_access_prevented($this->user1->id));
        $this->assertTrue(
            $othercontext->is_user_access_prevented($this->user1->id),
            'Um módulo de outro curso do mesmo tenant destino não deve ser afetado.'
        );
    }

    /**
     * Guarda de âmbito (secção 21/26) — mesmo com autorização ativa,
     * níveis de contexto fora de curso/módulo continuam sempre DENY por
     * este plugin. Usa-se o contexto de categoria do próprio tenant
     * destino como exemplo representativo.
     */
    public function test_category_context_is_never_authorized_even_with_active_grant(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $categorycontext = \context_coursecat::instance($this->tenant2->categoryid);

        $this->assertTrue(
            $categorycontext->is_user_access_prevented($this->user1->id),
            'A autorização é User -> Course; nunca deve abrir a categoria/tenant inteiro.'
        );
    }
}
