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
 * Cross-Tenant Access - página administrativa de um curso (secção 31).
 *
 * A segurança real já está toda em classes/external/ (Fase 3) - esta
 * página só faz require_login()+require_capability() para a
 * renderização em si; as ações (grant/revoke/search) passam sempre
 * pelas funções externas capability-gated via AJAX.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_crosstenant\service\authorization_manager;

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/crosstenant:view', $context);

$cangrant = has_capability('local/crosstenant:grantaccess', $context);

$PAGE->set_url('/local/crosstenant/course.php', ['id' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pagetitle', 'local_crosstenant', format_string($course->fullname)));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('pluginname', 'local_crosstenant'));

echo $OUTPUT->header();

$targettenantid = $context->tenantid ? (int) $context->tenantid : null;

if (!$targettenantid) {
    echo $OUTPUT->notification(get_string('coursehasnotenant', 'local_crosstenant'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$targettenantname = $DB->get_field('tenant', 'name', ['id' => $targettenantid]);
echo html_writer::tag(
    'p',
    get_string('coursetenant', 'local_crosstenant', format_string($targettenantname ?: ''))
);

// Lista de autorizações existentes (secção 31).
$records = $DB->get_records('local_crosstenant_access', ['courseid' => $courseid], 'timecreated DESC');
$rows = [];
foreach ($records as $record) {
    $authuser = $DB->get_record('user', ['id' => $record->userid]);
    $sourcetenant = $DB->get_record('tenant', ['id' => $record->source_tenantid]);
    $role = $DB->get_record('role', ['id' => $record->roleid]);

    $rows[] = [
        'userid' => (int) $record->userid,
        'fullname' => $authuser ? fullname($authuser) : get_string('deleteduser', 'local_crosstenant'),
        'sourcetenant' => $sourcetenant ? format_string($sourcetenant->name) : '-',
        'role' => $role ? role_get_name($role, $context) : $record->roleid,
        'granted' => userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'expires' => $record->timeexpires
            ? userdate($record->timeexpires, get_string('strftimedatetimeshort', 'langconfig'))
            : get_string('never', 'local_crosstenant'),
        'status' => $record->status,
        'statuslabel' => get_string('status_' . $record->status, 'local_crosstenant'),
        'canrevoke' => $cangrant && $record->status === authorization_manager::STATUS_ACTIVE,
        'reason' => $record->reason,
    ];
}

echo $OUTPUT->render_from_template('local_crosstenant/access_list', [
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'canmanage' => $cangrant,
]);

if ($cangrant) {
    echo $OUTPUT->render_from_template('local_crosstenant/add_user', [
        'courseid' => $courseid,
    ]);

    // Secção 21: papéis fixos da V1, descobertos via API, nunca
    // hardcoded como número - passados ao JS já resolvidos.
    $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $editingteacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

    $PAGE->requires->js_call_amd('local_crosstenant/manage', 'init', [
        $courseid, $studentroleid, $editingteacherroleid,
    ]);
}

echo $OUTPUT->footer();
