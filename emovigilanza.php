<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Gestione sicura nel caso in cui $_SESSION['utente'] sia un array anziché una stringa
$nome_utente = is_array($_SESSION['utente']) 
    ? (($_SESSION['utente']['nome'] ?? $_SESSION['utente']['username']) ?? 'Utente') 
    : $_SESSION['utente'];

$messaggio_esito = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_ritiro'])) {
    $id_ritiro = $_POST['id_ritiro'];
    $nuovo_stato_emo = isset($_POST['emovigilanza_ricevuta']) ? true : false;
    
    // Logica di aggiornamento (es. chiamata API a FastAPI / Supabase)
    $messaggio_esito = "Stato emovigilanza aggiornato con successo per la richiesta ID: " . htmlspecialchars($id_ritiro);
}

// SIMULAZIONE DATI (Da sostituire con la tua chiamata reale al database o API Supabase)
// Ciascun elemento rappresenta una richiesta con il suo stato di ritiro e di emovigilanza
$richieste_ospedale = [
    [
        'id' => 'uuid-001',
        'data' => '2026-06-19 23:30',
        'reparto' => 'Chirurgia Generale',
        'turno' => 'Notte',
        'note' => '[Ordinaria] Richiesta sacche',
        'stato' => 'Ritirato', // <-- Già ritirata: mostra la gestione emovigilanza
        'emovigilanza_ricevuta' => false
    ],
    [
        'id' => 'uuid-002',
        'data' => '2026-06-20 02:15',
        'reparto' => 'Medicina Interna',
        'turno' => 'Notte',
        'note' => '[Urgentissima] Controllo ematico',
        'stato' => 'Da ritirare', // <-- Non ancora ritirata: colonna vuota/non attiva
        'emovigilanza_ricevuta' => false
    ],
    [
        'id' => 'uuid-003',
        'data' => '2026-06-20 05:00',
        'reparto' => 'Terapia Intensiva',
        'turno' => 'Notte',
        'note' => '[Ordinaria] Sanguis',
        'stato' => 'Ritirato', // <-- Già ritirata: mostra la gestione emovigilanza
        'emovigilanza_ricevuta' => true
    ]
];
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
                    Utente: <?php echo htmlspecialchars($nome_utente); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Esci</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row mb-3">
            <div class="col-12">
                <h2>Monitoraggio Richieste e Modulo Emovigilanza</h2>
                <p class="text-muted">Gestione centralizzata dei ritiri di sangue, turni ospedalieri e verifica ricezione moduli di emovigilanza (attiva solo dopo il ritiro).</p>
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
                            <?php foreach ($richieste_ospedale as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($r['data']); ?></td>
                                    <td><?php echo htmlspecialchars($r['reparto']); ?></td>
                                    <td><?php echo htmlspecialchars($r['turno']); ?></td>
                                    <td><?php echo htmlspecialchars($r['note']); ?></td>
                                    <td>
                                        <?php if ($r['stato'] === 'Ritirato'): ?>
                                            <span class="badge bg-success">Ritirato</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Da ritirare</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['stato'] === 'Ritirato'): ?>
                                            <!-- Visibile e popolato solo se lo stato è "Ritirato" -->
                                            <?php if ($r['emovigilanza_ricevuta']): ?>
                                                <span class="badge bg-success">Ricevuto</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Mancante</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <!-- Vuoto o non applicabile finché non viene ritirato -->
                                            <span class="text-muted fst-italic">In attesa di ritiro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['stato'] === 'Ritirato' && !$r['emovigilanza_ricevuta']): ?>
                                            <!-- Il pulsante compare solo se è ritirato e l'emovigilanza non è stata ancora segnata come ricevuta -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="id_ritiro" value="<?php echo htmlspecialchars($r['id']); ?>">
                                                <input type="hidden" name="emovigilanza_ricevuta" value="1">
                                                <button type="submit" class="btn btn-sm btn-success">Segna Ricevuto</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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
