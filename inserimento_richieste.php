<?php
// File: /blood/inserimento_richieste.php
session_start();
if (!isset($_SESSION['utente'])) { 
    header("Location: index.php"); 
    exit; 
}

require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = "";
$tipo_messaggio = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    date_default_timezone_set('Europe/Rome');
    
    $numero_richiesta = trim($_POST['numero_richiesta'] ?? '');
    $paziente = trim($_POST['paziente'] ?? '');
    $codice_fiscale = trim($_POST['codice_fiscale'] ?? '');
    $reparto = trim($_POST['reparto'] ?? '');
    $data_prelievo = trim($_POST['data_prelievo'] ?? date('Y-m-d'));
    $esami = trim($_POST['esami'] ?? '');

    if (!empty($numero_richiesta) && !empty($paziente)) {$dati = [
            'numero_richiesta' => $numero_richiesta,
            'paziente' => $paziente,
            'codice_fiscale' => $codice_fiscale,
            'reparto' => $reparto,
            'data_prelievo' => $data_prelievo,
            'esami' => $esami,
            'created_at' => date('c')
        ];

        // Sfruttiamo l'helper centralizzato del progetto
        $risultato = esegui_post_api('richieste_trasporto',$dati);

        if ($risultato !== null && !isset($risultato['code'])) {$messaggio = "Richiesta N. $numero_richiesta registrata con successo su Supabase!";
            $tipo_messaggio = "success";
        } else {
            $errore_dettaglio = $risultato['message'] ?? 'Errore sconosciuto';$messaggio = "Errore durante il salvataggio: " . $errore_dettaglio;
            $tipo_messaggio = "error";
        }
    } else {
        $messaggio = "Il Numero Richiesta e il nome del Paziente sono obbligatori.";
        $tipo_messaggio = "error";
    }
}

// Recupero ultime richieste tramite la funzione di get dell'helper
$richieste_recenti = esegui_get_api('richieste_trasporto?select=*&order=id.desc&limit=15') ?? [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Inserimento Richieste Trasporto - Furgone</title>
    <!-- Bootstrap CSS per coerenza grafica -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Tesseract.js per la scansione OCR automatica della foto -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 15px; background: #f4f4f9; margin: 0; }
        .form-card { background: white; padding: 20px; border-radius: 12px; max-width: 600px; margin: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 25px; }
        h1 { font-size: 1.5rem; text-align: center; color: #333; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; font-weight: 600; color: #555; }
        input, select, textarea { width: 100%; padding: 12px; margin-top: 5px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 16px; }
        button[type="submit"] { width: 100%; padding: 16px; margin-top: 25px; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 18px; font-weight: bold; }
        .success { color: #155724; background: #d4edda; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 15px; font-weight: bold; }
        .error { color: #721c24; background: #f8d7da; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 15px; font-weight: bold; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="form-card">
        <h1>Gestione Richieste Trasporto (Furgone)</h1>
        
        <?php if(!empty($messaggio)): ?>
            <div class="<?php echo $tipo_messaggio; ?>"><?php echo htmlspecialchars($messaggio); ?></div>
        <?php endif; ?>

        <!-- Sezione Scansione Automatica Foto (OCR) -->
        <div class="card border-info mb-4" style="border-radius: 8px;">
            <div class="card-header bg-info text-white" style="border-top-left-radius: 8px; border-top-right-radius: 8px;">
                <h5 class="mb-0 fs-6">📷 Scansione Multipla da Foglio (Estrai Tutti i Record)</h5>
            </div>
            <div class="card-body p-3">
                <p class="text-muted small mb-2">Scatta una foto al foglio riepilogativo per estrarre e registrare automaticamente <strong>tutte le righe</strong> presenti:</p>
                <div class="mb-2">
                    <input type="file" class="form-control form-control-sm" id="file_foto" accept="image/*" capture="environment">
                </div>
                <div id="status_ocr" class="fw-bold text-primary small mb-2"></div>
                <button type="button" class="btn btn-outline-info btn-sm w-100" id="btn_esegui_ocr">Estrai e Salva Tutti i Record</button>
            </div>
        </div>

        <form method="POST" id="form_inserimento">
            <label>Numero Richiesta *</label>
            <input type="text" name="numero_richiesta" id="numero_richiesta" placeholder="es. 2622135890" required autofocus>

            <label>Cognome e Nome Paziente *</label>
            <input type="text" name="paziente" id="paziente" placeholder="es. BRANCACCIO LUIGI" required>

            <label>Codice Fiscale</label>
            <input type="text" name="codice_fiscale" id="codice_fiscale" placeholder="es. BRNLGU71A20F839X">

            <label>Reparto di Provenienza</label>
            <input type="text" name="reparto" id="reparto" placeholder="es. 08 S. SANITARIA">

            <label>Data Prelievo / Accettazione</label>
            <input type="date" name="data_prelievo" id="data_prelievo" value="<?php echo date('Y-m-d'); ?>">

            <label>Esami / Prestazioni</label>
            <input type="text" name="esami" id="esami" placeholder="es. RICERCA MICOBACTERI">

            <button type="submit">Registra Richiesta su Supabase</button>
        </form>

        <a href="bacheca_ritiri.php" class="back-link">Torna alla bacheca</a>
    </div>

    <!-- Tabella Storico Recenti -->
    <div class="form-card" style="max-width: 800px;">
        <h3 class="fs-5 mb-3 text-secondary text-center">Ultime Richieste Inserite</h3>
        <div class="table-responsive">
            <table class="table table-striped table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>N. Richiesta</th>
                        <th>Paziente</th>
                        <th>Reparto</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($richieste_recenti) && is_array($richieste_recenti)): ?>
                        <?php foreach ($richieste_recenti as$item): ?>
                            <tr>
                                <td><code><strong><?php echo htmlspecialchars($item['numero_richiesta'] ?? ''); ?></strong></code></td>
                                <td><?php echo htmlspecialchars($item['paziente'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($item['reparto'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($item['data_prelievo'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center py-3 text-muted">Nessuna richiesta recente trovata.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Script JavaScript per OCR Multiplo e invio massivo a Supabase -->
    <script>
        document.getElementById('btn_esegui_ocr').addEventListener('click', async () => {
            const fileInput = document.getElementById('file_foto');
            const statusDiv = document.getElementById('status_ocr');
            
            if (fileInput.files.length === 0) {
                alert('Seleziona o scatta prima una foto del foglio.');
                return;
            }

            const file = fileInput.files[0];
            statusDiv.innerText = 'Elaborazione immagine in corso (OCR in corso)...';

            try {
                const worker = await Tesseract.createWorker('ita+eng');
                const ret = await worker.recognize(file);
                const testoRiconosciuto = ret.data.text;
                await worker.terminate();

                statusDiv.innerText = 'Analisi multi-riga in corso...';

                const linee = testoRiconosciuto.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                
                let recordsTrovati = [];
                let richiestaCorrente = "";
                let pazienteCorrente = "";
                let cfCorrente = "";
                let esamiCorrenti = [];

                // Analisi riga per riga per individuare blocchi di richieste multiple
                for (let i = 0; i < linee.length; i++) {
                    let riga = linee[i];
                    let rigaUpper = riga.toUpperCase();

                    // Cerca un codice richiesta (es. sequenza di 8-12 cifre)
                    let matchReq = riga.match(/\b\d{8,12}\b/);
                    if (matchReq) {
                        // Se avevamo già una richiesta aperta, la salviamo prima di iniziare la nuova
                        if (richiestaCorrente && pazienteCorrente) {
                            recordsTrovati.push({
                                numero_richiesta: richiestaCorrente,
                                paziente: pazienteCorrente,
                                codice_fiscale: cfCorrente,
                                reparto: document.getElementById('reparto').value || 'Reparto Gen.',
                                data_prelievo: document.getElementById('data_prelievo').value,
                                esami: esamiCorrenti.length > 0 ? esamiCorrenti.join(', ') : 'Esami da foglio'
                            });
                        }
                        richiestaCorrente = matchReq[0];
                        pazienteCorrente = "";
                        cfCorrente = "";
                        esamiCorrenti = [];
                        continue;
                    }

                    // Cerca Codice Fiscale all'interno della riga
                    let matchCF = riga.match(/[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]/i);
                    if (matchCF) {
                        cfCorrente = matchCF[0].toUpperCase();
                        // Spesso il nome del paziente precede il codice fiscale sulla stessa riga o su quella sopra
                        let pulita = riga.replace(matchCF[0], '').trim();
                        if (pulita.length > 3 && /^[A-Z\s]+$/.test(pulita)) {
                            pazienteCorrente = pulita;
                        }
                        continue;
                    }

                    // Se la riga è composta da lettere maiuscole e spazi, potrebbe essere il nome del paziente
                    if (!pazienteCorrente && /^[A-Z\s]{5,}$/.test(riga) && !rigaUpper.includes('REGIONE') && !rigaUpper.includes('AZIENDA') && !rigaUpper.includes('PAGINA')) {
                        if (riga.split(' ').length >= 2) {
                            pazienteCorrente = riga;
                            continue;
                        }
                    }

                    // Raccoglie eventuali descrizioni di esami
                    if (rigaUpper.includes('RICERCA') || rigaUpper.includes('TEST') || rigaUpper.includes('ESAME') || rigaUpper.includes('DOSAGGIO')) {
                        esamiCorrenti.push(riga);
                    }
                }

                // Aggiunge l'ultimo record rimasto in coda
                if (richiestaCorrente && pazienteCorrente) {
                    recordsTrovati.push({
                        numero_richiesta: richiestaCorrente,
                        paziente: pazienteCorrente,
                        codice_fiscale: cfCorrente,
                        reparto: document.getElementById('reparto').value || 'Reparto Gen.',
                        data_prelievo: document.getElementById('data_prelievo').value,
                        esami: esamiCorrenti.length > 0 ? esamiCorrenti.join(', ') : 'Esami da foglio'
                    });
                }

                if (recordsTrovati.length === 0) {
                    // Fallimento parsing strutturato: compila almeno la prima riga nel form classico
                    statusDiv.innerText = 'Impossibile estrarre blocchi multipli puliti. Compilazione singolo modulo...';
                    const matchRichiestaUnica = testoRiconosciuto.match(/\b\d{8,12}\b/);
                    if (matchRichiestaUnica) document.getElementById('numero_richiesta').value = matchRichiestaUnica[0];
                    document.getElementById('esami').value = "Testo OCR: " + testoRiconosciuto.substring(0, 100);
                    return;
                }

                statusDiv.innerText = `Trovate ${recordsTrovati.length} richieste. Invio in corso a Supabase...`;

                // Invio in blocco di tutte le righe trovate tramite fetch asincrona o ciclo POST
                let salvate = 0;
                for (let rec of recordsTrovati) {
                    try {
                        let response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams(rec)
                        });
                        if (response.ok) salvate++;
                    } catch (e) {
                        console.error("Errore invio record:", e);
                    }
                }

                statusDiv.innerText = `Completato! Salvate con successo ${salvate} su ${recordsTrovati.length} richieste. Ricaricamento...`;
                setTimeout(() => { window.location.reload(); }, 1500);

            } catch (err) {
                console.error(err);
                statusDiv.innerText = 'Errore durante l\'elaborazione dell\'immagine.';
            }
        });
    </script>
</body>
</html>
