<?php
// Avvio della sessione centralizzato per tutta l'applicazione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurazione Supabase tramite Variabili d'Ambiente Render
$turni_url = getenv('TURNI_SUPABASE_URL');
$turni_key = getenv('TURNI_SUPABASE_KEY');

// Test rapido di lettura variabili Render (rimuovilo dopo il controllo)
echo "<!-- DEBUG URL: " . (defined('SUPABASE_URL') ? SUPABASE_URL : 'NON DEFINITO') . " -->";
// Fallback di sicurezza nel caso in cui le variabili non vengano trovate

if (!$turni_url || !$turni_key) {
    error_log("ATTENZIONE: Variabili d'ambiente TURNI_SUPABASE_URL o TURNI_SUPABASE_KEY non trovate.");
}

if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', $turni_url ?: '');
}

if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', $turni_key ?: '');
}

/**
 * Funzione centralizzata per le chiamate REST a Supabase tramite cURL
 * Punta direttamente alle tabelle con prefisso nello schema pubblico
 */
function supabase_request($endpoint, $query = '', $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint . $query;
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if ($http_code >= 200 && $http_code < 300) {
        return is_array($decoded) ? $decoded : [];
    }

    if (is_array($decoded) && isset($decoded['code'])) {
        return $decoded;
    }

    return ['code' => 'HTTP_' . $http_code, 'message' => $response];
}
?>
