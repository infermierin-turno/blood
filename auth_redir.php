<?php
session_start();
require_once __DIR__ . '/config_sangue.php'; // Assicurati che punti al file di connessione di Supabase
require_once __DIR__ . '/api_helper_sangue.php'; // Assicurati che includa la funzione di chiamata API

// Se arriva la richiesta automatica dal QR code
if (isset($_GET['auto']) && $_GET['auto'] === 'bacheca') {
    
    // Interroga direttamente Supabase per l'utente viewer
    $risultato = esegui_get_api("utenti?email=eq.vedo@emoteca.it");

    if ($risultato && is_array($risultato) && count($risultato) > 0) {
        // Imposta la sessione utente con i dati reali trovati nel database
        $_SESSION['utente'] = $risultato[0];
        session_write_close();
        
        // Reindirizza alla bacheca in sola lettura
        header("Location: bacheca_ritiri.php");
        exit;
    } else {
        die("Errore: Utente vedo@bacheca.it non trovato su Supabase.");
    }
}

// Se qualcuno arriva qui senza parametri, rimandalo al login normale
header("Location: index.php");
exit;
