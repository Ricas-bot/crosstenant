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
 * Fase 3 — testes de aceitação da fronteira de segurança (capability).
 *
 * Complementa tests/extension_point_test.php (Fase 2, que testa
 * authorization_manager diretamente, sem capability) provando que a
 * camada classes/external/ é onde a segurança de facto vive — secção
 * 33/34/41 do prompt de implementação: "Nunca considerar hidden button =
 * security".
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\external\grant_access;
use local_crosstenant\external\revoke_access;
use local_crosstenant\external\get_authorizations;

class local_crosstenant_external_test extends \core_phpunit\testcase {

    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $course2;
    /** @var \context_course */
    protected $coursecontext2;
    /** @var \stdClass utilizador do próprio tenant2, com papel manager no curso */
    protected $manager;
    /** @var \stdClass utilizador do próprio tenant2, sem papel de gestão */
    protected $plainuser;
    /** @var int */
    protected $studentroleid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var \totara_tenant\testing\generator $tenantgenerator */
        $tenantgenerator = \totara_tenant\testing\generator::instance();
        $tenantgenerator->enable_tenants();
        $this->setAdminUser();

        $tenant1 = $tenantgenerator->create_tenant();
        $this->tenant2 = $tenantgenerator->create_tenant();

        $this->user1 = $this->getDataGenerator()->create_user(['tenantid' => $tenant1->id]);

        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $this->course2 = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $this->coursecontext2 = \context_course::instance($this->course2->id);

        global $DB;
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $this->manager = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant2->id]);
        role_assign($managerroleid, $this->manager->id, $this->coursecontext2->id);

        $this->plainuser = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant2->id]);
    }

    public function test_grant_requires_capability(): void {
        $this->setUser($this->plainuser);

        $this->expectException(\required_capability_exception::class);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Teste', 0);
    }

    public function test_grant_succeeds_with_capability_and_actually_allows_access(): void {
        $this->setUser($this->manager);

        $result = grant_access::execute(
            $this->user1->id, $this->course2->id, $this->studentroleid, 'Formação obrigatória', 0
        );

        $this->assertArrayHasKey('id', $result);
        $this->assertSame('active', $result['status']);
        $this->assertFalse(
            $this->coursecontext2->is_user_access_prevented($this->user1->id),
            'O grant via external não é só um registo em BD — tem de refletir-se na decisão real de acesso.'
        );
    }

    public function test_revoke_requires_capability(): void {
        $this->setUser($this->manager);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Teste', 0);

        $this->setUser($this->plainuser);
        $this->expectException(\required_capability_exception::class);
        revoke_access::execute($this->user1->id, $this->course2->id);
    }

    public function test_revoke_succeeds_with_capability(): void {
        $this->setUser($this->manager);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Teste', 0);

        $result = revoke_access::execute($this->user1->id, $this->course2->id);

        $this->assertTrue($result['revoked']);
        $this->assertTrue($this->coursecontext2->is_user_access_prevented($this->user1->id));
    }

    public function test_view_requires_capability(): void {
        $this->setUser($this->plainuser);

        $this->expectException(\required_capability_exception::class);
        get_authorizations::execute($this->course2->id);
    }

    public function test_view_succeeds_with_capability_and_lists_authorization(): void {
        $this->setUser($this->manager);
        grant_access::execute($this->user1->id, $this->course2->id, $this->studentroleid, 'Teste listagem', 0);

        $result = get_authorizations::execute($this->course2->id);

        $this->assertCount(1, $result['authorizations']);
        $this->assertSame($this->user1->id, $result['authorizations'][0]['userid']);
        $this->assertSame('active', $result['authorizations'][0]['status']);
    }

    /** Secção 9/21 — ter capability para conceder não basta para escolher um papel privilegiado. */
    public function test_grant_via_external_still_rejects_privileged_role(): void {
        global $DB;
        $this->setUser($this->manager);
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $this->expectException(\moodle_exception::class);
        grant_access::execute($this->user1->id, $this->course2->id, $managerroleid, 'Tentativa indevida', 0);
    }

    /** Secção 42 — guarda estrutural: os parâmetros aceites nunca incluem tenant ids. */
    public function test_grant_endpoint_never_accepts_tenant_ids_as_input(): void {
        $paramsdesc = grant_access::execute_parameters();
        $keys = array_keys($paramsdesc->keys);

        $this->assertNotContains('source_tenantid', $keys);
        $this->assertNotContains('target_tenantid', $keys);
        $this->assertNotContains('tenantid', $keys);
    }

    /** Secção 40 — parâmetros inválidos (courseid inexistente) nunca chegam ao serviço. */
    public function test_grant_rejects_nonexistent_course(): void {
        $this->setUser($this->manager);

        $this->expectException(\dml_missing_record_exception::class);
        grant_access::execute($this->user1->id, 999999, $this->studentroleid, 'Curso inexistente', 0);
    }
}
