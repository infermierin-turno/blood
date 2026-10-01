<?php
session_start();
if (!isset($_SESSION['utente'])) {
    http_response_code(401);
    echo json_encode(['errore' => 'Non autorizzato']);
    exit;
}

if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

require_once __DIR__ . '/api_helper_sangue.php';

$data = json_decode(file_get_contents('php://input'), true);
$fcm_token = $data['fcm_token'] ?? null;

if (!$fcm_token) {
    http_response_code(400);
    echo json_encode(['errore' => 'Token mancante']);
    exit;
}

// Estraiamo l'ID dell'utente direttamente dalla sessione (se salvato come array) 
// o ricaviamolo in base alla struttura della tua sessione.
$utente_id = null;

if (is_array($_SESSION['utente'])) {
    // Se nella sessione salvi direttamente l'id (es. 'id' o 'user_id')
    $utente_id = $_SESSION['utente']['id'] ?? $_SESSION['utente']['user_id'] ?? null;
    
    // Se non c'è l'id diretto ma c'è l'email, cerchiamo l'utente su Supabase
    if (!$utente_id && isset($_SESSION['utente']['email'])) {
        $email_utente = $_SESSION['utente']['email'];
        $utenti = esegui_get_api("utenti?email=eq." . urlencode($email_utente));
        if (!empty($utenti)) {
            $utente_id = $utenti[0]['id'];
        }
    }
} else {
    // Caso in cui $_SESSION['utente'] fosse una stringa (es. email o username)
    $email_utente = $_SESSION['utente'];
    $utenti = esegui_get_api("utenti?email=eq." . urlencode($email_utente));
    if (!empty($utenti)) {
        $utente_id = $utenti[0]['id'];
    }
}

if (!$utente_id) {
    http_response_code(404);
    echo json_encode(['errore' => 'ID Utente non trovato nella sessione o nel database']);
    exit;
}

// Salviamo o aggiorniamo il token su Supabase (Upsert basato su fcm_token)
$payload = json_encode([
    'utente_id' => $utente_id,
    'fcm_token' => $fcm_token,
    'updated_at' => date('c')
]);

$url = SUPABASE_URL . '/rest/v1/fcm_tokens';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: resolution=merge-duplicates' // Fondamentale per l'upsert
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code >= 200 && $http_code < 300) {
    echo json_encode(['successo' => true]);
} else {
    http_response_code(500);
    echo json_encode(['errore' => 'Errore nel salvataggio su Supabase', 'dettagli' => $response]);
}
