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
 * Fase 6 — Revoke.
 *
 * A maior parte da revogação já foi construída e testada nas Fases 2-4
 * (tests/extension_point_test.php, tests/enrolment_test.php) — o grant()
 * idempotente, a remoção de inscrição via instância dedicada, e a
 * preservação de inscrições independentes já estavam cobertos. Este
 * ficheiro cobre especificamente o que ficou por resolver nesta fase:
 * revogar uma autorização já logicamente expirada mas ainda não varrida
 * por expire_due() (secção 4, Regra 4 — a autorização expirada tem de
 * ser considerada inválida, incluindo para efeitos de revogação
 * explícita).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_crosstenant\service\authorization_manager;
use local_crosstenant\service\enrolment_manager;

class local_crosstenant_revoke_test extends \core_phpunit\testcase {

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

    /**
     * O caso que motivou a revisão desta fase: uma autorização com
     * timeexpires no passado, mas cujo status na BD continua "active"
     * porque expire_due() ainda não correu. revoke() tem de conseguir
     * revogá-la na mesma — não pode devolver "nada para revogar".
     */
    public function test_revoke_succeeds_on_a_logically_expired_but_unswept_row(): void {
        global $DB;

        $record = authorization_manager::grant(
            $this->user1->id, $this->course2->id, $this->studentroleid, 2,
            'Formação obrigatória', time() - 3600
        );

        // Confirma o cenário: continua "active" na BD, apesar de já
        // logicamente expirada.
        $raw = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);
        $this->assertSame(authorization_manager::STATUS_ACTIVE, $raw->status);
        $this->assertTrue($this->coursecontext2->is_user_access_prevented($this->user1->id));

        $revoked = authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertTrue($revoked, 'revoke() tem de ter efeito mesmo sobre uma linha "active" já logicamente expirada.');

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);
        $this->assertSame(
            authorization_manager::STATUS_REVOKED,
            $updated->status,
            'A linha não pode continuar "active" depois de um revoke explícito.'
        );
    }

    /** Idempotência: revogar duas vezes seguidas não falha nem faz nada da segunda vez. */
    public function test_revoke_twice_is_idempotent(): void {
        authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');

        $first = authorization_manager::revoke($this->user1->id, $this->course2->id, 2);
        $second = authorization_manager::revoke($this->user1->id, $this->course2->id, 2);

        $this->assertTrue($first);
        $this->assertFalse($second, 'Nada para revogar da segunda vez — não é um erro, é idempotência.');
    }

    /** Revogar sem nunca ter existido autorização também não é um erro. */
    public function test_revoke_with_nothing_to_revoke_returns_false_without_error(): void {
        $result = authorization_manager::revoke($this->user1->id, $this->course2->id, 2);
        $this->assertFalse($result);
    }

    /** Registo de auditoria (timerevoked/revokedby) fica preenchido corretamente. */
    public function test_revoke_records_who_and_when(): void {
        global $DB;

        $before = time();
        $record = authorization_manager::grant($this->user1->id, $this->course2->id, $this->studentroleid, 2, 'Motivo');
        authorization_manager::revoke($this->user1->id, $this->course2->id, 99);
        $after = time();

        $updated = $DB->get_record('local_crosstenant_access', ['id' => $record->id]);
        $this->assertSame(99, (int) $updated->revokedby);
        $this->assertGreaterThanOrEqual($before, (int) $updated->timerevoked);
        $this->assertLessThanOrEqual($after, (int) $updated->timerevoked);
    }
}
