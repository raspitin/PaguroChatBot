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
    const analytics = window.paguroAnalytics || null;

    // =========================================================
    // CHAT WIDGET CONTROLS
    // =========================================================

    $(document).on('click', '.paguro-chat-launcher, .close-btn', function() {
        var root = $(this).closest('.paguro-chat-root');
        var windowChat = root.find('.paguro-chat-window');
        if (root.hasClass('paguro-mode-widget')) windowChat.fadeToggle();
        else windowChat.toggle();
        if (analytics && typeof analytics.track === 'function' && $(this).hasClass('paguro-chat-launcher')) {
            analytics.track('chat_widget_opened', {
                page_type: 'chat_widget'
            });
        }
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

    function appendPendingBotMsg(chatBody, label) {
        var safeLabel = escapeHtml(label || 'Paguro sta verificando');
        var pendingId = 'paguro-pending-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        chatBody.append(
            '<div class="paguro-msg paguro-msg-bot paguro-msg-pending" data-pending-id="' + pendingId + '" role="status" aria-live="polite">' +
            '<img src="' + data.icon_url + '" class="paguro-bot-avatar" alt="Paguro">' +
            '<div class="paguro-msg-content">' +
            '<span class="paguro-pending-label">' + safeLabel + '</span>' +
            '<span class="paguro-typing-dots" aria-hidden="true">' +
            '<span></span><span></span><span></span>' +
            '</span>' +
            '</div>' +
            '</div>'
        );
        scrollToBottom(chatBody);
        return pendingId;
    }

    function removePendingBotMsg(chatBody, pendingId) {
        if (!pendingId) return;
        chatBody.find('.paguro-msg-pending[data-pending-id="' + pendingId + '"]').remove();
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
        var input = chatWindow.find('.paguro-input-field');
        var sendBtn = chatWindow.find('.paguro-send-btn');
        if (offset === 0) appendUserMsg(chatBody, msg);
        var pendingId = appendPendingBotMsg(chatBody, 'Paguro sta verificando');
        var sessionId = (analytics && typeof analytics.getSessionId === 'function')
            ? analytics.getSessionId()
            : ('sess_' + Date.now());

        var triedRefresh = false;
        input.prop('disabled', true);
        sendBtn.prop('disabled', true);

        function sendRequest() {
            $.post(data.ajax_url, {
                action: 'paguro_chat_request',
                nonce: data.nonce,
                message: msg,
                session_id: sessionId,
                offset: offset,
                filter_month: month,
                apt_id: aptId,
                direct_action: directAction ? 1 : 0,
                page_path: window.location.pathname || '',
                page_type: 'chat'
            }, function(res) {
                removePendingBotMsg(chatBody, pendingId);
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
                input.prop('disabled', false).focus();
                sendBtn.prop('disabled', false);
            }).fail(function() {
                removePendingBotMsg(chatBody, pendingId);
                appendBotMsg(chatBody, "❌ Errore di connessione.");
                input.prop('disabled', false).focus();
                sendBtn.prop('disabled', false);
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
