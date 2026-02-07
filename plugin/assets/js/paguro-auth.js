/**
 * PAGURO AUTH MODULE
 * Gestisce l'autenticazione e l'accesso alle aree riservate
 */

jQuery(document).ready(function($) {

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
        var iban = $.trim(form.find('[name="customer_iban"]').val() || '');
        iban = iban.replace(/\s+/g, '').toUpperCase();
        if (!iban) {
            alert('Inserisci un IBAN valido per il rimborso.');
            form.find('[name="customer_iban"]').focus();
            e.preventDefault();
            return;
        }
        var ibanRegex = /^[A-Z]{2}[0-9A-Z]{13,32}$/;
        if (!ibanRegex.test(iban) || (iban.indexOf('IT') === 0 && iban.length !== 27)) {
            alert('IBAN non valido. Verifica e riprova.');
            form.find('[name="customer_iban"]').focus();
            e.preventDefault();
            return;
        }
        form.find('[name="customer_iban"]').val(iban);

        var msg = "Confermi l'invio della richiesta di cancellazione?\n" +
                  "Inviando la richiesta con l'IBAN indicato rinunci al soggiorno e, una volta confermata la cancellazione, " +
                  "il rimborso sarà disposto sul conto indicato.";
        if (!confirm(msg)) {
            e.preventDefault();
            return;
        }
    });

    $(document).on('submit', '#paguro-cancel-waitlist-form', function(e) {
        if (!confirm('Sei sicuro? Verrai rimosso dalla lista d\'attesa.')) {
            e.preventDefault();
            return;
        }

        var btn = $('#paguro-cancel-waitlist-btn');
        var msgDiv = $('#paguro-cancel-msg');

        btn.prop('disabled', true);
        msgDiv.html('⏳ Elaborazione...');
    });

});
