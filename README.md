# Paguro ChatBot 🦀
**Versione Corrente:** 3.0.7 (Stable Safe Mode)
**Stack:** WordPress Plugin (PHP) + FastAPI (Python) + Ollama (LLM) + InfluxDB

## 📖 Descrizione del Progetto
Paguro ChatBot è un sistema di prenotazione ibrido (Chatbot AI + Interfaccia Web) per la gestione di appartamenti turistici ("Villa Celi").
Il sistema gestisce disponibilità, preventivi, concorrenza tra utenti (Race Condition) e caricamento delle distinte di pagamento in modo sicuro.

---

## ⚙️ Architettura Tecnica

### 1. WordPress Plugin (Frontend & Logic)
* **Path:** `plugin/`
* **Files Chiave:**
    * `paguro-chatbot.php`: Core logic, DB management, Shortcodes, Security hooks.
    * `admin-page.php`: Pannello di controllo Admin (Timeline, Settings, Gestione Prenotazioni).
    * `paguro-front.js`: Gestione Chat UI, AJAX calls, Upload handler.
* **Database (Custom Tables):**
    * `wp_paguro_apartments`: ID, Nome, Prezzo Base, Pricing JSON (prezzi settimanali).
    * `wp_paguro_availability`: Gestisce le prenotazioni, i lock temporanei e i dati ospiti.

### 2. Backend AI (Python)
* **Path:** `app/main.py`
* **Tecnologia:** FastAPI.
* **Ruolo:** Riceve i messaggi della chat, interroga Ollama (LLM) per risposte conversazionali o restituisce azioni strutturate (JSON) per la verifica disponibilità.
* **Logging:** Scrive metriche su InfluxDB (`idb.viamerano24.it`).

---

## 🔒 Logica di Business e Sicurezza

### 1. Flusso di Prenotazione
1.  **Chat:** L'utente chiede disponibilità. Il bot risponde (solo Sabato-Sabato).
2.  **Lock:** L'utente clicca "Prenota".
    * Il sistema verifica se le date sono **Confermate (Status 1)**. Se sì → Errore "Occupato".
    * Se le date sono libere o solo "In attesa" (Status 2) → Crea un Token e redirige al Form.
3.  **Checkout:** L'utente compila i dati (Nome, Email, Telefono) nello slug `/prenotazione/`.
4.  **Riepilogo:** L'utente atterra su `/riepilogo-prenotazione/`.
    * Vede i dati bancari (IBAN) e l'importo.
    * Carica la distinta (PDF/JPG).

### 2. Gestione Concorrenza (Race Condition "First-to-Pay")
Il sistema permette a più utenti di avere preventivi aperti per le stesse date.
* **Status 2 (Pending):** Più utenti possono averlo contemporaneamente.
* **Alert UI:** Nel riepilogo appare un avviso giallo: *"⚡ Affrettati! Altre X richieste in corso"*.
* **Winner:** Il primo che carica la distinta e viene confermato dall'Admin vince.
* **Loser:** Gli altri ricevono una mail di "Priorità Persa" e vedono un box rosso nel riepilogo con opzione per rimborso (gestione telefonica).

### 3. Sicurezza Implementata (v3.0.7)
* **Anti-Cache:** Headers `nocache` forzati su tutte le pagine con `?token=...`.
* **Upload:** Verifica Magic Bytes (MIME reale) ed estensione file. Rename con hash MD5.
* **Auth:** Accesso alle pagine di riepilogo tramite Cookie sicuro (`wp_hash`). Se manca il cookie, appare form di login (Email check).
* **GDPR:** Funzione "Anonimizza" (non cancella il record finanziario, ma obfusca i dati personali PII).

---

## 🛠️ Configurazione WordPress

### Shortcodes
Da inserire nelle rispettive pagine WP:
1.  `[paguro_chat]` : Widget Chat (Footer/Home).
2.  `[paguro_checkout]` : Pagina modulo dati (Slug default: `prenotazione`).
3.  `[paguro_summary]` : Pagina upload/riepilogo (Slug default: `riepilogo-prenotazione`).

### Pannello Admin > Configurazione
Campi essenziali da configurare al primo avvio:
* **API URL:** Endpoint del backend Python.
* **Date Stagione:** Inizio/Fine (YYYY-MM-DD).
* **Dati Bancari:** IBAN e Intestatario (usati nei template email).
* **Slug Pagine:** Inserire gli slug delle pagine create per far funzionare i redirect.
* **Template Email:** Tutti i testi delle notifiche sono editabili qui (supportano `{guest_phone}`, `{iban}`, ecc.).

---

## 📝 Note per lo Sviluppatore (Next Steps)
* **Fix recenti:** Risolto problema visualizzazione HTML nelle mail admin, logica concorrenza rilassata, gestione upload UI.
* **TNR (Test):** Verificare sempre il flusso completo (Chat -> Form -> Upload -> Mail Admin) dopo ogni modifica.
* **Deploy:** Ricordare di configurare `.env` per i servizi Python/InfluxDB.
