function invia_notifica_push_fcm($titolo, $messaggio) {
    if (!defined('SUPABASE_URL')) {
        define('SUPABASE_URL', getenv('SUPABASE_URL'));
    }
    if (!defined('SUPABASE_KEY')) {
        define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
    }

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
        return "Nessun token trovato in Supabase.";
    }

    $fcm_server_key = getenv('FCM_SERVER_KEY') ?? 'BKhRHAH4cctir9Lo0B_KJsfYbv1YZ9FpmMWoXO7V13FL1aEgwNLy_SsG3AgnOu273Y2GphPWiXKZzZ9rVIBznZ8';

    $risultati_invio = [];
    foreach ($tokens_data as $row) {
        $token = $row['fcm_token'] ?? null;
        if (!$token) continue;

        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $titolo,
                'body' => $messaggio,
                'icon' => '/favicon.ico'
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

        $risultati_invio[] = "HTTP Code: $http_code - Risposta: $result";
    }
    
    // Stampa per debug a schermo
    echo "<div style='background:#fff3cd; padding:10px; margin:10px 0; border:1px solid #ffeeba;'><strong>DEBUG FCM:</strong><br>" . implode("<br>", $risultati_invio) . "</div>";
    return true;
}
