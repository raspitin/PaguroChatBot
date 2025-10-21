#!/bin/bash

# =========================================================
# PaguroChatBot Backend Setup Script
# Eseguire su api.viamerano24.it (Server Ubuntu)
# =========================================================

echo "📦 Avvio del processo di setup per PaguroChatBot Backend (Docker)."

# ---------------------------------------------------------
# 1. Verifica Prerequisiti
# ---------------------------------------------------------

echo -e "\n--- 1. Verifica Prerequisiti (Docker e Docker Compose) ---"

# Verifica Docker
if ! command -v docker &> /dev/null
then
    echo "❌ Docker non trovato. Installare Docker sul server Ubuntu prima di continuare."
    exit 1
fi
echo "✅ Docker installato."

# Verifica Docker Compose (v2)
if ! command -v docker compose &> /dev/null
then
    echo "❌ Docker Compose (v2) non trovato. Assicurati che sia installato."
    exit 1
fi
echo "✅ Docker Compose installato."

# ---------------------------------------------------------
# 2. Configurazione Variabili d'Ambiente
# ---------------------------------------------------------

echo -e "\n--- 2. Configurazione Variabili d'Ambiente ---"

if [ ! -f .env ]; then
    echo "⚠️ File .env non trovato. Creazione da .env.example..."
    cp .env.example .env
    echo "✍️  Il file ./.env è stato creato. DEVI modificarlo con le tue chiavi segrete."
    echo "🚨 Arresto. Modifica ./.env e riesegui lo script."
    exit 1
fi

echo "✅ File .env trovato. Caricamento configurazione."

# Carica il Token API dal file .env (necessario per il test)
source .env
if [ -z "$PAGURO_API_TOKEN" ]; then
    echo "❌ Variabile PAGURO_API_TOKEN mancante o vuota nel file .env."
    exit 1
fi
echo "✅ Token API caricato."

# ---------------------------------------------------------
# 3. Download Modello Ollama (Opzionale, ma consigliato)
# ---------------------------------------------------------

echo -e "\n--- 3. Pre-caricamento Modello Ollama (phi3:3.8b) ---"

# Pull Ollama per garantire che il modello sia disponibile prima dell'avvio completo
docker pull ollama/ollama:latest

# Scarica il modello che verrà usato dal servizio Ollama (es. llama2)
docker run --rm -v ollama_data:/root/.ollama ollama/ollama pull phi3:3.8b
echo "✅ Modello phi3:3.8b pre-caricato in ollama_data."

# ---------------------------------------------------------
# 4. Avvio dei Servizi Docker
# ---------------------------------------------------------

echo -e "\n--- 4. Avvio dei Servizi (API, DB, Ollama) ---"

# Costruisce l'immagine API e avvia tutti i servizi in background (-d)
docker compose up --build -d
if [ $? -ne 0 ]; then
    echo "❌ Errore durante l'avvio di Docker Compose. Controlla i log."
    exit 1
fi

echo "✅ Tutti i servizi Docker sono stati avviati correttamente sulla porta 5000."

# ---------------------------------------------------------
# 5. Test di Connettività Locale
# ---------------------------------------------------------

echo -e "\n--- 5. Test di Connettività API (Porta 5000) ---"

echo "⏳ Attesa di 10 secondi per l'avvio completo dei container..."
sleep 10

TEST_URL="http://localhost:5000/api/v1/admin/status"
AUTH_HEADER="Authorization: Bearer $PAGURO_API_TOKEN"

echo "Tentativo di connessione a: $TEST_URL"

# Usa curl per chiamare l'endpoint di test che verifica sia la connettività che il Token API
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X GET -H "Content-Type: application/json" -H "$AUTH_HEADER" $TEST_URL)

if [ "$HTTP_CODE" -eq 200 ]; then
    echo "🎉 SETUP COMPLETATO CON SUCCESSO!"
    echo "   API (paguro_api) risponde correttamente sulla porta 5000."
    echo "   Token API (Bearer) accettato."
elif [ "$HTTP_CODE" -eq 401 ]; then
    echo "⚠️ L'API è raggiungibile, ma il TOKEN API non è stato accettato (HTTP 401 Unauthorized)."
    echo "   Controlla l'implementazione della verifica del Token nel tuo codice API."
else
    echo "❌ Errore di connessione o HTTP CODE inatteso ($HTTP_CODE)."
    echo "   Verifica che il tuo codice API sia in ascolto sulla porta 80 interna al container."
fi

echo -e "\nPer fermare i servizi: docker compose down"
echo "Per vedere i log: docker compose logs -f"
