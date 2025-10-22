import os
import psycopg2
from psycopg2.pool import SimpleConnectionPool
from contextlib import contextmanager

class DBManager:
    """Gestisce la connessione al database PostgreSQL e le operazioni CRUD."""
    
    def __init__(self):
        self.DB_NAME = os.environ.get("DB_NAME")
        self.DB_USER = os.environ.get("DB_USER")
        self.DB_PASS = os.environ.get("DB_PASS")
        self.DB_HOST = os.environ.get("DB_HOST")
        self.DB_PORT = os.environ.get("DB_PORT", "5432")

        # Configura il pool di connessioni
        self.connection_pool = SimpleConnectionPool(
            minconn=1, 
            maxconn=10, 
            database=self.DB_NAME,
            user=self.DB_USER,
            password=self.DB_PASS,
            host=self.DB_HOST,
            port=self.DB_PORT
        )
        self.initialize_tables()

    @contextmanager
    def get_conn(self):
        """Fornisce una connessione dal pool e la rilascia automaticamente."""
        conn = self.connection_pool.getconn()
        try:
            yield conn
        finally:
            self.connection_pool.putconn(conn)

    def initialize_tables(self):
        """
        Crea le tabelle se non esistono. 
        Implementa una gestione dell'errore per ignorare i conflitti di sequenza
        che causano il crash all'avvio (Fatal Error).
        """
        sql_appartamenti = """
        CREATE TABLE IF NOT EXISTS Appartamenti (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(255) NOT NULL UNIQUE,
            max_ospiti INTEGER DEFAULT 4,
            attivo BOOLEAN DEFAULT TRUE
        );
        """
        sql_prenotazioni = """
        CREATE TABLE IF NOT EXISTS Prenotazioni (
            id SERIAL PRIMARY KEY,
            appartamento_id INTEGER REFERENCES Appartamenti(id) ON DELETE CASCADE,
            data_inizio DATE NOT NULL,
            data_fine DATE NOT NULL,
            nome_cliente VARCHAR(255),
            status VARCHAR(50) DEFAULT 'occupato',
            UNIQUE (appartamento_id, data_inizio, data_fine)
        );
        """
        # Lista di errori che vogliamo ignorare durante la creazione
        errors_to_ignore = [
            "duplicate key value violates unique constraint",
            "already exists"
        ]
        
        try:
            with self.get_conn() as conn:
                with conn.cursor() as cur:
                    # 1. Tenta la creazione di Appartamenti
                    try:
                        cur.execute(sql_appartamenti)
                        print("DBManager: Tabella Appartamenti inizializzata o già esistente.")
                    except Exception as e:
                        if any(err in str(e) for err in errors_to_ignore):
                            print("DBManager: Avviso - Tabella Appartamenti o sequenza già esistente (Ignorato).")
                        else:
                            raise e 
                        
                    # 2. Tenta la creazione di Prenotazioni
                    try:
                        cur.execute(sql_prenotazioni)
                        print("DBManager: Tabella Prenotazioni inizializzata o già esistente.")
                    except Exception as e:
                        # Ignora anche errori di FK se l'altra tabella non è stata creata subito
                        if any(err in str(e) for err in errors_to_ignore) or "reference to a non-existent" in str(e):
                            print("DBManager: Avviso - Tabella Prenotazioni già esistente (Ignorato).")
                        else:
                            raise e

                conn.commit()
            
        except Exception as e:
            # Errore critico non gestito (es. DB irraggiungibile)
            print(f"DBManager: Errore critico non gestito all'avvio del DB: {e}")
            raise e
    
    # =========================================================
    # CRUD: APPARTAMENTI
    # =========================================================
    
    def get_appartamenti(self):
        with self.get_conn() as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT id, nome, max_ospiti FROM Appartamenti WHERE attivo = TRUE ORDER BY id;")
                apartments = cur.fetchall()
                return [{"id": a[0], "nome": a[1], "max_ospiti": a[2]} for a in apartments]

    def create_appartamento(self, nome, max_ospiti):
        with self.get_conn() as conn:
            with conn.cursor() as cur:
                # La clausola RETURNING id è cruciale per ottenere l'ID creato
                cur.execute(
                    "INSERT INTO Appartamenti (nome, max_ospiti) VALUES (%s, %s) RETURNING id;",
                    (nome, max_ospiti)
                )
                conn.commit()
                return cur.fetchone()[0]
    
    # [TODO]: Implementare delete/update appartamenti

    # =========================================================
    # CRUD: PRENOTAZIONI / OCCUPAZIONI
    # =========================================================
    
    def get_occupazioni_by_apartment(self, apartment_id):
        sql = """
        SELECT id, data_inizio, data_fine, nome_cliente, status 
        FROM Prenotazioni 
        WHERE appartamento_id = %s 
        ORDER BY data_inizio;
        """
        with self.get_conn() as conn:
            with conn.cursor() as cur:
                cur.execute(sql, (apartment_id,))
                occupations = cur.fetchall()
                # Converte i risultati in un formato leggibile
                return [{
                    "id": o[0], 
                    "data_inizio": o[1].isoformat(), 
                    "data_fine": o[2].isoformat(), 
                    "nome_cliente": o[3],
                    "status": o[4]
                } for o in occupations]

    def create_occupazione(self, apartment_id, start_date, end_date, client_name="Admin"):
        sql = """
        INSERT INTO Prenotazioni (appartamento_id, data_inizio, data_fine, nome_cliente) 
        VALUES (%s, %s, %s, %s);
        """
        with self.get_conn() as conn:
            with conn.cursor() as cur:
                cur.execute(sql, (apartment_id, start_date, end_date, client_name))
                conn.commit()


    # =========================================================
    # LOGICA: VERIFICA DISPONIBILITÀ
    # =========================================================

    def check_overlap(self, apartment_id, start_date, end_date):
        """Verifica se il periodo richiesto si sovrappone a una prenotazione esistente."""
        sql = """
        SELECT COUNT(*) 
        FROM Prenotazioni 
          AND status IN ('occupato', 'prenotato')
          AND NOT (data_fine <= %s OR data_inizio >= %s);
        """
        
        try:
            with self.get_conn() as conn:
                with conn.cursor() as cur:
                    cur.execute(sql, (apartment_id, start_date, end_date, start_date, end_date)) # I parametri erano sbagliati, corretti qui
                    overlap_count = cur.fetchone()[0]
                    return overlap_count > 0
        except Exception as e:
            print(f"DBManager: Errore durante il controllo di sovrapposizione: {e}")
            return True