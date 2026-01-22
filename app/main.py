import os
from datetime import datetime
from fastapi import FastAPI, Request, BackgroundTasks
from pydantic import BaseModel
from influxdb_client import InfluxDBClient, Point
from influxdb_client.client.write_api import SYNCHRONOUS
from ollama import AsyncClient

app = FastAPI()

# --- CONFIGURAZIONE (Solo da variabili d'ambiente per sicurezza) ---
INFLUX_URL = os.getenv("INFLUX_URL")
INFLUX_TOKEN = os.getenv("INFLUX_TOKEN")
INFLUX_ORG = os.getenv("INFLUX_ORG")
INFLUX_BUCKET = os.getenv("INFLUX_BUCKET")
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://ollama:11434")
MODEL_NAME = os.getenv("MODEL_NAME", "llama3.2:1b")

if not INFLUX_TOKEN:
    print("WARNING: INFLUX_TOKEN non impostato. I log falliranno.")

# Setup Clients
influx_client = InfluxDBClient(url=INFLUX_URL, token=INFLUX_TOKEN, org=INFLUX_ORG)
write_api = influx_client.write_api(write_options=SYNCHRONOUS)
ollama_client = AsyncClient(host=OLLAMA_HOST)

class ChatRequest(BaseModel):
    message: str
    session_id: str

# --- LOGGING BACKGROUND ---
def log_to_influx(request_data: dict, headers: dict):
    try:
        country = headers.get("cf-ipcountry", "Unknown")
        ip = headers.get("cf-connecting-ip", "Unknown")
        
        # Anonimizzazione basilare IP (in attesa di fix GDPR completo step 3)
        ip_hash = str(hash(ip)) if ip else "unknown"

        point = (
            Point("chat_interaction")
            .tag("country", country)
            .tag("session_id", request_data.get("session_id"))
            .field("message_length", len(request_data.get("message", "")))
            .field("ip_hash", ip_hash)
            .time(datetime.utcnow())
        )
        write_api.write(bucket=INFLUX_BUCKET, org=INFLUX_ORG, record=point)
    except Exception as e:
        print(f"Errore InfluxDB: {e}")

# --- ENDPOINT CHAT ---
@app.post("/chat")
async def chat_endpoint(chat_req: ChatRequest, request: Request, background_tasks: BackgroundTasks):
    user_msg = chat_req.message.lower().strip()
    
    # 1. Logging non bloccante
    headers = dict(request.headers)
    background_tasks.add_task(log_to_influx, chat_req.dict(), headers)

    # 2. FAST PATH
    saluti = ["ciao", "buongiorno", "buonasera", "salve", "hola", "ehi"]
    if any(x == user_msg for x in saluti) or (any(x in user_msg for x in saluti) and len(user_msg.split()) < 3):
        return {
            "type": "TEXT",
            "reply": "Ciao! Sono Paguro, il tuo assistente virtuale per Villa Celi. Cerchi disponibilità o informazioni?"
        }

    keywords_foto = ["foto", "vedere", "immagini", "interno", "esterni", "camere"]
    if any(k in user_msg for k in keywords_foto):
        link_foto_corallo = "https://www.villaceli.it/appartamento-corallo/" 
        link_foto_tartaruga = "https://www.villaceli.it/appartamento-tartaruga/" 
        return {
            "type": "TEXT",
            "reply": f"Certamente! Guarda qui: <br>• <a href='{link_foto_corallo}' target='_blank'>Appartamento Corallo</a><br>• <a href='{link_foto_tartaruga}' target='_blank'>Appartamento Tartaruga</a>"
        }

    keywords_pos = ["dove", "raggiungere", "arrivare", "mappa", "posizione", "strada"]
    if any(k in user_msg for k in keywords_pos) or ("dove" in user_msg and "siete" in user_msg):
        link_sito = "https://www.villaceli.it/dove-siamo/"
        return {
            "type": "TEXT",
            "reply": f"Siamo a Palinuro, immersi nel verde! 🌿<br>Trovi indicazioni e mappa <a href='{link_sito}' target='_blank'>sul nostro sito</a>."
        }

    keywords_prenotazione = ["prenot", "disponib", "prezzo", "costo", "luglio", "agosto", "settimana", "liber", "date"]
    if any(k in user_msg for k in keywords_prenotazione):
        return {
            "type": "ACTION",
            "action": "CHECK_AVAILABILITY",
            "reply": "Controllo subito il calendario..."
        }

    # 3. SLOW PATH
    try:
        system_prompt = (
            "Sei Paguro, assistente di Villa Celi. "
            "Rileva la lingua e rispondi sempre nella lingua dell'utente. "
            "Se l'utente chiede cose strane o non legate alle vacanze, "
            "rispondi simpaticamente riportando il discorso sulle vacanze. "
            "Sii breve (max 30 parole)."
        )
        
        response = await ollama_client.chat(model=MODEL_NAME, messages=[
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': chat_req.message},
        ], options={
            'num_predict': 50,
            'temperature': 0.2
        })

        bot_reply = response['message']['content']
        return {"type": "TEXT", "reply": bot_reply}

    except Exception as e:
        print(f"Errore Ollama: {e}")
        return {"type": "TEXT", "reply": "Scusa, ho avuto un attimo di esitazione. Puoi ripetere?"}
