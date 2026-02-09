/**
 * PAGURO UI OVERLAY
 * Gestisce messaggi e conferme in overlay
 */

jQuery(document).ready(function($) {
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
            closeOverlay();
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && overlay.hasClass('is-open')) {
                closeOverlay();
            }
        });

        return overlay;
    }

    function openOverlay(opts) {
        var overlay = ensureOverlay();
        var card = overlay.find('.paguro-overlay-card');
        var content = overlay.find('.paguro-overlay-content');
        var actions = overlay.find('.paguro-overlay-actions');

        overlay.removeClass('is-receipt');
        card.attr('style', '');
        content.html(opts.content || '');
        actions.html(opts.actions || '');

        if (opts.mode === 'receipt') {
            overlay.addClass('is-receipt');
        }

        overlay.addClass('is-open').attr('aria-hidden', 'false');
    }

    function closeOverlay() {
        var overlay = $('#paguro-overlay');
        if (!overlay.length) return;
        overlay.removeClass('is-open').attr('aria-hidden', 'true');
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
        }
    };

    $(document).on('click', '.paguro-receipt-link', function(e) {
        var url = $(this).data('receipt-url') || $(this).attr('href');
        if (!url) return;
        e.preventDefault();
        window.paguroUi.showReceipt(url);
    });
});
