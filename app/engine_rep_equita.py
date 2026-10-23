from datetime import datetime, timedelta
import os
import httpx

SUPABASE_URL = os.getenv("TURNI_SUPABASE_URL", "")
SUPABASE_KEY = os.getenv("TURNI_SUPABASE_KEY", "")

# Codici ufficiali reperibilità definiti nella tabella tipologie_turno
CODICI_REPERIBILITA = ["RDM", "RDP", "RN"]

async def verifica_equita_e_suggerisci_sostituti(
    utente_id: str, 
    reparto_id: str, 
    data_turno: str, 
    tipo_reperibilita: str = "RDM", 
    organizzazione_id: str = None
):
    """
    1. Analizza l'equità semestrale delle reperibilità (RDM, RDP, RN) per il dipendente.
    2. Trova e ordina i migliori sostituti della stessa squadra rispettando la complementarità dei turni.
    """
    headers = {
        "apikey": SUPABASE_KEY,
        "Authorization": f"Bearer {SUPABASE_KEY}",
        "Content-Type": "application/json",
        "Accept-Profile": "public",
        "Content-Profile": "public"
    }

    oggi = datetime.now()
    sei_mesi_fa = (oggi - timedelta(days=180)).strftime('%Y-%m-%d 00:00:00')
    mese_corrente_str = oggi.strftime('%Y-%m')

    async with httpx.AsyncClient() as client:
        # A. Recupero di tutti gli utenti del reparto con la loro squadra
        url_utenti = f"{SUPABASE_URL}/rest/v1/staging_utenti?select=id,nome,email,reparto_id,squadra,ruolo"
        if reparto_id:
            url_utenti += f"&reparto_id=eq.{reparto_id}"
        
        resp_utenti = await client.get(url_utenti, headers=headers)
        if resp_utenti.status_code != 200:
            return {"errore": "Impossibile recuperare gli utenti da Supabase"}
        
        tutti_utenti = resp_utenti.json()
        mappa_utenti = {u["id"]: u for u in tutti_utenti}
        
        utente_target = mappa_utenti.get(utente_id)
        squadra_target = utente_target.get("squadra") if utente_target else None

        # B. Recupero storico pianificazione degli ultimi 6 mesi per il calcolo dell'equità
        url_pianificazione = f"{SUPABASE_URL}/rest/v1/pianificazione?data_inizio=gte.{sei_mesi_fa}&select=id,utente_id,data_inizio,tipo_evento,reparto_id"
        if reparto_id:
            url_pianificazione += f"&reparto_id=eq.{reparto_id}"

        resp_plan = await client.get(url_pianificazione, headers=headers)
        if resp_plan.status_code != 200:
            return {"errore": "Impossibile recuperare la pianificazione da Supabase"}

        turni_totali = resp_plan.json()

        # C. Calcolo statistiche equità semestrale e turni nel giorno specifico
        statistiche_utenti = {}
        turni_giorno_selezionato = {}

        for t in turni_totali:
            u_id = t.get("utente_id")
            sigla = str(t.get("tipo_evento", "")).upper().strip()
            d_inizio = str(t.get("data_inizio", ""))

            # Mappa turni assegnati nella data specifica della sostituzione
            if d_inizio.startswith(data_turno):
                if u_id not in turni_giorno_selezionato:
                    turni_giorno_selezionato[u_id] = []
                turni_giorno_selezionato[u_id].append(sigla)

            # Conteggio Reperibilità (solo RDM, RDP, RN)
            if sigla in CODICI_REPERIBILITA or "REP" in sigla:
                if u_id not in statistiche_utenti:
                    statistiche_utenti[u_id] = {"semestre": 0, "mese_corrente": 0}
                
                statistiche_utenti[u_id]["semestre"] += 1
                if d_inizio.startswith(mese_corrente_str):
                    statistiche_utenti[u_id]["mese_corrente"] += 1

        dati_utente = statistiche_utenti.get(utente_id, {"semestre": 0, "mese_corrente": 0})
        tot_utenti = len(tutti_utenti) if len(tutti_utenti) > 0 else 1
        tot_rep_semestre = sum(item["semestre"] for item in statistiche_utenti.values())
        media_reparto = tot_rep_semestre / tot_utenti

        soglia_max_mensile = 6
        supero_mensile = dati_utente["mese_corrente"] >= soglia_max_mensile
        sbilanciato_semestre = dati_utente["semestre"] > (media_reparto * 1.35)

        avvisi = []
        if supero_mensile:
            avvisi.append(f"⚠️ Limite raggiunto: {dati_utente['mese_corrente']} reperibilità questo mese (Max: {soglia_max_mensile}).")
        if sbilanciato_semestre:
            avvisi.append(f"📊 Sbilanciamento semestrale: Totale {dati_utente['semestre']} reperibilità (Media reparto: {media_reparto:.1f}).")

        # D. Algoritmo di ricerca e ranking sostituti basato sulla complementarità dei turni
        sostituti_proposti = []

        turni_target_giorno = turni_giorno_selezionato.get(utente_id, [])
        turno_target_tipo = ""
        
        # Valutazione del turno del dipendente da sostituire
        for t_sigla in turni_target_giorno:
            s_up = t_sigla.upper().strip()
            if s_up in ["M", "RDM", "STM"]:
                turno_target_tipo = "M"
                break
            elif s_up in ["P", "RDP", "STP"]:
                turno_target_tipo = "P"
                break
            elif s_up in ["N", "RN"]:
                turno_target_tipo = "N"
                break

        # Se il tipo non è dedotto dal turno esistente, usiamo il parametro d'ingresso tipo_reperibilita
        if not turno_target_tipo:
            t_rep_up = tipo_reperibilita.upper().strip()
            if t_rep_up in ["RDM", "M"]:
                turno_target_tipo = "M"
            elif t_rep_up in ["RDP", "P"]:
                turno_target_tipo = "P"
            elif t_rep_up in ["RN", "N"]:
                turno_target_tipo = "N"

        if squadra_target and turno_target_tipo:
            for u_id, u_info in mappa_utenti.items():
                if u_id == utente_id:
                    continue
                
                # Regola 1: Stessa Squadra
                if u_info.get("squadra") != squadra_target:
                    continue

                turni_u = turni_giorno_selezionato.get(u_id, [])
                idoneo = False
                motivo_compatibilita = ""

                # Regola 2: Complementarità dei turni nella stessa data
                # - Target Mattina (M / RDM) -> Sostituto Pomeriggio (P)
                if turno_target_tipo == "M" and any(str(tu).upper().strip() in ["P", "RDP", "STP"] for tu in turni_u):
                    idoneo = True
                    motivo_compatibilita = "In turno di Pomeriggio (compatibile per coprire la Mattina)"
                
                # - Target Pomeriggio (P / RDP) -> Sostituto Mattina (M)
                elif turno_target_tipo == "P" and any(str(tu).upper().strip() in ["M", "RDM", "STM"] for tu in turni_u):
                    idoneo = True
                    motivo_compatibilita = "In turno di Mattina (compatibile per coprire il Pomeriggio)"
                
                # - Target Notte (N / RN) -> Sostituto Smonto (S)
                elif turno_target_tipo == "N" and any(str(tu).upper().strip() == "S" for tu in turni_u):
                    idoneo = True
                    motivo_compatibilita = "In Smonto notte (idoneo per coprire la Notte)"

                if idoneo:
                    tot_rep_u = statistiche_utenti.get(u_id, {}).get("semestre", 0)
                    sostituti_proposti.append({
                        "utente_id": u_id,
                        "nome": u_info.get("nome") or u_info.get("email"),
                        "squadra": u_info.get("squadra"),
                        "reperibilita_semestre": tot_rep_u,
                        "motivo": motivo_compatibilita
                    })

        # Ordina i sostituti per minor numero di reperibilità effettuate nel semestre (Equità)
        sostituti_proposti.sort(key=lambda x: x["reperibilita_semestre"])

        return {
            "utente_id": utente_id,
            "squadra": squadra_target,
            "turno_target": turno_target_tipo,
            "reperibilita_mese": dati_utente["mese_corrente"],
            "reperibilita_semestre": dati_utente["semestre"],
            "media_reparto_semestre": round(media_reparto, 1),
            "supero_limite_mensile": supero_mensile,
            "sbilanciato_semestre": sbilanciato_semestre,
            "avvisi": avvisi,
            "sostituti_consigliati": sostituti_proposti
        }
