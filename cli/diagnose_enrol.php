<?php
/**
 * EDUdigital — Cross-Tenant Course Access (V1)
 * Diagnóstico read-only: porque é que enrol_manual_plugin::add_instance()
 * devolveu null para um curso específico.
 *
 * USO:
 *   php local/crosstenant/cli/diagnose_enrol.php --course=18
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(['course' => 0], []);

$courseid = (int) $options['course'];
if (!$courseid) {
    fwrite(STDERR, "Uso: php diagnose_enrol.php --course=X\n");
    exit(1);
}

echo "=== Diagnóstico enrolment manual — courseid={$courseid} ===\n\n";

echo "1. \$CFG->enrol_plugins_enabled:\n   {$CFG->enrol_plugins_enabled}\n";
$manualenabled = in_array('manual', explode(',', $CFG->enrol_plugins_enabled), true);
echo '   manual está na lista de plugins de inscrição ativos no site? ' . ($manualenabled ? 'SIM' : 'NÃO') . "\n\n";

echo "2. enrol_is_enabled('manual'): " . (enrol_is_enabled('manual') ? 'true' : 'false') . "\n\n";

$context = context_course::instance($courseid, IGNORE_MISSING);
if (!$context) {
    fwrite(STDERR, "ERRO: contexto do curso {$courseid} não encontrado.\n");
    exit(1);
}

$manual = enrol_get_plugin('manual');
echo "3. can_add_instance({$courseid}) como utilizador admin CLI:\n";
\core\session\manager::set_user(get_admin());
$canaddmanual = $manual->can_add_instance($courseid);
echo '   manual: ';
var_export($canaddmanual);
echo "\n";

$self = enrol_get_plugin('self');
$canaddself = $self ? $self->can_add_instance($courseid) : null;
echo '   self:   ';
var_export($canaddself);
echo "\n";

$cohort = enrol_get_plugin('cohort');
$canaddcohort = $cohort ? $cohort->can_add_instance($courseid) : null;
echo '   cohort: ';
var_export($canaddcohort);
echo "\n\n";

echo "4. Instâncias de inscrição já existentes neste curso:\n";
global $DB;
$instances = $DB->get_records('enrol', ['courseid' => $courseid], 'sortorder ASC');
if (!$instances) {
    echo "   (nenhuma)\n";
} else {
    foreach ($instances as $i) {
        echo "   id={$i->id} enrol={$i->enrol} name=" . ($i->name ?? '(sem nome)') . " status={$i->status}\n";
    }
}

echo "\n5. Capabilities do admin CLI neste contexto:\n";
echo '   moodle/course:enrolconfig: ' . (has_capability('moodle/course:enrolconfig', $context) ? 'SIM' : 'NÃO') . "\n";
echo '   enrol/manual:config: ' . (has_capability('enrol/manual:config', $context) ? 'SIM' : 'NÃO') . "\n";
echo '   enrol/self:config: ' . (has_capability('enrol/self:config', $context) ? 'SIM' : 'NÃO') . "\n";

echo "\n6. Quantas instâncias de cada tipo já existem neste curso (para perceber se há limite):\n";
$counts = [];
foreach ($instances as $i) {
    $counts[$i->enrol] = ($counts[$i->enrol] ?? 0) + 1;
}
foreach ($counts as $type => $n) {
    echo "   {$type}: {$n}\n";
}

echo "\n=== Fim do diagnóstico ===\n";