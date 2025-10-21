import requests
import json
import sys
import os

# ----------------------------------------------------------------------
# Configurazione Test
# ----------------------------------------------------------------------

# L'API è esposta sulla porta 5000 dell'host Ubuntu tramite Docker Compose.
BASE_URL = "http://localhost:5000"
API_ENDPOINT = "/api/v1/ollama/query"
FULL_URL = f"{BASE_URL}{API_ENDPOINT}"

# La query deve essere non attinente alla prenotazione per forzare il fallback Ollama.
TEST_QUERY = "Ciao, parlami un po' della villa Celi."

print("=====================================================")
print("🤖 Test di Connessione Backend API -> Servizio Ollama")
print("=====================================================")
print(f"URL di destinazione: {FULL_URL}")
print(f"Query di test: '{TEST_QUERY}'")
print("-----------------------------------------------------")

# ----------------------------------------------------------------------
# Esecuzione della Richiesta
# ----------------------------------------------------------------------

payload = {
    "query": TEST_QUERY
}
headers = {
    "Content-Type": "application/json"
}

try:
    # Esegue la richiesta POST
    response = requests.post(FULL_URL, headers=headers, data=json.dumps(payload), timeout=60)
    
    # Stampa lo stato HTTP
    print(f"Codice di Risposta HTTP: {response.status_code}")
    
    # Tenta di decodificare la risposta JSON
    try:
        response_json = response.json()
    except json.JSONDecodeError:
        print("❌ Errore: La risposta non è un JSON valido.")
        print(f"Risposta raw: {response.text}")
        sys.exit(1)

    # Verifica il successo
    if response.status_code == 200:
        print("✅ Successo! L'API ha risposto e Ollama ha elaborato.")
        
        # Stampa i contenuti chiave (response_text e redirect)
        print("\nContenuto della Risposta (Estratto Ollama):")
        print(f"  Testo Principale (ollama): {response_json.get('response_text', 'N/A')}")
        print(f"  Testo di Reindirizzamento: {response_json.get('redirect', 'N/A')}")
        
    elif response.status_code == 503:
        # Codice restituito dal tuo app.py se Ollama non è raggiungibile
        print("⚠️ FALLIMENTO (503): Il servizio Ollama è irraggiungibile.")
        print(f"Messaggio di errore: {response_json.get('message', 'N/A')}")
        
    else:
        # Altri errori HTTP
        print(f"❌ FALLIMENTO ({response.status_code}): Errore inatteso dall'API.")
        print(f"Dettagli: {response_json}")

except requests.exceptions.ConnectionError:
    print("❌ Errore di Connessione: Assicurati che Docker sia in esecuzione e che il container 'paguro_api_service' sia attivo sulla porta 5000.")
    print("Eseguire 'docker compose ps' per verificare lo stato dei servizi.")
except Exception as e:
    print(f"❌ Errore Imprevisto: {e}")

print("=====================================================")
