/**
 * PAGURO FORM MODULE
 * Gestisce l'invio dei form (quotazione e waitlist)
 */

jQuery(document).ready(function($) {
    // Safe fallback if localized data is missing
    const data = window.paguroData || {
        ajax_url: window.ajaxurl || '/wp-admin/admin-ajax.php',
        nonce: '',
        booking_url: '/',
        icon_url: '',
        recaptcha_site: '',
        msgs: {}
    };
    function showOverlayMessage(msg, type) {
        if (window.paguroUi && typeof window.paguroUi.showMessage === 'function') {
            window.paguroUi.showMessage(msg, type);
        } else {
            alert(msg);
        }
    }

    function refreshNonce() {
        return $.post(data.ajax_url, { action: 'paguro_refresh_nonce' }).then(function(res) {
            if (res && res.success && res.data && res.data.nonce) {
                data.nonce = res.data.nonce;
                return data.nonce;
            }
            throw new Error('Nonce refresh failed');
        });
    }

    function withRecaptcha(action, done) {
        if (data.recaptcha_site && typeof grecaptcha !== 'undefined') {
            grecaptcha.ready(function() {
                grecaptcha.execute(data.recaptcha_site, { action: action }).then(function(token) {
                    done(token);
                }).catch(function() {
                    done('');
                });
            });
        } else {
            done('');
        }
    }

    // =========================================================
    // MULTI-WEEK SELECTION (CHAT)
    // =========================================================

    var multiState = {
        apt: null,
        selections: []
    };

    function selectionKey(item) {
        return item.in + '|' + item.out;
    }

    function normalizeSelectionList() {
        var seen = {};
        var out = [];
        multiState.selections.forEach(function(item) {
            var key = selectionKey(item);
            if (seen[key]) return;
            seen[key] = true;
            out.push(item);
        });
        multiState.selections = out;
    }

    function getPanelFromElement(el) {
        return $(el).closest('.paguro-chat-window, .paguro-chat-root').find('.paguro-multi-panel').last();
    }

    function updateMultiPanel(panel) {
        if (!panel || panel.length === 0) return;
        normalizeSelectionList();
        var list = panel.find('.paguro-multi-selected');
        if (multiState.selections.length === 0) {
            list.text('Nessuna settimana selezionata.');
            panel.find('.paguro-multi-confirm').addClass('is-disabled');
            return;
        }
        var html = '<ul>';
        multiState.selections.forEach(function(item) {
            html += '<li>' + item.in + ' - ' + item.out + '</li>';
        });
        html += '</ul>';
        list.html(html);
        panel.find('.paguro-multi-confirm').removeClass('is-disabled');
    }

    function appendBotMsg(chatBody, html) {
        var safeHtml = (typeof html === 'string') ? html : '';
        chatBody.append(
            '<div class="paguro-msg paguro-msg-bot">' +
            '<img src="' + data.icon_url + '" class="paguro-bot-avatar" alt="Paguro">' +
            '<div class="paguro-msg-content">' + safeHtml + '</div>' +
            '</div>'
        );
        chatBody.scrollTop(chatBody[0].scrollHeight);
    }

    function clearSelections(panel) {
        multiState.selections = [];
        multiState.apt = null;
        $('.paguro-week-toggle.is-selected').removeClass('is-selected').text('[Seleziona]');
        updateMultiPanel(panel);
    }

    $(document).on('click', '.paguro-week-toggle', function(e) {
        e.preventDefault();
        var btn = $(this);
        var apt = btn.data('apt');
        var dateIn = btn.data('in');
        var dateOut = btn.data('out');
        if (!apt || !dateIn || !dateOut) return;

        var panel = getPanelFromElement(btn);

        if (multiState.apt && multiState.apt !== apt) {
            showOverlayMessage('Le settimane devono appartenere allo stesso appartamento. Selezione azzerata.', 'warning');
            clearSelections(panel);
        }

        multiState.apt = apt;
        var key = dateIn + '|' + dateOut;
        var existingIndex = -1;
        for (var i = 0; i < multiState.selections.length; i++) {
            if (selectionKey(multiState.selections[i]) === key) {
                existingIndex = i;
                break;
            }
        }
        if (existingIndex >= 0) {
            multiState.selections.splice(existingIndex, 1);
            btn.removeClass('is-selected').text('[Seleziona]');
        } else {
            multiState.selections.push({ in: dateIn, out: dateOut });
            btn.addClass('is-selected').text('[Selezionata]');
        }
        updateMultiPanel(panel);
    });

    $(document).on('click', '.paguro-multi-confirm', function(e) {
        e.preventDefault();
        var btn = $(this);
        if (btn.hasClass('is-disabled')) return;
        if (!multiState.selections.length || !multiState.apt) {
            showOverlayMessage('Seleziona almeno una settimana prima di confermare.', 'warning');
            return;
        }

        var triedRefresh = false;
        var chatRoot = btn.closest('.paguro-chat-window, .paguro-chat-root');
        var chatBody = chatRoot.find('.paguro-chat-body');

        function sendMultiLock() {
            withRecaptcha('paguro_lock_multi', function(token) {
                $.post(data.ajax_url, {
                    action: 'paguro_lock_dates_multi',
                    nonce: data.nonce,
                    recaptcha_token: token || '',
                    apt_name: multiState.apt,
                    dates: JSON.stringify(multiState.selections)
                }, function(res) {
                    if (res.success) {
                        var targetUrl = res.data.redirect_url ? res.data.redirect_url : (data.booking_url + res.data.redirect_params);
                        window.location.href = targetUrl;
                    } else {
                        var errMsgRaw = res.data && res.data.msg ? res.data.msg : "Errore";
                        if (!triedRefresh && /sessione scaduta/i.test(errMsgRaw)) {
                            triedRefresh = true;
                            refreshNonce().then(sendMultiLock).catch(function() {
                                showOverlayMessage("⚠️ " + errMsgRaw, 'error');
                            });
                            return;
                        }
                        if (res.data && res.data.unavailable && Array.isArray(res.data.unavailable)) {
                            res.data.unavailable.forEach(function(u) {
                                var key = (u.in_raw || u.in) + '|' + (u.out_raw || u.out);
                                multiState.selections = multiState.selections.filter(function(s) {
                                    return selectionKey(s) !== key;
                                });
                                $('.paguro-week-toggle.is-selected').each(function() {
                                    var t = $(this);
                                    if (t.data('in') === u.in_raw || t.data('in') === u.in) {
                                        t.removeClass('is-selected').text('[Seleziona]');
                                    }
                                });
                            });
                            updateMultiPanel(getPanelFromElement(btn));
                        }
                        if (res.data && res.data.suggestions_html) {
                            appendBotMsg(chatBody, res.data.suggestions_html);
                        }
                        showOverlayMessage("⚠️ " + errMsgRaw, 'error');
                    }
                }).fail(function() {
                    showOverlayMessage("❌ Errore di connessione", 'error');
                });
            });
        }

        if (!data.nonce) {
            refreshNonce().then(sendMultiLock).catch(sendMultiLock);
        } else {
            sendMultiLock();
        }
    });

    // =========================================================
    // QUOTE/WAITLIST FORM SUBMIT
    // =========================================================

    $(document).on('submit', '#paguro-quote-form, #paguro-waitlist-form, #paguro-native-form', function(e) {
        e.preventDefault();

        var form = $(this);
        var btn = form.find('#paguro-submit-btn');
        var msgDiv = form.find('#paguro-form-msg');

        btn.prop('disabled', true);
        msgDiv.html('').removeClass('error success');

        var triedRefresh = false;

        function postForm(formData) {
            $.post(data.ajax_url, formData, function(res) {
                if (res.success) {
                    msgDiv
                        .html('<span class="success">✅ ' + (data.msgs.form_success || 'Richiesta inviata!') + '</span>')
                        .addClass('success');

                    // Redirect dopo 1.5 secondi
                    setTimeout(function() {
                        window.location.href = res.data.redirect;
                    }, 1500);
                } else {
                    var errMsgRaw = res.data && res.data.msg ? res.data.msg : "Errore sconosciuto";
                    if (!triedRefresh && /sessione scaduta/i.test(errMsgRaw)) {
                        triedRefresh = true;
                        refreshNonce().then(sendForm).catch(function() {
                            var errMsg = $('<div/>').text(errMsgRaw).html();
                            msgDiv.html('<span class="error">❌ ' + errMsg + '</span>').addClass('error');
                            btn.prop('disabled', false);
                        });
                        return;
                    }
                    // Escape user input per evitare XSS
                    var errMsg = $('<div/>').text(errMsgRaw).html();
                    msgDiv
                        .html('<span class="error">❌ ' + errMsg + '</span>')
                        .addClass('error');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                msgDiv
                    .html('<span class="error">❌ Errore di connessione</span>')
                    .addClass('error');
                btn.prop('disabled', false);
            });
        }

        function sendForm() {
            var baseData = form.serialize() + '&action=paguro_submit_booking&nonce=' + data.nonce;
            withRecaptcha('paguro_booking', function(token) {
                var payload = baseData + (token ? '&recaptcha_token=' + encodeURIComponent(token) : '');
                postForm(payload);
            });
        }

        if (!data.nonce) {
            refreshNonce().then(sendForm).catch(sendForm);
        } else {
            sendForm();
        }
    });

    // =========================================================
    // BOOK FROM CHAT (Lock Dates - Quote)
    // =========================================================

    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var oldTxt = btn.text();
        btn.text(data.msgs.btn_locking).prop('disabled', true);

        var triedRefresh = false;

        function sendLock() {
            withRecaptcha('paguro_lock', function(token) {
                $.post(data.ajax_url, {
                    action: 'paguro_lock_dates',
                    nonce: data.nonce,
                    recaptcha_token: token || '',
                    apt_name: btn.data('apt'),
                    date_in: btn.data('in'),
                    date_out: btn.data('out')
                }, function(res) {
                    if (res.success) {
                        btn.text('Apro il form...');
                        var targetUrl = res.data.redirect_url ? res.data.redirect_url : (data.booking_url + res.data.redirect_params);
                        window.location.href = targetUrl;
                    } else {
                        var errMsgRaw = res.data && res.data.msg ? res.data.msg : "Errore";
                        if (!triedRefresh && /sessione scaduta/i.test(errMsgRaw)) {
                            triedRefresh = true;
                            refreshNonce().then(sendLock).catch(function() {
                                var errMsg = $('<div/>').text(errMsgRaw).html();
                                var msgDiv = $('<div class="paguro-form-message error">⚠️ ' + errMsg + '</div>');
                                btn.before(msgDiv);
                                btn.text(oldTxt).prop('disabled', false);
                            });
                            return;
                        }
                        var errMsg = $('<div/>').text(errMsgRaw).html();
                        var msgDiv = $('<div class="paguro-form-message error">⚠️ ' + errMsg + '</div>');
                        btn.before(msgDiv);
                        btn.text(oldTxt).prop('disabled', false);
                    }
                }).fail(function() {
                    var msgDiv = $('<div class="paguro-form-message error">❌ Errore di connessione</div>');
                    btn.before(msgDiv);
                    btn.text(oldTxt).prop('disabled', false);
                });
            });
        }

        if (!data.nonce) {
            refreshNonce().then(sendLock).catch(sendLock);
        } else {
            sendLock();
        }
    });

    // =========================================================
    // WAITLIST FROM CHAT
    // =========================================================

    $(document).on('click', '.paguro-waitlist-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var oldTxt = btn.text();
        btn.text('⏳ ...').prop('disabled', true);

        var triedRefresh = false;

        function sendWaitlist() {
            withRecaptcha('paguro_waitlist', function(token) {
                $.post(data.ajax_url, {
                    action: 'paguro_start_waitlist',
                    nonce: data.nonce,
                    recaptcha_token: token || '',
                    apt_name: btn.data('apt'),
                    date_in: btn.data('in'),
                    date_out: btn.data('out')
                }, function(res) {
                    if (res.success) {
                        window.location.href = data.booking_url + res.data.redirect_params;
                    } else {
                        var errMsgRaw = res.data && res.data.msg ? res.data.msg : "Errore";
                        if (!triedRefresh && /sessione scaduta/i.test(errMsgRaw)) {
                            triedRefresh = true;
                            refreshNonce().then(sendWaitlist).catch(function() {
                                showOverlayMessage("⚠️ " + errMsgRaw, 'error');
                                btn.text(oldTxt).prop('disabled', false);
                            });
                            return;
                        }
                        showOverlayMessage("⚠️ " + errMsgRaw, 'error');
                        btn.text(oldTxt).prop('disabled', false);
                    }
                }).fail(function() {
                    showOverlayMessage("❌ Errore di connessione", 'error');
                    btn.text(oldTxt).prop('disabled', false);
                });
            });
        }

        if (!data.nonce) {
            refreshNonce().then(sendWaitlist).catch(sendWaitlist);
        } else {
            sendWaitlist();
        }
    });

});
