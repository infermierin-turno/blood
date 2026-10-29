import os
import sys
from datetime import datetime, timedelta
from fastapi import FastAPI, HTTPException, Query, Request
from pydantic import BaseModel, ConfigDict
import httpx
from typing import Optional, List, Dict, Any

# Importazione dell'agente di equità e suggerimento sostituti per la reperibilità
try:
    from app.engine_rep_equita import verifica_equita_e_suggerisci_sostituti
except ImportError:
    async def verifica_equita_e_suggerisci_sostituti(*args, **kwargs):
        return {"message": "Modulo equità non disponibile"}

app = FastAPI(title="TurnoMed Python Engine", version="2.8.21")

SUPABASE_URL = os.getenv("TURNI_SUPABASE_URL", "")
SUPABASE_KEY = os.getenv("TURNI_SUPABASE_KEY", "")

class GenerazioneRequest(BaseModel):
    model_config = ConfigDict(extra='allow')
    
    organizzazione_id: Optional[str] = None
    reparto_id: Optional[str] = None
    data_inizio: Optional[str] = None
    data_fine: Optional[str] = None
    anno: Optional[int] = None
    mese: Optional[int] = None
    operatore_id: Optional[str] = None
    utente_id: Optional[str] = None
    operatori_ids: Optional[List[str]] = []
    utenti_ids: Optional[List[str]] = []
    turno_iniziale: Optional[str] = None
    turno_partenza: Optional[str] = None
    sequenza_turni: Optional[List[str]] = ["M", "P", "N", "S", "R"]
    rispetta_ferie: Optional[bool] = True
    rispetta_ferie_approvate: Optional[bool] = True
    riposo_domenicale: Optional[bool] = False
    modalita_mattinieri: Optional[bool] = False
    ferie_approvate: Optional[List[Dict[str, Any]]] = []

@app.middleware("http")
async def log_requests(request: Request, call_next):
    print(f"[UVICORN LOG] Richiesta in arrivo: {request.method} {request.url.path}")
    response = await call_next(request)
    print(f"[UVICORN LOG] Risposta inviata con status: {response.status_code}")
    return response

@app.get("/")
def read_root():
    return {"status": "online", "message": "TurnoMed Python Engine attivo!"}

@app.get("/debug-rotte-attive")
def debug_rotte_attive():
    routes = [{"path": route.path, "name": route.name} for route in app.routes]
    return {"status": "ok", "totale_rotte": len(routes), "rotte": routes}

@app.get("/api/equita/reperibilita")
async def api_verifica_equita(
    utente_id: str = Query(..., description="ID del dipendente"),
    reparto_id: str = Query(..., description="ID del reparto"),
    data_turno: str = Query(..., description="Data del turno YYYY-MM-DD"),
    tipo_reperibilita: str = Query("REP", description="Tipo o fascia di reperibilità"),
    organizzazione_id: str = Query(None, description="ID organizzazione opzionale")
):
    try:
        risultato = await verifica_equita_e_suggerisci_sostituti(
            utente_id=utente_id,
            reparto_id=reparto_id,
            data_turno=data_turno,
            tipo_reperibilita=tipo_reperibilita,
            organizzazione_id=organizzazione_id
        )
        return {"success": True, "data": risultato}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.api_route("/genera-turni", methods=["GET", "POST"])
@app.api_route("/app/genera-turni", methods=["GET", "POST"])
async def genera_turni(request: Request):
    try:
        body_json = await request.json()
    except Exception:
        body_json = {}

    if not body_json:
        body_json = dict(request.query_params)

    try:
        data = GenerazioneRequest(**body_json)
    except Exception:
        class DummyData:
            pass
        data = DummyData()
        data.organizzazione_id = body_json.get("organizzazione_id")
        data.reparto_id = body_json.get("reparto_id")
        data.data_inizio = body_json.get("data_inizio")
        data.data_fine = body_json.get("data_fine")
        data.anno = int(body_json.get("anno")) if body_json.get("anno") else None
        data.mese = int(body_json.get("mese")) if body_json.get("mese") else None
        data.operatore_id = body_json.get("operatore_id")
        data.utente_id = body_json.get("utente_id")
        data.operatori_ids = body_json.get("operatori_ids", [])
        data.utenti_ids = body_json.get("utenti_ids", [])
        data.turno_iniziale = body_json.get("turno_iniziale")
        data.turno_partenza = body_json.get("turno_partenza")
        data.sequenza_turni = body_json.get("sequenza_turni", ["M", "P", "N", "S", "R"])
        data.rispetta_ferie = bool(body_json.get("rispetta_ferie", True))
        data.rispetta_ferie_approvate = bool(body_json.get("rispetta_ferie_approvate", True))
        data.riposo_domenicale = bool(body_json.get("riposo_domenicale", False))
        data.modalita_mattinieri = bool(body_json.get("modalita_mattinieri", False))

    if not SUPABASE_URL or not SUPABASE_KEY:
        raise HTTPException(status_code=500, detail="Configurazione Supabase mancante.")

    headers = {
        "apikey": SUPABASE_KEY,
        "Authorization": f"Bearer {SUPABASE_KEY}",
        "Content-Type": "application/json",
        "Prefer": "resolution=merge-duplicates,on_conflict=utente_id,data_inizio"
    }

    org_id = getattr(data, "organizzazione_id", None)
    rep_id = getattr(data, "reparto_id", None)
    op_id = getattr(data, "operatore_id", None) or getattr(data, "utente_id", None)
    
    target_ids = getattr(data, "operatori_ids", []) or getattr(data, "utenti_ids", []) or []
    if op_id and op_id not in target_ids:
        target_ids.append(op_id)

    t_iniziale = getattr(data, "turno_iniziale", None) or getattr(data, "turno_partenza", None)
    riposo_dom = bool(getattr(data, "riposo_domenicale", False) or getattr(data, "modalita_mattinieri", False))
    seq_raw = getattr(data, "sequenza_turni", ["M", "P", "N", "S", "R"])
    sequenza_turni = seq_raw if (seq_raw and len(seq_raw) > 0) else ["M", "P", "N", "S", "R"]

    autore_uuid = op_id if op_id else (target_ids[0] if target_ids else None)

    anno_val = getattr(data, "anno", None)
    mese_val = getattr(data, "mese", None)
    data_inizio_val = getattr(data, "data_inizio", None)

    if not anno_val or not mese_val:
        if data_inizio_val:
            try:
                dt_parsed = datetime.strptime(str(data_inizio_val)[:10], "%Y-%m-%d")
                anno = dt_parsed.year
                mese = dt_parsed.month
            except Exception:
                anno = datetime.now().year
                mese = datetime.now().month
        else:
            anno = datetime.now().year
            mese = datetime.now().month
    else:
        anno = int(anno_val)
        mese = int(mese_val)

    async with httpx.AsyncClient() as client:
        try:
            url_all = f"{SUPABASE_URL}/rest/v1/staging_utenti?select=*"
            resp_all = await client.get(url_all, headers=headers)
            
            if resp_all.status_code != 200:
                raise HTTPException(status_code=500, detail=f"Errore di comunicazione con il database Supabase (Status: {resp_all.status_code}).")
            
            tutti_operatori = resp_all.json()
            if not tutti_operatori or not isinstance(tutti_operatori, list):
                raise HTTPException(status_code=400, detail="Nessun operatore trovato nella tabella staging_utenti.")

            operatori = []
            if target_ids:
                for o in tutti_operatori:
                    o_id_str = str(o.get('id') or o.get('utente_id'))
                    if o_id_str in [str(t) for t in target_ids]:
                        operatori.append(o)
            
            if not operatori and rep_id and str(rep_id).strip() != "" and str(rep_id).lower() != "null":
                operatori = [o for o in tutti_operatori if str(o.get('reparto_id')) == str(rep_id)]

            if not operatori:
                operatori = tutti_operatori

            if not operatori:
                raise HTTPException(status_code=400, detail="Nessun operatore trovato con i filtri selezionati.")

            data_inizio_mese_str = f"{anno:04d}-{mese:02d}-01"
            if mese == 12:
                primo_giorno_next = datetime(anno + 1, 1, 1)
            else:
                primo_giorno_next = datetime(anno, mese + 1, 1)
            
            ultimo_giorno = primo_giorno_next - timedelta(days=1)
            tot_giorni = ultimo_giorno.day
            data_fine_mese_str = ultimo_giorno.strftime("%Y-%m-%d")

            is_custom_range = False
            d_start_dt = None
            d_end_dt = None
            data_fine_val = getattr(data, "data_fine", None)
            if data_inizio_val and data_fine_val:
                try:
                    d_start_dt = datetime.strptime(str(data_inizio_val)[:10], "%Y-%m-%d")
                    d_end_dt = datetime.strptime(str(data_fine_val)[:10], "%Y-%m-%d")
                    if (d_end_dt - d_start_dt).days >= 0:
                        is_custom_range = True
                        data_inizio_mese_str = d_start_dt.strftime("%Y-%m-%d")
                        data_fine_mese_str = d_end_dt.strftime("%Y-%m-%d")
                except Exception:
                    is_custom_range = False

            assenze_map = {}
            if getattr(data, "rispetta_ferie", True) or getattr(data, "rispetta_ferie_approvate", True):
                url_assenze = f"{SUPABASE_URL}/rest/v1/pianificazione?data_inizio=gte.{data_inizio_mese_str} 00:00:00&data_inizio=lte.{data_fine_mese_str} 23:59:59&stato=eq.Approvato&select=utente_id,data_inizio,tipo_evento"
                resp_assenze = await client.get(url_assenze, headers=headers)
                if resp_assenze.status_code == 200:
                    for row in resp_assenze.json():
                        u_id = row.get("utente_id")
                        d_str = str(row.get("data_inizio", ""))[:10]
                        t_ev = row.get("tipo_evento")
                        if u_id and d_str and t_ev:
                            if u_id not in assenze_map:
                                assenze_map[u_id] = {}
                            assenze_map[u_id][d_str] = t_ev

            payload_inserimento = []

            for index_op, op in enumerate(operatori):
                utente_id = op.get("id") or op.get("utente_id")
                rep_operatore = op.get("reparto_id") or rep_id
                org_operatore = op.get("organizzazione_id") or org_id

                if not utente_id:
                    continue
                
                if t_iniziale and t_iniziale in sequenza_turni:
                    indice_seq = sequenza_turni.index(t_iniziale)
                else:
                    indice_seq = (index_op * 2) % len(sequenza_turni)

                if is_custom_range and d_start_dt and d_end_dt:
                    current_date_iter = d_start_dt
                    while current_date_iter <= d_end_dt:
                        data_str = current_date_iter.strftime("%Y-%m-%d")
                        data_inizio_ts = f"{data_str} 00:00:00+00"
                        data_fine_ts = f"{data_str} 23:59:59+00"

                        if utente_id in assenze_map and data_str in assenze_map[utente_id]:
                            current_date_iter += timedelta(days=1)
                            continue

                        if riposo_dom and current_date_iter.weekday() == 6:
                            turno_assegnato = "R"
                        elif riposo_dom:
                            turno_assegnato = "M"
                        else:
                            turno_assegnato = sequenza_turni[indice_seq]
                            indice_seq = (indice_seq + 1) % len(sequenza_turni)

                        payload_inserimento.append({
                            "organizzazione_id": org_operatore,
                            "reparto_id": rep_operatore,
                            "utente_id": utente_id,
                            "data_inizio": data_inizio
