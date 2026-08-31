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
 * Fase 9 — privacy provider (secção 29).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_crosstenant\privacy\provider;
use local_crosstenant\service\authorization_manager;

class local_crosstenant_privacy_test extends \core_privacy\tests\provider_testcase {

    /** @var \stdClass */
    protected $tenant1;
    /** @var \stdClass */
    protected $tenant2;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $granter;
    /** @var \stdClass */
    protected $course2;
    /** @var \context_course */
    protected $coursecontext2;
    /** @var int */
    protected $studentroleid;
    /** @var \stdClass registo criado no setUp */
    protected $record;

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
        $this->granter = $this->getDataGenerator()->create_user(['tenantid' => $this->tenant2->id]);

        $tenantcategory2 = \coursecat::get($this->tenant2->categoryid);
        $this->course2 = $this->getDataGenerator()->create_course(['category' => $tenantcategory2->id]);
        $this->coursecontext2 = \context_course::instance($this->course2->id);

        global $DB;
        $this->studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $this->record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, $this->granter->id, 'Formação obrigatória'
        );
    }

    public function test_get_contexts_for_userid_finds_the_course_for_the_authorized_user(): void {
        $contextlist = provider::get_contexts_for_userid($this->user1->id);
        $this->assertContains($this->coursecontext2->id, $contextlist->get_contextids());
    }

    public function test_get_contexts_for_userid_finds_the_course_for_the_granter_too(): void {
        $contextlist = provider::get_contexts_for_userid($this->granter->id);
        $this->assertContains($this->coursecontext2->id, $contextlist->get_contextids());
    }

    public function test_get_contexts_for_userid_empty_for_unrelated_user(): void {
        $unrelated = $this->getDataGenerator()->create_user();
        $contextlist = provider::get_contexts_for_userid($unrelated->id);
        $this->assertNotContains($this->coursecontext2->id, $contextlist->get_contextids());
    }

    public function test_get_users_in_context_finds_both_authorized_user_and_granter(): void {
        $userlist = new userlist($this->coursecontext2, 'local_crosstenant');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertContains($this->user1->id, $userids);
        $this->assertContains($this->granter->id, $userids);
    }

    public function test_export_user_data_includes_the_authorization(): void {
        $approvedlist = new approved_contextlist(
            $this->user1, 'local_crosstenant', [$this->coursecontext2->id]
        );
        provider::export_user_data($approvedlist);

        $exportdata = writer::with_context($this->coursecontext2)->get_data([
            get_string('pluginname', 'local_crosstenant'), (string) $this->record->id,
        ]);

        $this->assertNotEmpty($exportdata);
        $this->assertEquals($this->user1->id, $exportdata->userid);
        $this->assertEquals('Formação obrigatória', $exportdata->reason);
    }

    public function test_delete_data_for_user_anonymises_but_keeps_the_row(): void {
        global $DB;

        $approvedlist = new approved_contextlist(
            $this->user1, 'local_crosstenant', [$this->coursecontext2->id]
        );
        provider::delete_data_for_user($approvedlist);

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $this->record->id]);
        $this->assertNotFalse($updated, 'A linha de auditoria tem de continuar a existir.');
        $this->assertEquals(0, $updated->userid, 'O userid do titular deve ficar anonimizado.');
        $this->assertEquals(
            $this->granter->id, $updated->createdby,
            'createdby não deve ser tocado por um pedido do TITULAR — só o do próprio criador.'
        );
    }

    public function test_delete_data_for_user_on_granter_anonymises_createdby_only(): void {
        global $DB;

        $approvedlist = new approved_contextlist(
            $this->granter, 'local_crosstenant', [$this->coursecontext2->id]
        );
        provider::delete_data_for_user($approvedlist);

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $this->record->id]);
        $this->assertEquals(0, $updated->createdby);
        $this->assertEquals(
            $this->user1->id, $updated->userid,
            'userid do titular autorizado não deve ser tocado por um pedido do GRANTER.'
        );
    }

    public function test_delete_data_for_all_users_in_context_removes_the_row(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->coursecontext2);

        $this->assertFalse($DB->record_exists('local_crosstenant_access', ['id' => $this->record->id]));
    }

    public function test_delete_data_for_users_bulk(): void {
        global $DB;

        $approveduserlist = new approved_userlist($this->coursecontext2, 'local_crosstenant', [$this->user1->id]);
        provider::delete_data_for_users($approveduserlist);

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $this->record->id]);
        $this->assertEquals(0, $updated->userid);
    }
}
