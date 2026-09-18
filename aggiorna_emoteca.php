<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

$supabase_url = "https://ruvdlcgsmtwszxsposjt.supabase.co";
$supabase_key = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJ1dmRsY2dzbXR3c3p4c3Bvc2p0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODMxNjQ4MzksImV4cCI6MjA5ODc0MDgzOX0.V_nFon6WsICyaiiN1bujrg5P9ORKb8-L1eMBlCFKZF8";

$messaggio = "";
$errore = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['azione']) && $_POST['azione'] === 'aggiungi') {
        $gruppo = trim($_POST['gruppo_sanguigno'] ?? '');
        $stato = trim($_POST['stato'] ?? 'Disponibile');
        
        $data = json_encode([
            'gruppo_sanguigno' => $gruppo,
            'stato' => $stato
        ]);
        
        $ch = curl_init("$supabase_url/rest/v1/emoteca_scorte");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $supabase_key",
            "Authorization: Bearer $supabase_key",
            "Content-Type: application/json",
            "Prefer: return=representation"
        ]);
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            $messaggio = "Nuova sacca aggiunta con successo!";
        } else {
            $errore = "Errore durante l'inserimento su Supabase (Codice HTTP: $http_code)";
        }
    } elseif (isset($_POST['id_aggiorna']) && isset($_POST['nuovo_stato'])) {
        $id = $_POST['id_aggiorna'];
        $nuovo_stato = $_POST['nuovo_stato'];
        
        $data = json_encode(['stato' => $nuovo_stato]);
        
        $ch = curl_init("$supabase_url/rest/v1/emoteca_scorte?id=eq.$id");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: $supabase_key",
            "Authorization: Bearer $supabase_key",
            "Content-Type: application/json",
            "Prefer: return=minimal"
        ]);
        curl_exec($ch);
        curl_close($ch);
        $messaggio = "Stato della sacca aggiornato con successo!";
    }
}

$ch = curl_init("$supabase_url/rest/v1/emoteca_scorte?select=*&order=data_inserimento.desc");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $supabase_key",
    "Authorization: Bearer $supabase_key"
]);
$response = curl_exec($ch);
curl_close($ch);
$scorte = json_decode($response, true);
if (!is_array($scorte)) {
    $scorte = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Scorte Emoteca</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="mb-4">Gestione Scorte Emoteca (0 Negativo e AB Negativo)</h1>
    
    <?php if (!empty($messaggio)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($messaggio); ?></div>
    <?php endif; ?>
    <?php if (!empty($errore)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($errore); ?></div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">Aggiungi Nuova Sacca</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="azione" value="aggiungi">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Gruppo Sanguigno</label>
                        <select name="gruppo_sanguigno" class="form-select" required>
                            <option value="0 Negativo">0 Negativo (Critica - Massima Priorità)</option>
                            <option value="AB Negativo">AB Negativo (Rara - Alta Priorità)</option>
                            <option value="0 Positivo">0 Positivo</option>
                            <option value="A Positivo">A Positivo</option>
                            <option value="A Negativo">A Negativo</option>
                            <option value="B Positivo">B Positivo</option>
                            <option value="B Negativo">B Negativo</option>
                            <option value="AB Positivo">AB Positivo</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Stato Iniziale</label>
                        <select name="stato" class="form-select" required>
                            <option value="Disponibile">Disponibile</option>
                            <option value="Utilizzato">Utilizzato</option>
                            <option value="Scaduto">Scaduto</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100">Registra</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">Elenco Scorte e Monitoraggio Priorità</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID Sacca</th>
                            <th>Gruppo Sanguigno</th>
                            <th>Stato Attuale</th>
                            <th>Data Inserimento</th>
                            <th>Azioni Rapide</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($scorte)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Nessuna sacca presente in archivio.</td></tr>
                        <?php else: ?>
                            <?php foreach ($scorte as $sacca): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($sacca['id'] ?? ''); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($sacca['gruppo_sanguigno'] ?? ''); ?></strong>
                                        <?php if (($sacca['gruppo_sanguigno'] ?? '') === '0 Negativo'): ?>
                                            <span class="badge bg-danger ms-2">Massima Priorità</span>
                                        <?php elseif (($sacca['gruppo_sanguigno'] ?? '') === 'AB Negativo'): ?>
                                            <span class="badge bg-warning text-dark ms-2">Priorità Alta</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo (($sacca['stato'] ?? '') === 'Disponibile') ? 'success' : 'secondary'; ?>">
                                            <?php echo htmlspecialchars($sacca['stato'] ?? ''); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($sacca['data_inserimento'] ?? ''); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="id_aggiorna" value="<?php echo htmlspecialchars($sacca['id']); ?>">
                                            <select name="nuovo_stato" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                <option value="" disabled selected>Cambia stato...</option>
                                                <option value="Disponibile">Disponibile</option>
                                                <option value="Utilizzato">Utilizzato</option>
                                                <option value="Scaduto">Scaduto</option>
                                            </select>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>