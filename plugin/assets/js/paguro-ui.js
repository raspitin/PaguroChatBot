/**
 * PAGURO UI OVERLAY
 * Gestisce messaggi e conferme in overlay
 */

jQuery(document).ready(function($) {
    const data = window.paguroData || {
        ajax_url: window.ajaxurl || '/wp-admin/admin-ajax.php',
        nonce: '',
        analytics_action: 'paguro_track_event'
    };

    function randomSessionChunk() {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var buffer = new Uint32Array(2);
            window.crypto.getRandomValues(buffer);
            return Array.prototype.map.call(buffer, function(part) {
                return part.toString(16);
            }).join('');
        }
        return Math.random().toString(16).slice(2) + Date.now().toString(16);
    }

    function buildSessionId() {
        return 'paguro_' + Date.now().toString(36) + '_' + randomSessionChunk().slice(0, 16);
    }

    function getSessionId() {
        var key = 'paguro_session_id';
        try {
            var existing = window.localStorage.getItem(key);
            if (existing) return existing;
            var next = buildSessionId();
            window.localStorage.setItem(key, next);
            return next;
        } catch (err) {
            if (window.paguroSessionId) return window.paguroSessionId;
            window.paguroSessionId = buildSessionId();
            return window.paguroSessionId;
        }
    }

    function trackEvent(eventName, payload) {
        if (!data.ajax_url || !data.nonce || !eventName) return;

        var body = $.extend({
            session_id: getSessionId(),
            page_path: window.location.pathname || '',
            page_title: document.title || '',
            referrer: document.referrer || '',
            viewport_width: window.innerWidth || 0
        }, payload || {});

        $.ajax({
            url: data.ajax_url,
            type: 'POST',
            data: {
                action: data.analytics_action || 'paguro_track_event',
                nonce: data.nonce,
                event_name: eventName,
                payload: JSON.stringify(body)
            },
            timeout: 2500
        });
    }

    window.paguroAnalytics = {
        getSessionId: getSessionId,
        track: trackEvent
    };

    function ensureOverlay() {
        var existing = $('#paguro-overlay');
        if (existing.length) return existing;

        var html = '' +
            '<div id="paguro-overlay" class="paguro-overlay" aria-hidden="true">' +
            '  <div class="paguro-overlay-backdrop" aria-hidden="true"></div>' +
            '  <div class="paguro-overlay-card" role="dialog" aria-modal="true">' +
            '    <button type="button" class="paguro-overlay-close" aria-label="Chiudi">×</button>' +
            '    <div class="paguro-overlay-content"></div>' +
            '    <div class="paguro-overlay-actions"></div>' +
            '  </div>' +
            '</div>';

        $('body').append(html);
        var overlay = $('#paguro-overlay');

        overlay.on('click', '.paguro-overlay-close, .paguro-overlay-backdrop', function() {
            if (overlay.hasClass('is-locked')) return;
            closeOverlay();
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && overlay.hasClass('is-open') && !overlay.hasClass('is-locked')) {
                closeOverlay();
            }
        });

        return overlay;
    }

    function openOverlay(opts) {
        opts = opts || {};
        var overlay = ensureOverlay();
        var card = overlay.find('.paguro-overlay-card');
        var content = overlay.find('.paguro-overlay-content');
        var actions = overlay.find('.paguro-overlay-actions');

        overlay.removeClass('is-receipt is-loading is-locked');
        card.attr('style', '');
        content.html(opts.content || '');
        actions.html(opts.actions || '');

        if (opts.mode === 'receipt') {
            overlay.addClass('is-receipt');
        }
        if (opts.mode === 'loading') {
            overlay.addClass('is-loading');
        }
        if (opts.locked) {
            overlay.addClass('is-locked');
        }

        overlay.addClass('is-open').attr('aria-hidden', 'false');
    }

    function closeOverlay() {
        var overlay = $('#paguro-overlay');
        if (!overlay.length) return;
        overlay.removeClass('is-open is-receipt is-loading is-locked').attr('aria-hidden', 'true');
        overlay.find('.paguro-overlay-content').html('');
        overlay.find('.paguro-overlay-actions').html('');
    }

    window.paguroUi = {
        showMessage: function(message, type) {
            var safe = $('<div>').text(message || '').html();
            var msgClass = type ? ' paguro-overlay-message--' + type : '';
            var content = '<div class="paguro-overlay-message' + msgClass + '">' + safe + '</div>';
            var actions = '<button type="button" class="paguro-btn paguro-btn-primary paguro-overlay-ok">OK</button>';

            openOverlay({ content: content, actions: actions });

            $('#paguro-overlay').one('click', '.paguro-overlay-ok', function() {
                closeOverlay();
            });
        },

        showConfirm: function(message, onConfirm, onCancel) {
            var safe = $('<div>').text(message || '').html().replace(/\n/g, '<br>');
            var content = '<div class="paguro-overlay-message">' + safe + '</div>';
            var actions = '' +
                '<button type="button" class="paguro-btn paguro-btn-secondary paguro-overlay-cancel">Annulla</button>' +
                '<button type="button" class="paguro-btn paguro-btn-primary paguro-overlay-confirm">Conferma</button>';

            openOverlay({ content: content, actions: actions });

            var overlay = $('#paguro-overlay');
            overlay.off('click.paguroConfirm');
            overlay.on('click.paguroConfirm', '.paguro-overlay-confirm', function() {
                closeOverlay();
                if (typeof onConfirm === 'function') onConfirm();
            });
            overlay.on('click.paguroConfirm', '.paguro-overlay-cancel', function() {
                closeOverlay();
                if (typeof onCancel === 'function') onCancel();
            });
        },

        showReceipt: function(url) {
            if (!url) return;
            var safeUrl = $('<div>').text(url).text();
            var content = '<iframe class="paguro-overlay-frame" src="' + safeUrl + '" loading="lazy"></iframe>';
            var actions = '<button type="button" class="paguro-btn paguro-btn-secondary paguro-overlay-close-btn">Chiudi</button>';

            openOverlay({ content: content, actions: actions, mode: 'receipt' });

            $('#paguro-overlay').one('click', '.paguro-overlay-close-btn', function() {
                closeOverlay();
            });
        },

        showLoading: function(message) {
            var safe = $('<div>').text(message || '').html();
            var content = '' +
                '<div class="paguro-overlay-loading">' +
                '  <span class="paguro-overlay-spinner" aria-hidden="true"></span>' +
                '  <div class="paguro-overlay-message">' + safe + '</div>' +
                '</div>';
            openOverlay({ content: content, actions: '', mode: 'loading', locked: true });
        },

        close: function() {
            closeOverlay();
        }
    };

    $(document).on('click', '.paguro-receipt-link', function(e) {
        var url = $(this).data('receipt-url') || $(this).attr('href');
        if (!url) return;
        e.preventDefault();
        window.paguroUi.showReceipt(url);
    });
});
