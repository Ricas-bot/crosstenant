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

namespace local_crosstenant\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider para local_crosstenant (secção 29).
 *
 * Dados armazenados: userid (titular autorizado), source_tenantid,
 * target_tenantid, courseid, roleid, status, reason, timestamps,
 * createdby, timerevoked, revokedby. Três utilizadores diferentes podem
 * aparecer numa única linha: o autorizado (userid), quem concedeu
 * (createdby) e quem revogou (revokedby) — cada um pode pedir os seus
 * próprios dados independentemente dos outros.
 *
 * DECISÃO DE POLÍTICA (a confirmar com o cliente): um pedido de
 * eliminação anonimiza o campo de identificação da pessoa que pediu
 * (userid/createdby/revokedby, conforme aplicável), em vez de apagar a
 * linha inteira. Isto preserva o registo de auditoria em si — "alguém
 * foi autorizado a este curso, por este motivo, nesta data" — sem
 * manter a identidade da pessoa que exerceu o direito ao apagamento.
 * Retenção: sem prazo definido nesta V1 (a decidir pelo cliente,
 * conforme a política de retenção de registos de auditoria do
 * organismo).
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_crosstenant_access',
            [
                'userid' => 'privacy:metadata:local_crosstenant_access:userid',
                'source_tenantid' => 'privacy:metadata:local_crosstenant_access:source_tenantid',
                'target_tenantid' => 'privacy:metadata:local_crosstenant_access:target_tenantid',
                'courseid' => 'privacy:metadata:local_crosstenant_access:courseid',
                'roleid' => 'privacy:metadata:local_crosstenant_access:roleid',
                'status' => 'privacy:metadata:local_crosstenant_access:status',
                'reason' => 'privacy:metadata:local_crosstenant_access:reason',
                'timecreated' => 'privacy:metadata:local_crosstenant_access:timecreated',
                'timemodified' => 'privacy:metadata:local_crosstenant_access:timemodified',
                'timeexpires' => 'privacy:metadata:local_crosstenant_access:timeexpires',
                'createdby' => 'privacy:metadata:local_crosstenant_access:createdby',
                'timerevoked' => 'privacy:metadata:local_crosstenant_access:timerevoked',
                'revokedby' => 'privacy:metadata:local_crosstenant_access:revokedby',
            ],
            'privacy:metadata:local_crosstenant_access'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {local_crosstenant_access} lca
                  JOIN {context} ctx ON ctx.instanceid = lca.courseid AND ctx.contextlevel = :contextcourse
                 WHERE lca.userid = :userid1 OR lca.createdby = :userid2 OR lca.revokedby = :userid3";

        $contextlist->add_from_sql($sql, [
            'contextcourse' => CONTEXT_COURSE,
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
        ]);

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_course)) {
            return;
        }

        $params = ['courseid' => $context->instanceid];

        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {local_crosstenant_access} WHERE courseid = :courseid AND userid IS NOT NULL',
            $params
        );
        $userlist->add_from_sql(
            'createdby',
            'SELECT createdby FROM {local_crosstenant_access} WHERE courseid = :courseid AND createdby IS NOT NULL',
            $params
        );
        $userlist->add_from_sql(
            'revokedby',
            'SELECT revokedby FROM {local_crosstenant_access} WHERE courseid = :courseid AND revokedby IS NOT NULL',
            $params
        );
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_course)) {
                continue;
            }

            $records = $DB->get_records('local_crosstenant_access', ['courseid' => $context->instanceid]);

            foreach ($records as $record) {
                $involves = ((int) $record->userid === $userid)
                    || ((int) $record->createdby === $userid)
                    || ($record->revokedby !== null && (int) $record->revokedby === $userid);

                if (!$involves) {
                    continue;
                }

                $data = (object) [
                    'userid' => $record->userid,
                    'source_tenantid' => $record->source_tenantid,
                    'target_tenantid' => $record->target_tenantid,
                    'courseid' => $record->courseid,
                    'roleid' => $record->roleid,
                    'status' => $record->status,
                    'reason' => $record->reason,
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $record->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime((int) $record->timemodified),
                    'timeexpires' => $record->timeexpires
                        ? \core_privacy\local\request\transform::datetime((int) $record->timeexpires)
                        : null,
                    'createdby' => $record->createdby,
                    'timerevoked' => $record->timerevoked
                        ? \core_privacy\local\request\transform::datetime((int) $record->timerevoked)
                        : null,
                    'revokedby' => $record->revokedby,
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_crosstenant'), (string) $record->id],
                    $data
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!($context instanceof \context_course)) {
            return;
        }

        $DB->delete_records('local_crosstenant_access', ['courseid' => $context->instanceid]);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_course)) {
                continue;
            }
            self::anonymise_user_in_course($userid, (int) $context->instanceid);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_course)) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::anonymise_user_in_course((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Anonimiza este utilizador em qualquer papel (userid, createdby,
     * revokedby) que ocupe nas linhas deste curso — ver nota de política
     * no topo da classe. Nunca apaga a linha inteira.
     */
    protected static function anonymise_user_in_course(int $userid, int $courseid): void {
        global $DB;

        $DB->set_field('local_crosstenant_access', 'userid', 0, ['courseid' => $courseid, 'userid' => $userid]);
        $DB->set_field('local_crosstenant_access', 'createdby', 0, ['courseid' => $courseid, 'createdby' => $userid]);
        $DB->set_field(
            'local_crosstenant_access', 'revokedby', null,
            ['courseid' => $courseid, 'revokedby' => $userid]
        );
    }
}
