import os
from datetime import datetime, timedelta
from fastapi import FastAPI, HTTPException, Query
from pydantic import BaseModel, ConfigDict
import httpx
from typing import Optional, List, Dict, Any

# Importazione dell'agente di equità e suggerimento sostituti per la reperibilità
from app.engine_rep_equita import verifica_equita_e_suggerisci_sostituti

app = FastAPI(title="TurnoMed Python Engine", version="2.8.17")

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

@app.get("/")
def read_root():
    return {"status": "online", "message": "TurnoMed Python Engine attivo!"}

@app.get("/api/equita/reperibilita")
async def api_verifica_equita(
    utente_id: str = Query(..., description="ID del dipendente"),
    reparto_id: str = Query(..., description="ID del reparto"),
    data_turno: str = Query(..., description="Data del turno YYYY-MM-DD"),
    tipo_reperibilita: str = Query("REP", description="Tipo o fascia di reperibilità"),
    organizzazione_id: str = Query(None, description="ID organizzazione opzionale")
):
    """
    Endpoint per il controllo semestrale dell'equità di reperibilità 
    e il suggerimento intelligente dei sostituti basato su squadra e turni complementari.
    """
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

@app.post("/genera-turni")
@app.post("/app/genera-turni")
async def genera_turni(data: GenerazioneRequest):
    if not SUPABASE_URL or not SUPABASE_KEY:
        raise HTTPException(status_code=500, detail="Configurazione Supabase mancante.")

    headers = {
        "apikey": SUPABASE_KEY,
        "Authorization": f"Bearer {SUPABASE_KEY}",
        "Content-Type": "application/json",
        "Prefer": "resolution=merge-duplicates,on_conflict=utente_id,data_inizio"
    }

    org_id = data.organizzazione_id
    rep_id = data.reparto_id
    op_id = data.operatore_id or data.utente_id
    
    # Raccogliamo gli ID target passati dalla modale PHP (operatori_ids o utenti_ids)
    target_ids = data.operatori_ids or data.utenti_ids or []
    if op_id and op_id not in target_ids:
        target_ids.append(op_id)

    t_iniziale = data.turno_iniziale or data.turno_partenza
    riposo_dom = data.riposo_domenicale or data.modalita_mattinieri
    sequenza_turni = data.sequenza_turni if (data.sequenza_turni and len(data.sequenza_turni) > 0) else ["M", "P", "N", "S", "R"]

    autore_uuid = op_id if op_id else (target_ids[0] if target_ids else None)

    if not data.anno or not data.mese:
        if data.data_inizio:
            try:
                dt_parsed = datetime.strptime(str(data.data_inizio)[:10], "%Y-%m-%d")
                anno = dt_parsed.year
                mese = dt_parsed.month
            except:
                anno = datetime.now().year
                mese = datetime.now().month
        else:
            anno = datetime.now().year
            mese = datetime.now().month
    else:
        anno = data.anno
        mese = data.mese

    async with httpx.AsyncClient() as client:
        try:
            # Interrogazione pulita della tabella staging_utenti
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
            if data.data_inizio and data.data_fine:
                try:
                    d_start_dt = datetime.strptime(str(data.data_inizio)[:10], "%Y-%m-%d")
                    d_end_dt = datetime.strptime(str(data.data_fine)[:10], "%Y-%m-%d")
                    if (d_end_dt - d_start_dt).days >= 0:
                        is_custom_range = True
                        data_inizio_mese_str = d_start_dt.strftime("%Y-%m-%d")
                        data_fine_mese_str = d_end_dt.strftime("%Y-%m-%d")
                except:
                    is_custom_range = False

            # Recupero ferie/assenze approvate se richiesto
            assenze_map = {}
            if data.rispetta_ferie or data.rispetta_ferie_approvate:
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
                            "data_inizio": data_inizio_ts,
                            "data_fine": data_fine_ts,
                            "tipo_evento": turno_assegnato,
                            "stato": "Generato da AI",
                            "creato_da": autore_uuid,
                            "modificato_da": autore_uuid,
                            "note": "Generato automaticamente da Motore AI"
                        })
                        current_date_iter += timedelta(days=1)
                else:
                    for giorno in range(1, tot_giorni + 1):
                        current_date_iter = datetime(anno, mese, giorno)
                        data_str = current_date_iter.strftime("%Y-%m-%d")
                        data_inizio_ts = f"{data_str} 00:00:00+00"
                        data_fine_ts = f"{data_str} 23:59:59+00"

                        if utente_id in assenze_map and data_str in assenze_map[utente_id]:
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
                            "data_inizio": data_inizio_ts,
                            "data_fine": data_fine_ts,
                            "tipo_evento": turno_assegnato,
                            "stato": "Generato da AI",
                            "creato_da": autore_uuid,
                            "modificato_da": autore_uuid,
                            "note": "Generato automaticamente da Motore AI"
                        })

            if not payload_inserimento:
                return {
                    "success": True,
                    "message": "Nessun turno da generare (tutti i giorni coperti da assenze o intervallo vuoto)."
                }

            url_pianificazione = f"{SUPABASE_URL}/rest/v1/pianificazione"
            resp_upsert = await client.post(url_pianificazione, headers=headers, json=payload_inserimento)

            if resp_upsert.status_code not in [200, 201, 204]:
                raise HTTPException(status_code=500, detail=f"Errore Supabase durante il salvataggio: {resp_upsert.text}")

            modo_str = " (con domeniche di riposo garantite)" if riposo_dom else ""
            return {
                "success": True,
                "message": f"Turni generati con successo per {len(operatori)} operatore/i{modo_str}"
            }

        except Exception as e:
            if isinstance(e, HTTPException):
                raise e
            raise HTTPException(status_code=500, detail=f"Errore interno del motore: {str(e)}")
