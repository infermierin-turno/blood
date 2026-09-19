<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Inclusione delle funzioni condivise per le chiamate API o di database
// require_once 'config.php'; 

// Esempio di logica di aggiornamento stato emovigilanza se inviato via POST
$messaggio_esito = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_ritiro'])) {
    $id_ritiro = $_POST['id_ritiro'];
    $nuovo_stato_emo = isset($_POST['emovigilanza_ricevuta']) ? true : false;
    
    // Qui inserisci la chiamata di aggiornamento verso Supabase o il tuo backend FastAPI
    // Esempio simulato di aggiornamento:
    // $risultato = aggiorna_stato_emovigilanza($id_ritiro, $nuovo_stato_emo);
    
    $messaggio_esito = "Stato emovigilanza aggiornato con successo per la richiesta ID: " . htmlspecialchars($id_ritiro);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Ritiri e Emovigilanza Ospedaliera</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">App Turni & Emoteca</a>
            <div class="d-flex">
                <span class="navbar-text text-white me-3">
                    Utente: <?php echo htmlspecialchars($_SESSION['utente']); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Esci</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row mb-3">
            <div class="col-12">
                <h2>Monitoraggio Richieste e Modulo Emovigilanza</h2>
                <p class="text-muted">Gestione centralizzata dei ritiri di sangue, turni ospedalieri e verifica ricezione moduli di emovigilanza.</p>
            </div>
        </div>

        <?php if (!empty($messaggio_esito)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $messaggio_esito; ?>
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
                                <th>Emovigilanza Ricevuta</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Esempio di riga dati dinamica (da popolare con il recupero record da Supabase) -->
                            <tr>
                                <td>2026-06-19 23:30</td>
                                <td>Chirurgia Generale</td>
                                <td>Notte</td>
                                <td>[Ordinaria] Richiesta sacche urgenti</td>
                                <td><span class="badge bg-warning text-dark">Da ritirare</span></td>
                                <td>
                                    <span class="badge bg-danger">Mancante</span>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="id_ritiro" value="esempio-uuid-123">
                                        <input type="hidden" name="emovigilanza_ricevuta" value="1">
                                        <button type="submit" class="btn btn-sm btn-success">Segna Ricevuto</button>
                                    </form>
                                </td>
                            </tr>
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