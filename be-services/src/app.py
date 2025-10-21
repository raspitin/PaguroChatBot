import os
import datetime
import logging
from flask import Flask, request, jsonify
from flask_cors import CORS
import requests
from db_manager import DBManager # Importa il nuovo gestore DB

app = Flask(__name__)
app.logger.setLevel(logging.ERROR) 

# --- Configurazione Globale ---
API_TOKEN = os.environ.get("API_TOKEN")
OLLAMA_HOST_ENV = os.environ.get("OLLAMA_HOST", "ollama:11434")

# Inizializza il DB Manager (tabelle create qui)
try:
    db_manager = DBManager()
except Exception as e:
    app.logger.error(f"FATAL: Impossibile connettersi o inizializzare il database: {e}")
    db_manager = None # Permette all'API di avviarsi ma fallirà sulle operazioni DB


# --- Middleware di Sicurezza: Verifica Token ---
def require_auth(f):
    def wrapper(*args, **kwargs):
        auth_header = request.headers.get("Authorization")
        if not auth_header or not auth_header.startswith("Bearer "):
            return jsonify({"error": "Unauthorized", "message": "Missing or invalid Authorization header."}), 401
        
        token = auth_header.split(" ")[1]
        if token != API_TOKEN:
            return jsonify({"error": "Forbidden", "message": "Invalid API Token."}), 403
            
        if not db_manager:
            return jsonify({"error": "DB Error", "message": "Database non connesso o inizializzato."}), 500
            
        return f(*args, **kwargs)
    wrapper.__name__ = f.__name__
    return wrapper

# Funzione Placeholder: Logica di Prenotazione (Sabato)
def is_valid_booking_period(start_date_str, end_date_str):
    """Verifica se le date di inizio e fine sono di Sabato (Sabato = 5)."""
    try:
        start_date = datetime.datetime.strptime(start_date_str, '%Y-%m-%d').date()
        end_date = datetime.datetime.strptime(end_date_str, '%Y-%m-%d').date()
        
        if start_date.weekday() != 5 or end_date.weekday() != 5:
            return False, "Le date di check-in e check-out devono essere di Sabato."
        
        if end_date <= start_date:
            return False, "La data di check-out deve essere successiva alla data di check-in."

        if (end_date - start_date).days % 7 != 0:
             return False, "La prenotazione deve coprire settimane intere (multipli di 7 giorni)."
        
        return start_date.isoformat(), end_date.isoformat(), "Periodo valido."
    except ValueError:
        return None, None, "Formato data non valido. Usa YYYY-MM-DD."


# =========================================================
# ENDPOINT PUBBLICI (Chatbot FE)
# =========================================================

@app.route("/api/v1/disponibilita", methods=["POST"])
def check_availability():
    """Verifica la disponibilità di un appartamento tramite query DB."""
    if not db_manager:
        return jsonify({"available": False, "message": "Errore interno del database."}), 500

    data = request.get_json()
    start_date_str = data.get('data_inizio')
    end_date_str = data.get('data_fine')
    apartment_id = data.get('appartamento_id')

    # 1. Verifica la regola del Sabato
    start_date, end_date, validation_message = is_valid_booking_period(start_date_str, end_date_str)
    if not start_date:
        return jsonify({"available": False, "message": validation_message}), 400

    # 2. Verifica la sovrapposizione nel DB
    is_overlapping = db_manager.check_overlap(apartment_id, start_date, end_date)

    if is_overlapping:
        return jsonify({"available": False, "message": "L'appartamento non è disponibile per il periodo selezionato (sovrapposizione).", "start": start_date, "end": end_date}), 200
    else:
        return jsonify({"available": True, "message": "Disponibile", "start": start_date, "end": end_date}), 200


@app.route("/api/v1/ollama/query", methods=["POST"])
def ollama_query():
    """Fallback Ollama (logica invariata, utilizza phi3 come modello)."""
    # ... (il codice di Ollama rimane identico alla versione phi3 corretta) ...
    data = request.get_json()
    user_query = data.get('query')
    
    system_prompt = "Sei Paguro, l'assistente virtuale simpatico di Villa Celi. Il tuo scopo è rispondere in modo amichevole e reindirizzare SEMPRE l'utente alla verifica e prenotazione di un appartamento. Inserisci sempre una frase che incoraggi la prenotazione."
    
    ollama_data = {
        "model": "phi3:3.8b",  
        "prompt": f"{system_prompt} Utente: {user_query}",
        "stream": False
    }

    ollama_url = f"http://{os.environ.get('OLLAMA_HOST', 'ollama:11434')}/api/generate"

    try:
        # Aggiunto timeout esplicito per la richiesta LLM
        ollama_response = requests.post(ollama_url, json=ollama_data, timeout=290)
        ollama_response.raise_for_status()
        
        response_json = ollama_response.json()
        response_text = response_json.get('response', 'Mi dispiace, il modello Ollama non ha risposto.')
        
        redirect_text = "Se vuoi verificare la disponibilità, dimmi le date o chiedi 'disponibilità'!"
        
        return jsonify({
            "response_text": response_text, 
            "redirect": redirect_text
        }), 200

    except requests.exceptions.RequestException as e:
        app.logger.error(f"Ollama connection error: {e}")
        return jsonify({"error": "Ollama Service Unreachable", "message": "Il servizio AI non è disponibile (Codice 503). Contatta l'amministrazione per informazioni."}), 503

    except Exception as e:
        app.logger.error(f"Unhandled Internal Error in ollama_query: {e}")
        return jsonify({"error": "Internal Server Error", "message": f"Errore non gestito: {e}"}), 500


# =========================================================
# ENDPOINT AMMINISTRATIVI (Pannello Admin - richiedono Token)
# =========================================================

@app.route("/api/v1/admin/status", methods=["GET"])
@require_auth
def admin_status():
    """Endpoint di test per verificare la connettività e l'autenticazione. (DB check implicito)."""
    return jsonify({"status": "OK", "message": "Connessione e Token API validi."}), 200

@app.route("/api/v1/admin/appartamenti", methods=["GET", "POST"])
@require_auth
def manage_appartamenti():
    """CRUD: Ottiene o crea appartamenti."""
    if request.method == "GET":
        apartments = db_manager.get_appartamenti()
        return jsonify({"appartamenti": apartments}), 200
    
    elif request.method == "POST":
        data = request.get_json()
        nome = data.get('nome')
        max_ospiti = data.get('max_ospiti', 4)
        if not nome:
            return jsonify({"error": "Nome mancante"}), 400
        
        try:
            new_id = db_manager.create_appartamento(nome, max_ospiti)
            return jsonify({"message": "Appartamento creato", "id": new_id}), 201
        except Exception as e:
             app.logger.error(f"Errore creazione appartamento: {e}")
             return jsonify({"error": "Errore DB: Impossibile creare l'appartamento."}), 500


@app.route("/api/v1/admin/occupazioni/<int:apartment_id>", methods=["GET", "POST"])
@require_auth
def manage_occupations(apartment_id):
    """CRUD: Ottiene o crea occupazioni per un appartamento specifico."""
    if request.method == "GET":
        occupations = db_manager.get_occupazioni_by_apartment(apartment_id)
        return jsonify({"occupazioni": occupations}), 200
    
    elif request.method == "POST":
        data = request.get_json()
        start_date_str = data.get('data_inizio')
        end_date_str = data.get('data_fine')

        start_date, end_date, validation_message = is_valid_booking_period(start_date_str, end_date_str)
        if not start_date:
            return jsonify({"error": validation_message}), 400
        
        # 1. Verifica la sovrapposizione prima di inserire
        if db_manager.check_overlap(apartment_id, start_date, end_date):
            return jsonify({"error": "Le date si sovrappongono a un periodo esistente."}), 409 # Conflict

        try:
            db_manager.create_occupazione(apartment_id, start_date, end_date)
            return jsonify({"message": "Occupazione creata con successo."}), 201
        except Exception as e:
            app.logger.error(f"Errore creazione occupazione: {e}")
            return jsonify({"error": "Errore DB: Impossibile creare l'occupazione."}), 500


if __name__ == "__main__":
    app.run(host='0.0.0.0', port=80)