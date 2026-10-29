from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
import os
import re
from fastapi import FastAPI, HTTPException, Response, Request, Query
from pydantic import BaseModel, ConfigDict
import httpx
from supabase import Client, create_client
from typing import List, Optional, Union, Any, Dict

# Importiamo l'app di Shopify dal file separato (se presente)
try:
    from main_shopify import app as shopify_app
except ImportError:
    shopify_app = None

app = FastAPI(
    title="Emoteca & Turni Unified Engine", 
    version="3.3.5", 
    redirect_slashes=True
)

# Montiamo l'applicazione Shopify se disponibile
if shopify_app:
    app.mount("/shopify", shopify_app)

# ==========================================
# CONFIGURAZIONE SICURA DA VARIABILI D'AMBIENTE
# ==========================================

# 1. Database Emoteca
EMOTECA_URL = os.getenv("SUPABASE_URL", "").strip().rstrip("/")
EMOTECA_KEY = os.getenv("SUPABASE_KEY", "").strip()
supabase_emoteca: Client = create_client(EMOTECA_URL, EMOTECA_KEY) if EMOTECA_URL and EMOTECA_KEY else None

# 2. Database Turni
TURNI_URL = (os.getenv("TURNI_SUPABASE_URL") or os.getenv("SUPABASE_URL", "")).strip().rstrip("/")
TURNI_KEY = (os.getenv("TURNI_SUPABASE_URL") or os.getenv("SUPABASE_URL", "")).strip()
supabase_turni: Client = create_client(TURNI_URL, TURNI_KEY) if TURNI_URL and TURNI_KEY else None

# Importazione dell'agente di equità (se presente in app/)
try:
    from app.engine_rep_equita import verifica_equita_e_suggerisci_sostituti
except ImportError:
    async def verifica_equita_e_suggerisci_sostituti(*args, **kwargs):
        return {"message": "Modulo equità non disponibile"}


# ==========================================
# SEZIONE STATUS & DEBUG
# ==========================================

@app.get("/api-status")
def home():
    return {
        "status": "online", 
        "message": "Emoteca & Turni Backend online",
        "emoteca_attiva": bool(supabase_emoteca),
        "turni_attivi": bool(supabase_turni)
    }

@app.get("/debug-rotte")
@app.get("/debug-rotte-attive")
def debug_rotte():
    routes = [{"path": route.path, "name": route.name} for route in app.routes]
    return {"status": "ok", "totale_rotte": len(routes), "rotte": routes}


# ==========================================
# SEZIONE MOTORE TURNI & GESTIONE MODALE
# ==========================================

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

class ModificaTurnoRequest(BaseModel):
    model_config = ConfigDict(extra='allow')
    id: Optional[str] = None
    organizzazione_id: Optional[str] = None
    reparto_id: Optional[str] = None
    utente_id: Optional[str] = None
    data_inizio: Optional[str] = None
    data_fine: Optional[str] = None
    tipo_evento: Optional[str] = None
    stato: Optional[str] = "Confermato"
    note: Optional[str] = "Inserito/Modificato da Modale"

@app.post("/api/turni/salva-singolo")
@app.post("/salva-singolo-turno")
async def salva_singolo_turno(payload: ModificaTurnoRequest):
    """Endpoint dedicato alla modale per aggiungere o modificare un singolo turno nel DB Turni"""
    if not TURNI_URL or not TURNI_KEY:
        raise HTTPException(status_code=500, detail="Configurazione Supabase Turni mancante.")

    headers = {
        "apikey": TURNI_KEY,
        "Authorization": f"Bearer {TURNI_KEY}",
        "Content-Type": "application/json",
        "Prefer": "resolution=merge-duplicates"
    }

    dati_turno = {
        "organizzazione_id": payload.organizzazione_id,
        "reparto_id": payload.reparto_id,
        "utente_id": payload.utente_id,
        "data_inizio": payload.data_inizio,
        "data_fine": payload.data_fine or payload.data_inizio,
        "tipo_evento": payload.tipo_evento,
        "stato": payload.stato,
        "note": payload.note
    }

    async with httpx.AsyncClient() as client:
        url = f"{TURNI_URL}/rest/v1/pianificazione"
        if payload.id:
            url_edit = f"{url}?id=eq.{payload.id}"
            resp = await client.patch(url_edit, headers=headers, json=dati_turno)
        else:
            resp = await client.post(url, headers=headers, json=[dati_turno])

        if resp.status_code not in [200, 201, 204]:
            raise HTTPException(status_code=500, detail=f"Errore salvataggio turno su Supabase: {resp.text}")

        return {"success": True, "message": "Turno salvato con successo tramite modale."}


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
        data.turno_iniziale = body_json.get("turno_iniziale") or body_json.get("turno_partenza")
        data.turno_partenza = body_json.get("turno_partenza")
        data.sequenza_turni = body_json.get("sequenza_turni", ["M", "P", "N", "S", "R"])
        data.rispetta_ferie = bool(body_json.get("rispetta_ferie", True))
        data.rispetta_ferie_approvate = bool(body_json.get("rispetta_ferie_approvate", True))
        data.riposo_domenicale = bool(body_json.get("riposo_domenicale", False))
        data.modalita_mattinieri = bool(body_json.get("modalita_mattinieri", False))

    if not TURNI_URL or not TURNI_KEY:
        raise HTTPException(status_code=500, detail="Configurazione Supabase Turni mancante nelle variabili d'ambiente di Render.")

    headers = {
        "apikey": TURNI_KEY,
        "Authorization": f"Bearer {TURNI_KEY}",
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
    sequenza_turni = seq_raw if (seq_raw and isinstance(seq_raw, list) and len(seq_raw) > 0) else ["M", "P", "N", "S", "R"]

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
            url_all = f"{TURNI_URL}/rest/v1/staging_utenti?select=*"
            resp_all = await client.get(url_all, headers=headers)
            
            if resp_all.status_code != 200:
                raise HTTPException(status_code=500, detail=f"Errore Supabase Turni (Status: {resp_all.status_code} - Dettaglio: {resp_all.text}).")
            
            tutti_operatori = resp_all.json()
            if not tutti_operatori or not isinstance(tutti_operatori, list):
                raise HTTPException(status_code=400, detail="Nessun operatore trovato in staging_utenti sul database Turni.")

            operatori = []
            if target_ids:
                for o in tutti_operatori:
                    o_id_str = str(
                        o.get('id') or 
                        o.get('utente_id') or 
                        o.get('user_id') or 
                        o.get('uuid') or 
                        o.get('dipendente_id') or ''
                    )
                    if o_id_str in [str(t) for t in target_ids]:
                        operatori.append(o)
            
            if not operatori and rep_id and str(rep_id).strip() != "" and str(rep_id).lower() != "null":
                operatori = [o for o in tutti_operatori if str(o.get('reparto_id') or o.get('reparto')) == str(rep_id)]

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
                url_assenze = f"{TURNI_URL}/rest/v1/pianificazione?data_inizio=gte.{data_inizio_mese_str} 00:00:00&data_inizio=lte.{data_fine_mese_str} 23:59:59&stato=eq.Approvato&select=utente_id,data_inizio,tipo_evento"
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
                utente_id = (
                    op.get("id") or 
                    op.get("utente_id") or 
                    op.get("user_id") or 
                    op.get("uuid") or 
                    op.get("dipendente_id")
                )
                rep_operatore = op.get("reparto_id") or op.get("reparto") or rep_id
                org_operatore = op.get("organizzazione_id") or org_id

                if not utente_id:
                    continue
                
                if t_iniziale and t_iniziale in sequenza_turni:
                    indice_seq = sequenza_turni.index(t_iniziale)
                else:
                    indice_seq = (index_op * 1) % len(sequenza_turni)

                if is_custom_range and d_start_dt and d_end_dt:
                    current_date_iter = d_start_dt
                    while current_date_iter <= d_end_dt:
                        data_str = current_date_iter.strftime("%Y-%m-%d")
                        data_inizio_ts = f"{data_str} 00:00:00+00"
                        data_fine_ts = f"{data_str} 23:59:59+00"

                        if utente_id in assenze_map and data_str in assenze_map[utente_id]:
                            turno_assegnato = assenze_map[utente_id][data_str]
                            note_turno = "Ferie/Assenza inserita senza alterare la sequenza"
                        else:
                            if riposo_dom and current_date_iter.weekday() == 6:
                                turno_assegnato = "R"
                            else:
                                turno_assegnato = sequenza_turni[indice_seq]
                                indice_seq = (indice_seq + 1) % len(sequenza_turni)
                            note_turno = "Generato rigorosamente da sequenza personalizzata"

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
                            "note": note_turno
                        })
                        current_date_iter += timedelta(days=1)
                else:
                    for giorno in range(1, tot_giorni + 1):
                        current_date_iter = datetime(anno, mese, giorno)
                        data_str = current_date_iter.strftime("%Y-%m-%d")
                        data_inizio_ts = f"{data_str} 00:00:00+00"
                        data_fine_ts = f"{data_str} 23:59:59+00"

                        if utente_id in assenze_map and data_str in assenze_map[utente_id]:
                            turno_assegnato = assenze_map[utente_id][data_str]
                            note_turno = "Ferie/Assenza inserita senza alterare la sequenza"
                        else:
                            if riposo_dom and current_date_iter.weekday() == 6:
                                turno_assegnato = "R"
                            else:
                                turno_assegnato = sequenza_turni[indice_seq]
                                indice_seq = (indice_seq + 1) % len(sequenza_turni)
                            note_turno = "Generato rigorosamente da sequenza personalizzata"

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
                            "note": note_turno
                        })

            if not payload_inserimento:
                return {
                    "success": True,
                    "message": "Nessun turno da generare (tutti i giorni coperti da assenze o intervallo vuoto)."
                }

            url_pianificazione = f"{TURNI_URL}/rest/v1/pianificazione"
            resp_upsert = await client.post(url_pianificazione, headers=headers, json=payload_inserimento)

            if resp_upsert.status_code not in [200, 201, 204]:
                raise HTTPException(status_code=500, detail=f"Errore Supabase Turni salvataggio: {resp_upsert.text}")

            modo_str = " (con ferie gestite senza scartare i turni della sequenza)"
            return {
                "success": True,
                "message": f"Turni generati con successo per {len(operatori)} operatore/i{modo_str}"
            }

        except Exception as e:
            if isinstance(e, HTTPException):
                raise e
            raise HTTPException(status_code=500, detail=f"Errore interno del motore: {str(e)}")


# ==========================================
# SEZIONE EMOTECA & BOOKING
# ==========================================

@app.get("/analizza-scorte")
def analizza_scorte():
    if not supabase_emoteca:
        raise HTTPException(status_code=500, detail="Supabase Emoteca non configurato.")
    try:
        response = (
            supabase_emoteca.table("emoteca_scorte")
            .select("*")
            .eq("stato", "Disponibile")
            .execute()
        )
        sacche = response.data
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

    critiche = []
    for sacca in sacche:
        gruppo = sacca.get("gruppo_sanguigno", "").strip()

        if gruppo == "0 Negativo":
            critiche.append({
                "id": sacca["id"],
                "gruppo_sanguigno": gruppo,
                "priorita": "Massima",
                "messaggio": "Sacca universale critica (0 Negativo) - Priorità massima di utilizzo",
            })
        elif gruppo == "AB Negativo":
            critiche.append({
                "id": sacca["id"],
                "gruppo_sanguigno": gruppo,
                "priorita": "Alta",
                "messaggio": "Gruppo raro (AB Negativo) - Monitorare scorte limitate",
            })

    return {
        "stato": "successo",
        "totale_analizzate": len(sacche),
        "sacche_critiche": critiche,
    }


class BookingPayload(BaseModel):
    reparto: Optional[Union[str, List[str]]] = None
    orario_invio: Optional[str] = None
    note: Optional[Any] = None
    urgenza: Optional[str] = "Ordinaria"
    emoglobina: Optional[Any] = None


@app.post("/ricevi-booking")
def ricevi_booking(payload: BookingPayload, response: Response):
    if not supabase_emoteca:
        raise HTTPException(status_code=500, detail="Supabase Emoteca non configurato.")
    
    reparto_raw = payload.reparto
    if isinstance(reparto_raw, list):
        reparto_val = reparto_raw[0].strip() if reparto_raw else ""
    elif isinstance(reparto_raw, str):
        reparto_val = reparto_raw.strip()
    else:
        reparto_val = ""

    if reparto_val and all(c.isdigit() or c in '.,' for c in reparto_val):
        response.status_code = 200
        return {"status": "SKIPPED", "motivo": "Reparto non valido"}

    if not reparto_val:
        response.status_code = 400
        return "Errore Dati - Campo reparto mancante"

    testo_grezzo = f"{str(payload.note or '')} {str(payload.emoglobina or '')} {str(payload)}"

    emoglobina_trovata = None
    match_emo = re.search(r'(?:emoglobina|hb|valore)[:\s]*([0-9]+([.,][0-9]+)?)', testo_grezzo, re.IGNORECASE)
    if match_emo:
        emoglobina_trovata = match_emo.group(1).replace(',', '.')
    else:
        emo_raw = payload.emoglobina
        if emo_raw:
            emo_str = str(emo_raw[0]) if isinstance(emo_raw, list) and emo_raw else str(emo_raw)
            match_num = re.search(r'([0-9]+([.,][0-9]+)?)', emo_str)
            if match_num:
                emoglobina_trovata = match_num.group(1).replace(',', '.')

    nota_input = str(payload.note) if payload.note and not isinstance(payload.note, (list, dict)) else ""

    orario_input = (
        payload.orario_invio
        if payload.orario_invio
        else datetime.now(ZoneInfo('Europe/Rome')).strftime("%H:%M")
    )

    try:
        parti_ora = orario_input.split(":")
        ora = int(parti_ora[0])
        minuti = int(parti_ora[1])
    except (ValueError, IndexError):
        ora, minuti = datetime.now(ZoneInfo('Europe/Rome')).hour, datetime.now(ZoneInfo('Europe/Rome')).minute

    minuti_totali = (ora * 60) + minuti
    turno_calcolato = "Pomeriggio" if 480 <= minuti_totali <= 750 else "Notte"
    urgenza_input = payload.urgenza if payload.urgenza else "Ordinaria"

    note_finali = f"[{urgenza_input}]"
    if emoglobina_trovata:
        note_finali += f" Emoglobina: {emoglobina_trovata} g/dL -"
    
    if nota_input and "emoglobina" not in nota_input.lower() and "hb" not in nota_input.lower():
        note_finali += f" {nota_input}"

    dati_da_inserire = {
        "reparto": reparto_val,
        "turno_successivo": turno_calcolato,
        "note": note_finali.strip(),
        "stato": "Da ritirare",
        "notifica_inviata": False,
    }

    try:
        res = supabase_emoteca.table("ritiri_sangue").insert(dati_da_inserire).execute()
        response.status_code = 200
        return {
            "status": "OK",
            "turno": turno_calcolato,
            "urgenza": urgenza_input,
            "azione_immediata": True if urgenza_input.lower() == "urgentissima" else False,
        }
    except Exception as e:
        response.status_code = 500
        return f"Errore DB - {str(e)}"


# ==========================================
# ROTTE ACCUMULO (CON E SENZA SLASH FINALE)
# ==========================================

@app.get("/preleva-accumulo-mattina")
@app.post("/preleva-accumulo-mattina")
@app.get("/preleva-accumulo-mattina/")
@app.post("/preleva-accumulo-mattina/")
def preleva_accumulo_mattina():
    if not supabase_emoteca:
        raise HTTPException(status_code=500, detail="Supabase Emoteca non configurato.")
    try:
        response = (
            supabase_emoteca.table("ritiri_sangue")
            .select("*")
            .eq("notifica_inviata", False)
            .execute()
        )
        richieste = response.data

        if not richieste:
            return {
                "status": "ok",
                "totale": 0,
                "richieste": [],
                "html_riepilogo": "<p>Nessuna prenotazione registrata per questa fascia.</p>"
            }

        ids = [r["id"] for r in richieste]
        supabase_emoteca.table("ritiri_sangue").update({"notifica_inviata": True}).in_("id", ids).execute()

        corpo_html = """
            <h2>Riepilogo Richieste Ordinarie (Mattina)</h2>
            <table border='1' style='border-collapse:collapse; padding:8px; width:100%; font-family:Arial, sans-serif;'>
                <tr style='background-color:#f2f2f2;'>
                    <th>Reparto</th>
                    <th>Turno</th>
                    <th>Note</th>
                </tr>
        """
        for r in richieste:
            corpo_html += f"""
                <tr>
                    <td>{r.get('reparto', '')}</td>
                    <td>{r.get('turno_successivo', '')}</td>
                    <td>{r.get('note', '')}</td>
                </tr>
            """
        corpo_html += "</table>"

        return {
            "status": "ok",
            "totale": len(richieste),
            "richieste": richieste,
            "html_riepilogo": corpo_html
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/preleva-accumulo-pomeriggio")
@app.post("/preleva-accumulo-pomeriggio")
@app.get("/preleva-accumulo-pomeriggio/")
@app.post("/preleva-accumulo-pomeriggio/")
def preleva_accumulo_pomeriggio():
    if not supabase_emoteca:
        raise HTTPException(status_code=500, detail="Supabase Emoteca non configurato.")
    try:
        response = (
            supabase_emoteca.table("ritiri_sangue")
            .select("*")
            .eq("notifica_inviata", False)
            .execute()
        )
        richieste = response.data

        if not richieste:
            return {
                "status": "ok",
                "totale": 0,
                "richieste": [],
                "html_riepilogo": "<p>Nessuna prenotazione registrata per questa fascia.</p>"
            }

        ids = [r["id"] for r in richieste]
        supabase_emoteca.table("ritiri_sangue").update({"notifica_inviata": True}).in_("id", ids).execute()

        corpo_html = """
            <h4>Si richiede utilizzo del furgone per consegna richieste pomeridiane e emocomponenti da ritirare (Furgone ore 16:30)</h4>
            <table border='1' style='border-collapse:collapse; padding:8px; width:100%; font-family:Arial, sans-serif;'>
                <tr style='background-color:#f2f2f2;'>
                    <th>Reparto</th>
                    <th>Turno</th>
                    <th>Note</th>
                </tr>
        """
        for r in richieste:
            corpo_html += f"""
                <tr>
                    <td>{r.get('reparto', '')}</td>
                    <td>{r.get('turno_successivo', '')}</td>
                    <td>{r.get('note', '')}</td>
                </tr>
            """
        corpo_html += "</table>"

        return {
            "status": "ok",
            "totale": len(richieste),
            "richieste": richieste,
            "html_riepilogo": corpo_html
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/preleva-accumulo-notte")
@app.post("/preleva-accumulo-notte")
@app.get("/preleva-accumulo-notte/")
@app.post("/preleva-accumulo-notte/")
def preleva_accumulo_notte():
    if not supabase_emoteca:
        raise HTTPException(status_code=500, detail="Supabase Emoteca non configurato.")
    tz_italia = ZoneInfo('Europe/Rome')
    tempo_italia = datetime.now(tz_italia)
    
    ora_attuale = tempo_italia.hour
    minuto_attuale = tempo_italia.minute
    minuti_totali_correnti = (ora_attuale * 60) + minuto_attuale
    
    siamo_di_notte = (minuti_totali_correnti >= 1110) or (minuti_totali_correnti <= 480)
    
    if not siamo_di_notte:
        return {
            "status": "ok",
            "totale": 0,
            "richieste": [],
            "html_riepilogo": "<p>Fuori dalla fascia oraria consentita (18:30 - 08:00).</p>"
        }

    try:
        response = (
            supabase_emoteca.table("ritiri_sangue")
            .select("*")
            .eq("notifica_inviata", False)
            .execute()
        )
        richieste = response.data

        if not richieste:
            return {
                "status": "ok",
                "totale": 0,
                "richieste": [],
                "html_riepilogo": "<p>Nessuna prenotazione registrata per la fascia serale/notturna (18:30 - 08:00).</p>"
            }

        ids = [r["id"] for r in richieste]
        supabase_emoteca.table("ritiri_sangue").update({"notifica_inviata": True}).in_("id", ids).execute()

        orario_invio_effettivo = tempo_italia.strftime("%H:%M")

        corpo_html = f"""
            <h4>Si richiede utilizzo del furgone per consegna richieste serali/notturne e emocomponenti da ritirare (Fascia 18:30 - 08:00)</h4>
            <p><em>Notifiche elaborate e inviate alle ore: {orario_invio_effettivo}</em></p>
            <table border='1' style='border-collapse:collapse; padding:8px; width:100%; font-family:Arial, sans-serif;'>
                <tr style='background-color:#f2f2f2;'>
                    <th>Reparto</th>
                    <th>Turno</th>
                    <th>Note</th>
                </tr>
        """
        for r in richieste:
            corpo_html += f"""
                <tr>
                    <td>{r.get('reparto', '')}</td>
                    <td>{r.get('turno_successivo', '')}</td>
                    <td>{r.get('note', '')}</td>
                </tr>
            """
        corpo_html += "</table>"

        return {
            "status": "ok",
            "totale": len(richieste),
            "richieste": richieste,
            "html_riepilogo": corpo_html
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
