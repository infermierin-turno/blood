from datetime import datetime
from zoneinfo import ZoneInfo
import os
import re
from fastapi import FastAPI, HTTPException, Response
from pydantic import BaseModel
from supabase import Client, create_client
from typing import List, Optional, Union, Any

# Importiamo l'app di Shopify dal file separato
from main_shopify import app as shopify_app

app = FastAPI()

# Montiamo l'applicazione Shopify sotto il prefisso /shopify
app.mount("/shopify", shopify_app)

SUPABASE_URL = "https://ruvdlcgsmtwszxsposjt.supabase.co"
SUPABASE_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJ1dmRsY2dzbXR3c3p4c3Bvc2p0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODMxNjQ4MzksImV4cCI6MjA5ODc0MDgzOX0.V_nFon6WsICyaiiN1bujrg5P9ORKb8-L1eMBlCFKZF8"
supabase: Client = create_client(SUPABASE_URL, SUPABASE_KEY)


@app.get("/")
def home():
    return {"status": "online", "message": "Shopify Coffee Agent & Hospital Backend online"}


@app.get("/analizza-scorte")
def analizza_scorte():
    try:
        response = (
            supabase.table("emoteca_scorte")
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
                "messaggio": (
                    "Sacca universale critica (0 Negativo) - Priorità massima di utilizzo"
                ),
            })
        elif gruppo == "AB Negativo":
            critiche.append({
                "id": sacca["id"],
                "gruppo_sanguigno": gruppo,
                "priorita": "Alta",
                "messaggio": (
                    "Gruppo raro (AB Negativo) - Monitorare scorte limitate"
                ),
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
    print(f"DEBUG RICEVUTO DA POWER AUTOMATE: {payload}")
    
    # 1. Estrazione sicura e pulita del reparto
    reparto_raw = payload.reparto
    if isinstance(reparto_raw, list):
        reparto_val = reparto_raw[0].strip() if reparto_raw else ""
    elif isinstance(reparto_raw, str):
        reparto_val = reparto_raw.strip()
    else:
        reparto_val = ""

    # FILTRO ANTIRICHIESTE-MONCHE: se il reparto è solo un numero, scartiamo la chiamata sdoppiata
    if reparto_val and all(c.isdigit() or c in '.,' for c in reparto_val):
        print(f"RICHIESTA SCARTATA: Il campo reparto contiene solo un valore numerico ('{reparto_val}').")
        response.status_code = 200
        return {"status": "SKIPPED", "motivo": "Chiamata scartata perché il reparto non è un testo valido"}

    if not reparto_val:
        response.status_code = 400
        return "Errore Dati - Campo reparto mancante"

    # 2. Raccogliamo tutto il testo possibile (note, emoglobina, payload grezzo) per un'analisi unificata
    testo_grezzo = f"{str(payload.note or '')} {str(payload.emoglobina or '')} {str(payload)}"

    # 3. Estrazione intelligente dell'emoglobina tramite Regex flessibile
    emoglobina_trovata = None
    
    # Cerca pattern espliciti come "emoglobina: 11.5", "hb 12", "valore hb: 10" ecc.
    match_emo = re.search(r'(?:emoglobina|hb|valore)[:\s]*([0-9]+([.,][0-9]+)?)', testo_grezzo, re.IGNORECASE)
    if match_emo:
        emoglobina_trovata = match_emo.group(1).replace(',', '.')
    else:
        # Se non c'è l'etichetta esplicita ma c'è un valore numerico nel campo emoglobina specifico
        emo_raw = payload.emoglobina
        if emo_raw:
            emo_str = str(emo_raw[0]) if isinstance(emo_raw, list) and emo_raw else str(emo_raw)
            match_num = re.search(r'([0-9]+([.,][0-9]+)?)', emo_str)
            if match_num:
                emoglobina_trovata = match_num.group(1).replace(',', '.')

    # Pulizia delle note originali (escludendo eventuali duplicati di etichette)
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

    # 4. Composizione pulita delle note finali
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
        res = supabase.table("ritiri_sangue").insert(dati_da_inserire).execute()
        print(f"RISULTATO INSERIMENTO SUPABASE: {res}")

        response.status_code = 200
        return {
            "status": "OK",
            "turno": turno_calcolato,
            "urgenza": urgenza_input,
            "azione_immediata": True
            if urgenza_input.lower() == "urgentissima"
            else False,
        }
    except Exception as e:
        print(f"ERRORE DETTAGLIATO SUPABASE/DB: {str(e)}")
        response.status_code = 500
        return f"Errore DB - {str(e)}"


@app.get("/preleva-accumulo-mattina")
def preleva_accumulo_mattina():
    try:
        response = (
            supabase.table("ritiri_sangue")
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
        supabase.table("ritiri_sangue").update({"notifica_inviata": True}).in_(
            "id", ids
        ).execute()

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
def preleva_accumulo_pomeriggio():
    try:
        response = (
            supabase.table("ritiri_sangue")
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
        supabase.table("ritiri_sangue").update({"notifica_inviata": True}).in_(
            "id", ids
        ).execute()

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
def preleva_accumulo_notte():
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
            supabase.table("ritiri_sangue")
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
        supabase.table("ritiri_sangue").update({"notifica_inviata": True}).in_(
            "id", ids
        ).execute()

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
