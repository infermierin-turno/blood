from datetime import datetime, timedelta
import os
import httpx

SUPABASE_URL = os.getenv("TURNI_SUPABASE_URL", "")
SUPABASE_KEY = os.getenv("TURNI_SUPABASE_KEY", "")

async def verifica_equita_reperibilita(utente_id: str, reparto_id: str, organizzazione_id: str = None):
    """
    Analizza le reperibilità degli ultimi 6 mesi per un dipendente e per il suo reparto,
    restituendo un report di equità e avvisi di superamento soglia.
    """
    headers = {
        "apikey": SUPABASE_KEY,
        "Authorization": f"Bearer {SUPABASE_KEY}",
        "Content-Type": "application/json",
        "Accept-Profile": "public",
        "Content-Profile": "public"
    }

    # Calcola la data di inizio per il semestre precedente (6 mesi fa)
    oggi = datetime.now()
    sei_mesi_fa = (oggi - timedelta(days=180)).strftime('%Y-%m-%d 00:00:00')
    mese_corrente_str = oggi.strftime('%Y-%m')

    async with httpx.AsyncClient() as client:
        # 1. Recupera tutte le reperibilità del reparto negli ultimi 6 mesi dalla tabella pianificazione
        url_pianificazione = f"{SUPABASE_URL}/rest/v1/pianificazione?data_inizio=gte.{sei_mesi_fa}&select=id,utente_id,data_inizio,tipo_evento,reparto_id"
        if reparto_id:
            url_pianificazione += f"&reparto_id=eq.{reparto_id}"
            
        response = await client.get(url_pianificazione, headers=headers)
        if response.status_code != 200:
            return {"errore": "Impossibile contattare Supabase per il controllo equità"}

        turni = response.json()

        # Filtriamo solo quelli relativi alle reperibilità
        reperibilita_totali = []
        for t in turni:
            sigla = str(t.get("tipo_evento", "")).upper()
            if "REP" in sigla or sigla == "R":
                reperibilita_totali.append(t)

        # 2. Raggruppa per utente e conteggia (Totale semestre e Mese corrente)
        statistiche_utenti = {}
        conteggio_mese_corrente = 0

        for r in reperibilita_totali:
            u_id = r.get("utente_id")
            data_str = r.get("data_inizio", "")
            
            if u_id not in statistiche_utenti:
                statistiche_utenti[u_id] = {"semestre": 0, "mese_corrente": 0}
            
            statistiche_utenti[u_id]["semestre"] += 1
            
            if data_str.startswith(mese_corrente_str):
                statistiche_utenti[u_id]["mese_corrente"] += 1

        # Dati specifici dell'utente richiesto
        dati_utente = statistiche_utenti.get(utente_id, {"semestre": 0, "mese_corrente": 0})
        
        # Calcola la media del reparto nel semestre
        tot_utenti_reparto = len(statistiche_utenti) if len(statistiche_utenti) > 0 else 1
        tot_reperibilita_semestre = sum(item["semestre"] for item in statistiche_utenti.values())
        media_reparto_semestre = tot_reperibilita_semestre / tot_utenti_reparto

        # Regole di alert
        soglia_max_mensile = 6
        supero_mensile = dati_utente["mese_corrente"] >= soglia_max_mensile
        sbilanciato_semestre = dati_utente["semestre"] > (media_reparto_semestre * 1.35) # 35% sopra la media

        messaggi_avviso = []
        if supero_mensile:
            messaggi_avviso.append(f"⚠️ Attenzione: Il dipendente ha già raggiunto {dati_utente['mese_corrente']} reperibilità in questo mese (Limite max: {soglia_max_mensile}).")
        if sbilanciato_semestre:
            messaggi_avviso.append(f"📊 Sbilanciamento semestrale: Totale {dati_utente['semestre']} reperibilità nel semestre (Media reparto: {media_reparto_semestre:.1f}).")

        return {
            "utente_id": utente_id,
            "reperibilita_mese": dati_utente["mese_corrente"],
            "reperibilita_semestre": dati_utente["semestre"],
            "media_reparto_semestre": round(media_reparto_semestre, 1),
            "supero_limite_mensile": supero_mensile,
            "sbilanciato_semestre": sbilanciato_semestre,
            "avvisi": messaggi_avviso
        }
