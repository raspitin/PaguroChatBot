import os
import datetime
import logging
import psycopg2
from flask import Flask, request, jsonify, make_response
from flask_cors import CORS
from functools import wraps
import requests
from db_manager import DBManager 

app = Flask(__name__)
app.logger.setLevel(logging.ERROR) 

# --- INIZIALIZZAZIONE GLOBALE DEL DB MANAGER (FIX NameError) ---
db_manager = None

# --- Configurazione CORS (VERSIONE FINALE) ---
CORS(app, resources={r"/api/*": {
    "origins": ["https://www.villaceli.it", "https://villaceli.it"], 
    "methods": ["GET", "POST", "PUT", "DELETE", "OPTIONS"], 
    "allow_headers": ["Content-Type", "Authorization"],     
    "max_age": 86400  
}})

# --- Configurazione Ambiente ---
API_TOKEN = os.environ.get("API_TOKEN")
OLLAMA_HOST_ENV = os.environ.get("OLLAMA_HOST", "ollama:11434")

try:
    db_manager = DBManager() 
except Exception as e:
    app.logger.error(f"FATAL: Impossibile connettersi o inizializzare il database: {e}")
    db_manager = None 


# --- Middleware di Sicurezza: Verifica Token (Invariata) ---
def require_auth(f):
    @wraps(f)
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
    return wrapper

# Funzione Placeholder: Logica di Prenotazione (Sabato)
def is_valid_booking_period(start_date_str, end_date_str):
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
# ENDPOINT AMMINISTRATIVI (Pannello Admin - richiedono Token)
# =========================================================

@app.route("/api/v1/admin/status", methods=["GET"])
@require_auth
def admin_status():
    return jsonify({"status": "OK", "message": "Connessione e Token API validi."}), 200

@app.route("/api/v1/admin/appartamenti", methods=["GET", "POST"])
@require_auth
def create_read_appartamenti():
    if request.method == "GET":
        appartamenti = db_manager.get_appartamenti()
        return jsonify({"appartamenti": appartamenti}), 200
    
    elif request.method == "POST":
        data = request.get_json()
        nome = data.get('nome')
        max_ospiti = data.get('max_ospiti', 4)
        if not nome:
            return jsonify({"error": "Nome mancante"}), 400
        
        try:
            new_id = db_manager.create_appartamento(nome, max_ospiti)
            return jsonify({"message": "Appartamento creato", "id": new_id}), 201
        except psycopg2.errors.UniqueViolation:
             return jsonify({"error": "Nome appartamento già esistente."}), 409
        except Exception as e:
             app.logger.error(f"Errore creazione appartamento: {e}")
             return jsonify({"error": "Errore DB: Impossibile creare l'appartamento."}), 500

# --- APPARTAMENTI: OPTIONS (Handler separato per il preflight) ---
@app.route("/api/v1/admin/appartamenti/<int:apt_id>", methods=["OPTIONS"])
def options_update_delete_appartamenti(apt_id):
    return make_response('', 200)

# === APPARTAMENTI: UPDATE/DELETE (Rotta Semplice con Auth) ===
@app.route("/api/v1/admin/appartamenti/<int:apt_id>", methods=["PUT", "DELETE"])
@require_auth
def update_delete_appartamenti(apt_id):
    
    if request.method == "PUT":
        data = request.get_json()
        new_name = data.get('nome')
        new_guests = data.get('max_ospiti')
        
        if not new_name or not new_guests:
            return jsonify({"error": "Dati mancanti per l'aggiornamento (Nome o Ospiti)"}), 400
        
        try:
            # La chiamata è corretta per la funzione DB aggiornata
            rows = db_manager.update_appartamento(apt_id, new_name, new_guests) 
            if rows == 0:
                 return jsonify({"error": "Appartamento non trovato o non modificato."}), 404
            return jsonify({"message": "Appartamento aggiornato"}), 200
        except psycopg2.errors.UniqueViolation:
             return jsonify({"error": "Nome appartamento già esistente."}), 409
        except Exception as e:
             app.logger.error(f"Errore aggiornamento appartamento: {e}")
             return jsonify({"error": "Errore DB: Impossibile aggiornare l'appartamento."}), 500
        
    elif request.method == "DELETE":
        try:
            rows = db_manager.delete_appartamento(apt_id)
            if rows == 0:
                 return jsonify({"error": "Appartamento non trovato o non eliminato."}), 404
            return jsonify({"message": "Appartamento eliminato (disattivato)"}), 204
        except Exception as e:
             return jsonify({"error": "Errore DB: Impossibile eliminare l'appartamento."}), 500

# ... (Logica Occupazioni e Ollama Omessa) ...

if __name__ == "__main__":
    app.run(host='0.0.0.0', port=80)