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
 * Fase 10 — checklist de segurança (secção 39-42).
 *
 * A maior parte já estava coberta por testes de fases anteriores —
 * este ficheiro consolida essa cobertura e acrescenta os itens que
 * ainda faltavam mesmo: utilizador eliminado/suspenso (secção 35),
 * curso eliminado (secção 36), e IDOR entre cursos (capability scoping).
 *
 * Cobertura já existente noutros ficheiros, não duplicada aqui:
 * - tenant spoofing estrutural: tests/external_test.php
 *   test_grant_endpoint_never_accepts_tenant_ids_as_input
 * - escalada de papel: tests/*_test.php (várias)
 * - capability bypass: tests/external_test.php
 * - autorização revogada/expirada: tests/revoke_test.php, extension_point_test.php
 * - CSRF/sesskey: NÃO testado aqui — herdado do dispatcher AJAX nativo
 *   do Moodle/Totara (db/services.php usa 'ajax' => true), que já valida
 *   sesskey para qualquer chamada autenticada. Ver CORE_PATCH.md/README
 *   para a justificação.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;
use local_crosstenant\external\grant_access;

class local_crosstenant_security_test extends \core_phpunit\testcase {

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

    /** Secção 35 — utilizador suspenso: acesso efetivo tem de seguir o estado real. */
    public function test_suspended_user_access_denied_despite_active_authorization(): void {
        global $DB;

        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');
        $this->assertFalse($this->coursecontext2->is_user_access_prevented($this->user1->id));

        $DB->set_field('user', 'suspended', 1, ['id' => $this->user1->id]);

        $this->assertTrue(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Utilizador suspenso não deve ter acesso, mesmo com autorização "active" na BD.'
        );

        // A autorização em si permanece "active" — só o acesso efetivo é
        // negado. Não reativamos nem alteramos o utilizador (secção 35).
        $raw = $DB->get_record('local_crosstenant_access', [
            'userid' => $this->user1->id, 'courseid' => $this->course2->id,
        ]);
        $this->assertSame(authorization_manager::STATUS_ACTIVE, $raw->status);
    }

    /** Secção 35 — utilizador eliminado: mesma garantia, via delete_user() oficial. */
    public function test_deleted_user_access_denied_despite_active_authorization(): void {
        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');
        $this->assertFalse($this->coursecontext2->is_user_access_prevented($this->user1->id));

        delete_user($this->user1);

        $this->assertTrue(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'Utilizador eliminado não deve ter acesso, mesmo com autorização "active" na BD.'
        );
    }

    /** Secção 36 — curso eliminado: autorizações órfãs marcadas como revoked, sem erro. */
    public function test_course_deletion_marks_active_authorizations_as_revoked(): void {
        global $DB;

        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo'
        );

        delete_course($this->course2, false);

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);
        $this->assertNotFalse($updated, 'A linha de auditoria tem de sobreviver à eliminação do curso.');
        $this->assertSame(
            authorization_manager::STATUS_REVOKED,
            $updated->status,
            'Uma autorização "active" órfã (curso eliminado) não deve ficar presa em "active" para sempre.'
        );
        $this->assertSame(0, (int) $updated->revokedby, 'revokedby=0 identifica uma revogação do sistema, não de uma pessoa.');
    }

    public function test_course_deletion_handler_is_idempotent(): void {
        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo'
        );
        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        // Já não há nada "active" — chamar o handler não deve fazer nada nem falhar.
        $count = authorization_manager::handle_course_deleted($this->course2->id);
        $this->assertSame(0, $count);
    }

    /** IDOR — capability num curso não se estende a outro curso. */
    public function test_capability_in_one_course_does_not_grant_access_in_another(): void {
        global $DB;

        $tenantcategory1 = \coursecat::get($this->tenant1->categoryid);
        $othercourse = $this->getDataGenerator()->create_course(['category' => $tenantcategory1->id]);
        $othercontext = \context_course::instance($othercourse->id);

        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $manager = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant1->id]);
        // Manager SÓ no outro curso (othercourse), nunca no course2.
        role_assign($managerroleid, $manager->id, $othercontext->id);

        $this->setUser($manager);

        $this->expectException(\required_capability_exception::class);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Tentativa IDOR', 0);
    }

    /** Secção 41 — role escalation com um roleid que não existe de todo. */
    public function test_grant_rejects_nonexistent_roleid(): void {
        $this->expectException(\moodle_exception::class);
        authorization_manager::grant($this->user1->id, $this->course2->id, 999999, 2, 'Roleid inventado');
    }

    /** Direct URL / AJAX endpoint abuse — chamada sem sessão autenticada. */
    public function test_anonymous_call_to_grant_access_is_rejected(): void {
        $this->setUser(0);

        $this->expectException(\Exception::class);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Sem sessão', 0);
    }

    /** Guarda de regressão: revogar/expirar nunca altera user.tenantid (secção 42/62). */
    public function test_revoke_and_expire_never_touch_tenant_membership(): void {
        global $DB;

        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');
        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);
        $this->assertEquals($this->tenant1->id, $DB->get_field('user', 'tenantid', ['id' => $this->user1->id]));

        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo', time() - 1
        );
        authorization_manager::expire_due();
        $this->assertEquals($this->tenant1->id, $DB->get_field('user', 'tenantid', ['id' => $this->user1->id]));
    }
}
