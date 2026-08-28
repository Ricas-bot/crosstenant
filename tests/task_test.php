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
 * Fase 7 — scheduled task.
 *
 * A lógica de negócio (expire_due()) já estava testada desde a Fase 2/4
 * — isto prova só o encaixe: que a task está registada e que executá-la
 * produz o mesmo efeito que chamar o serviço diretamente.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;
use local_crosstenant\task\process_expirations;

class local_crosstenant_task_test extends \core_phpunit\testcase {

    public function test_task_is_registered_with_the_scheduler(): void {
        $task = \core\task\manager::get_scheduled_task(process_expirations::class);
        $this->assertInstanceOf(process_expirations::class, $task);
    }

    public function test_task_has_a_name(): void {
        $task = new process_expirations();
        $this->assertNotEmpty($task->get_name());
    }

    public function test_task_execution_expires_due_authorizations_and_removes_enrolment(): void {
        $this->resetAfterTest();

        /** @var \totara_tenant\testing\generator $tenantgenerator */
        $tenantgenerator = \totara_tenant\testing\generator::instance();
        $tenantgenerator->enable_tenants();
        $this->setAdminUser();

        $tenant1 = $tenantgenerator->create_tenant();
        $tenant2 = $tenantgenerator->create_tenant();
        $user1 = $this->getDataGenerator()->create_user(['tenantid' => $tenant1->id]);

        $tenantcategory2 = \coursecat::get($tenant2->categoryid);
        $course2 = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $coursecontext2 = \context_course::instance($course2->id);

        global $DB;
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        authorization_manager::grant($user1->id, $course2->id, $studentroleid, 2, 'Motivo', time() - 1);
        $this->assertTrue(is_enrolled($coursecontext2, $user1->id));

        $task = new process_expirations();
        $task->execute();

        $this->assertFalse(
            is_enrolled($coursecontext2, $user1->id),
            'Executar a task tem de produzir o mesmo efeito que chamar expire_due() diretamente.'
        );
    }
}
