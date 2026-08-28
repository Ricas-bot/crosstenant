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
 * Fase 4 — testes de aceitação da integração com inscrição real.
 *
 * Complementa tests/extension_point_test.php (Fase 2, que só testa a
 * decisão de acesso) provando que grant()/revoke() agora produzem uma
 * inscrição real via API oficial (secção 12/27/28), sem nunca destruir
 * uma inscrição independente que não pertença ao plugin (secção 13/23).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;
use local_crosstenant\service\enrolment_manager;

class local_crosstenant_enrolment_test extends \core_phpunit\testcase {

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

    public function test_grant_creates_real_enrolment(): void {
        $this->assertFalse(
            is_enrolled($this->coursecontext2, $this->user1->id),
            'Antes do grant, o utilizador não deve estar inscrito.'
        );

        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertTrue(
            is_enrolled($this->coursecontext2, $this->user1->id),
            'Depois do grant, tem de existir uma inscrição real — não só uma linha na tabela do plugin.'
        );
    }

    public function test_enrolment_uses_a_dedicated_instance(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $instance = enrolment_manager::find_instance($this->course2->id);
        $this->assertNotNull($instance);
        $this->assertSame(enrolment_manager::ENROL_TYPE, $instance->enrol);
        $this->assertSame(enrolment_manager::INSTANCE_NAME, $instance->name);

        global $DB;
        $exists = $DB->record_exists('user_enrolments', [
            'userid' => $this->user1->id,
            'enrolid' => $instance->id,
        ]);
        $this->assertTrue($exists, 'A inscrição tem de estar associada à instância dedicada do plugin.');
    }

    public function test_revoke_removes_enrolment(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );
        $this->assertTrue(is_enrolled($this->coursecontext2, $this->user1->id));

        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertFalse(
            is_enrolled($this->coursecontext2, $this->user1->id),
            'Depois do revoke, a inscrição criada pelo plugin tem de desaparecer.'
        );
    }

    /** Secção 13/23 — o teste mais importante desta fase. */
    public function test_revoke_never_touches_an_independent_enrolment(): void {
        // Simula uma inscrição "normal", de outra origem, para o mesmo
        // utilizador no mesmo curso — usando o gerador de testes, que
        // escreve diretamente via API oficial de inscrição, à parte da
        // instância dedicada do plugin.
        $this->getDataGenerator()->enrol_user($this->user1->id, $this->course2->id, 'student');

        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $this->assertTrue(
            enrolment_manager::has_independent_enrolment($this->user1->id, $this->course2->id),
            'Devia detetar a inscrição independente criada pelo gerador de testes.'
        );

        authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertTrue(
            is_enrolled($this->coursecontext2, $this->user1->id),
            'A inscrição independente tem de sobreviver à revogação do acesso cross-tenant.'
        );

        $instance = enrolment_manager::find_instance($this->course2->id);
        global $DB;
        $plugintenrolmentgone = !$DB->record_exists('user_enrolments', [
            'userid' => $this->user1->id,
            'enrolid' => $instance->id,
        ]);
        $this->assertTrue($plugintenrolmentgone, 'A inscrição da instância do plugin tem de ter desaparecido.');
    }

    public function test_ensure_enrolment_is_idempotent(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo A'
        );
        // Chamada direta e repetida ao serviço de baixo nível (não passa
        // por grant(), que já é idempotente por outra via) para confirmar
        // que a própria API de inscrição não gera erro nem duplicado.
        enrolment_manager::ensure_enrolment($this->user1->id, $this->course2->id, $this->studentroleid);

        global $DB;
        $instance = enrolment_manager::find_instance($this->course2->id);
        $count = $DB->count_records('user_enrolments', ['userid' => $this->user1->id, 'enrolid' => $instance->id]);
        $this->assertSame(1, $count);
    }

    /** Secção 27 — a expiração também tem de limpar a inscrição, não só o estado. */
    public function test_expire_due_also_removes_enrolment(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória', time() - 1
        );

        // O grant() com timeexpires no passado já bloqueia a decisão de
        // acesso (Fase 2/3), mas a inscrição em si só é removida quando
        // a scheduled task (aqui, chamada diretamente) processa a
        // expiração.
        $this->assertTrue(is_enrolled($this->coursecontext2, $this->user1->id));

        authorization_manager::expire_due();

        $this->assertFalse(
            is_enrolled($this->coursecontext2, $this->user1->id),
            'expire_due() tem de remover a inscrição criada pelo plugin, tal como revoke().'
        );
    }

    public function test_same_tenant_course_is_never_touched_by_enrolment_manager(): void {
        // Guarda de regressão: confirma que nenhuma instância dedicada é
        // criada num curso same-tenant só porque o serviço existe no
        // sistema — o plugin não deve interferir onde não foi chamado.
        $tenantcategory1 = \coursecat::get($this->tenant1->categoryid);
        $course1 = $this->getDataGenerator()->create_course(['category' => $tenantcategory1->id]);

        $this->assertNull(enrolment_manager::find_instance($course1->id));
    }
}