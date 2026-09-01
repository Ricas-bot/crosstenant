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
 * Cross-tenant course access — authorization service.
 *
 * Owns local_crosstenant_access exclusively. Nothing else in this plugin,
 * and nothing in Totara core, should write to this table directly.
 *
 * WHO / WHAT / WHEN / WHY (secção 67 do prompt de implementação) vivem
 * inteiramente aqui. O core só sabe fazer uma pergunta booleana — ver
 * lib.php e CORE_PATCH.md.
 *
 * Esta classe não faz nenhuma verificação de capability — isso é
 * responsabilidade da camada que a chama (UI/AJAX/CLI), a implementar a
 * partir da Fase 3/8, conforme a secção 33/34 do prompt de implementação.
 * A camada de serviço aqui é deliberadamente "burra" quanto a quem pode
 * chamá-la; só valida a integridade dos próprios dados.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_manager {

    const STATUS_ACTIVE = 'active';
    const STATUS_REVOKED = 'revoked';
    const STATUS_EXPIRED = 'expired';

    /** @var string[] shortnames dos papéis permitidos na V1 (secção 21) */
    const ALLOWED_ROLE_SHORTNAMES = ['student', 'editingteacher'];

    /**
     * Concede acesso cross-tenant. Idempotente: se já existir uma
     * autorização ativa e válida para este userid+courseid, devolve-a sem
     * criar duplicado (secção 46 — "grant() grant() não deve resultar em
     * 2 authorizations").
     *
     * source_tenantid e target_tenantid NUNCA vêm do chamador — são
     * sempre derivados aqui, do utilizador e do curso reais (secção 17).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $roleid tem de estar em ALLOWED_ROLE_SHORTNAMES (secção 21)
     * @param int $createdby ator que concede
     * @param string|null $reason
     * @param int|null $timeexpires unix timestamp; null = sem expiração
     * @return \stdClass o registo de autorização (existente ou recém-criado)
     * @throws \moodle_exception em input inválido ou papel não permitido
     */
    public static function grant(
        int $userid,
        int $courseid,
        int $roleid,
        int $createdby,
        ?string $reason = null,
        ?int $timeexpires = null
    ): \stdClass {
        global $DB;

        if (!self::is_role_allowed($roleid)) {
            throw new \moodle_exception('crosstenant_role_not_allowed', 'local_crosstenant');
        }

        // Fontes de verdade — nunca vindas do chamador (secção 17/42).
        $sourcetenantid = self::get_user_tenantid($userid);
        $targettenantid = self::get_course_tenantid($courseid);

        if (!$sourcetenantid) {
            throw new \moodle_exception('crosstenant_user_has_no_tenant', 'local_crosstenant');
        }
        if (!$targettenantid) {
            throw new \moodle_exception('crosstenant_course_has_no_tenant', 'local_crosstenant');
        }
        if ($sourcetenantid === $targettenantid) {
            throw new \moodle_exception('crosstenant_same_tenant', 'local_crosstenant');
        }

        $transaction = $DB->start_delegated_transaction();

        $existing = self::get_active_authorization($userid, $courseid);
        if ($existing) {
            $transaction->allow_commit();
            return $existing;
        }

        $now = time();
        $record = new \stdClass();
        $record->userid = $userid;
        $record->source_tenantid = $sourcetenantid;
        $record->target_tenantid = $targettenantid;
        $record->courseid = $courseid;
        $record->roleid = $roleid;
        $record->status = self::STATUS_ACTIVE;
        $record->reason = $reason;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->timeexpires = $timeexpires;
        $record->createdby = $createdby;
        $record->timerevoked = null;
        $record->revokedby = null;

        $record->id = (int) $DB->insert_record('local_crosstenant_access', $record);

        // Secção 45: mesma transação da autorização. Se enrolment_manager
        // lançar exceção, nada foi ainda cometido (allow_commit() abaixo
        // não é alcançado) — o Totara reverte automaticamente a
        // transação delegada quando a exceção propaga sem commit
        // explícito. Nunca fica "authorization = active, enrolment =
        // missing".
        enrolment_manager::ensure_enrolment($userid, $courseid, $roleid);

        $transaction->allow_commit();

        // Evento de auditoria: Fase 10. Placeholder deliberado.
        self::trigger_granted_event($record);

        return $record;
    }

    /**
     * Revoga a(s) autorização(ões) ativa(s) para este userid+courseid.
     * Idempotente: revogar algo já revogado/inexistente não faz nada
     * (secção 46).
     *
     * Nota importante (Fase 6): usa diretamente status=active na BD, não
     * get_active_authorization() — que também filtra por timeexpires no
     * passado. Uma intenção explícita de revogar tem de ter sempre
     * efeito sobre qualquer linha "active" que exista, mesmo que já
     * esteja logicamente expirada e ainda não tenha sido varrida por
     * expire_due(); caso contrário ficaria uma linha "active" esquecida
     * na tabela, mesmo depois de um admin ter clicado "revoke".
     *
     * @param int $userid
     * @param int $courseid
     * @param int $revokedby
     * @return bool true se pelo menos uma autorização foi de facto
     *              revogada, false se não havia nada ativo
     */
    public static function revoke(int $userid, int $courseid, int $revokedby): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $activerecords = $DB->get_records('local_crosstenant_access', [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => self::STATUS_ACTIVE,
        ]);

        if (!$activerecords) {
            $transaction->allow_commit();
            return false;
        }

        $now = time();
        foreach ($activerecords as $record) {
            $record->status = self::STATUS_REVOKED;
            $record->timerevoked = $now;
            $record->revokedby = $revokedby;
            $record->timemodified = $now;
            $DB->update_record('local_crosstenant_access', $record);
        }

        // A remoção da inscrição desta instância dedicada nunca toca em
        // inscrições de outras origens — ver nota de desenho em
        // enrolment_manager. Não é preciso um "if" aqui à volta disto;
        // é sempre seguro chamar.
        enrolment_manager::remove_plugin_enrolment($userid, $courseid);

        $transaction->allow_commit();

        foreach ($activerecords as $record) {
            self::trigger_revoked_event($record);
        }

        return true;
    }

    /**
     * Chamado pelo observer de \core\event\course_deleted (Fase 10,
     * secção 36 — "limpar referências inválidas... não impedir a
     * eliminação normal do curso"). Marca qualquer autorização "active"
     * deste curso como "revoked" — nunca apaga a linha (preserva o
     * histórico), e nunca chama enrolment_manager: o curso e as suas
     * instâncias de inscrição já foram removidos pela própria eliminação
     * do curso, e tentar mexer neles outra vez seria redundante ou
     * geraria erro. Não dispara evento de revogação: já não existe
     * contexto de curso válido para o associar (trigger_revoked_event já
     * se protege sozinho contra isso, mas evitamos a chamada por clareza).
     *
     * Idempotente: chamar duas vezes não faz nada da segunda vez.
     *
     * @param int $courseid
     * @return int número de autorizações marcadas como revoked
     */
    public static function handle_course_deleted(int $courseid): int {
        global $DB;

        $now = time();
        $activerecords = $DB->get_records('local_crosstenant_access', [
            'courseid' => $courseid,
            'status' => self::STATUS_ACTIVE,
        ]);

        foreach ($activerecords as $record) {
            $record->status = self::STATUS_REVOKED;
            $record->timerevoked = $now;
            $record->revokedby = 0; // Sistema — curso eliminado, sem ator humano.
            $record->timemodified = $now;
            $DB->update_record('local_crosstenant_access', $record);
        }

        return count($activerecords);
    }

    /**
     * Marca como expired as autorizações ativas cujo timeexpires já
     * passou. Chamado pela scheduled task (Fase 7), mas exposto aqui para
     * poder ser exercido diretamente por testes/CLI sem depender do
     * agendador. Idempotente.
     *
     * @return int número de autorizações marcadas como expired
     */
    public static function expire_due(): int {
        global $DB;

        $now = time();
        $due = $DB->get_records_select(
            'local_crosstenant_access',
            'status = ? AND timeexpires IS NOT NULL AND timeexpires < ?',
            [self::STATUS_ACTIVE, $now]
        );

        $count = 0;
        foreach ($due as $record) {
            $transaction = $DB->start_delegated_transaction();
            $record->status = self::STATUS_EXPIRED;
            $record->timemodified = $now;
            $DB->update_record('local_crosstenant_access', $record);
            enrolment_manager::remove_plugin_enrolment((int) $record->userid, (int) $record->courseid);
            $transaction->allow_commit();
            self::trigger_expired_event($record);
            $count++;
        }

        return $count;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return bool true se existe autorização válida (ativa e não expirada) agora mesmo
     */
    public static function is_authorized(int $userid, int $courseid): bool {
        return self::get_active_authorization($userid, $courseid) !== null;
    }

    /**
     * Devolve a autorização ativa para este userid+courseid, ou null.
     *
     * Uma linha "active" cujo timeexpires já passou mas que a scheduled
     * task ainda não varreu é tratada como NÃO autorizada aqui e agora —
     * a validade é decidida em tempo real (secção 4 do prompt: "Regra 4 -
     * autorização expirada... a autorização deve ser considerada
     * inválida"); a limpeza de estado da task é só para arrumação e
     * relatórios, não é ela que decide o acesso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get_active_authorization(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('local_crosstenant_access', [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => self::STATUS_ACTIVE,
        ]);

        if (!$record) {
            return null;
        }

        if ($record->timeexpires !== null && $record->timeexpires < time()) {
            return null;
        }

        // Secção 35 (Fase 10): a autorização pode continuar "active" para
        // fins históricos, mas o acesso EFETIVO tem sempre de seguir o
        // estado real do utilizador. Nunca reativar/restaurar o
        // utilizador aqui — só recusar o acesso.
        $userok = $DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$userok) {
            return null;
        }

        // Normalizar tipos numéricos: registos vindos de get_record() podem
        // trazer strings consoante o driver de BD. Chamadores (incluindo o
        // grant() idempotente, que compara ->id entre um registo recém
        // inserido e um devolvido daqui) fazem comparações estritas.
        $record->id = (int) $record->id;
        $record->userid = (int) $record->userid;
        $record->courseid = (int) $record->courseid;
        $record->source_tenantid = (int) $record->source_tenantid;
        $record->target_tenantid = (int) $record->target_tenantid;
        $record->roleid = (int) $record->roleid;
        $record->timeexpires = $record->timeexpires !== null ? (int) $record->timeexpires : null;

        return $record;
    }

    /**
     * @param int $roleid
     * @return bool true apenas para papéis explicitamente permitidos na V1
     */
    public static function is_role_allowed(int $roleid): bool {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal(self::ALLOWED_ROLE_SHORTNAMES);
        $allowedids = $DB->get_fieldset_select('role', 'id', "shortname $insql", $params);
        // Normalizar para int: get_fieldset_select() pode devolver strings
        // consoante o driver de BD, e a comparação abaixo é estrita.
        $allowedids = array_map('intval', $allowedids);

        return in_array($roleid, $allowedids, true);
    }

    /**
     * Fonte de verdade para o tenant de origem — nunca aceitar do
     * chamador (secção 17/42).
     */
    protected static function get_user_tenantid(int $userid): ?int {
        global $DB;
        $tenantid = $DB->get_field('user', 'tenantid', ['id' => $userid, 'deleted' => 0]);
        // Normalizar para int: get_field() pode devolver string consoante
        // o driver de BD, e comparamos isto com === mais abaixo.
        return $tenantid ? (int) $tenantid : null;
    }

    /**
     * Fonte de verdade para o tenant de destino — nunca aceitar do
     * chamador (secção 17/42). Lê diretamente do contexto do curso, tal
     * como o próprio core faz em context::is_user_access_prevented().
     */
    protected static function get_course_tenantid(int $courseid): ?int {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        // Normalizar para int pelo mesmo motivo — context->tenantid nem
        // sempre chega já como int, e comparamos isto com === mais abaixo.
        return $context->tenantid ? (int) $context->tenantid : null;
    }

    /**
     * Dispara o evento de concessão (secção 25/35). userid = quem
     * concedeu (createdby); relateduserid = quem foi autorizado.
     */
    protected static function trigger_granted_event(\stdClass $record): void {
        $context = \context_course::instance($record->courseid, IGNORE_MISSING);
        if (!$context) {
            // Secção 36 — curso entretanto eliminado: não gerar erros.
            return;
        }

        $event = \local_crosstenant\event\access_granted::create([
            'objectid' => $record->id,
            'context' => $context,
            'relateduserid' => $record->userid,
            'userid' => $record->createdby,
            'other' => [
                'source_tenantid' => (int) $record->source_tenantid,
                'target_tenantid' => (int) $record->target_tenantid,
                'roleid' => (int) $record->roleid,
            ],
        ]);
        $event->add_record_snapshot('local_crosstenant_access', $record);
        $event->trigger();
    }

    /**
     * Dispara o evento de revogação. userid = quem revogou (revokedby);
     * relateduserid = quem perdeu o acesso.
     */
    protected static function trigger_revoked_event(\stdClass $record): void {
        $context = \context_course::instance($record->courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $event = \local_crosstenant\event\access_revoked::create([
            'objectid' => $record->id,
            'context' => $context,
            'relateduserid' => $record->userid,
            'userid' => $record->revokedby,
            'other' => [
                'source_tenantid' => (int) $record->source_tenantid,
                'target_tenantid' => (int) $record->target_tenantid,
            ],
        ]);
        $event->add_record_snapshot('local_crosstenant_access', $record);
        $event->trigger();
    }

    /**
     * Dispara o evento de expiração. userid = 0 (ação do sistema, via
     * scheduled task — não há um ator humano); relateduserid = quem
     * perdeu o acesso.
     */
    protected static function trigger_expired_event(\stdClass $record): void {
        $context = \context_course::instance($record->courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $event = \local_crosstenant\event\access_expired::create([
            'objectid' => $record->id,
            'context' => $context,
            'relateduserid' => $record->userid,
            'userid' => 0,
            'other' => [
                'source_tenantid' => (int) $record->source_tenantid,
                'target_tenantid' => (int) $record->target_tenantid,
            ],
        ]);
        $event->add_record_snapshot('local_crosstenant_access', $record);
        $event->trigger();
    }
}