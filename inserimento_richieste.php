<?php
// File: /blood/inserimento_richieste.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['utente'])) { 
    header("Location: index.php"); 
    exit; 
}

require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = "";
$tipo_messaggio = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    date_default_timezone_set('Europe/Rome');
    
    // Supporto sia per chiamata singola (form) sia per chiamata JSON massiva dall'OCR
    $input_json = file_get_contents('php://input');
    $dati_post = !empty($input_json) ? json_decode($input_json, true) :$_POST;

    $numero_richiesta = trim($dati_post['numero_richiesta'] ?? '');
    $paziente = trim($dati_post['paziente'] ?? '');
    $codice_fiscale = trim($dati_post['codice_fiscale'] ?? '');
    $reparto = trim($dati_post['reparto'] ?? '');
    $data_prelievo = trim($dati_post['data_prelievo'] ?? date('Y-m-d'));
    $esami = trim($dati_post['esami'] ?? '');
    
    // Gestione del campo booleano reale salvato su Supabase
    $processato = isset($dati_post['processato']) ? (bool)$dati_post['processato'] : true;

    // Se per qualche motivo il paziente è vuoto, assegnamo un progressivo di emergenza per rispettare il vincolo NOT NULL
    if (empty($paziente)) {$paziente = "PAZIENTE DA VERIFICARE";
    }

    if (!empty($numero_richiesta)) {$dati = [
            'numero_richiesta' => $numero_richiesta,
            'paziente' => $paziente,
            'codice_fiscale' => $codice_fiscale,
            'reparto' => $reparto,
            'data_prelievo' => $data_prelievo,
            'esami' => $esami,
            'processato' => $processato,
            'created_at' => date('c')
        ];

        // Sfruttiamo l'helper centralizzato del progetto
        $risultato = esegui_post_api('richieste_trasporto',$dati);

        // Controllo della risposta dell'API Supabase
        if ($risultato !== null && !isset($risultato['code']) && !isset($risultato['error'])) {$risposta_ok = true;
            $messaggio = "Richiesta N. $numero_richiesta registrata con successo!";
            $tipo_messaggio = "success";
        } else {
            $risposta_ok = false;
            $errore_dettaglio =$risultato['message'] ?? $risultato['error'] ?? 'Errore sconosciuto';$messaggio = "Errore Supabase: " . $errore_dettaglio;
            $tipo_messaggio = "error";
        }

        // Risposta JSON per i cicli multipli via JavaScript
        if (!empty($input_json)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $risposta_ok, 'message' =>$messaggio]);
            exit;
        }
    } else {
        if (!empty($input_json)) {             header('Content-Type: application/json');             echo json_encode(['success' => false, 'message' => 'Numero richiesta obbligatorio mancante.']);             exit;         }$messaggio = "Il Numero Richiesta è obbligatorio.";
        $tipo_messaggio = "error";
    }
}

// Gestione del filtro data per le richieste recenti (GET)
date_default_timezone_set('Europe/Rome');
$filtro_data =$_GET['filtro_data'] ?? '';

if (!empty($filtro_data)) {
    // Filtro per data prelievo esatta su Supabase (formato YYYY-MM-DD)
    $endpoint_storico = "richieste_trasporto?select=*&data_prelievo=eq.$filtro_data&order=id.desc";
} else {
    // Default: limita agli ultimi 3 giorni (data odierna - 3 giorni)
    $data_limite = date('Y-m-d', strtotime('-3 days'));
    $endpoint_storico = "richieste_trasporto?select=*&data_prelievo=gte.$data_limite&order=id.desc";
}

// Recupero richieste filtrate tramite la funzione di get dell'helper
$richieste_recenti = esegui_get_api($endpoint_storico) ?? [];
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

        <!-- Sezione Scansione Automatica Foto (OCR) Multipla -->
        <div class="card border-info mb-4" style="border-radius: 8px;">
            <div class="card-header bg-info text-white" style="border-top-left-radius: 8px; border-top-right-radius: 8px;">
                <h5 class="mb-0 fs-6">📷 Scansione Multipla con Stato</h5>
            </div>
            <div class="card-body p-3">
                <p class="text-muted small mb-2">Scatta la foto: tutte le righe individuate verranno salvate. I nomi trovati sulla foto vengono associati automaticamente (o numerati in sequenza).</p>
                <div class="mb-2">
                    <input type="file" class="form-control form-control-sm" id="file_foto" accept="image/*" capture="environment">
                </div>
                <div id="status_ocr" class="fw-bold text-primary small mb-2"></div>
                <button type="button" class="btn btn-outline-info btn-sm w-100" id="btn_esegui_ocr">Estrai e Verifica Anteprima</button>
            </div>
        </div>

        <form method="POST">
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

    <!-- Modale di Check e Conferma Post-OCR -->
    <div class="modal fade" id="modalCheckOcr" tabindex="-1" aria-labelledby="modalCheckOcrLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalCheckOcrLabel">🔍 Verifica e Stato dei Record Estratti</h5>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Tutte le righe qui sotto verranno salvate. Il check attivo imposta lo stato su <strong>Processata</strong> (spento = <strong>Da verificare</strong>).</p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle" id="tabella_risultati_ocr">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th style="width: 8%;">Processata</th>
                                    <th style="width: 25%;">N. Richiesta</th>
                                    <th style="width: 34%;">Paziente</th>
                                    <th style="width: 33%;">Codice Fiscale</th>
                                </tr>
                            </thead>
                            <tbody id="corpo_tabella_ocr">
                                <!-- Generato dinamicamente via JS -->
                            </tbody>
                        </table>
                    </div>
                    <div id="status_salvataggio_massivo" class="fw-bold text-center mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-success fw-bold" id="btn_conferma_invio_massivo">Salva Tutte le Righe</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabella Storico Recenti con Filtro Data -->
    <div class="form-card" style="max-width: 800px;">
        <h3 class="fs-5 mb-3 text-secondary text-center">Storico Richieste (Ultimi 3 giorni)</h3>
        
        <!-- Form Filtro Data -->
        <form method="GET" class="row g-2 align-items-center mb-3 bg-light p-2 rounded-2 mx-0">
            <div class="col-auto">
                <label for="filtro_data" class="col-form-label fw-bold small mb-0">Filtra per Data:</label>
            </div>
            <div class="col">
                <input type="date" class="form-control form-control-sm mt-0" id="filtro_data" name="filtro_data" value="<?php echo htmlspecialchars($filtro_data); ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm px-3">Filtra</button>
                <?php if (!empty($filtro_data)): ?>
                    <a href="inserimento_richieste.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>N. Richiesta</th>
                        <th>Paziente</th>
                        <th>Reparto</th>
                        <th>Data</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($richieste_recenti) && is_array($richieste_recenti)): ?>
                        <?php foreach ($richieste_recenti as$item): ?>
                            <tr>
                                <td><code><strong><?php echo htmlspecialchars($item['numero_richiesta'] ?? ''); ?></strong></code></td>
                                <td><?php echo htmlspecialchars($item['paziente'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($item['reparto'] ?? ''); ?></td>
                                <td><small><?php echo htmlspecialchars($item['data_prelievo'] ?? ''); ?></small></td>
                                <td>
                                    <?php if (isset($item['processato'])): ?>
                                        <?php if ($item['processato']): ?>
                                            <span class="badge bg-success">Processata</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Da verificare</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N.D.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-3 text-muted">Nessuna richiesta trovata per i criteri selezionati.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bootstrap JS Bundle (necessario per il Modale) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script JavaScript per OCR e Gestione Check Intermedio -->
    <script>
        let modalCheckInstance = null;

        document.addEventListener('DOMContentLoaded', () => {
            modalCheckInstance = new bootstrap.Modal(document.getElementById('modalCheckOcr'));
        });

        document.getElementById('btn_esegui_ocr').addEventListener('click', async () => {
            const fileInput = document.getElementById('file_foto');
            const statusDiv = document.getElementById('status_ocr');
            
            if (fileInput.files.length === 0) {
                alert('Seleziona o scatta prima una foto del foglio.');
                return;
            }

            const file = fileInput.files[0];
            statusDiv.innerText = 'Elaborazione immagine in corso (OCR)...';

            try {
                const worker = await Tesseract.createWorker('ita+eng');
                const ret = await worker.recognize(file);
                const testoRiconosciuto = ret.data.text;
                await worker.terminate();

                statusDiv.innerText = 'Analisi e strutturazione dei dati...';

                const linee = testoRiconosciuto.split('\n').map(l => l.trim()).filter(l => l.length > 2);
                
                let numeriRichiestaTrovati = [];
                let pazientiTrovati = [];
                let codiciFiscaliTrovati = [];

                linee.forEach(riga => {
                    let matchReq = riga.match(/\b\d{8,12}\b/g);
                    if (matchReq) {
                        matchReq.forEach(num => {
                            if (!numeriRichiestaTrovati.includes(num)) {
                                numeriRichiestaTrovati.push(num);
                            }
                        });
                    }

                    let matchCF = riga.match(/[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]/gi);
                    if (matchCF) {
                        matchCF.forEach(cf => {
                            if (!codiciFiscaliTrovati.includes(cf.toUpperCase())) {
                                codiciFiscaliTrovati.push(cf.toUpperCase());
                            }
                        });
                    }

                    // Cerca stringhe testuali in maiuscolo che sembrano nomi di persona (almeno 2 parole, escludendo termini fissi)
                    let upperRiga = riga.toUpperCase();
                    if (/^[A-Z\s]{5,}$/.test(riga) && 
                        !upperRiga.includes('REGIONE') && 
                        !upperRiga.includes('AZIENDA') && 
                        !upperRiga.includes('CODICE') && 
                        !upperRiga.includes('FISCALE') &&
                        !upperRiga.includes('RICHIESTA') &&
                        !upperRiga.includes('DATA') &&
                        !upperRiga.includes('PRELIEVO')) {
                        if (riga.split(' ').length >= 2 && !pazientiTrovati.includes(riga)) {
                            pazientiTrovati.push(riga);
                        }
                    }
                });

                if (numeriRichiestaTrovati.length === 0) {
                    statusDiv.innerText = 'Nessun numero di richiesta rilevato. Riprova con una foto più nitida.';
                    return;
                }

                // Popoliamo la tabella del modale di check
                let tbody = document.getElementById('corpo_tabella_ocr');
                tbody.innerHTML = '';

                for (let i = 0; i < numeriRichiestaTrovati.length; i++) {
                    let numReq = numeriRichiestaTrovati[i];
                    // Se l'OCR trova un nome lo usa, altrimenti assegna un progressivo che parte da 1 per questa foto
                    let nomePaziente = pazientiTrovati[i] || `PAZIENTE ${i + 1} (DA VERIFICARE)`;
                    let codiceFisc = codiciFiscaliTrovati[i] || "";

                    let tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="text-center">
                            <input class="form-check-input row-processata" type="checkbox" checked style="width: 22px; height: 22px;" title="Seleziona per segnare come Processata">
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm val-richiesta" value="${numReq}">
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm val-paziente" value="${nomePaziente}" placeholder="Inserisci nome paziente">
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm val-cf" value="${codiceFisc}" placeholder="Codice fiscale (opzionale)">
                        </td>
                    `;
                    tbody.appendChild(tr);
                }

                statusDiv.innerText = '';
                modalCheckInstance.show();

            } catch (err) {
                console.error(err);
                statusDiv.innerText = 'Errore durante l\'elaborazione dell\'immagine.';
            }
        });

        // Pulsante di conferma finale nel modale
        document.getElementById('btn_conferma_invio_massivo').addEventListener('click', async () => {
            const righe = document.querySelectorAll('#corpo_tabella_ocr tr');
            let recordsDaSalvare = [];
            let repartoDefault = document.getElementById('reparto').value || 'Reparto Gen.';
            let dataDefault = document.getElementById('data_prelievo').value;

            righe.forEach(riga => {
                let checkbox = riga.querySelector('.row-processata');
                let numReq = riga.querySelector('.val-richiesta').value.trim();
                let paziente = riga.querySelector('.val-paziente').value.trim();
                let cf = riga.querySelector('.val-cf').value.trim();
                let isProcessato = checkbox ? checkbox.checked : false;

                if (numReq !== '') {
                    if (paziente === '') {
                        paziente = "PAZIENTE DA VERIFICARE";
                    }
                    recordsDaSalvare.push({
                        numero_richiesta: numReq,
                        paziente: paziente,
                        codice_fiscale: cf,
                        reparto: repartoDefault,
                        data_prelievo: dataDefault,
                        esami: "Esami da scansione OCR",
                        processato: isProcessato
                    });
                }
            });

            if (recordsDaSalvare.length === 0) {
                alert('Nessun record valido da salvare.');
                return;
            }

            let statusSalvataggio = document.getElementById('status_salvataggio_massivo');
            statusSalvataggio.innerText = `Salvataggio in corso di ${recordsDaSalvare.length} record su Supabase...`;
            statusSalvataggio.className = "fw-bold text-center mt-3 text-primary";

            let salvate = 0;
            for (let rec of recordsDaSalvare) {
                try {
                    let response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(rec)
                    });
                    let resJson = await response.json();
                    if (resJson.success) {
                        salvate++;
                    }
                } catch (e) {
                    console.error("Errore salvataggio riga:", e);
                }
            }

            statusSalvataggio.innerText = `Completato! Registrate con successo ${salvate} su ${recordsDaSalvare.length} richieste. Ricaricamento...`;
            statusSalvataggio.className = "fw-bold text-center mt-3 text-success";

            setTimeout(() => { window.location.reload(); }, 1500);
        });
    </script>
</body>
</html>
