jQuery(document).ready(function($) {
    // VARIABILI GLOBALI
    var $testButton = $('#paguro-test-btn');
    var $testResult = $('#paguro-test-result');
    var $apartmentTableBody = $('#paguro-apartment-table tbody');
    var $apartmentSelect = $('#paguro-apartment-select');
    var $occupationList = $('#paguro-occupation-list');
    var $occupationsArea = $('#paguro-occupations-area');

    // Mappa per associare ID appartamento al nome
    var apartmentMap = {}; 
    
    // ===============================================
    // 1. GESTIONE CONNESSIONE (Esistente)
    // ===============================================

    $testButton.on('click', function(e) {
        e.preventDefault();
        
        var token = PaguroAdmin.token;
        var url = PaguroAdmin.url;
        
        if (!url || url.length < 10) {
            $testResult.html('<p style="color:red;">❌ Salva prima un URL Backend valido.</p>');
            return;
        }
        if (!token || token.length < 10) {
            $testResult.html('<p style="color:red;">❌ Salva prima un Token API valido e complesso.</p>');
            return;
        }

        $testResult.html('<p style="color:blue;">⏳ Esecuzione del test di connessione...</p>');
        $testButton.prop('disabled', true);

        $.ajax({
            url: PaguroAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'paguro_test_connection',
                security: PaguroAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $testResult.html('<p style="color:green;">✅ ' + response.data.message + '</p>');
                } else {
                    $testResult.html('<p style="color:red;">❌ ' + response.data.message + '</p>');
                }
            },
            error: function(xhr, status, error) {
                $testResult.html('<p style="color:red;">❌ Errore di comunicazione AJAX/Server. Controlla i log di PHP o la console del browser.</p>');
            },
            complete: function() {
                $testButton.prop('disabled', false);
            }
        });
    });


    // ===============================================
    // 2. GESTIONE APPARTAMENTI (NUOVO CRUD)
    // ===============================================

    function loadApartments() {
        var endpoint = PaguroAdmin.url + '/api/v1/admin/appartamenti';
        
        $.ajax({
            url: endpoint,
            type: 'GET',
            dataType: 'json',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Authorization', 'Bearer ' + PaguroAdmin.token);
            },
            success: function(data) {
                $apartmentTableBody.empty();
                $apartmentSelect.empty().append('<option value="">-- Seleziona un Appartamento --</option>');
                apartmentMap = {};
                
                if (data.appartamenti && data.appartamenti.length > 0) {
                    data.appartamenti.forEach(function(apt) {
                        apartmentMap[apt.id] = apt.nome;
                        
                        $apartmentTableBody.append(
                            '<tr>' +
                                '<td>' + apt.id + '</td>' +
                                '<td>' + apt.nome + '</td>' +
                                '<td>' + apt.max_ospiti + '</td>' +
                                '<td><button class="button button-small paguro-delete-apt" data-id="' + apt.id + '">Elimina</button></td>' +
                            '</tr>'
                        );
                        
                        $apartmentSelect.append(
                            '<option value="' + apt.id + '">' + apt.id + ' - ' + apt.nome + '</option>'
                        );
                    });
                } else {
                    $apartmentTableBody.append('<tr><td colspan="4">Nessun appartamento trovato.</td></tr>');
                }
            },
            error: function() {
                alert("Errore nel caricamento degli appartamenti. Controlla il Token API e la connessione BE.");
            }
        });
    }

    $('#paguro-add-apartment-form').on('submit', function(e) {
        e.preventDefault(); // PRIMA prevenzione
        
        var nome = $('#apt-name').val();
        var ospiti = $('#apt-guests').val();
        
        var endpoint = PaguroAdmin.url + '/api/v1/admin/appartamenti';

        $.ajax({
            url: endpoint,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ nome: nome, max_ospiti: ospiti }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Authorization', 'Bearer ' + PaguroAdmin.token);
            },
            statusCode: {
                201: function() {
                    alert('Appartamento aggiunto con successo!');
                    loadApartments(); // Ricarica la lista
                    $('#apt-name').val('');
                }
            },
            error: function(xhr) {
                // Gestione degli errori, inclusi 409 (Nome già esistente)
                var msg = xhr.responseJSON ? xhr.responseJSON.error : 
                          xhr.statusText ? "Errore HTTP " + xhr.status + ": " + xhr.statusText : 
                          "Errore sconosciuto (Controlla i log del server API).";
                alert("Errore nell'aggiunta dell'appartamento: " + msg);
            }
        });
        
        // SECONDA prevenzione (la più robusta)
        return false; 
    });

    // Eventualmente implementare la cancellazione (DELETE) qui...

    // ===============================================
    // 3. GESTIONE OCCUPAZIONI (NUOVO CRUD)
    // ===============================================

    function loadOccupations(apartmentId) {
        if (!apartmentId) return;
        
        var endpoint = PaguroAdmin.url + '/api/v1/admin/occupazioni/' + apartmentId;
        
        $('#current-apt-name').text(apartmentMap[apartmentId]);
        $occupationsArea.show();
        $occupationList.html('<tr><td colspan="4">Caricamento occupazioni...</td></tr>');

        $.ajax({
            url: endpoint,
            type: 'GET',
            dataType: 'json',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Authorization', 'Bearer ' + PaguroAdmin.token);
            },
            success: function(data) {
                $occupationList.empty();
                if (data.occupazioni && data.occupazioni.length > 0) {
                    data.occupazioni.forEach(function(occ) {
                        $occupationList.append(
                            '<tr>' +
                                '<td>' + occ.data_inizio + '</td>' +
                                '<td>' + occ.data_fine + '</td>' +
                                '<td>' + occ.status + '</td>' +
                                '<td><button class="button button-small paguro-delete-occ" data-id="' + occ.id + '">Libera</button></td>' +
                            '</tr>'
                        );
                    });
                } else {
                    $occupationList.append('<tr><td colspan="4">Nessuna occupazione registrata.</td></tr>');
                }
            },
            error: function() {
                alert("Errore nel caricamento delle occupazioni.");
                $occupationList.html('<tr><td colspan="4">Errore di comunicazione con il Backend.</td></tr>');
            }
        });
    }

    $apartmentSelect.on('change', function() {
        var aptId = $(this).val();
        if (aptId) {
            loadOccupations(aptId);
        } else {
            $occupationsArea.hide();
        }
    });


    $('#paguro-add-occupation-form').on('submit', function(e) {
        e.preventDefault();
        var aptId = $apartmentSelect.val();
        var start = $('#occ-start').val();
        var end = $('#occ-end').val();
        
        if (!aptId) {
             alert('Seleziona un appartamento.');
             return false;
        }

        var endpoint = PaguroAdmin.url + '/api/v1/admin/occupazioni/' + aptId;

        $.ajax({
            url: endpoint,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ data_inizio: start, data_fine: end }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('Authorization', 'Bearer ' + PaguroAdmin.token);
            },
            statusCode: {
                201: function() {
                    alert('Occupazione aggiunta con successo!');
                    loadOccupations(aptId); // Ricarica la lista
                }
            },
            error: function(xhr) {
                var msg = xhr.responseJSON ? xhr.responseJSON.error : "Errore generico. Controlla che le date siano Sabato/Sabato e non si sovrappongano.";
                alert("Errore nell'aggiunta dell'occupazione: " + msg);
            }
        });
        
        return false;
    });


    // ===============================================
    // 4. INIZIALIZZAZIONE
    // ===============================================

    // Esegui la funzione di caricamento degli appartamenti quando l'amministrazione è pronta
    if ($('#paguro-apartment-table').length) {
        loadApartments();
    }
});