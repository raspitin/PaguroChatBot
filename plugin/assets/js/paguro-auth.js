/**
 * PAGURO AUTH MODULE
 * Gestisce l'autenticazione e l'accesso alle aree riservate
 */

jQuery(document).ready(function($) {
    const data = window.paguroData || { msgs: {} };

    // =========================================================
    // LOGIN FORM
    // =========================================================

    $(document).on('submit', '#paguro-auth-form', function(e) {
        // Form si invia normalmente (POST)
        // Il PHP handle con wp_redirect
    });

    // =========================================================
    // CANCELLATION FROM SUMMARY
    // =========================================================

    $(document).on('submit', '#paguro-cancel-booking-form', function(e) {
        var form = $(this);
        if (form.data('paguro-confirmed')) {
            return;
        }
        var iban = $.trim(form.find('[name="customer_iban"]').val() || '');
        iban = iban.replace(/\s+/g, '').toUpperCase();
        if (!iban) {
            if (window.paguroUi && typeof window.paguroUi.showMessage === 'function') {
                window.paguroUi.showMessage(data.msgs.iban_required || 'Inserisci un IBAN valido.', 'error');
            } else {
                alert(data.msgs.iban_required || 'Inserisci un IBAN valido.');
            }
            form.find('[name="customer_iban"]').focus();
            e.preventDefault();
            return;
        }
        var ibanRegex = /^[A-Z]{2}[0-9A-Z]{13,32}$/;
        if (!ibanRegex.test(iban) || (iban.indexOf('IT') === 0 && iban.length !== 27)) {
            if (window.paguroUi && typeof window.paguroUi.showMessage === 'function') {
                window.paguroUi.showMessage(data.msgs.iban_invalid || 'IBAN non valido.', 'error');
            } else {
                alert(data.msgs.iban_invalid || 'IBAN non valido.');
            }
            form.find('[name="customer_iban"]').focus();
            e.preventDefault();
            return;
        }
        form.find('[name="customer_iban"]').val(iban);

        var msg = data.msgs.cancel_confirm || "Confermi la richiesta di cancellazione? Rimborso sul conto indicato.";
        e.preventDefault();
        if (window.paguroUi && typeof window.paguroUi.showConfirm === 'function') {
            window.paguroUi.showConfirm(msg, function() {
                form.data('paguro-confirmed', true);
                form.trigger('submit');
            });
        } else if (confirm(msg)) {
            form.data('paguro-confirmed', true);
            form.trigger('submit');
        }
    });

    $(document).on('submit', '#paguro-cancel-waitlist-form', function(e) {
        var form = $(this);
        if (form.data('paguro-confirmed')) {
            return;
        }

        var msg = data.msgs.waitlist_exit_confirm || "Confermi l'uscita dalla lista d'attesa?";
        e.preventDefault();
        var doSubmit = function() {
            var btn = $('#paguro-cancel-waitlist-btn');
            var msgDiv = $('#paguro-cancel-msg');
            btn.prop('disabled', true);
            msgDiv.html('Elaborazione...');
            form.data('paguro-confirmed', true);
            form.trigger('submit');
        };
        if (window.paguroUi && typeof window.paguroUi.showConfirm === 'function') {
            window.paguroUi.showConfirm(msg, doSubmit);
        } else if (confirm(msg)) {
            doSubmit();
        } else {
            return;
        }
    });

});
