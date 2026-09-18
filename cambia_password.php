<?php
// /blood/cambia_password.php
session_start();

// Verifica che l'utente sia loggato
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/api_helper_sangue.php';

// Estrazione sicura dell'email dalla sessione (gestisce sia stringa che array)
$utente_sessione = $_SESSION['utente'];
if (is_array($utente_sessione)) {
    $email_utente = $utente_sessione['email'] ?? '';
} else {
    $email_utente = (string)$utente_sessione;
}

$messaggio = "";
$colore_messaggio = "green";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nuova_password = $_POST['nuova_password'];
    $conferma_password = $_POST['conferma_password'];

    // Controllo che le password coincidano
    if ($nuova_password !== $conferma_password) {
        $messaggio = "Errore: le password non coincidono.";
        $colore_messaggio = "red";
    } elseif (strlen($nuova_password) < 6) {
        $messaggio = "Errore: la password deve essere di almeno 6 caratteri.";
        $colore_messaggio = "red";
    } elseif (empty($email_utente)) {
        $messaggio = "Errore: impossibile identificare l'email dell'utente in sessione.";
        $colore_messaggio = "red";
    } else {
        // Genera il nuovo hash
        $hash = password_hash($nuova_password, PASSWORD_DEFAULT);
        
        // Aggiorna nel database utilizzando l'email estratta in sicurezza
        $update = esegui_patch_api('utenti?email=eq.' . urlencode($email_utente), ['password_hash' => $hash]);
        
        if ($update !== false) {
            $messaggio = "Password aggiornata con successo!";
        } else {
            $messaggio = "Errore durante l'aggiornamento. Riprova.";
            $colore_messaggio = "red";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambia Password</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 20px; background: #f4f4f9; display: flex; justify-content: center; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h1 { font-size: 1.2rem; text-align: center; }
        input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .msg { text-align: center; font-weight: bold; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Cambia la tua password</h1>
        <form method="POST">
            <input type="password" name="nuova_password" placeholder="Nuova Password" required>
            <input type="password" name="conferma_password" placeholder="Conferma Nuova Password" required>
            <button type="submit">Aggiorna Password</button>
        </form>
        <p class="msg" style="color: <?php echo $colore_messaggio; ?>;"><?php echo $messaggio; ?></p>
        <div style="text-align: center; margin-top: 20px;">
            <a href="bacheca_ritiri.php">Torna alla bacheca</a>
        </div>
    </div>
</body>
</html>