<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Inclusione del file di configurazione centralizzato (richiesto dalle regole di progetto)
require_once 'config.php';

// Controllo sessione utente sicuro
$nome_utente = is_array($_SESSION['utente']) 
    ? (($_SESSION['utente']['nome'] ?? $_SESSION['utente']['username']) ?? 'Utente') 
    : $_SESSION['utente'];

$messaggio_esito = "";
$errore_esito = "";

// 1. GESTIONE AGGIORNAMENTO EMOVIGILANZA TRAMITE POST (alla spunta della checkbox)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_ritiro'])) {
    $id_ritiro = $_POST['id_ritiro'];
    $emovigilanza_ricevuta = isset($_POST['emovigilanza_ricevuta']) ? true : false;

    // Chiamata PATCH a Supabase tramite le costanti o funzioni definite in config.php
    $url_patch = SUPABASE_URL . "/rest/v1/ritiri_sangue?id=eq." . urlencode($id_ritiro);
    $dati_update = json_encode(['emovigilanza_ricevuta' => $emovigilanza_ricevuta]);

    $ch = curl_init($url_patch);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $dati_update);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . SUPABASE_KEY,
        "Authorization: Bearer " . SUPABASE_KEY,
        "Content-Type: application/json",
        "Prefer: return=minimal"
    ]);

    $risposta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $messaggio_esito = "Stato emovigilanza aggiornato con successo.";
    } else {
        $errore_esito = "Errore durante l'aggiornamento su Supabase (Codice: $http_code).";
    }
}

// 2. RECUPERO REALE DA SUPABASE
// Filtriamo i record che sono stati effettivamente ritirati (es. stato = 'Ritirato' oppure consegnato_sit = true)
$url_get = SUPABASE_URL . "/rest/v1/ritiri_sangue?or=(stato.eq.Ritirato,consegnato_sit.eq.true)&order=created_at.desc";

$ch = curl_init($url_get);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: " . SUPABASE_KEY,
    "Authorization: Bearer " . SUPABASE_KEY,
    "Content-Type: application/json"
]);

$response_json = curl_exec($ch);
$http_code_get = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$richieste_effettive = [];
if ($http_code_get >= 200 && $http_code_get < 300) {
    $richieste_effettive = json_decode($response_json, true);
} else {
    $errore_esito = "Impossibile recuperare i dati da Supabase (Codice: $http_code_get). Verifica configurazione in config.php.";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Emovigilanza - Ritiri Effettuati</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">App Turni & Emoteca</a>
            <div class="d-flex">
                <span class="navbar-text text-white me-3">
                    Utente: <?php echo htmlspecialchars($nome_utente); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Esci</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row mb-3">
            <div class="col-12">
                <h2>Verifica Modulo Emovigilanza (Ritiri Effettuati)</h2>
                <p class="text-muted">Elenco delle richieste già ritirate. Spunta la casella per confermare la ricezione del modulo di emovigilanza.</p>
            </div>
        </div>

        <?php if (!empty($messaggio_esito)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $messaggio_esito; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errore_esito)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $errore_esito; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Data / Ora</th>
                                <th>Reparto</th>
                                <th>Turno</th>
                                <th>Note</th>
                                <th>Stato Ritiro</th>
                                <th>Modulo Emovigilanza Ricevuto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($richieste_effettive)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Nessuna richiesta ritirata trovata nel database.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($richieste_effettive as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($r['reparto'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($r['turno_successivo'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge bg-success">Ritirato</span>
                                        </td>
                                        <td>
                                            <!-- Form con checkbox: al cambio invia automaticamente il form salvando il valore -->
                                            <form method="POST" class="d-flex align-items-center">
                                                <input type="hidden" name="id_ritiro" value="<?php echo htmlspecialchars($r['id']); ?>">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="emovigilanza_ricevuta" value="1" 
                                                        id="emo_<?php echo $r['id']; ?>" 
                                                        <?php echo (!empty($r['emovigilanza_ricevuta']) && $r['emovigilanza_ricevuta'] == true) ? 'checked' : ''; ?>
                                                        onchange="this.form.submit()">
                                                    <label class="form-check-label ms-2" for="emo_<?php echo $r['id']; ?>">
                                                        <?php echo (!empty($r['emovigilanza_ricevuta']) && $r['emovigilanza_ricevuta'] == true) ? '<span class="text-success fw-bold">Ricevuto</span>' : '<span class="text-danger">Mancante</span>'; ?>
                                                    </label>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <footer class="text-center text-muted mt-5 py-3">
        <p>&copy; 2026 Coordinamento Sanitario - Gestione Ospedaliera</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
