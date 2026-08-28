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
 * Fase 2 — testes de aceitação do extension point com o plugin real
 * instalado. Complementa lib/tests/accesslib_crosstenant_extension_test.php
 * (Fase 1, sem plugin) com os Testes B e C da secção 37 do prompt de
 * implementação, mais expiração, revogação, idempotência e restrição de
 * papel (secções 43, 42, 46, 21).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;

class local_crosstenant_extension_point_test extends \core_phpunit\testcase {

    /** @var \stdClass */
    protected $tenant1;
    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $course2;
    /** @var \context_course */
    protected $coursecontext2;
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
        $this->coursecontext2 = \context_course::instance($this->course2->id);

        global $DB;
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }

    /** Teste B (secção 37) — cross-tenant sem autorização, com o plugin já instalado. */
    public function test_teste_b_cross_tenant_denied_without_authorization(): void {
        $this->assertTrue(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Sem autorização, o acesso cross-tenant deve continuar bloqueado mesmo com o plugin instalado.'
        );
    }

    /** Teste C (secção 37) — cross-tenant com autorização ativa. */
    public function test_teste_c_cross_tenant_allowed_with_active_authorization(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertFalse(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Com autorização ativa, o acesso cross-tenant deve ser permitido.'
        );
    }

    /** Secção 26/58 — a autorização é User -> Course, nunca User -> Tenant. */
    public function test_authorization_does_not_leak_to_other_courses_of_same_target_tenant(): void {
        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $othercourse = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $othercontext = \context_course::instance($othercourse->id);

        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertFalse($this->coursecontext2->is_user_access_prevented($this->user1->id));
        $this->assertTrue(
            $othercontext->is_user_access_prevented($this->user1->id),
            'A autorização não deve abrir outros cursos do mesmo tenant destino.'
        );
    }

    /** Secção 42 — depois de revogado, volta a DENY; a pertença ao Tenant A mantém-se intacta. */
    public function test_revoke_denies_access_again_and_tenant_membership_is_untouched(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );
        $this->assertFalse($this->coursecontext2->is_user_access_prevented($this->user1->id));

        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertTrue(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Depois de revogado, o acesso deve voltar a DENY.'
        );

        global $DB;
        $tenantidafter = $DB->get_field('user', 'tenantid', ['id' => $this->user1->id]);
        $this->assertEquals(
            $this->tenant1->id,
            $tenantidafter,
            'A revogação nunca deve alterar user.tenantid — a pertença ao Tenant A tem de continuar intacta.'
        );
    }

    /** Secção 43 — expiração denega mesmo antes da scheduled task correr. */
    public function test_expired_authorization_denies_access_before_task_runs(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória', time() - 1
        );

        $this->assertTrue(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Uma autorização já expirada deve DENY mesmo antes de expire_due() correr.'
        );
    }

    /** Secção 27 — expire_due() varre e marca, de forma idempotente. */
    public function test_expire_due_marks_record_and_is_idempotent(): void {
        global $DB;

        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória', time() - 1
        );

        $count1 = authorization_manager::expire_due();
        $this->assertSame(1, $count1);

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);
        $this->assertSame(authorization_manager::STATUS_EXPIRED, $updated->status);

        $count2 = authorization_manager::expire_due();
        $this->assertSame(0, $count2, 'Correr expire_due() duas vezes não deve reprocessar o mesmo registo.');
    }

    /** Secção 46 — grant() chamado duas vezes não cria duas autorizações ativas. */
    public function test_grant_is_idempotent_no_duplicate_active_rows(): void {
        global $DB;

        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo A');
        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo B');

        $count = $DB->count_records('local_crosstenant_access', [
            'userid' => $this->user1->id,
            'courseid' => $this->course2->id,
            'status' => authorization_manager::STATUS_ACTIVE,
        ]);

        $this->assertSame(1, $count);
    }

    /** Secção 21/9 (Teste crítico de privilégio) — papel administrativo é sempre rejeitado. */
    public function test_grant_rejects_disallowed_role(): void {
        global $DB;
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $this->expectException(\moodle_exception::class);
        authorization_manager::grant($this->user1->id, $this->course2->id, $managerroleid, 2, 'Tentativa indevida');
    }

    /** Secção 17/32 — same tenant nunca gera autorização cross-tenant. */
    public function test_grant_rejects_same_tenant(): void {
        $tenantcategory1 = \coursecat::get($this->tenant1->categoryid);
        $course1 = $this->getDataGenerator()->create_course(['category' => $tenantcategory1->id]);

        $this->expectException(\moodle_exception::class);
        authorization_manager::grant($this->user1->id, $course1->id, $this->studentroleid, 2, 'Mesmo tenant');
    }

    /** Secção 11 — same-tenant continua completamente inalterado com o plugin instalado. */
    public function test_same_tenant_access_still_unaffected_with_plugin_installed(): void {
        $tenantcategory1 = \coursecat::get($this->tenant1->categoryid);
        $course1 = $this->getDataGenerator()->create_course(['category' => $tenantcategory1->id]);
        $coursecontext1 = \context_course::instance($course1->id);

        $this->assertFalse($coursecontext1->is_user_access_prevented($this->user1->id));
    }
}
