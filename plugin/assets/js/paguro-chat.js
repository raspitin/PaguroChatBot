/**
 * PAGURO CHAT MODULE
 * Gestisce il widget chat e la comunicazione con il bot
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

    // =========================================================
    // CHAT WIDGET CONTROLS
    // =========================================================

    $(document).on('click', '.paguro-chat-launcher, .close-btn', function() {
        var root = $(this).closest('.paguro-chat-root');
        var windowChat = root.find('.paguro-chat-window');
        if (root.hasClass('paguro-mode-widget')) windowChat.fadeToggle();
        else windowChat.toggle();
    });

    // =========================================================
    // CHAT HELPERS
    // =========================================================

    function scrollToBottom(chatBody) {
        chatBody.scrollTop(chatBody[0].scrollHeight);
    }

    function escapeHtml(text) {
        return $('<div/>').text(text).html();
    }

    function appendUserMsg(chatBody, text) {
        var safeText = escapeHtml(text);
        chatBody.append(
            '<div class="paguro-msg paguro-msg-user">' +
            '<div class="paguro-msg-content">' + safeText + '</div>' +
            '</div>'
        );
        scrollToBottom(chatBody);
    }

    function appendBotMsg(chatBody, html) {
        var safeHtml = (typeof html === 'string') ? html : '';
        chatBody.append(
            '<div class="paguro-msg paguro-msg-bot">' +
            '<img src="' + data.icon_url + '" class="paguro-bot-avatar" alt="Paguro">' +
            '<div class="paguro-msg-content">' + safeHtml + '</div>' +
            '</div>'
        );
        scrollToBottom(chatBody);
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

    // =========================================================
    // SEND MESSAGE TO BOT
    // =========================================================

    function performSend(chatWindow, msg, offset = 0, month = '', aptId = '', directAction = false) {
        if (!msg || !msg.trim()) return;

        var chatBody = chatWindow.find('.paguro-chat-body');
        if (offset === 0) appendUserMsg(chatBody, msg);

        var triedRefresh = false;

        function sendRequest() {
            $.post(data.ajax_url, {
                action: 'paguro_chat_request',
                nonce: data.nonce,
                message: msg,
                session_id: 'sess_' + Date.now(),
                offset: offset,
                filter_month: month,
                apt_id: aptId,
                direct_action: directAction ? 1 : 0
            }, function(res) {
                if (res.success) {
                    appendBotMsg(chatBody, res.data.reply);
                } else {
                    var reply = (res.data && res.data.reply) ? res.data.reply : '';
                    if (!triedRefresh && reply && reply.toLowerCase().indexOf('sessione scaduta') !== -1) {
                        triedRefresh = true;
                        refreshNonce().then(sendRequest).catch(function() {
                            appendBotMsg(chatBody, "⚠️ " + (reply || "Errore server."));
                        });
                    } else {
                        appendBotMsg(chatBody, "⚠️ " + (reply || "Errore server."));
                    }
                }
            }).fail(function() {
                appendBotMsg(chatBody, "❌ Errore di connessione.");
            });
        }

        // If nonce mancante, prova a rigenerarlo prima di inviare
        if (!data.nonce) {
            refreshNonce().then(sendRequest).catch(sendRequest);
        } else {
            sendRequest();
        }
    }

    // =========================================================
    // SEND BUTTON
    // =========================================================

    $(document).on('click', '.paguro-send-btn', function() {
        var root = $(this).closest('.paguro-chat-window, .paguro-chat-root');
        var input = root.find('.paguro-input-field');
        var txt = input.val().trim();

        if (txt) {
            performSend(root, txt);
            input.val('');
            input.focus();
        }
    });

    // =========================================================
    // ENTER KEY
    // =========================================================

    $(document).on('keypress', '.paguro-input-field', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).siblings('.paguro-send-btn').click();
        }
    });

    // =========================================================
    // QUICK BUTTONS (MONTHS)
    // =========================================================

    $(document).on('click', '.paguro-quick-btn', function(e) {
        e.preventDefault();
        var msg = $(this).data('msg');
        performSend($(this).closest('.paguro-chat-window, .paguro-chat-root'), msg, 0, '', '', true);
    });

    // =========================================================
    // LOAD MORE
    // =========================================================

    $(document).on('click', '.paguro-load-more', function(e) {
        e.preventDefault();
        $(this).hide();
        performSend(
            $(this).closest('.paguro-chat-window, .paguro-chat-root'),
            "Availability",
            $(this).data('offset'),
            $(this).data('month'),
            $(this).data('apt')
        );
    });

});
