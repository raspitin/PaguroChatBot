import os
import datetime
from flask import Flask, request, jsonify
from flask_cors import CORS
import requests

app = Flask(__name__)

# --- Configurazione CORS ---
# Permette solo l'origine WordPress (www.villaceli.it)
CORS(app, resources={r"/api/*": {"origins": "https://www.villaceli.it"}})

# --- Configurazione Ambiente ---
API_TOKEN = os.environ.get("API_TOKEN")
OLLAMA_HOST = os.environ.get("OLLAMA_HOST", "http://ollama:11434")

# Placeholder per la connessione al DB (Postgres)
# DB_URL = f"postgresql://{os.environ.get('DB_USER')}:{os.environ.get('DB_PASS')}@{os.environ.get('DB_HOST')}/{os.environ.get('DB_NAME')}"

# --- Middleware di Sicurezza: Verifica Token ---
def require_auth(f):
    def wrapper(*args, **kwargs):
        auth_header = request.headers.get("Authorization")
        if not auth_header or not auth_header.startswith("Bearer "):
            return jsonify({"error": "Unauthorized", "message": "Missing or invalid Authorization header."}), 401
        
        token = auth_header.split(" ")[1]
        if token != API_TOKEN:
            return jsonify({"error": "Forbidden", "message": "Invalid API Token."}), 403
            
        return f(*args, **kwargs)
    wrapper.__name__ = f.__name__
    return wrapper

# --- Funzione Placeholder: Logica di Prenotazione (Sabato) ---
def is_valid_booking_period(start_date_str, end_date_str):
    """Verifica se le date di inizio e fine sono di Sabato."""
    try:
        start_date = datetime.datetime.strptime(start_date_str, '%Y-%m-%d').date()
        end_date = datetime.datetime.strptime(end_date_str, '%Y-%m-%d').date()
        
        # Lunedì è 0, Domenica è 6. Sabato è 5.
        if start_date.weekday() != 5 or end_date.weekday() != 5:
            return False, "Le date di check-in e check-out devono essere di Sabato."
        
        if end_date <= start_date:
            return False, "La data di check-out deve essere successiva alla data di check-in."

        # Verifica che il periodo sia un multiplo di 7 giorni (settimane contigue)
        if (end_date - start_date).days % 7 != 0:
             return False, "La prenotazione deve coprire settimane intere (multipli di 7 giorni)."
        
        return True, "Periodo valido."
    except ValueError:
        return False, "Formato data non valido. Usa YYYY-MM-DD."

# =========================================================
# ENDPOINT PUBBLICI (Chatbot FE)
# =========================================================

@app.route("/api/v1/disponibilita", methods=["POST"])
def check_availability():
    """Verifica la disponibilità di un appartamento."""
    data = request.get_json()
    start_date = data.get('data_inizio')
    end_date = data.get('data_fine')
    apartment_id = data.get('appartamento_id')

    valid, message = is_valid_booking_period(start_date, end_date)
    if not valid:
        return jsonify({"available": False, "message": message}), 400

    # LOGICA REALE: Interrogare il DB (Postgres) per controllare le sovrapposizioni.
    # Placeholder per la verifica della disponibilità
    is_available_in_db = True 

    if is_available_in_db:
        return jsonify({"available": True, "apartment": apartment_id, "start": start_date, "end": end_date}), 200
    else:
        return jsonify({"available": False, "message": "L'appartamento non è disponibile per il periodo selezionato."}), 200

@app.route("/api/v1/ollama/query", methods=["POST"])
def ollama_query():
    """Fallback Ollama per richieste non attinenti."""
    data = request.get_json()
    user_query = data.get('query')
    
    # Prompt di sistema per Ollama
    system_prompt = "Sei Paguro, l'assistente virtuale simpatico di Villa Celi. Il tuo scopo è rispondere in modo amichevole e reindirizzare SEMPRE l'utente alla verifica e prenotazione di un appartamento."
    
    ollama_data = {
        "model": "llama2:7b",  # Sostituire con il modello scelto
        "prompt": f"{system_prompt} Utente: {user_query}",
        "stream": False
    }

    try:
        ollama_response = requests.post(f"{OLLAMA_HOST}/api/generate", json=ollama_data)
        ollama_response.raise_for_status()
        
        response_text = ollama_response.json().get('response', 'Mi dispiace, il modello Ollama non ha risposto.')
        
        # Aggiunge il reindirizzamento
        redirect_text = "Se hai bisogno di prenotare, chiedimi 'disponibilità' o inserisci le date!"
        
        return jsonify({"response_text": response_text, "redirect": redirect_text}), 200
    except requests.exceptions.RequestException as e:
        app.logger.error(f"Ollama connection error: {e}")
        return jsonify({"error": "Ollama Service Unreachable", "message": "Il servizio AI non è disponibile. Contatta l'amministrazione per informazioni."}), 503

# =========================================================
# ENDPOINT AMMINISTRATIVI (Pannello Admin - richiedono Token)
# =========================================================

@app.route("/api/v1/admin/status", methods=["GET"])
@require_auth
def admin_status():
    """Endpoint di test per verificare la connettività e l'autenticazione."""
    # QUI: Si potrebbe aggiungere la verifica della connessione al DB
    return jsonify({"status": "OK", "message": "Connessione e Token API validi."}), 200

@app.route("/api/v1/admin/occupazioni", methods=["GET", "POST", "PUT", "DELETE"])
@require_auth
def manage_occupations():
    """CRUD per le occupazioni/prenotazioni (Logica da implementare)."""
    if request.method == "GET":
        # LOGICA: Recupera le occupazioni dal DB
        return jsonify({"occupations": []}), 200
    return jsonify({"message": f"Endpoint {request.method} per le occupazioni in costruzione."}), 200

if __name__ == "__main__":
    # Gunicorn verrà usato nel Dockerfile per un deploy robusto, 
    # ma in locale si può usare il server Flask
    app.run(host='0.0.0.0', port=80)
