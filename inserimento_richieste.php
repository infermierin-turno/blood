<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Configurazione Supabase tramite Variabili d'Ambiente di Render
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = '';
$tipo_messaggio = '';

// Gestione dell'invio del modulo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero_richiesta = trim($_POST['numero_richiesta'] ?? '');
    $paziente = trim($_POST['paziente'] ?? '');
    $codice_fiscale = trim($_POST['codice_fiscale'] ?? '');
    $reparto = trim($_POST['reparto'] ?? '');
    $data_prelievo = trim($_POST['data_prelievo'] ?? date('Y-m-d'));
    $esami = trim($_POST['esami'] ?? '');

    if (!empty($numero_richiesta) && !empty($paziente)) {
        $data = [
            'numero_richiesta' => $numero_richiesta,
            'paziente' => $paziente,
            'codice_fiscale' => $codice_fiscale,
            'reparto' => $reparto,
            'data_prelievo' => $data_prelievo,
            'esami' => $esami,
            'created_at' => date('c')
        ];

        $ch = curl_init("$supabase_url/rest/v1/richieste_trasporto");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "apikey: $supabase_key",
            "Authorization: Bearer $supabase_key",
            "Prefer: return=minimal"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            $messaggio = "Richiesta N. $numero_richiesta registrata con successo su Supabase!";
            $tipo_messaggio = "success";
        } else {
            $messaggio = "Errore durante il salvataggio su Supabase (Codice HTTP: $http_code).";
            $tipo_messaggio = "danger";
        }
    } else {
        $messaggio = "Attenzione: Il Numero Richiesta e il nome del Paziente sono obbligatori.";
        $tipo_messaggio = "warning";
    }
}

// Recupero ultime richieste registrate da Supabase per la tabella di riepilogo
$ch = curl_init("$supabase_url/rest/v1/richieste_trasporto?select=*&order=id.desc&limit=15");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $supabase_key",
    "Authorization: Bearer $supabase_key"
]);
$response_get = curl_exec($ch);
curl_close($ch);
$richieste_recenti = json_decode($response_get, true) ?? [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inserimento Richieste Trasporto - Furgone Sangue</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Tesseract.js per la scansione OCR automatica direttamente da smartphone/fotocamera -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
</head>
<body class="bg-light">
    <div class="container my-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Gestione Richieste Trasporto (Furgone)</h4>
                        <a href="index.php" class="btn btn-sm btn-light">Home</a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($messaggio)): ?>
                            <div class="alert alert-<?php echo $tipo_messaggio; ?>" role="alert">
                                <?php echo htmlspecialchars($messaggio); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Sezione Scansione Automatica Foto (OCR) -->
                        <div class="card border-info mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">Scansione Rapida Fotocamera (Estrai da Foglio)</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small">Scatta una foto al foglio o caricala: il sistema leggerà automaticamente il numero richiesta, il paziente e i dati principali.</p>
                                <div class="mb-3">
                                    <input type="file" class="form-control" id="file_foto" accept="image/*" capture="environment">
                                </div>
                                <div id="status_ocr" class="fw-bold text-primary mb-2"></div>
                                <button type="button" class="btn btn-outline-info w-100" id="btn_esegui_ocr">Estrai Dati dalla Foto</button>
                            </div>
                        </div>

                        <!-- Form di Inserimento / Modifica Dati -->
                        <form method="POST" action="">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="numero_richiesta" class="form-label"><strong>Numero Richiesta *</strong> <span class="text-danger">(Il codice chiave)</span></label>
                                    <input type="text" class="form-control form-control-lg border-primary fw-bold text-dark" id="numero_richiesta" name="numero_richiesta" placeholder="Es. 2622135890" required autofocus>
                                </div>
                                <div class="col-md-6">
                                    <label for="paziente" class="form-label"><strong>Paziente (Cognome Nome) *</strong></label>
                                    <input type="text" class="form-control" id="paziente" name="paziente" placeholder="Es. BRANCACCIO LUIGI" required>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="codice_fiscale" class="form-label">Codice Fiscale</label>
                                    <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale" placeholder="Es. BRNLGU71A20F839X">
                                </div>
                                <div class="col-md-6">
                                    <label for="reparto" class="form-label">Reparto</label>
                                    <input type="text" class="form-control" id="reparto" name="reparto" placeholder="Es. 08 S. SANITARIA">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="data_prelievo" class="form-label">Data Prelievo / Accettazione</label>
                                    <input type="date" class="form-control" id="data_prelievo" name="data_prelievo" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="esami" class="form-label">Esami / Prestazioni</label>
                                    <input type="text" class="form-control" id="esami" name="esami" placeholder="Es. RICERCA MICOBACTERI">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success w-100 py-2 fs-5">Registra Richiesta su Supabase</button>
                        </form>
                    </div>
                </div>

                <!-- Tabella Storico Recenti -->
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Ultime Richieste Inserite</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
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
                </div>

            </div>
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

                // Tentativo di estrazione intelligente dei campi dal testo estratto
                const matchRichiesta = testoRiconosciuto.match(/\b\d{10}\b/);
                if (matchRichiesta) {
                    document.getElementById('numero_richiesta').value = matchRichiesta[0];
                }

                const matchCF = testoRiconosciuto.match(/[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]/i);
                if (matchCF) {
                    document.getElementById('codice_fiscale').value = matchCF[0].toUpperCase();
                }

                document.getElementById('esami').value = "Scansione OCR completata";

                statusDiv.innerText = 'Dati estratti con successo! Controlla i campi prima di salvare.';
            } catch (err) {
                console.error(err);
                statusDiv.innerText = 'Errore durante la lettura dell\'immagine. Riprova.';
            }
        });
    </script>
</body>
</html>