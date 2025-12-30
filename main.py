from fastapi import FastAPI, Request, BackgroundTasks
from pydantic import BaseModel
import logging
import os
import ollama
from influxdb_client import InfluxDBClient, Point
from influxdb_client.client.write_api import SYNCHRONOUS
from datetime import datetime

app = FastAPI()

# --- CONFIGURAZIONE ---
INFLUX_URL = os.getenv("INFLUX_URL", "http://192.168.1.140:8086")
INFLUX_TOKEN = os.getenv("INFLUX_TOKEN", "my-token")
INFLUX_ORG = os.getenv("INFLUX_ORG", "my-org")
INFLUX_BUCKET = os.getenv("INFLUX_BUCKET", "paguro_analytics")
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://ollama:11434")

# Usa un modello leggero
MODEL_NAME = "llama3.2:1b" 

# Client InfluxDB
client = InfluxDBClient(url=INFLUX_URL, token=INFLUX_TOKEN, org=INFLUX_ORG)
write_api = client.write_api(write_options=SYNCHRONOUS)

class ChatRequest(BaseModel):
    message: str
    session_id: str

# --- LOGGING ASINCRONO ---
def log_to_influx(request_data: dict, headers: dict):
    try:
        country = headers.get("cf-ipcountry", "Unknown")
        ip = headers.get("cf-connecting-ip", "Unknown")
        
        point = (
            Point("chat_interaction")
            .tag("country", country)
            .tag("session_id", request_data.get("session_id"))
            .field("message_length", len(request_data.get("message", "")))
            .field("ip_hash", str(hash(ip)))
            .time(datetime.utcnow())
        )
        write_api.write(bucket=INFLUX_BUCKET, org=INFLUX_ORG, record=point)
    except Exception as e:
        print(f"Errore InfluxDB: {e}")

# --- ENDPOINT CHAT ---
@app.post("/chat")
async def chat_endpoint(chat_req: ChatRequest, request: Request, background_tasks: BackgroundTasks):
    user_msg = chat_req.message.lower()
    
    # 1. Logging
    headers = dict(request.headers)
    background_tasks.add_task(log_to_influx, chat_req.dict(), headers)

    # 2. FAST PATH (Risposte immediate keyword-based)
    
    # Saluti
    saluti = ["ciao", "buongiorno", "buonasera", "salve", "hola", "ehi"]
    if any(x in user_msg for x in saluti) and len(user_msg.split()) < 3:
        return {
            "type": "TEXT",
            "reply": "Ciao! Sono Paguro il tuo assistente virtuale. Cerchi disponibilità o informazioni sugli appartamenti?"
        }

    # FOTO E IMMAGINI
    keywords_foto = ["foto", "vedere", "immagini", "interno", "esterni", "camere"]
    if any(k in user_msg for k in keywords_foto):
        # Sostituisci '#' con il link reale alla tua pagina galleria
        link_foto_corallo = "https://www.villaceli.it/appartamento-corallo/" 
        link_foto_tartaruga = "https://www.villaceli.it/appartamento-tartaruga/" 
        return {
            "type": "TEXT",
            "reply": f"Certamente! Puoi vedere tutte le foto degli appartamenti e degli esterni <a href='{link_foto_corallo}' target='_blank' style='color:blue; text-decoration:underline;'>Appartamento Corallo <br> <a href='{link_foto_tartaruga}' target='_blank' style='color:blue; text-decoration:underline;'>Appartamento Tartaruga</a>."
        }

    # POSIZIONE E COME ARRIVARE
    keywords_pos = ["dove", "raggiungere", "arrivare", "mappa", "posizione", "strada"]
    if any(k in user_msg for k in keywords_pos):
        # Sostituisci '#' con il link a Google Maps o alla pagina contatti
        link_sito = "https://www.villaceli.it/dove-siamo/"
        return {
            "type": "TEXT",
            "reply": f"Siamo a Palinuro, immersi nel verde! 🌿<br>Trovi la posizione esatta e le indicazioni stradali <a href='{link_sito}' target='_blank' style='color:blue; text-decoration:underline;'>sul nostro sito</a>"
        }


    # Info generiche (Dove siamo)
    if "dove" in user_msg and ("siete" in user_msg or "trova" in user_msg):
        return {
            "type": "TEXT",
            "reply": "Siamo a Palinuro, nel cuore del Cilento! Trovi la posizione esatta sul sito."
        }

    # Azioni (Prenotazione)
    keywords_prenotazione = ["prenot", "disponib", "prezzo", "costo", "luglio", "agosto", "settimana", "liber", "date"]
    if any(k in user_msg for k in keywords_prenotazione):
        return {
            "type": "ACTION",
            "action": "CHECK_AVAILABILITY",
            "reply": "Controllo subito il calendario..."
        }

    # 3. SLOW PATH (IA TinyLlama)
    try:
        # Prompt ingegnerizzato per "smontare" richieste fuori contesto
        system_prompt = (
            "Sei Paguro, assistente virtuale per case vacanze Villa Celi. "
            "rileva la lingua ri rispondi sempre nella lingua rilevata. "
            "Se l'utente chiede cose strane, tecniche o non legate alle vacanze (es. sottomarini, politica, calcoli), "
            "ignoralo e rispondi con : 'Ahaha simpatico! 😄 Ma torniamo a noi: hai già scelto le date?'.  o risposte simili adattate alla domanda"
            "Non dare mai spiegazioni tecniche."
        )
        
        response = ollama.chat(model=MODEL_NAME, messages=[
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': chat_req.message},
        ], options={
            'num_predict': 40,   # Risposte brevi
            'num_ctx': 256,      # Contesto ridotto per velocità
            'temperature': 0.2   # Bassa creatività = più obbedienza al prompt
        })

        bot_reply = response['message']['content']
        return {"type": "TEXT", "reply": bot_reply}

    except Exception as e:
        print(f"Errore Ollama: {e}")
        return {"type": "TEXT", "reply": "Scusa, mi sono distratto un attimo. Puoi ripetere?"}
