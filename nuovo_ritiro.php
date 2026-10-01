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

// Gestione dell'invio del form per un nuovo ritiro basato sulla tabella ritiri_sangue
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ricaviamo il nome o l'email dell'operatore loggato per il campo inserito_da
    $operatore_nome = '';
    if (is_array($_SESSION['utente'])) {
        $operatore_nome = $_SESSION['utente']['email'] ?? $_SESSION['utente']['nome'] ?? 'Operatore';
    } else {
        $operatore_nome = $_SESSION['utente'];
    }

    $dati_ritiro = [
        'reparto' => $_POST['reparto'] ?? '',
        'turno_successivo' => $_POST['turno_successivo'] ?? '',
        'stato' => 'In attesa',
        'data_ritiro' => $_POST['data_ritiro'] ?? date('Y-m-d'),
        'note' => $_POST['note'] ?? '',
        'inserito_da' => $operatore_nome,
        'codice_a_barre' => $_POST['codice_a_barre'] ?? '',
        'notifica_inviata' => false,
        'created_at' => date('c')
    ];

    // Inserimento nella tabella corretta ritiri_sangue su Supabase
    $url_inserimento = SUPABASE_URL . '/rest/v1/ritiri_sangue';
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
        $messaggio_esito = "<div style='background:#d4edda; color:#155724; padding:10px; border:1px solid #c3e6cb; margin-bottom:15px;'>Ritiro registrato con successo in ritiri_sangue! Invio notifica push...</div>";
        
        // Attiva l'invio della notifica push FCM e cattura il debug
        ob_start();
        $titolo_notifica = "Nuovo Ritiro - Reparto: " . $_POST['reparto'];
        $testo_notifica = "Codice: " . ($_POST['codice_a_barre'] ?? 'N/D') . " | Turno: " . ($_POST['turno_successivo'] ?? 'N/D');
        invia_notifica_push_fcm($titolo_notifica, $testo_notifica);
        $debug_fcm_output = ob_get_clean();
    } else {
        $messaggio_esito = "<div style='background:#f8d7da; color:#721c24; padding:10px; border:1px solid #f5c6cb; margin-bottom:15px;'>Errore inserimento Supabase: $response</div>";
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
        .container { max-width: 650px; background: #ffffff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: auto; }
        h2 { color: #333; margin-top: 0; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { resize: vertical; height: 80px; }
        button { background: #007bff; color: white; border: none; padding: 12px 15px; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        button:hover { background: #0056b3; }
        .back-link { display: block; margin-top: 15px; text-align: center; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Registra Nuovo Ritiro Sangue</h2>
        
        <?php echo $messaggio_esito; ?>
        <?php echo $debug_fcm_output; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="reparto">Reparto di Destinazione:</label>
                <input type="text" id="reparto" name="reparto" required placeholder="Es. Chirurgia, Medicina d'Urgenza">
            </div>

            <div class="form-group">
                <label for="turno_successivo">Turno Successivo:</label>
                <input type="text" id="turno_successivo" name="turno_successivo" placeholder="Es. Mattina / Pomeriggio / Notte">
            </div>

            <div class="form-group">
                <label for="data_ritiro">Data Ritiro:</label>
                <input type="date" id="data_ritiro" name="data_ritiro" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="form-group">
                <label for="codice_a_barre">Codice a Barre:</label>
                <input type="text" id="codice_a_barre" name="codice_a_barre" placeholder="Scansiona o inserisci codice a barre">
            </div>

            <div class="form-group">
                <label for="note">Note:</label>
                <textarea id="note" name="note" placeholder="Eventuali note cliniche o logistiche..."></textarea>
            </div>

            <button type="submit">Salva Ritiro e Invia Notifica Push</button>
        </form>

        <a href="bacheca_ritiri.php" class="back-link">← Torna alla Bacheca</a>
    </div>
</body>
</html>
