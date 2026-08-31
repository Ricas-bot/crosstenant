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
 * Fase 9 — eventos (secção 25/35).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;
use local_crosstenant\event\access_granted;
use local_crosstenant\event\access_revoked;
use local_crosstenant\event\access_expired;

class local_crosstenant_event_test extends \core_phpunit\testcase {

    /** @var \stdClass */
    protected $tenant1;
    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $course2;
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

        global $DB;
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }

    public function test_grant_triggers_access_granted_event(): void {
        $sink = $this->redirectEvents();

        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Formação obrigatória'
        );

        $events = $sink->get_events();
        $sink->close();

        $granted = array_values(array_filter($events, function($e) {
            return $e instanceof access_granted;
        }));

        $this->assertCount(1, $granted);
        $event = $granted[0];

        $this->assertEquals($record->id, $event->objectid);
        $this->assertEquals($this->user1->id, $event->relateduserid);
        $this->assertEquals(2, $event->userid);
        $this->assertEquals($this->tenant1->id, $event->other['source_tenantid']);
        $this->assertEquals($this->tenant2->id, $event->other['target_tenantid']);
        $this->assertEquals(\context_course::instance($this->course2->id)->id, $event->contextid);
    }

    public function test_revoke_triggers_access_revoked_event_with_revoker_as_userid(): void {
        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');

        $sink = $this->redirectEvents();
        authorization_manager::revoke($this->user1->id, $this->course2->id, 99);
        $events = $sink->get_events();
        $sink->close();

        $revoked = array_values(array_filter($events, function($e) {
            return $e instanceof access_revoked;
        }));

        $this->assertCount(1, $revoked);
        $this->assertEquals(99, $revoked[0]->userid, 'userid do evento tem de ser quem revogou, não quem foi revogado.');
        $this->assertEquals($this->user1->id, $revoked[0]->relateduserid);
    }

    public function test_expire_due_triggers_access_expired_event_with_system_userid(): void {
        authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo', time() - 1
        );

        $sink = $this->redirectEvents();
        authorization_manager::expire_due();
        $events = $sink->get_events();
        $sink->close();

        $expired = array_values(array_filter($events, function($e) {
            return $e instanceof access_expired;
        }));

        $this->assertCount(1, $expired);
        $this->assertEquals(0, $expired[0]->userid, 'Expiração é ação do sistema — userid=0, sem ator humano.');
        $this->assertEquals($this->user1->id, $expired[0]->relateduserid);
    }

    /** Secção 36 — curso eliminado não deve gerar erro ao disparar o evento. */
    public function test_no_error_when_course_context_is_missing(): void {
        global $DB;

        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo'
        );

        // Simula um curso cujo contexto desapareceu (cenário de limpeza
        // incompleta) sem depender de apagar o curso de verdade via API,
        // o que teria efeitos colaterais fora do âmbito deste teste.
        $DB->set_field('local_crosstenant_access', 'courseid', 999999, ['id' => $record->id]);
        $stale = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);

        $sink = $this->redirectEvents();
        // Chamado indiretamente via reflexão, para exercitar exatamente o
        // caminho protegido por IGNORE_MISSING sem expor o método publicamente.
        $reflection = new \ReflectionMethod(authorization_manager::class, 'trigger_granted_event');
        $reflection->setAccessible(true);
        $reflection->invoke(null, $stale);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(0, $events, 'Sem contexto válido, não deve disparar evento nenhum — e não deve rebentar.');
    }
}
