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

namespace local_crosstenant\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Cross-tenant course access — enrolment service.
 *
 * Traduz uma autorização em inscrição real, usando exclusivamente a API
 * oficial de inscrição (secção 12: "Utilizar as APIs oficiais... Não
 * inserir diretamente registos na tabela de enrolment através de SQL.").
 *
 * DECISÃO DE DESENHO — instância de inscrição dedicada por curso, via
 * enrol_self, não enrol_manual:
 *
 * A ideia original (secção 14) era criar, por curso, UMA instância de
 * inscrição própria e nomeada (INSTANCE_NAME), para que remover a
 * inscrição desta instância dedicada nunca pudesse, por construção,
 * tocar numa inscrição criada por outro método — sem precisar de
 * adivinhar nem comparar metadados, só pela separação estrutural que o
 * Totara já mantém entre instâncias de enrol.
 *
 * Confirmado em produção-espelhada (QA, 2026-08-19) que `enrol_manual`
 * só permite UMA instância por curso — `can_add_instance()` devolve
 * false se já existir uma, e este site já tem sempre uma instância
 * manual "normal" por curso. `enrol_self`, ao contrário, permite
 * múltiplas instâncias por curso (confirmado com o mesmo diagnóstico).
 * Por isso este serviço usa `self`, nunca `manual`, para a instância
 * dedicada — mantendo a mesma garantia estrutural de separação.
 *
 * A instância criada nunca é pensada para o utilizador usar por si
 * mesmo: leva uma chave de inscrição (password) aleatória, nunca
 * divulgada a ninguém, para que mesmo que apareça listada na página de
 * inscrição do curso, ninguém consiga completá-la manualmente. O
 * plugin só a usa programaticamente, via enrol_user()/unenrol_user().
 *
 * has_independent_enrolment() é puramente informativo (não decide SE se
 * remove a inscrição — isso é sempre seguro dado o desenho acima) — é
 * para auditoria/eventos saberem se o utilizador ainda ficou com algum
 * acesso ao curso por outra via depois da revogação.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment_manager {

    /**
     * Nome fixo e reconhecível da instância dedicada a este plugin. O
     * aviso em português é propositado — qualquer administrador que veja
     * isto na lista de métodos de inscrição do curso percebe
     * imediatamente que não deve mexer manualmente.
     */
    const INSTANCE_NAME = 'EDUdigital: Cross-Tenant Access (não editar manualmente)';

    /** @var string tipo de plugin de inscrição usado — ver nota de desenho na classe */
    const ENROL_TYPE = 'self';

    /**
     * Garante que o utilizador está inscrito no curso, com o papel dado,
     * através da instância dedicada deste plugin. Idempotente: chamar
     * duas vezes com os mesmos argumentos não duplica nem falha —
     * enrol_user() da API oficial atualiza a linha existente.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $roleid
     * @throws \moodle_exception se o curso não existir ou a instância
     *         dedicada não puder ser criada
     */
    public static function ensure_enrolment(int $userid, int $courseid, int $roleid): void {
        $instance = self::get_or_create_instance($courseid);
        $plugin = enrol_get_plugin(self::ENROL_TYPE);
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
    }

    /**
     * Remove apenas a inscrição criada por este plugin (a da instância
     * dedicada). Nunca toca em inscrições de outras instâncias — ver
     * nota de desenho na classe. Idempotente: se não houver nada para
     * remover, não faz nada nem falha.
     *
     * @param int $userid
     * @param int $courseid
     */
    public static function remove_plugin_enrolment(int $userid, int $courseid): void {
        $instance = self::find_instance($courseid);
        if (!$instance) {
            return;
        }

        $plugin = enrol_get_plugin(self::ENROL_TYPE);
        $plugin->unenrol_user($instance, $userid);
    }

    /**
     * Puramente informativo (ver nota de desenho na classe) — indica se
     * o utilizador tem, além da inscrição deste plugin, alguma outra
     * inscrição ativa no mesmo curso por outra via.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function has_independent_enrolment(int $userid, int $courseid): bool {
        global $DB;

        $ourinstance = self::find_instance($courseid);
        $ourinstanceid = $ourinstance ? $ourinstance->id : 0;

        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND e.courseid = :courseid
                   AND e.id <> :ourinstanceid
                   AND ue.status = :active";

        return $DB->record_exists_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'ourinstanceid' => $ourinstanceid,
            'active' => ENROL_USER_ACTIVE,
        ]);
    }

    /**
     * Devolve a instância dedicada deste plugin para o curso, se existir.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function find_instance(int $courseid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => self::ENROL_TYPE,
            'name' => self::INSTANCE_NAME,
        ]);

        return $record ?: null;
    }

    /**
     * Devolve a instância dedicada, criando-a se ainda não existir para
     * este curso. Reativa-a se por acaso alguém a tiver desativado
     * manualmente na UI de métodos de inscrição.
     *
     * @param int $courseid
     * @return \stdClass
     * @throws \moodle_exception se can_add_instance() recusar ou
     *         add_instance() falhar — nunca falha silenciosamente.
     */
    protected static function get_or_create_instance(int $courseid): \stdClass {
        global $DB;

        $existing = self::find_instance($courseid);
        $plugin = enrol_get_plugin(self::ENROL_TYPE);

        if ($existing) {
            if ((int) $existing->status !== ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($existing, ENROL_INSTANCE_ENABLED);
                $existing->status = ENROL_INSTANCE_ENABLED;
            }
            return $existing;
        }

        if (!$plugin->can_add_instance($courseid)) {
            throw new \moodle_exception(
                'crosstenant_cannot_add_enrol_instance', 'local_crosstenant', '',
                null, "courseid={$courseid}, enrol=" . self::ENROL_TYPE
            );
        }

        $course = get_course($courseid);
        $instanceid = $plugin->add_instance($course, [
            'name' => self::INSTANCE_NAME,
            'status' => ENROL_INSTANCE_ENABLED,
            // Chave de inscrição aleatória, nunca divulgada — garante
            // que ninguém consegue completar esta inscrição pela página
            // normal do curso, mesmo que a veja listada. Só usado
            // programaticamente via enrol_user()/unenrol_user().
            'password' => random_string(32),
        ]);

        if (!$instanceid) {
            throw new \moodle_exception(
                'crosstenant_enrol_instance_creation_failed', 'local_crosstenant', '',
                null, "courseid={$courseid}, enrol=" . self::ENROL_TYPE
            );
        }

        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
}