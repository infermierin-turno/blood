<?php
// Salva come debug_storico.php
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

echo "<h1>Debug Connessione Supabase</h1>";

// 1. Testiamo la connessione alla tabella
$data = esegui_get_api("log_movimenti");

if ($data === null) {
    echo "<p style='color:red;'><strong>ERRORE:</strong> Impossibile contattare l'API. Verifica SUPABASE_URL e SUPABASE_KEY in config_sangue.php.</p>";
} elseif (is_array($data) && empty($data)) {
    echo "<p style='color:orange;'><strong>ATTENZIONE:</strong> La tabella 'log_movimenti' è vuota oppure le policy RLS (permessi) su Supabase non consentono la lettura.</p>";
    echo "<p>Vai su Supabase -> Authentication -> Policies -> Crea una policy 'SELECT' per la tabella 'log_movimenti' che permetta l'accesso a 'anon'.</p>";
} else {
    echo "<p style='color:green;'><strong>SUCCESSO:</strong> Tabella trovata. Ecco le colonne rilevate nel primo record:</p>";
    echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>";
    print_r(array_keys($data[0]));
    echo "</pre>";
    
    echo "<p><strong>Anteprima dati (primo record):</strong></p>";
    echo "<pre style='background:#eee; padding:10px;'>";
    print_r($data[0]);
    echo "</pre>";
}
?>