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
 * local_crosstenant/manage
 *
 * Pesquisa de utilizadores de outro tenant, confirmacao antes de
 * conceder (seccao 32) e antes de revogar (seccao 33). Todas as chamadas
 * passam pelas funcoes externas capability-gated de classes/external/ --
 * este modulo nao faz nenhuma decisao de seguranca, so UI.
 *
 * Testado manualmente em 2026-08-27 (QA): conceder como Formador,
 * conceder como Formando, revogar -- tudo confirmado a funcionar. O
 * `.find('[data-action="save"]').text(...)` no lugar de
 * `modal.setSaveButtonText(...)` foi um fix empirico confirmado nesse
 * teste -- este Totara nao expoe setSaveButtonText() no objeto modal
 * devolvido por ModalFactory.create() nesta versao.
 *
 * @module     local_crosstenant/manage
 * @copyright  2026 EDUdigital
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'jquery',
    'core/ajax',
    'core/notification',
    'core/str',
    'core/modal_factory',
    'core/modal_events'
], function($, Ajax, Notification, Str, ModalFactory, ModalEvents) {

    var SELECTORS = {
        SEARCH_INPUT: '[data-region="crosstenant-search-input"]',
        SEARCH_RESULTS: '[data-region="crosstenant-search-results"]',
        GRANT_FORM: '[data-region="crosstenant-grant-form"]',
        SELECTED_USERID: '[data-region="crosstenant-selected-userid"]',
        SELECTED_LABEL: '[data-region="crosstenant-selected-label"]',
        SELECTED_TENANT: '[data-region="crosstenant-selected-tenant"]',
        ROLE: '[data-region="crosstenant-role"]',
        EXPIRES: '[data-region="crosstenant-expires"]',
        REASON: '[data-region="crosstenant-reason"]'
    };

    var courseid = null;
    var studentroleid = null;
    var editingteacherroleid = null;
    var searchTimeout = null;

    var roleIdFor = function(shortname) {
        return shortname === 'editingteacher' ? editingteacherroleid : studentroleid;
    };

    var clearResults = function() {
        $(SELECTORS.SEARCH_RESULTS).empty();
    };

    var selectUser = function(userid, fullname, tenantname) {
        $(SELECTORS.SELECTED_USERID).val(userid);
        $(SELECTORS.SELECTED_LABEL).text(fullname);
        $(SELECTORS.SELECTED_TENANT).val(tenantname);
        $(SELECTORS.GRANT_FORM).show();
        clearResults();
        $(SELECTORS.SEARCH_INPUT).val('');
    };

    var renderResults = function(users) {
        var $results = $(SELECTORS.SEARCH_RESULTS);
        $results.empty();

        if (!users.length) {
            Str.get_string('nousersfound', 'local_crosstenant').done(function(str) {
                $('<div class="list-group-item text-muted"></div>').text(str).appendTo($results);
            }).fail(Notification.exception);
            return;
        }

        users.forEach(function(user) {
            var label = user.fullname + ' \u2014 ' + user.tenantname;
            $('<button type="button" class="list-group-item list-group-item-action"></button>')
                .text(label)
                .on('click', function() {
                    selectUser(user.id, user.fullname, user.tenantname);
                })
                .appendTo($results);
        });
    };

    var doSearch = function(query) {
        if (query.length < 2) {
            clearResults();
            return;
        }

        Ajax.call([{
            methodname: 'local_crosstenant_search_users',
            args: {courseid: courseid, query: query}
        }])[0].done(function(response) {
            renderResults(response.users);
        }).fail(Notification.exception);
    };

    var reloadPage = function() {
        window.location.reload();
    };

    var confirmGrant = function() {
        var userid = parseInt($(SELECTORS.SELECTED_USERID).val(), 10);
        var fullname = $(SELECTORS.SELECTED_LABEL).text();
        var tenantname = $(SELECTORS.SELECTED_TENANT).val();
        var roleshortname = $(SELECTORS.ROLE).val();
        var rolelabel = $(SELECTORS.ROLE + ' option:selected').text();
        var expiresvalue = $(SELECTORS.EXPIRES).val();
        var reason = $(SELECTORS.REASON).val();
        var timeexpires = 0;

        if (expiresvalue) {
            timeexpires = Math.floor(new Date(expiresvalue + 'T23:59:59').getTime() / 1000);
        }

        var confirmbody = {
            user: fullname,
            tenant: tenantname,
            role: rolelabel
        };

        $.when(
            Str.get_string('confirmgranttitle', 'local_crosstenant'),
            Str.get_string('confirmgrantbody', 'local_crosstenant', confirmbody),
            Str.get_string('grantaccess', 'local_crosstenant')
        ).done(function(title, body, savelabel) {
            ModalFactory.create({
                type: ModalFactory.types.SAVE_CANCEL,
                title: title,
                body: body
            }).done(function(modal) {
                modal.getRoot().find('[data-action="save"]').text(savelabel);

                modal.getRoot().on(ModalEvents.save, function() {
                    Ajax.call([{
                        methodname: 'local_crosstenant_grant_access',
                        args: {
                            userid: userid,
                            courseid: courseid,
                            roleid: roleIdFor(roleshortname),
                            reason: reason,
                            timeexpires: timeexpires
                        }
                    }])[0].done(reloadPage).fail(Notification.exception);
                });

                modal.show();
            }).fail(Notification.exception);
        }).fail(Notification.exception);
    };

    var confirmRevoke = function(userid, fullname, tenantname) {
        var confirmbody = {
            user: fullname,
            tenant: tenantname
        };

        $.when(
            Str.get_string('confirmrevoketitle', 'local_crosstenant'),
            Str.get_string('confirmrevokebody', 'local_crosstenant', confirmbody),
            Str.get_string('revoke', 'local_crosstenant')
        ).done(function(title, body, savelabel) {
            ModalFactory.create({
                type: ModalFactory.types.SAVE_CANCEL,
                title: title,
                body: body
            }).done(function(modal) {
                modal.getRoot().find('[data-action="save"]').text(savelabel);

                modal.getRoot().on(ModalEvents.save, function() {
                    Ajax.call([{
                        methodname: 'local_crosstenant_revoke_access',
                        args: {userid: parseInt(userid, 10), courseid: courseid}
                    }])[0].done(reloadPage).fail(Notification.exception);
                });

                modal.show();
            }).fail(Notification.exception);
        }).fail(Notification.exception);
    };

    return {
        /**
         * @param {Number} pcourseid
         * @param {Number} pstudentroleid
         * @param {Number} peditingteacherroleid
         */
        init: function(pcourseid, pstudentroleid, peditingteacherroleid) {
            courseid = pcourseid;
            studentroleid = pstudentroleid;
            editingteacherroleid = peditingteacherroleid;

            $(SELECTORS.SEARCH_INPUT).on('input', function() {
                var query = $(this).val();
                window.clearTimeout(searchTimeout);
                searchTimeout = window.setTimeout(function() {
                    doSearch(query);
                }, 300);
            });

            $(document).on('click', '[data-action="grant"]', confirmGrant);

            $(document).on('click', '[data-action="cancel-grant"]', function() {
                $(SELECTORS.GRANT_FORM).hide();
                $(SELECTORS.SELECTED_USERID).val('');
                $(SELECTORS.SELECTED_LABEL).text('');
                $(SELECTORS.SELECTED_TENANT).val('');
                $(SELECTORS.REASON).val('');
                $(SELECTORS.EXPIRES).val('');
            });

            $(document).on('click', '[data-action="revoke"]', function() {
                var $btn = $(this);
                confirmRevoke(
                    $btn.attr('data-userid'),
                    $btn.attr('data-fullname'),
                    $btn.attr('data-sourcetenant')
                );
            });
        }
    };
});