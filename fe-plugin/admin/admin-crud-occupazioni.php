<div class="wrap">
    <h1>Paguro ChatBot - Gestione Appartamenti e Occupazioni</h1>
    <?php settings_errors(); ?>
    
    <div id="paguro-app-crud">
        
        <h2>Appartamenti Registrati</h2>
        <table class="wp-list-table widefat fixed striped" id="paguro-apartment-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome Appartamento</th>
                    <th>Max Ospiti</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                </tbody>
        </table>

        <div style="margin-top: 20px;">
            <h3>Aggiungi Nuovo Appartamento</h3>
            <form id="paguro-add-apartment-form">
                <input type="text" id="apt-name" placeholder="Nome (es: Corallo)" required />
                <input type="number" id="apt-guests" placeholder="Max Ospiti" value="4" required min="1" />
                <button type="submit" class="button button-primary">Aggiungi</button>
            </form>
        </div>

        <hr>

        <h2>Gestione Occupazioni</h2>
        <p>Seleziona un appartamento per vedere e gestire le sue occupazioni settimanali.</p>
        
        <label for="paguro-apartment-select">Seleziona Appartamento:</label>
        <select id="paguro-apartment-select" style="min-width: 250px;">
            <option value="">-- Seleziona un Appartamento --</option>
            </select>
        
        <div id="paguro-occupations-area" style="margin-top: 20px; display: none;">
            <h3>Occupazioni per <span id="current-apt-name"></span></h3>
            
            <form id="paguro-add-occupation-form" style="margin-bottom: 20px; padding: 10px; border: 1px solid #ccc;">
                <h4>Aggiungi Occupazione (Ingresso/Uscita Sabato)</h4>
                <input type="date" id="occ-start" required />
                <input type="date" id="occ-end" required />
                <button type="submit" class="button button-secondary">Aggiungi Occupazione</button>
                <p class="description">La data di inizio e fine devono essere un Sabato.</p>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Data Inizio</th>
                        <th>Data Fine</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody id="paguro-occupation-list">
                    </tbody>
            </table>

            <div id="paguro-calendar-view" style="margin-top: 30px;">
                 <p><em>Placeholder per il calendario FullCalendar.</em></p>
            </div>
        </div>
    </div>
</div>