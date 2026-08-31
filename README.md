# local_crosstenant - Cross-Tenant Course Access (V1)

**Estado atual: Fase 9 concluída (secção 65 do prompt de implementação) — aguarda validação no vosso ambiente.**

## Fase 9 - eventos e privacy

**Eventos** (secção 25/35): `access_granted`, `access_revoked`,
`access_expired`, ligados aos placeholders vazios que já existiam em
`authorization_manager`. Cada um regista quem foi autorizado
(`relateduserid`) e quem agiu (`userid`) — com uma distinção importante:
no evento de expiração, `userid = 0`, porque é a scheduled task a agir,
não uma pessoa. Todos incluem `source_tenantid`/`target_tenantid` em
`other`, e nenhum rebenta se o curso entretanto tiver sido eliminado
(`IGNORE_MISSING` antes de disparar — secção 36). Para verificar depois
de conceder/revogar na UI: *Site administration → Reports → Logs*,
filtrar pelo componente deste plugin.

**Privacy provider** (secção 29): implementa exportação e eliminação
GDPR. Decisão de política a validar com o cliente — em vez de apagar a
linha inteira quando alguém pede eliminação dos seus dados, o campo que
identifica essa pessoa especificamente (`userid`, `createdby` ou
`revokedby`, conforme o papel que ocupava) é anonimizado, preservando o
registo de auditoria em si ("alguém foi autorizado, por quem, quando,
porquê") sem manter a identidade de quem pediu o apagamento. Três
pessoas diferentes podem aparecer na mesma linha (o autorizado, quem
concedeu, quem revogou) — cada uma só pode pedir os seus próprios dados,
nunca os dos outros dois.

`tests/event_test.php` e `tests/privacy_test.php` cobrem isto — a
correr via `\core_privacy\tests\provider_testcase`, o padrão oficial de
teste de privacy do Moodle/Totara.

## Achados pós-Fase-8 (teste manual em QA, 2026-08-27)

**Confirmado a funcionar de ponta a ponta:** conceder acesso como
Formador, conceder como Formando, revogar — tudo testado com sucesso no
browser. Isto fecha, finalmente, o smoke test manual pendente desde a
Fase 5.

**Bug real encontrado e corrigido:** `ModalFactory.create()` não expõe
`setSaveButtonText()` nesta versão do Totara — corrigido para
`modal.getRoot().find('[data-action="save"]').text(savelabel)`,
confirmado a funcionar. `manage.js` atualizado.

**Strings da UI personalizadas pela equipa** (Formador/Formando em vez
de Professor Editor/Aluno, textos de confirmação mais detalhados) —
`confirmgrantbody`/`confirmrevokebody` passaram a usar `{$a->user}`,
`{$a->tenant}` e (só no grant) `{$a->role}`. `manage.js` atualizado para
passar um objeto com essas três propriedades, incluindo capturar o
tenant do candidato selecionado na pesquisa (não estava a ser guardado
antes) e o texto do papel escolhido. `lang/en/` sincronizado com a mesma
forma de `$a`, para não partir para sessões em inglês.

**`$plugin->requires` confirmado:** `2020061500` (Moodle 3.9), a base
real desta instalação de Totara — `version.php` atualizado, TODO removido.

**Comportamento esperado, não é bug:** tentar conceder acesso a um
utilizador SEM tenant (`crosstenant_user_has_no_tenant`) falha sempre, e
está correto — um utilizador sem tenant nunca é bloqueado pelo
isolamento nativo (`is_user_access_prevented()` devolve `false` logo no
início para esses utilizadores), por isso "conceder acesso cross-tenant"
não se aplica. Possível polish futuro: excluir estes utilizadores da
pesquisa em `search_users.php`, já que tentar autorizá-los falha sempre.

**Achado em aberto — cursos não aparecem no Registo de Formação /
"Os meus cursos":** `is_user_access_prevented()` (o método que
patchámos) decide "posso abrir este curso agora", mas listagens como
Registo de Formação e dashboard usam funções em `lib/enrollib.php`
(`enrol_get_all_users_courses_sql()` e semelhantes) que fazem o seu
próprio JOIN SQL direto contra `tenantid`, inteiramente à parte do
extension point. O utilizador cross-tenant consegue aceder ao curso
diretamente, mas fica invisível nestas listagens. Isto é um segundo
mecanismo de isolamento, distinto do que já corrigimos — não avançar
para um patch sem primeiro correr
`deployment/patches/totara-19/survey_tenant_joins.sh` (read-only) para
confirmar a dimensão real do problema, seguindo o mesmo processo
disciplinado da Fase 1.

## Fase 8 - UI administrativa

**Aviso honesto antes de tudo:** esta fase foi escrita sem acesso a um
browser real para verificar a renderização. Os padrões usados (Mustache,
AMD/RequireJS, `core/modal_factory`, `core/ajax`) são estáveis há muitas
versões do Moodle/Totara, mas esta é a primeira fase do projeto que não
foi validada com dados reais antes de chegar até ti — precisa mesmo de
verificação visual.

**Passo que falta e que os scripts CLI não substituem — build do AMD:**
`amd/src/manage.js` não corre diretamente em produção. O Totara, fora de
modo developer, espera o ficheiro compilado em `amd/build/manage.min.js`.
Depois de copiar os ficheiros, corre o processo de build do vosso site
(tipicamente `grunt amd` a partir da raiz do código, ou o equivalente que
já usem) — sem isto, a página carrega mas a pesquisa/confirmação não
funciona (o browser vai acusar "module not found" na consola). Em
alternativa, para um teste rápido, podes ativar
`$CFG->debug = DEBUG_DEVELOPER` temporariamente, o que permite ao Totara
carregar diretamente de `amd/src/` sem build — não fazer isto em
produção.

**Porque é que o número de versão subiu desta vez:** `db/access.php`,
`db/services.php` e `db/tasks.php` só são reprocessados pelo Totara
quando `$plugin->version` muda. Esta fase acrescenta `search_users` a
`db/services.php` — sem o bump, o Totara não ficaria a conhecer a nova
função depois de correres a atualização.

**O que esta fase acrescenta:**
- `course.php` — a página em si (*Course administration → Cross-Tenant
  Access*, secção 31), sem lógica de segurança própria — delega tudo em
  `require_capability()` + nas funções externas já existentes.
- `classes/external/search_users.php` — pesquisa candidatos de OUTROS
  tenants (secção 16/32), nunca do tenant do próprio curso.
- `templates/access_list.mustache`, `templates/add_user.mustache`.
- `amd/src/manage.js` — pesquisa com debounce, seleção de candidato,
  confirmação antes de conceder (secção 32) e antes de revogar (secção
  33) via `core/modal_factory`, chamando sempre as funções externas
  capability-gated — nenhuma decisão de segurança vive no JS.
- `lib.php` — `local_crosstenant_extend_navigation_course()`, o hook
  oficial do Totara para acrescentar a ligação ao menu de administração
  do curso; só aparece em cursos com tenant e para quem tem
  `local/crosstenant:view`.
- `tests/search_users_test.php` — prova a exclusão de utilizadores do
  mesmo tenant, entre outros casos.

**Checklist de verificação visual, depois do build do AMD:**
1. Abrir um curso com tenant, confirmar que a ligação "Cross-Tenant
   Access" aparece no menu de administração do curso (a localização
   exata depende do tema — se não encontrares, navega diretamente a
   `local/crosstenant/course.php?id=X`).
2. Pesquisar um nome de utilizador de outro tenant, confirmar que
   aparece na lista e que um utilizador do próprio tenant do curso NÃO
   aparece.
3. Selecionar um candidato, preencher papel/expiração/motivo, clicar
   "Conceder acesso", confirmar que o modal de confirmação mostra o
   texto certo (secção 32) antes de agir.
4. Confirmar que a tabela de autorizados atualiza depois de conceder.
5. Clicar "Revogar" numa linha, confirmar o modal (secção 33), confirmar
   que o estado muda para "Revogada".

## Fase 7 - scheduled task

Peça pequena, como esperado — a lógica (`expire_due()`) já existia e já
estava testada desde a Fase 2/4. `classes/task/process_expirations.php`
+ `db/tasks.php` são só o encaixe no agendador do Totara, correndo a
cada 15 minutos por defeito (ajustável em *Site administration > Server
> Scheduled tasks* sem alterar código).

Vale sublinhar: mesmo que esta task nunca corresse, o sistema não
ficaria inseguro — a decisão de acesso (`get_active_authorization()`)
já trata em tempo real uma autorização com `timeexpires` no passado
como inválida, independentemente do estado gravado na BD. Esta task
existe para arrumação (marcar `expired`, remover a inscrição real), não
para decidir acesso.

`tests/task_test.php` prova o registo e a execução. Para testar via a
própria ferramenta oficial do Totara em vez de um script à parte:
```bash
php admin/cli/scheduled_task.php --execute='\local_crosstenant\task\process_expirations'
```
`cli/verify_manual.php` também passou a confirmar que a task está
registada (leitura apenas).


## Fase 6 - Revoke

A maior parte da revogação já tinha sido construída sem se esperar por
esta fase — `authorization_manager::revoke()` (Fase 2), remoção de
inscrição real (Fase 4), e o endpoint capability-gated `revoke_access`
(Fase 3) já existiam e estavam testados. Em vez de inventar trabalho já
feito, esta fase focou-se numa lacuna real encontrada ao reler a secção
23 com atenção: `revoke()` usava `get_active_authorization()`, que
também filtra por `timeexpires` no passado — um admin a tentar revogar
explicitamente uma autorização já logicamente expirada mas ainda não
varrida por `expire_due()` ficava sem efeito nenhum, deixando uma linha
`status=active` esquecida na tabela.

Corrigido: `revoke()` passou a consultar diretamente `status=active` na
BD (não através do filtro de expiração), e revoga qualquer linha que
encontre — a intenção explícita de um humano tem de ter sempre efeito.
`tests/revoke_test.php` prova isto, mais idempotência e o registo
correto de `timerevoked`/`revokedby`. `cli/verify_manual.php` também
passou a testar este cenário especificamente (passo 6b).

## Fase 5 - acesso real dentro do curso (atividades, ficheiros)

Esta fase encontrou uma lacuna de arquitetura real, não só confirmou o
que já existia: `access_manager::can_access()` só autorizava contexto de
**curso**. Mas os 7 pontos de `lib/filelib.php` identificados na análise
inicial servem a maioria dos ficheiros a partir do contexto do
**módulo/atividade**, que herda o `tenantid` do curso pai — o core
bloqueava-os exatamente da mesma forma, e sem este alargamento, um
utilizador autorizado ficaria com acesso ao curso mas não conseguiria
abrir um único ficheiro lá dentro.

Corrigido: `access_manager` agora resolve o curso tanto a partir de
`context_course` como de `context_module` (via `get_course_context()`),
e autoriza com base nesse curso. Deliberadamente **não** alargado a
blocos, comentários/reações, categoria, tenant ou sistema — mantém-se a
garantia da secção 21 de nunca abrir mais do que o curso especificamente
autorizado.

`tests/module_access_test.php` prova: módulo bloqueado sem autorização,
liberto com autorização ativa, bloqueado outra vez após revogação, sem
fuga para módulos de outro curso do mesmo tenant, e uma guarda explícita
de que contexto de categoria nunca é autorizado mesmo com grant ativo.

`cli/verify_manual.php` também passou a verificar isto com um módulo
real do curso descoberto automaticamente (leitura apenas — não cria
nenhuma atividade nova).

**O que esta fase NÃO cobre**, por ser mais adequado a um smoke test
manual no browser do que a automação: download efetivo de um ficheiro
via `pluginfile.php`, submissão de quiz, e runtime de SCORM. O que se
prova aqui é o ponto de decisão real (`is_user_access_prevented`) — a
causa raiz identificada na análise inicial. Um teste manual completo com
um utilizador real, um PDF real e um quiz real continua a valer a pena
antes de dar a V1 como pronta para produção.

## Fase 4 - Enrolment Manager: inscrição real

`grant()` e `revoke()` já não só mexem na tabela do plugin — agora
produzem/removem uma inscrição real, através da API oficial de
inscrição manual (secção 12/27/28).

**Decisão de arquitetura chave:** em vez de tentar adivinhar, a
posteriori, se uma linha em `user_enrolments` "pertence" ao plugin
(secção 14), `enrolment_manager` cria **uma instância de inscrição
manual dedicada por curso** (`EDUdigital: Cross-Tenant Access (não
editar manualmente)`). Todas as inscrições concedidas por este plugin
nesse curso passam sempre por essa instância. Consequência: como o
Totara já mantém as linhas de `user_enrolments` completamente separadas
por instância de enrol, remover a inscrição da instância dedicada
**nunca pode, por construção**, tocar numa inscrição criada por outro
método (secção 13/23) — não é preciso comparar metadados nem adivinhar,
a separação é estrutural. `has_independent_enrolment()` existe (tal como
pedido na secção 20) mas passa a ser informativo, não uma condição que
decide se se remove — remover é sempre seguro dado este desenho.

`grant()`/`revoke()`/`expire_due()` chamam `enrolment_manager` dentro da
mesma transação da autorização (secção 45) — se a inscrição falhar, a
autorização também não fica criada.

**⚠️ Antes de correres `cli/verify_manual.php --confirm` outra vez:**
agora cria uma inscrição real, ainda que breve. Confirma que o envio de
email está desligado/redirecionado neste QA — o script avisa isto no
`--help`, mas não consegue verificar a configuração de email por ti.

## Fase 3 - capabilities + fronteira de segurança real

A partir desta fase, `authorization_manager` deixa de ser a fronteira de
segurança — passa a ser `classes/external/`. As três funções ligam-se ao
dispatcher AJAX/webservice nativo do Moodle/Totara (`db/services.php`,
`ajax => true`), o que dá sesskey e validação de contexto "de borla",
sem código de segurança escrito à mão (secção 39/41).

- `local/crosstenant:view`, `:grantaccess`, `:revokeaccess` - `db/access.php`,
  contexto de curso, arquétipos por defeito conservadores (só `manager`
  concede/revoga; `manager`+`editingteacher` podem ver). Ajustável por
  overrides de role no site, sem alterar código.
- `classes/external/grant_access.php`, `revoke_access.php`,
  `get_authorizations.php` - cada um: `validate_parameters()` →
  `validate_context()` → `require_capability()` → só depois chama o
  serviço. Nenhum aceita `source_tenantid`/`target_tenantid` como
  parâmetro — estruturalmente impossível fazer spoof de tenant por esta
  via (secção 42).
- `tests/external_test.php` - prova que sem a capability certa, cada
  função lança `required_capability_exception`; com a capability, o
  acesso real muda (não é só um registo em BD parado).

**⚠️ Ainda por confirmar da vossa parte antes de instalar:** este código
assume a convenção clássica da External API do Moodle/Totara
(`require_once($CFG->libdir . '/externallib.php'); class X extends
\external_api`), que se mantém suportada em Totara 19.0 sem alterações
disruptivas. Se preferires ter a certeza absoluta antes de instalar,
corre:
```bash
grep -rn "class.*extends.*external_api" local/ enrol/ mod/assign/classes/external/ 2>/dev/null | head -10
```
Se vires resultados a usar exatamly este padrão (`extends
\external_api` ou `extends external_api`, sem namespace `core_external`),
está confirmado - é o que este código já assume.

## Registo de validação da Fase 7

**2026-08-19, ambiente QA (`s5602pfcoa-qa`)** - `cli/verify_manual.php --confirm`,
depois de `admin/cli/upgrade.php` para o Totara conhecer a task nova:

```
[PASS] Fase 7 - scheduled task process_expirations está registada
```

20/20 no total. Registo confirmado com `\core\task\manager::get_scheduled_task()`
real, não só pela existência do ficheiro.

## Registo de validação da Fase 6

**2026-08-19, ambiente QA (`s5602pfcoa-qa`)** — `cli/verify_manual.php --confirm`,
mesmos dados reais (userid=23, courseid=18):

```
[PASS] Fase 6 - linha ainda "active" na BD antes do revoke explícito
[PASS] Fase 6 - revoke() tem efeito sobre autorização já logicamente expirada
[PASS] Fase 6 - linha passou a "revoked", não ficou "active" esquecida
```

19/19 no total. Confirmado com dados reais que a correção resolve
exatamente o cenário identificado - uma linha que ficaria presa em
`active` sem este ajuste.

## Registo de validação da Fase 5

**2026-08-19, ambiente QA (`s5602pfcoa-qa`)** - `cli/verify_manual.php --confirm`,
mesmos dados reais (userid=23, courseid=18), com um módulo real do curso
(cmid=18) incluído nas verificações:

```
[PASS] Fase 5 - módulo real (cmid=18) do curso: acesso permitido
[PASS] Fase 5 - depois do revoke: módulo real (cmid=18) volta a bloqueado
```

16/16 no total (as 14 anteriores + estas 2). Confirma, com dados de
produção-espelhada, que a correção da lacuna de `context_module` funciona
de facto - não só nos testes sintéticos do PHPUnit.

**Ainda em aberto antes de fechar esta fase por completo:** o smoke test
manual no browser (download real de um ficheiro, idealmente PDF, dentro
do curso 18, com o utilizador 23 autorizado). Isto prova a última milha
que nenhum destes scripts consegue verificar por si só — o pedido HTTP
real a `pluginfile.php`.

## Registo de validação da Fase 4

**2026-08-19, ambiente QA (`s5602pfcoa-qa`)** — `cli/verify_manual.php --confirm`,
dados reais (userid=23, courseid=18, tenant "DREAE" / "DREAE / EBI Arrifes"):

```
[PASS] Antes do grant: acesso bloqueado
[PASS] Antes do grant: sem inscrição
[PASS] Teste C - depois do grant: acesso permitido
[PASS] Depois do grant: existe inscrição real
[PASS] Idempotência - segundo grant devolve o mesmo id, não cria duplicado
[PASS] Depois do revoke: acesso volta a bloqueado
[PASS] Depois do revoke: inscrição removida
[PASS] Grant com papel "manager" é rejeitado
[PASS] Depois da rejeição: continua sem inscrição
[PASS] Autorização já expirada: acesso bloqueado
[PASS] Depois de expire_due(): inscrição removida
[PASS] Contagem final igual à contagem inicial (limpeza completa)
[PASS] Sem inscrição residual no final
Instância de inscrição dedicada removida (foi criada por este script e ficou vazia)
```

14/14. Três problemas reais encontrados e corrigidos nesta fase, por
esta ordem:

1. `enrol_manual` só permite **uma** instância por curso - `can_add_instance()`
   devolve `false` se já existir uma (e este site tem sempre uma). Trocado
   para `enrol_self`, confirmado a suportar múltiplas instâncias por curso
   neste ambiente antes de reescrever o código (`cli/diagnose_enrol.php`).
2. Tipos `int`/`string` inconsistentes vindos da camada de BD (mesma classe
   de bug já apanhada na Fase 2).
3. `cli/verify_manual.php` não tinha utilizador autenticado na sessão CLI -
   `can_add_instance()` falhava sempre contra um `$USER` anónimo, mesmo com
   o admin real a ter todas as capabilities. `diagnose_enrol.php` já fazia
   `\core\session\manager::set_user(get_admin())` corretamente; faltava no
   outro script.

## Registo de validação da Fase 2

**2026-08-19, ambiente QA (`s5602pfcoa-qa`)** - `cli/verify_manual.php --confirm`,
dados reais (userid=23, courseid=18, tenant "DREAE" / "DREAE / EBI Arrifes"):

```
[PASS] local_crosstenant regista o callback crosstenant_access_check
[PASS] Antes do grant: acesso bloqueado
[PASS] Teste C - depois do grant: acesso permitido
[PASS] Idempotência - segundo grant devolve o mesmo id, não cria duplicado
[PASS] Depois do revoke: acesso volta a bloqueado
[PASS] Grant com papel "manager" é rejeitado
[PASS] Autorização já expirada: acesso bloqueado
[PASS] Contagem final igual à contagem inicial (limpeza completa)
```

8/8. Nota técnica arquivada: a primeira corrida falhou por comparação
estrita (`===`/`in_array(..., true)`) entre valores `int` e `string`
devolvidos pela camada de BD (`get_fieldset_select()`/`get_field()`
nem sempre devolvem `int`) - corrigido em `authorization_manager.php`
com normalização explícita de tipos em `is_role_allowed()`,
`get_user_tenantid()`, `get_course_tenantid()` e
`get_active_authorization()`.

Isto é um esqueleto ainda sem UI, não um plugin pronto a usar por
administradores finais. Ainda não existe:

- UI (Fase 8) - mas a partir de agora a UI só precisa de chamar as três
  funções de `db/services.php` via JS; toda a segurança e a inscrição
  real já estão feitas
- Eventos e auditoria (Fase 10) - os pontos de disparo existem como
  placeholders vazios em `authorization_manager`
- Privacy provider (Fase 11)

O que já existe e está implementado até esta fase:

- `db/install.xml` - tabela `local_crosstenant_access`
- `db/access.php` - capabilities `view`/`grantaccess`/`revokeaccess`
- `db/services.php` - registo das 3 funções externas, com `ajax => true`
- `db/tasks.php` - registo da scheduled task de expiração
- `classes/service/authorization_manager.php` - `grant()`, `revoke()`,
  `expire_due()`, `is_authorized()`, `get_active_authorization()`,
  `is_role_allowed()` - agora também aciona `enrolment_manager`
- `classes/service/access_manager.php` — `can_access()`, chamado pelo
  extension point
- `classes/service/enrolment_manager.php` - `ensure_enrolment()`,
  `remove_plugin_enrolment()`, `has_independent_enrolment()`, via
  instância de inscrição manual dedicada por curso
- `classes/external/grant_access.php`, `revoke_access.php`,
  `get_authorizations.php`, `search_users.php` - fronteira de segurança
  capability-gated
- `classes/task/process_expirations.php` - scheduled task, chama
  `expire_due()`
- `lib.php` - `local_crosstenant_crosstenant_access_check()`, o callback
  que liga este plugin ao patch documentado em
  `deployment/patches/totara-19/CORE_PATCH.md`
- `lang/en/` + `lang/pt/` - mantidos em sincronia a partir da Fase 3
- `tests/extension_point_test.php` - PHPUnit, Fase 2 (serviço, sem capability)
- `tests/external_test.php` - PHPUnit, Fase 3 (fronteira de segurança)
- `tests/enrolment_test.php` - PHPUnit, Fase 4 (inscrição real,
  preservação de inscrição independente, limpeza na expiração)
- `tests/module_access_test.php` - PHPUnit, Fase 5 (contexto de módulo,
  ficheiros/atividades, guardas de âmbito)
- `tests/revoke_test.php` - PHPUnit, Fase 6 (revogação de autorização
  logicamente expirada mas não varrida, idempotência, registo de auditoria)
- `tests/task_test.php` - PHPUnit, Fase 7 (registo e execução da task)
- `tests/search_users_test.php` - PHPUnit, Fase 8 (exclusão de
  utilizadores do mesmo tenant, capability, curso sem tenant)
- `cli/verify_manual.php` - equivalente sem PHPUnit às Fases 2-7,
  self-cleaning, **nunca correr em produção** (não cobre a UI —
  ver checklist de verificação visual acima)
- `cli/diagnose_enrol.php` - diagnóstico read-only de enrolment (útil se
  mudar de mecanismo de inscrição outra vez no futuro)

## Regras de arquitetura que este código respeita

- `source_tenantid`/`target_tenantid` nunca vêm de input do chamador -
  são sempre derivados do utilizador e do curso reais em
  `authorization_manager` (secção 17/42), e nem sequer existem como
  parâmetro aceite nas funções externas.
- A autorização é sempre `User -> Course`, nunca `User -> Tenant` nem
  `Tenant -> Tenant` (secção 2/26/58) - `access_manager::can_access()`
  recusa qualquer contexto que não seja `context_course`.
- Papéis permitidos em V1: `student` (Learner) e `editingteacher`
  (secção 21) - qualquer outro é rejeitado em `is_role_allowed()`,
  mesmo por um ator com `grantaccess` (secção 9/21 - ter capability
  não basta para escolher um papel privilegiado).
- Capability nunca é decorativa: `grant_access`/`revoke_access`/
  `get_authorizations` validam sempre no servidor, nunca confiam num
  botão escondido na UI (secção 33/41).
- `grant()`/`revoke()`/`expire_due()` são idempotentes (secção 46).
- Revogar ou deixar expirar uma autorização nunca altera `user.tenantid`
  — a pertença original ao Tenant A não é tocada em lado nenhum deste
  código.

## Próximos passos (não implementados ainda)

Fase 10 (testes de segurança — secção 39-42: IDOR, CSRF, tenant spoofing, agora com uma UI real para testar), Fase 11 (upgrade/rollback do próprio plugin) — por esta ordem, conforme a secção 65 do prompt de implementação. O achado do Registo de Formação (ver acima) fica deliberadamente de fora, por decisão explícita, até haver disponibilidade para o investigar com calma.