<?php
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

/**
 * Invia una notifica push FCM a tutti i dispositivi registrati nella tabella fcm_tokens
 * 
 * @param string $titolo Titolo della notifica
 * @param string $messaggio Corpo/testo della notifica
 * @return bool True se almeno un invio è riuscito
 */
jnv_invia_notifica_push($titolo, $messaggio) {
    // 1. Recupera tutti i token FCM attivi da Supabase
    $url_tokens = SUPABASE_URL . '/rest/v1/fcm_tokens?select=fcm_token';
    $ch = curl_init($url_tokens);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json'
    ]);
    $response_tokens = curl_exec($ch);
    curl_close($ch);

    $tokens_data = json_decode($response_tokens, true);
    if (empty($tokens_data) || !is_array($tokens_data)) {
        return false; // Nessun token registrato
    }

    $inviato_almeno_uno = false;

    // NOTA: Se invii tramite Legacy HTTP API di Firebase (più semplice con chiave Server):
    // Puoi usare l'endpoint https://fcm.googleapis.com/fcm/send con 'to' (singolo) o 'registration_ids' (array)
    // Qui usiamo l'invio iterativo o multiplo tramite la chiave FCM Legacy (o HTTP v1 se preferisci)
    
    // Configuriamo la chiave Server FCM (assicurati di averla o di recuperarla da Firebase Project Settings -> Cloud Messaging -> Cloud Messaging API (Legacy))
    $fcm_server_key = getenv('FCM_SERVER_KEY') ?? 'TUA_FCM_SERVER_KEY_QUI';

    foreach ($tokens_data as $row) {
        $token = $row['fcm_token'] ?? null;
        if (!$token) continue;

        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $titolo,
                'body' => $messaggio,
                'icon' => '/favicon.ico',
                'click_action' => 'https://tuodominio.it/blood/bacheca_ritiri.php'
            ]
        ];

        $ch_fcm = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch_fcm, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_fcm, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch_fcm, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch_fcm, CURLOPT_HTTPHEADER, [
            'Authorization: key=' . $fcm_server_key,
            'Content-Type: application/json'
        ]);

        $result = curl_exec($ch_fcm);
        $http_code = curl_getinfo($ch_fcm, CURLINFO_HTTP_CODE);
        curl_close($ch_fcm);

        if ($http_code >= 200 && $http_code < 300) {
            $inviato_almeno_uno = true;
        }
    }

    return $inviato_almeno_uno;
}
