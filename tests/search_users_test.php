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
 * Fase 8 — pesquisa de utilizadores candidatos (secção 16/32).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\external\search_users;

class local_crosstenant_search_users_test extends \core_phpunit\testcase {

    /** @var \stdClass */
    protected $tenant1;
    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $course2;
    /** @var \stdClass utilizador com capability para pesquisar/conceder */
    protected $manager;
    /** @var \stdClass utilizador sem capability nenhuma */
    protected $plainuser;
    /** @var \stdClass candidato de outro tenant — deve aparecer */
    protected $othertenantcandidate;
    /** @var \stdClass utilizador do PRÓPRIO tenant do curso — nunca deve aparecer */
    protected $sametenantcandidate;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var \totara_tenant\testing\generator $tenantgenerator */
        $tenantgenerator = \totara_tenant\testing\generator::instance();
        $tenantgenerator->enable_tenants();
        $this->setAdminUser();

        $this->tenant1 = $tenantgenerator->create_tenant();
        $this->tenant2 = $tenantgenerator->create_tenant();

        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $this->course2 = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $coursecontext2 = \context_course::instance($this->course2->id);

        global $DB;
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $this->manager = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant2->id]);
        role_assign($managerroleid, $this->manager->id, $coursecontext2->id);

        $this->plainuser = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant2->id]);

        $this->othertenantcandidate = $this->getDataGenerator()->create_user([
            'tenantid' => $this->tenant1->id,
            'firstname' => 'Zulmira',
            'lastname' => 'CrosstenantCandidata',
        ]);

        $this->sametenantcandidate = $this->getDataGenerator()->create_user([
            'tenantid' => $this->tenant2->id,
            'firstname' => 'Zulmira',
            'lastname' => 'MesmoTenant',
        ]);
    }

    public function test_search_requires_capability(): void {
        $this->setUser($this->plainuser);

        $this->expectException(\required_capability_exception::class);
        search_users::execute($this->course2->id, 'Zulmira');
    }

    public function test_search_finds_other_tenant_candidate_but_not_same_tenant_user(): void {
        $this->setUser($this->manager);

        $result = search_users::execute($this->course2->id, 'Zulmira');
        $ids = array_column($result['users'], 'id');

        $this->assertContains(
            $this->othertenantcandidate->id,
            $ids,
            'Um candidato de outro tenant, correspondente à pesquisa, tem de aparecer.'
        );
        $this->assertNotContains(
            $this->sametenantcandidate->id,
            $ids,
            'Um utilizador do MESMO tenant do curso nunca deve aparecer (secção 17/32) — ' .
            'deve usar o mecanismo normal de inscrição.'
        );
    }

    public function test_search_with_short_query_returns_nothing(): void {
        $this->setUser($this->manager);

        $result = search_users::execute($this->course2->id, 'Z');
        $this->assertSame([], $result['users']);
    }

    public function test_search_on_course_without_tenant_returns_nothing(): void {
        $this->setAdminUser();
        $course0 = $this->getDataGenerator()->create_course();

        // Não há capability atribuída num curso sem tenant, mas o admin
        // já tem tudo — o ponto aqui é confirmar que a AUSÊNCIA de
        // tenant no curso, por si só, devolve lista vazia sem erro.
        $result = search_users::execute($course0->id, 'Zulmira');
        $this->assertSame([], $result['users']);
    }
}
