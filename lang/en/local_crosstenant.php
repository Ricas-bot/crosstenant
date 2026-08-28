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
 * English language strings for local_crosstenant.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Cross-Tenant Course Access';

$string['crosstenant:view'] = 'View cross-tenant course access authorizations';
$string['crosstenant:grantaccess'] = 'Grant cross-tenant course access';
$string['crosstenant:revokeaccess'] = 'Revoke cross-tenant course access';

$string['crosstenant_role_not_allowed'] = 'This role is not permitted for cross-tenant access in this version (only Learner or Editing Teacher).';
$string['crosstenant_user_has_no_tenant'] = 'The specified user does not belong to any tenant.';
$string['crosstenant_course_has_no_tenant'] = 'The specified course does not belong to any tenant.';
$string['crosstenant_same_tenant'] = 'This user already belongs to the tenant that owns this course. Use the normal Totara enrolment mechanism instead.';
$string['crosstenant_cannot_add_enrol_instance'] = 'Cannot create the dedicated enrolment instance for this course (can_add_instance() refused it).';
$string['crosstenant_enrol_instance_creation_failed'] = 'Failed to create the dedicated enrolment instance for this course.';

$string['task_process_expirations'] = 'Process expired cross-tenant course access authorizations';

// Fase 8 — UI.
$string['pagetitle'] = 'Cross-Tenant Access — {$a}';
$string['coursetenant'] = 'This course belongs to tenant: {$a}';
$string['coursehasnotenant'] = 'This course does not belong to any tenant — this plugin does not apply here.';
$string['authorizedusers'] = 'Authorized users';
$string['noauthorizations'] = 'No cross-tenant authorizations for this course yet.';
$string['user'] = 'User';
$string['sourcetenant'] = 'Source tenant';
$string['role'] = 'Role';
$string['granted'] = 'Granted';
$string['expires'] = 'Expires';
$string['expireshelp'] = 'Leave empty for no expiry.';
$string['status'] = 'Status';
$string['actions'] = 'Actions';
$string['revoke'] = 'Revoke';
$string['status_active'] = 'Active';
$string['status_revoked'] = 'Revoked';
$string['status_expired'] = 'Expired';
$string['never'] = 'Never';
$string['deleteduser'] = '(deleted user)';
$string['addusertitle'] = 'Add user';
$string['searchuser'] = 'Search for a user from another tenant';
$string['searchuserplaceholder'] = 'Type a name or email...';
$string['nousersfound'] = 'No matching users found in other tenants.';
$string['notenant'] = 'No tenant';
$string['learner'] = 'Learner';
$string['editingteacher'] = 'Editing Teacher';
$string['reason'] = 'Reason';
$string['grantaccess'] = 'Grant access';
$string['confirmgranttitle'] = 'Grant cross-tenant access?';
$string['confirmgrantbody'] = 'Grant cross-tenant access to {$a->user} from tenant {$a->tenant}? This will create a dedicated enrolment instance for the course, and the user will be enrolled with the role of {$a->role}.';
$string['confirmrevoketitle'] = 'Revoke access?';
$string['confirmrevokebody'] = 'Revoke cross-tenant access for {$a->user} from tenant {$a->tenant}? Their tenant membership is not affected.';