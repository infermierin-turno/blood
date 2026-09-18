<?php
// /blood/setup_utente.php
require_once __DIR__ . '/api_helper_sangue.php';

$email_test = "tuamail@esempio.it";
$pass_test = "password123"; // La password che vuoi usare

$dati = [
    'email' => $email_test,
    'password_hash' => password_hash($pass_test, PASSWORD_DEFAULT),
    'nome' => 'Mario',
    'cognome' => 'Rossi'
];

$risultato = inserisci_supabase_sangue('utenti', $dati);
print_r($risultato);
?>