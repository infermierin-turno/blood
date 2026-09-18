<?php
session_start();

// Controlla se è arrivato il payload di autenticazione da Tophost
if (isset($_GET['data'])) {
    // Decodifica i dati dell'utente passati in modo sicuro
    $json_decoded = base64_decode(urldecode($_GET['data']));
    $utente = json_decode($json_decoded, true);

    if ($utente && is_array($utente)) {
        // Imposta la sessione utente esattamente come faceva prima su Tophost
        $_SESSION['utente'] = $utente;
        
        // Reindirizza alla bacheca dei ritiri (che leggerà il ruolo 'viewer' e nasconderà i tasti)
        header("Location: bacheca_ritiri.php");
        exit;
    }
}

// Se il token manca o non è valido, mostra l'errore
die("Accesso non autorizzato o token mancante.");
