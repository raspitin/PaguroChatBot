import os
import datetime
import logging
import psycopg2
from flask import Flask, request, jsonify, make_response
from flask_cors import CORS
import requests
from db_manager import DBManager 

app = Flask(__name__)
app.logger.setLevel(logging.ERROR) 

# --- Configurazione CORS (VERSIONE DEFINITIVA) ---
# FIX: Assicuriamo che la configurazione sia esplicita e che copra l'header Authorization.
CORS(app, resources={r"/api/*": {
    # Permette origini specifiche (www.villaceli.it)
    "origins": ["https://www.villaceli.it", "https://villaceli.it"], 
    # Permette tutti i metodi richiesti
    "methods": ["GET", "POST", "PUT", "DELETE", "OPTIONS"], 
    # ESSENZIALE: Permette il passaggio dell'header Authorization
    "allow_headers": ["Content-Type", "Authorization"],     
    "max_age": 86400  
}})


# --- Middleware di Sicurezza: Verifica Token ---
def require_auth(f):
    def wrapper(*args, **kwargs):
        # ... (Logica di verifica Token e DB omessa, invariata) ...
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
# ... (Logica omessa, invariata) ...

# =========================================================
# ENDPOINT PUBBLICI (Chatbot FE)
# ... (Logica omessa, invariata) ...

# =========================================================
# ENDPOINT AMMINISTRATIVI (Pannello Admin - richiedono Token)
# =========================================================

@app.route("/api/v1/admin/status", methods=["GET"])
@require_auth
def admin_status():
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
        except psycopg2.errors.UniqueViolation:
             return jsonify({"error": "Nome appartamento già esistente."}), 409 # Conflict
        except Exception as e:
             app.logger.error(f"Errore creazione appartamento: {e}")
             return jsonify({"error": "Errore DB: Impossibile creare l'appartamento."}), 500


@app.route("/api/v1/admin/occupazioni/<int:apartment_id>", methods=["GET", "POST"])
@require_auth
def manage_occupations(apartment_id):
    """CRUD: Ottiene o crea occupazioni per un appartamento specifico."""
    # ... (Logica omessa, invariata) ...
    return jsonify({"message": "Endpoint non implementato."}), 501


if __name__ == "__main__":
    # Inizializza il DB Manager (lo spostiamo alla fine per permettere al logger di avviarsi)
    try:
        db_manager = DBManager()
    except Exception as e:
        app.logger.error(f"FATAL: Impossibile connettersi o inizializzare il database: {e}")
        db_manager = None 
        
    app.run(host='0.0.0.0', port=80)