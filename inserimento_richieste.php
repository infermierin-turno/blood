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
$debug_output = ""; // Variabile per il debug

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    date_default_timezone_set('Europe/Rome');
    
    $numero_richiesta = trim($_POST['numero_richiesta'] ?? '');
    $paziente = trim($_POST['paziente'] ?? '');
    $codice_fiscale = trim($_POST['codice_fiscale'] ?? '');
    $reparto = trim($_POST['reparto'] ?? '');
    $data_prelievo = trim($_POST['data_prelievo'] ?? date('Y-m-d'));
    $esami = trim($_POST['esami'] ?? '');

    if (!empty($numero_richiesta) && !empty($paziente)) {
        $dati = [
            'numero_richiesta' => $numero_richiesta,
            'paziente' => $paziente,
            'codice_fiscale' => $codice_fiscale,
            'reparto' => $reparto,
            'data_prelievo' => $data_prelievo,
            'esami' => $esami,
            'created_at' => date('c')
        ];

        // --- DEBUG PERSONALIZZATO PER TRACCIARE L'ERRORE ---
        global $supabase_url, $supabase_key;
        
        $url_endpoint = rtrim($supabase_url, '/') . '/rest/v1/richieste_trasporto';
        $payload_json = json_encode($dati);

        $ch = curl_init($url_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "apikey: $supabase_key",
            "Authorization: Bearer $supabase_key",
            "Prefer: return=representation" // Chiediamo a Supabase di restituire l'oggetto inserito
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Catturiamo tutto per vederlo a schermo
        $debug_output = "HTTP Code: $http_code | cURL Error: $curl_error | Risposta Supabase: $response";

        if ($http_code >= 200 && $http_code < 300) {
            $messaggio = "Richiesta N. $numero_richiesta registrata con successo!";
            $tipo_messaggio = "success";
        } else {
            $messaggio = "Errore durante il salvataggio su Supabase (HTTP $http_code).";
            $tipo_messaggio = "error";
        }
    } else {
        $messaggio = "Il Numero Richiesta e il nome del Paziente sono obbligatori.";
        $tipo_messaggio = "error";
    }
}

// Recupero ultime richieste
global $supabase_url, $supabase_key;
$richieste_recenti = [];
if (!empty($supabase_url) && !empty($supabase_key)) {
    $ch = curl_init("$supabase_url/rest/v1/richieste_trasporto?select=*&order=id.desc&limit=15");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: $supabase_key",
        "Authorization: Bearer $supabase_key"
    ]);
    $response_get = curl_exec($ch);
    curl_close($ch);
    $richieste_recenti = json_decode($response_get, true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Inserimento Richieste Trasporto - Furgone</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .debug-box { background: #222; color: #0ff; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 15px; word-break: break-all; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="form-card">
        <h1>Gestione Richieste Trasporto (Furgone)</h1>
        
        <?php if(!empty($messaggio)): ?>
            <div class="<?php echo $tipo_messaggio; ?>"><?php echo $messaggio; ?></div>
        <?php endif; ?>

        <!-- BOX DI DEBUG VISIVO -->
        <?php if(!empty($debug_output)): ?>
            <div class="debug-box">
                <strong>[DEBUG REST API]:</strong><br><?php echo htmlspecialchars($debug_output); ?>
            </div>
        <?php endif; ?>

        <!-- Sezione Scansione Automatica Foto (OCR) -->
        <div class="card border-info mb-4" style="border-radius: 8px;">
            <div class="card-header bg-info text-white" style="border-top-left-radius: 8px; border-top-right-radius: 8px;">
                <h5 class="mb-0 fs-6">📷 Scansione Rapida Fotocamera (Estrai da Foglio)</h5>
            </div>
            <div class="card-body p-3">
                <p class="text-muted small mb-2">Scatta una foto al foglio delle prestazioni:</p>
                <div class="mb-2">
                    <input type="file" class="form-control form-control-sm" id="file_foto" accept="image/*" capture="environment">
                </div>
                <div id="status_ocr" class="fw-bold text-primary small mb-2"></div>
                <button type="button" class="btn btn-outline-info btn-sm w-100" id="btn_esegui_ocr">Estrai Dati dalla Foto</button>
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
                        <?php foreach ($richieste_recenti as $item): ?>
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

    <!-- Script JavaScript per OCR automatico del foglio -->
    <script>
        document.getElementById('btn_esegui_ocr').addEventListener('click', async () => {
            const fileInput = document.getElementById('file_foto');
            const statusDiv = document.getElementById('status_ocr');
            
            if (fileInput.files.length === 0) {
                alert('Seleziona o scatta prima una foto del foglio.');
                return;
            }

            const file = fileInput.files[0];
            statusDiv.innerText = 'Elaborazione immagine in corso (Lettura OCR)...';

            try {
                const worker = await Tesseract.createWorker('ita+eng');
                const ret = await worker.recognize(file);
                const testoRiconosciuto = ret.data.text;
                await worker.terminate();

                statusDiv.innerText = 'Scansione completata! Analisi dati...';

                const matchRichiesta = testoRiconosciuto.match(/\b\d{10}\b/);
                if (matchRichiesta) {
                    document.getElementById('numero_richiesta').value = matchRichiesta[0];
                }

                const matchCF = testoRiconosciuto.match(/[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]/i);
                if (matchCF) {
                    document.getElementById('codice_fiscale').value = matchCF[0].toUpperCase();
                }

                document.getElementById('esami').value = "Scansione OCR completata";
                statusDiv.innerText = 'Dati estratti con successo! Controlla i campi.';
            } catch (err) {
                console.error(err);
                statusDiv.innerText = 'Errore durante la lettura dell\'immagine. Riprova.';
            }
        });
    </script>
</body>
</html>
