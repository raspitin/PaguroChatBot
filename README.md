# Paguro ChatBot 🤖🐚
**Versione:** 3.0.5 (Competition Mode & Middleware Logic)
**Descrizione:** Plugin WordPress ibrido per la gestione prenotazioni appartamenti estivi con supporto AI (Python/LLM) e logica "First-to-Pay".

---

## 🚀 Stato Attuale (v3.0.5)

Il sistema è in fase di **ottimizzazione UX/UI** e **stabilizzazione Core**.
È stata introdotta la logica "Middleware" nel PHP per gestire le risposte immediate (es. selezione date) senza dipendere dall'API Python, migliorando velocità e affidabilità.

### 📋 Funzionalità Principali

#### 1. Architettura Ibrida
- **Frontend (WP):** Gestisce interfaccia chat, form prenotazione, upload ricevute e area riservata.
- **Backend (Python):** (Opzionale per comandi base) Gestisce NLP avanzato per domande generiche tramite API.
- **Middleware (PHP):** Intercetta comandi specifici (Mesi, "Prenota", "Preventivo") per risposte istantanee.

#### 2. Flusso "Competition Mode" (Gara)
- Le richieste via chat creano uno stato **PENDING (Stato 2)**.
- Viene generato un **Token Univoco** valido 48 ore.
- **Race Condition:** Più utenti possono bloccare le stesse date. Il primo che carica la ricevuta del bonifico (e viene validato dall'admin) vince. Gli altri vengono cancellati/avvisati.
- **Social Pressure:** Avvisi visivi nell'area riservata indicano quanti altri utenti stanno guardando le stesse date ("🔥 Altri 3 utenti interessati...").

#### 3. Gestione Admin (Backend WP)
- **Timeline Visiva:** Calendario occupazione con colori differenziati per mese e tooltip con nomi occupanti.
- **Prenotazione Manuale:** Form dedicato per inserire prenotazioni bypassando i vincoli (es. regole Sabato-Sabato).
- **Tabella Riepilogativa:** Dettagli completi con calcolo automatico di Acconto (30%) e Saldo.
- **Azioni Rapide:** Conferma, Reinvia Mail, Notifica Rimborso, Anonimizzazione GDPR.

#### 4. UX Frontend & Area Riservata
- **Upload Migliorato:** Feedback visivo immediato al trascinamento file (Drag & Drop) e stato di caricamento.
- **Gestione Post-Prenotazione:**
  - Se ricevuta caricata: Mostra link al file e nasconde form upload.
  - **Cancellazione:** Pulsante "Richiedi Cancellazione" visibile solo se entro i termini (default 14gg dall'arrivo). Altrimenti mostra avviso di termini scaduti.

---

## 🔄 Changelog Recente

### v3.0.5 - UX Polish & Logic Fix
- **[CORE] Middleware PHP:** Aggiunto intercettore in `paguro-chatbot.php` che risponde a keyword (es. "Giugno", "Luglio") senza invocare l'API Python, risolvendo i timeout su risposte semplici.
- **[ADMIN] Inserimento Manuale:** Aggiunto box "Prenotazione Manuale" per inserimenti diretti senza vincoli di calendario.
- **[ADMIN] UI Update:** Timeline con mesi colorati pastello; Tabella con colonne Appartamento, Acconto e Saldo.
- **[FRONT] UX Upload:** Aggiunte classi CSS per animazione drag-over e spinner immediato al rilascio del file.
- **[FRONT] Area Utente:** Logica condizionale per mostrare/nascondere il pulsante di cancellazione in base alla data di arrivo.

### v3.0.4 - Admin & Stability
- Correzione pulsanti mancanti in Admin (Anonimizza, Reinvia Conferma).
- Fix visualizzazione timeline.

---

## 🛠️ Installazione & Configurazione

1. **WordPress:**
   - Copiare la cartella plugin in `/wp-content/plugins/`.
   - Attivare il plugin.
   - Configurare in *Admin > Paguro Booking > Configurazione*:
     - URL API Python (es. `https://api.tuodominio.it`).
     - Testi email e template messaggi.

2. **Python Container:**
   - Buildare l'immagine con `docker-compose up -d --build`.
   - Assicurarsi che `app/main.py` sia raggiungibile dall'indirizzo configurato in WP.

---

## 📝 Note per il prossimo sviluppo (TODO)

> **⚠️ ATTENZIONE - DA RIVEDERE:**
> **Dopo questo sviluppo (v3.0.5) è necessario rivedere urgentemente gli shortcode `[paguro_chat]` e gli altri widget in WordPress.**
> Attualmente ci sono incongruenze nel posizionamento o nel caricamento multiplo che vanno corrette.

1. **Shortcode & Widget:** Refactoring del rendering dello shortcode per evitare conflitti con temi WP.
2. **Refactoring CSS:** Pulizia finale stili per evitare sovrapposizioni con il tema padre.
3. **Test Stress:** Verificare il comportamento del Middleware sotto carico.

---

*Ultimo aggiornamento: 24/01/2026*
