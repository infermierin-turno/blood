<?php
session_start();
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

// Recuperi l'utente viewer predefinito dal database
$risultato = esegui_get_api("utenti?email=eq.vedo@emoteca.it");

if ($risultato && is_array($risultato) && count($risultato) > 0) {
    // Imposti la sessione come se avesse fatto il login
    $_SESSION['utente'] = $risultato[0];
    // Reindirizza alla bacheca (che grazie al controllo fatto prima riconoscerà il ruolo 'viewer' e nasconderà i tasti di scrittura)
    header("Location: bacheca_ritiri.php");
    exit;
} else {
    die("Utente di visualizzazione non configurato correttamente.");
}