<?php
session_start();

if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

// Inclusione dell'helper delle API (config_sangue.php è stato rimosso per sicurezza)
require_once __DIR__ . '/api_helper_sangue.php';

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
        die("Errore: Utente vedo@emoteca.it non trovato su Supabase.");
    }
}

// Se qualcuno arriva qui senza parametri, rimandalo al login normale
header("Location: index.php");
exit;
