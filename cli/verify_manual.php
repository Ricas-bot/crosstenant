<?php
/**
 * EDUdigital — Cross-Tenant Course Access (V1)
 * Verificação manual das Fases 2-4 — sem PHPUnit.
 *
 * ESCREVE dados reais: além da tabela própria do plugin
 * (local_crosstenant_access), a partir da Fase 4 grant()/revoke() também
 * criam/removem uma inscrição real (tabelas {enrol}/{user_enrolments})
 * através da instância de inscrição manual dedicada do plugin
 * (enrolment_manager::INSTANCE_NAME). Tudo o que este script cria é
 * removido no final, incluindo a própria instância de inscrição SE ela
 * tiver sido criada de raiz por este script (não remove uma instância
 * que já existisse antes, mesmo que fique vazia).
 *
 * ⚠️ RISCO A CONFIRMAR ANTES DE CORRER: uma inscrição real, mesmo que
 * breve, pode teoricamente despoletar notificação de boas-vindas ao
 * curso, consoante a configuração do site/curso. Confirma que o envio
 * de email está desativado/redirecionado neste ambiente de QA antes de
 * correres com --confirm — ou usa um curso/utilizador de teste dedicado
 * via --user/--course em vez da descoberta automática.
 *
 * O CENÁRIO DE INSCRIÇÃO INDEPENDENTE (secção 13/23 — revogar não deve
 * remover uma inscrição de outra origem) NÃO é coberto por este script,
 * de propósito — simulá-lo aqui obrigaria a inscrever de facto um
 * utilizador real através do mecanismo normal do curso. Esse cenário
 * está coberto em tests/enrolment_test.php (PHPUnit, base de dados
 * isolada).
 *
 * NUNCA correr isto em produção — só em staging/QA.
 *
 * USO:
 *   php local/crosstenant/cli/verify_manual.php --confirm
 *   php local/crosstenant/cli/verify_manual.php --confirm --user=X --course=Y
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_crosstenant\service\authorization_manager;
use local_crosstenant\service\enrolment_manager;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'confirm' => false,
        'user' => 0,
        'course' => 0,
    ],
    ['h' => 'help', 'y' => 'confirm']
);

if ($unrecognized) {
    fwrite(STDERR, "Argumentos não reconhecidos:\n  " . implode("\n  ", $unrecognized) . "\n");
    exit(1);
}

if ($options['help']) {
    echo <<<HELP
EDUdigital — Cross-Tenant Course Access (V1)
Verificação manual das Fases 2-4, sem PHPUnit. ESCREVE na tabela própria
do plugin E cria/remove uma inscrição real via instância dedicada.

⚠️  Confirma que o envio de email está desligado/redirecionado neste QA
    antes de correres com --confirm.

NUNCA correr em produção — só staging/QA.

Uso:
  php verify_manual.php --confirm
  php verify_manual.php --confirm --user=X --course=Y

Sem --confirm: modo dry-run, mostra o plano e sai sem escrever nada.

HELP;
    exit(0);
}

$checks = 0;
$fail = 0;

function check(string $label, bool $condition): bool {
    global $checks, $fail;
    $checks++;
    if (!$condition) {
        $fail++;
    }
    printf("[%s] %s\n", $condition ? 'PASS' : 'FAIL', $label);
    return $condition;
}

echo "=== EDUdigital — Cross-Tenant Course Access — verificação Fase 2 ===\n\n";

if (!class_exists('local_crosstenant\\service\\authorization_manager')) {
    fwrite(STDERR, "ERRO: local_crosstenant não parece estar instalado (classe não encontrada).\n");
    fwrite(STDERR, "Confirma que o plugin está em local/crosstenant/ e corre a atualização de plugins do site.\n");
    exit(1);
}

$callbacks = get_plugins_with_function('crosstenant_access_check', 'lib.php');
check('local_crosstenant regista o callback crosstenant_access_check', array_key_exists('local', $callbacks) && array_key_exists('crosstenant', $callbacks['local']));

$scheduledtask = \core\task\manager::get_scheduled_task('local_crosstenant\task\process_expirations');
check('Fase 7 — scheduled task process_expirations está registada', $scheduledtask instanceof \local_crosstenant\task\process_expirations);
if ($scheduledtask) {
    echo "  (para testar a execução real via o agendador oficial do Totara:\n";
    echo "   php admin/cli/scheduled_task.php --execute='\\local_crosstenant\\task\\process_expirations')\n";
}

$userid = (int) $options['user'];
$courseid = (int) $options['course'];

if (!$userid || !$courseid) {
    echo "\n--- Descoberta automática de um par cross-tenant real (leitura apenas) ---\n";
    $tenants = $DB->get_records('tenant', null, 'id ASC');

    $samecandidate = null;
    foreach ($tenants as $tenant) {
        $userids = $DB->get_fieldset_select('user', 'id', 'tenantid = ? AND deleted = 0', [$tenant->id]);
        if ($userids) {
            $samecandidate = ['tenant' => $tenant, 'userid' => reset($userids)];
            break;
        }
    }
    if (!$samecandidate) {
        fwrite(STDERR, "Não encontrei nenhum utilizador membro de um tenant neste ambiente.\n");
        fwrite(STDERR, "Indica manualmente: --user=X --course=Y\n");
        exit(1);
    }

    $crosscandidate = null;
    foreach ($tenants as $tenant) {
        if ((int) $tenant->id === (int) $samecandidate['tenant']->id) {
            continue;
        }
        $courseids = $DB->get_fieldset_select(
            'context', 'instanceid', 'contextlevel = ? AND tenantid = ?', [CONTEXT_COURSE, $tenant->id]
        );
        if ($courseids) {
            $crosscandidate = ['tenant' => $tenant, 'courseid' => reset($courseids)];
            break;
        }
    }
    if (!$crosscandidate) {
        fwrite(STDERR, "Não encontrei um segundo tenant com pelo menos um curso.\n");
        fwrite(STDERR, "Indica manualmente: --user=X --course=Y\n");
        exit(1);
    }

    $userid = (int) $samecandidate['userid'];
    $courseid = (int) $crosscandidate['courseid'];

    echo "Utilizador: {$userid}  (tenant '{$samecandidate['tenant']->name}')\n";
    echo "Curso:      {$courseid}  (tenant '{$crosscandidate['tenant']->name}')\n\n";
}

$context = context_course::instance($courseid, IGNORE_MISSING);
if (!$context) {
    fwrite(STDERR, "ERRO: contexto do curso {$courseid} não encontrado.\n");
    exit(1);
}

global $DB;
$baseline = $DB->count_records('local_crosstenant_access', ['userid' => $userid, 'courseid' => $courseid]);
echo "Linhas já existentes para este par userid+courseid (antes de começar): {$baseline}\n\n";

if (!$options['confirm']) {
    echo "--- MODO DRY-RUN (sem --confirm) ---\n";
    echo "Iria criar e depois apagar autorizações de teste, e uma inscrição\n";
    echo "real (via instância dedicada), para:\n";
    echo "  userid={$userid} courseid={$courseid}\n";
    echo "Nada foi escrito. Corre com --confirm para executar a verificação real.\n";
    exit(0);
}

echo "--- A EXECUTAR (escreve local_crosstenant_access + inscrição real) ---\n\n";

$studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$adminid = (int) get_admin()->id;

// Sem isto, este é um script CLI puro sem utilizador autenticado — $USER
// fica anónimo/convidado, e can_add_instance() (que verifica capability
// via has_capability() contra o utilizador atual) falha sempre, mesmo
// que o admin real tenha todas as permissões. diagnose_enrol.php já
// fazia isto corretamente; esqueci-me aqui.
\core\session\manager::set_user(get_admin());

$createdids = [];
$instanceexistedbefore = enrolment_manager::find_instance($courseid) !== null;

try {
    // 1. Antes de qualquer grant: deve estar bloqueado, e sem inscrição.
    check('Antes do grant: acesso bloqueado', $context->is_user_access_prevented($userid) === true);
    check('Antes do grant: sem inscrição', !is_enrolled($context, $userid));

    // 2. Teste C — grant válido -> ALLOW + inscrição real.
    $record = authorization_manager::grant(
        $userid, $courseid, $studentroleid, $adminid, '[TESTE AUTOMÁTICO Fase 2-4 - apagar]'
    );
    $createdids[] = $record->id;
    check('Teste C — depois do grant: acesso permitido', $context->is_user_access_prevented($userid) === false);
    check('Depois do grant: existe inscrição real', is_enrolled($context, $userid));

    // 2b. Fase 5 — um módulo real do curso (leitura apenas) também tem
    // de ficar liberto, não só o contexto do curso em si (secção 60:
    // ficheiros/atividades vivem em context_module).
    $cmid = $DB->get_field_select('course_modules', 'id', 'course = ? AND visible = 1', [$courseid]);
    if ($cmid) {
        $modulecontext = context_module::instance((int) $cmid, IGNORE_MISSING);
        if ($modulecontext) {
            check(
                "Fase 5 — módulo real (cmid={$cmid}) do curso: acesso permitido",
                $modulecontext->is_user_access_prevented($userid) === false
            );
        }
    } else {
        echo "(Fase 5: curso {$courseid} não tem nenhum módulo visível — a saltar esta verificação.)\n";
    }

    // 3. Idempotência — grant() outra vez não deve criar segunda linha nem duplicar inscrição.
    $record2 = authorization_manager::grant(
        $userid, $courseid, $studentroleid, $adminid, '[TESTE AUTOMÁTICO Fase 2-4 - apagar - repeticao]'
    );
    check('Idempotência — segundo grant devolve o mesmo id, não cria duplicado', $record2->id === $record->id);

    // 4. Revoke -> DENY outra vez, e inscrição removida.
    authorization_manager::revoke($userid, $courseid, $adminid);
    check('Depois do revoke: acesso volta a bloqueado', $context->is_user_access_prevented($userid) === true);
    check('Depois do revoke: inscrição removida', !is_enrolled($context, $userid));

    if ($cmid && $modulecontext) {
        check(
            "Fase 5 — depois do revoke: módulo real (cmid={$cmid}) volta a bloqueado",
            $modulecontext->is_user_access_prevented($userid) === true
        );
    }

    // 5. Papel não permitido -> deve rejeitar, sem criar inscrição nenhuma.
    $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
    $rejected = false;
    try {
        authorization_manager::grant($userid, $courseid, $managerroleid, $adminid, '[TESTE - deve falhar]');
    } catch (\moodle_exception $e) {
        $rejected = true;
    }
    check('Grant com papel "manager" é rejeitado', $rejected);
    check('Depois da rejeição: continua sem inscrição', !is_enrolled($context, $userid));

    // 6. Expiração no passado -> DENY imediato, sem esperar pela scheduled task.
    $expiredrecord = authorization_manager::grant(
        $userid, $courseid, $studentroleid, $adminid, '[TESTE AUTOMÁTICO Fase 2-4 - apagar - expirado]', time() - 1
    );
    $createdids[] = $expiredrecord->id;
    check('Autorização já expirada: acesso bloqueado', $context->is_user_access_prevented($userid) === true);

    // 6b. Fase 6 — revoke() explícito tem de conseguir revogar esta linha
    // mesmo estando ainda "active" na BD (expire_due() ainda não correu
    // sobre ela). Sem isto, ficaria uma linha "active" esquecida.
    $rawstatusbefore = $DB->get_field('local_crosstenant_access', 'status', ['id' => $expiredrecord->id]);
    check('Fase 6 — linha ainda "active" na BD antes do revoke explícito', $rawstatusbefore === 'active');

    $revokedexpired = authorization_manager::revoke($userid, $courseid, $adminid);
    check('Fase 6 — revoke() tem efeito sobre autorização já logicamente expirada', $revokedexpired === true);

    $rawstatusafter = $DB->get_field('local_crosstenant_access', 'status', ['id' => $expiredrecord->id]);
    check('Fase 6 — linha passou a "revoked", não ficou "active" esquecida', $rawstatusafter === 'revoked');

    // 7. Novo grant expirado, desta vez para confirmar expire_due() (em vez de revoke()).
    $expiredrecord2 = authorization_manager::grant(
        $userid, $courseid, $studentroleid, $adminid, '[TESTE AUTOMÁTICO Fase 2-4 - apagar - expirado 2]', time() - 1
    );
    $createdids[] = $expiredrecord2->id;

    // 8. expire_due() tem de limpar a inscrição desta segunda autorização já expirada.
    authorization_manager::expire_due();
    check('Depois de expire_due(): inscrição removida', !is_enrolled($context, $userid));

} finally {
    echo "\n--- Limpeza ---\n";
    foreach (array_unique($createdids) as $id) {
        $DB->delete_records('local_crosstenant_access', ['id' => $id]);
        echo "Apagado local_crosstenant_access.id={$id}\n";
    }

    // Garantia final: mesmo que algum passo acima tenha falhado a meio,
    // confirmar que não sobra inscrição nenhuma para este par.
    enrolment_manager::remove_plugin_enrolment($userid, $courseid);

    $after = $DB->count_records('local_crosstenant_access', ['userid' => $userid, 'courseid' => $courseid]);
    check('Contagem final igual à contagem inicial (limpeza completa)', $after === $baseline);
    check('Sem inscrição residual no final', !is_enrolled($context, $userid));

    if (!$instanceexistedbefore) {
        $instance = enrolment_manager::find_instance($courseid);
        if ($instance) {
            $stillhasenrolments = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id]);
            if (!$stillhasenrolments) {
                $enrolplugin = enrol_get_plugin(enrolment_manager::ENROL_TYPE);
                $enrolplugin->delete_instance($instance);
                echo "Instância de inscrição dedicada removida (foi criada por este script e ficou vazia).\n";
            } else {
                echo "AVISO: a instância dedicada ficou com outras inscrições — não foi removida.\n";
            }
        }
    } else {
        echo "Instância de inscrição dedicada já existia antes deste script — mantida como estava.\n";
    }
}

echo "\n=== Resumo: {$checks} verificações, " . ($checks - $fail) . " OK, {$fail} falhas ===\n";
exit($fail > 0 ? 1 : 0);