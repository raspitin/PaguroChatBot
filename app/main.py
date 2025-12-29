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

# Usa un modello leggero per velocità
MODEL_NAME = "tinyllama" 

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

    # 2. FAST PATH (Risposte immediate < 0.2s)
    
    # Saluti
    saluti = ["ciao", "buongiorno", "buonasera", "salve", "hola", "ehi"]
    if any(x in user_msg for x in saluti):
        return {
            "type": "TEXT",
            "reply": "Ciao! Benvenuto a Villa Celi. Cerchi disponibilità o informazioni?"
        }

    # Info generiche
    if "dove" in user_msg and ("siete" in user_msg or "trova" in user_msg):
        return {
            "type": "TEXT",
            "reply": "Siamo in una zona bellissima! Trovi la mappa e le indicazioni esatte sul nostro sito."
        }

    # Azioni (Prenotazione)
    keywords_prenotazione = ["prenot", "disponib", "prezzo", "costo", "luglio", "agosto", "settimana", "liber"]
    if any(k in user_msg for k in keywords_prenotazione):
        return {
            "type": "ACTION",
            "action": "CHECK_AVAILABILITY",
            "reply": "Verifico subito le settimane libere..."
        }

    # 3. SLOW PATH (IA TinyLlama)
    try:
        system_prompt = "Sei Paguro. Rispondi in italiano in max 15 parole. Sii simpatico."
        
        response = ollama.chat(model=MODEL_NAME, messages=[
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': chat_req.message},
        ], options={'num_predict': 30, 'num_ctx': 512})

        bot_reply = response['message']['content']
        return {"type": "TEXT", "reply": bot_reply}

    except Exception as e:
        print(f"Errore Ollama: {e}")
        return {"type": "TEXT", "reply": "Scusa, stavo schiacciando un pisolino. Puoi ripetere?"}
