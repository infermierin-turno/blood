<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

require_once __DIR__ . '/api_helper_sangue.php';

$messaggio_esito = "";
$debug_fcm_output = "";

// Gestione dell'invio del form per un nuovo ritiro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dati_ritiro = [
        'operatore_id' => $_SESSION['utente']['id'] ?? null,
        'reparto' => $_POST['reparto'] ?? '',
        'tipo_sangue' => $_POST['tipo_sangue'] ?? '',
        'unita' => $_POST['unita'] ?? 1,
        'stato' => 'In attesa',
        'created_at' => date('c')
    ];

    // Inserimento del ritiro su Supabase
    $url_inserimento = SUPABASE_URL . '/rest/v1/ritiri';
    $ch = curl_init($url_inserimento);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dati_ritiro));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $messaggio_esito = "<div style='background:#d4edda; color:#155724; padding:10px; border:1px solid #c3e6cb; margin-bottom:15px;'>Ritiro creato con successo! Invio notifica push in corso...</div>";
        
        // Attiva l'invio della notifica push FCM e cattura il debug
        ob_start();
        invia_notifica_push_fcm("Nuovo Ritiro Sangue", "È stato registrato un nuovo ritiro per il reparto: " . ($_POST['reparto'] ?? 'Generico'));
        $debug_fcm_output = ob_get_clean();
    } else {
        $messaggio_esito = "<div style='background:#f8d7da; color:#721c24; padding:10px; border:1px solid #f5c6cb; margin-bottom:15px;'>Errore durante la creazione del ritiro.</div>";
    }
}

/**
 * Funzione per l'invio delle notifiche push FCM con output di debug integrato
 */
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
        echo "<div style='background:#fff3cd; padding:10px; margin:10px 0; border:1px solid #ffeeba;'><strong>DEBUG FCM:</strong> Nessun token trovato in Supabase.</div>";
        return;
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

        $risultati_invio[] = "Token: " . substr($token, 0, 10) . "... | HTTP Code: $http_code - Risposta: $result";
    }
    
    echo "<div style='background:#fff3cd; padding:10px; margin:10px 0; border:1px solid #ffeeba;'><strong>DEBUG FCM:</strong><br>" . implode("<br>", $risultati_invio) . "</div>";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Nuovo Ritiro - Emoteca</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: auto; }
        h2 { color: #333; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        button:hover { background: #0056b3; }
        .back-link { display: block; margin-top: 15px; text-align: center; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Inserisci Nuovo Ritiro</h2>
        
        <?php echo $messaggio_esito; ?>
        <?php echo $debug_fcm_output; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="reparto">Reparto di Destinazione:</label>
                <input type="text" id="reparto" name="reparto" required placeholder="Es. Chirurgia, Medicina, Terapia Intensiva">
            </div>

            <div class="form-group">
                <label for="tipo_sangue">Gruppo Sanguigno:</label>
                <select id="tipo_sangue" name="tipo_sangue" required>
                    <option value="">Seleziona gruppo...</option>
                    <option value="0 Positivo">0 Positivo</option>
                    <option value="0 Negativo">0 Negativo</option>
                    <option value="A Positivo">A Positivo</option>
                    <option value="A Negativo">A Negativo</option>
                    <option value="B Positivo">B Positivo</option>
                    <option value="B Negativo">B Negativo</option>
                    <option value="AB Positivo">AB Positivo</option>
                    <option value="AB Negativo">AB Negativo</option>
                </select>
            </div>

            <div class="form-group">
                <label for="unita">Unità:</label>
                <input type="number" id="unita" name="unita" min="1" value="1" required>
            </div>

            <button type="submit">Registra Ritiro e Invia Notifica</button>
        </form>

        <a href="bacheca_ritiri.php" class="back-link">← Torna alla Bacheca</a>
    </div>
</body>
</html>
