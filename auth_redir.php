<?php
session_start();

// Controlla se è arrivato il payload di autenticazione da Tophost
if (isset($_GET['data'])) {
    // Decodifica i dati dell'utente passati in modo sicuro
    $json_decoded = base64_decode(urldecode($_GET['data']));
    $utente = json_decode($json_decoded, true);

    if ($utente && is_array($utente)) {
        // Imposta la sessione utente direttamente sull'app in esecuzione su Render
        $_SESSION['utente'] = $utente;
        
        // Reindirizza alla bacheca dei ritiri
        header("Location: bacheca_ritiri.php");
        exit;
    }
}

// Se il token è mancante o non valido, blocca l'accesso
die("Accesso non autorizzato o sessione scaduta.");
