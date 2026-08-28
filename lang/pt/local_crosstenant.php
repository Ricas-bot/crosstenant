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
 * Strings em português para local_crosstenant.
 *
 * Mantido em sincronia com lang/en/local_crosstenant.php a partir da
 * Fase 3 — qualquer string nova entra sempre nos dois ficheiros.
 *
 * @package    local_crosstenant
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Acesso Cross-Tenant a Cursos';

$string['crosstenant:view'] = 'Ver autorizações de acesso cross-tenant';
$string['crosstenant:grantaccess'] = 'Conceder acesso cross-tenant a cursos';
$string['crosstenant:revokeaccess'] = 'Revogar acesso cross-tenant a cursos';

$string['crosstenant_role_not_allowed'] = 'Este papel não é permitido para acesso cross-tenant nesta versão (apenas Aluno ou Professor Editor).';
$string['crosstenant_user_has_no_tenant'] = 'O utilizador indicado não pertence a nenhum tenant.';
$string['crosstenant_course_has_no_tenant'] = 'O curso indicado não pertence a nenhum tenant.';
$string['crosstenant_same_tenant'] = 'Este utilizador já pertence ao tenant proprietário deste curso. Utilize o mecanismo normal de inscrição do Totara.';
$string['crosstenant_cannot_add_enrol_instance'] = 'Não é possível criar a instância de inscrição dedicada para este curso (can_add_instance() recusou).';
$string['crosstenant_enrol_instance_creation_failed'] = 'Falha ao criar a instância de inscrição dedicada para este curso.';

$string['task_process_expirations'] = 'Processar autorizações de acesso cross-tenant expiradas';

// Fase 8 — UI. Strings desta secção ajustadas pela equipa em 2026-08-27
// depois do teste manual em QA (Formador/Formando confirmados a funcionar).
$string['pagetitle'] = 'Acesso Cross-Tenant ao Curso {$a}';
$string['coursetenant'] = 'Este curso pertence ao inquilino: {$a}';
$string['coursehasnotenant'] = 'Este curso não pertence a nenhum tenant — este plugin não se aplica aqui.';
$string['authorizedusers'] = 'Utilizadores autorizados';
$string['noauthorizations'] = 'Nenhuma autorização encontrada';
$string['user'] = 'Utilizador';
$string['sourcetenant'] = 'Inquilino de origem';
$string['role'] = 'Papel';
$string['granted'] = 'Concedido';
$string['expires'] = 'Expira';
$string['expireshelp'] = 'Data de expiração da autorização de acesso cross-tenant. Se não for definida, a autorização é permanente.';
$string['status'] = 'Estado';
$string['actions'] = 'Ações';
$string['revoke'] = 'Revogar';
$string['status_active'] = 'Ativo';
$string['status_revoked'] = 'Revogado';
$string['status_expired'] = 'Expirada';
$string['never'] = 'Nunca';
$string['deleteduser'] = '(utilizador eliminado)';
$string['addusertitle'] = 'Adicionar utilizador';
$string['searchuser'] = 'Pesquisar utilizador';
$string['searchuserplaceholder'] = 'Nome de utilizador ou email';
$string['nousersfound'] = 'Nenhum utilizador encontrado';
$string['notenant'] = 'Sem Inquilino';
$string['learner'] = 'Formando';
$string['editingteacher'] = 'Formador';
$string['reason'] = 'Motivo';
$string['grantaccess'] = 'Conceder Acesso';
$string['confirmgranttitle'] = 'Confirmar Concessão de Acesso';
$string['confirmgrantbody'] = 'Tem a certeza de que deseja conceder acesso cross-tenant ao utilizador {$a->user} do tenant {$a->tenant}? Isto irá criar uma instância de inscrição dedicada para o curso, e o utilizador será inscrito com o papel de {$a->role}.';
$string['confirmrevoketitle'] = 'Confirmar Revogação de Acesso';
$string['confirmrevokebody'] = 'Tem a certeza de que deseja revogar o acesso cross-tenant ao utilizador {$a->user} do tenant {$a->tenant}?';