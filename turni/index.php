<?php
session_start();
require_once 'config.php';

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        $utenti = [];

        // DEBUG: Scarichiamo TUTTI i record da staging_utenti senza filtri per ispezionarli
        $endpoint = "staging_utenti?select=*";
        $risposta = supabase_request($endpoint);

        // --- SCHERMATA DI DEBUG VISIVA ---
        echo "<div style='background: #1e1e1e; color: #00ff00; padding: 20px; margin: 20px; font-family: monospace; border: 2px solid #00ff00; border-radius: 8px; z-index: 99999; position: relative;'>";
        echo "<h3 style='color: #ff5555; margin-top: 0;'>DEBUG DATI SUPABASE (staging_utenti)</h3>";
        echo "<strong>Email cercata dal form:</strong> " . htmlspecialchars($email) . "<br><br>";
        echo "<strong>Record totali restituiti dalla tabella:</strong><pre>";
        var_dump($risposta);
        echo "</pre></div>";
        exit; // Interrompiamo l'esecuzione per leggere i dati a schermo
        // ---------------------------------

    } else {
        $errore = "Compila tutti i campi.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Turni App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; max-width: 400px; padding: 20px; background: white; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="text-center mb-4">
        <h3 class="fw-bold text-primary">Turni App</h3>
        <p class="text-muted small">Accedi al sistema gestionale</p>
    </div>

    <?php if (!empty($errore)) : ?>
        <div class="alert alert-danger py-2 small" role="alert">
            <?php echo htmlspecialchars($errore); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php">
        <div class="mb-3">
            <label for="email" class="form-label small fw-bold">Email</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control" id="email" name="email" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label small fw-bold">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary py-2 fw-bold">Accedi</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
