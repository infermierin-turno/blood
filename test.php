<?php
// /blood/test.php
// Questo file contiene solo logica PHP pura

// 1. Verifica che la sessione sia attiva
session_start();

// 2. Prova a includere l'helper per vedere se il percorso è giusto
if (file_exists('api_helper_sangue.php')) {
    require_once 'api_helper_sangue.php';
    $status = "Helper caricato con successo.";
} else {
    $status = "Errore: api_helper_sangue.php non trovato!";
}

// 3. Output di debug
header('Content-Type: text/plain');
echo "Status del sistema:\n";
echo "-------------------\n";
echo "Session ID: " . session_id() . "\n";
echo "Cartella corrente: " . __DIR__ . "\n";
echo "Risultato test: " . $status . "\n";
echo "Data/Ora server: " . date('Y-m-d H:i:s') . "\n";
?>