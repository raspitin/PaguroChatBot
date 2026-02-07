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
                                alert("⚠️ " + errMsgRaw);
                                btn.text(oldTxt).prop('disabled', false);
                            });
                            return;
                        }
                        alert("⚠️ " + errMsgRaw);
                        btn.text(oldTxt).prop('disabled', false);
                    }
                }).fail(function() {
                    alert("❌ Errore di connessione");
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
