<?php
// File: /blood/nuovo_ritiro.php
session_start();
if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }

require_once __DIR__ . '/api_helper_sangue.php';

// Funzione helper inclusa inline per l'invio delle notifiche push FCM tramite i token in Supabase
function invia_notifica_push_fcm($titolo, $messaggio) {
    if (!defined('SUPABASE_URL')) {
        define('SUPABASE_URL', getenv('SUPABASE_URL'));
    }
    if (!defined('SUPABASE_KEY')) {
        define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
    }

    // 1. Recupera tutti i token FCM registrati da Supabase
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
        return false;
    }

    // Chiave Server FCM (può essere impostata come variabile d'ambiente o inserita qui)
    $fcm_server_key = getenv('FCM_SERVER_KEY') ?? 'BKhRHAH4cctir9Lo0B_KJsfYbv1YZ9FpmMWoXO7V13FL1aEgwNLy_SsG3AgnOu273Y2GphPWiXKZzZ9rVIBznZ8';

    foreach ($tokens_data as $row) {
        $token = $row['fcm_token'] ?? null;
        if (!$token) continue;

        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $titolo,
                'body' => $messaggio,
                'icon' => '/favicon.ico',
                'click_action' => 'https://emotecaapp.firebaseapp.com/bacheca_ritiri.php'
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

        curl_exec($ch_fcm);
        curl_close($ch_fcm);
    }
    return true;
}

$messaggio = "";
$tipo_messaggio = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    date_default_timezone_set('Europe/Rome');
    
    // Recupero nome utente corretto dalla sessione
    $nome_operatore = $_SESSION['utente']['nome'] . ' ' . $_SESSION['utente']['cognome'];

    $id_richiesta = trim($_POST['id_richiesta'] ?? '');
    $paziente = trim($_POST['paziente'] ?? '');
    $note_inserite = trim($_POST['note'] ?? '');
    $emoglobina = trim($_POST['emoglobina'] ?? '');
    $emocomponente = trim($_POST['emocomponente'] ?? '');
    $reparto = trim($_POST['reparto'] ?? '');
    
    // Formattiamo le note includendo ID richiesta, paziente, tipo emocomponente, emoglobina e note aggiuntive
    $dettagli_aggiuntivi = [];
    if (!empty($id_richiesta)) {
        $dettagli_aggiuntivi[] = "ID Richiesta: " . $id_richiesta;
    }
    if (!empty($paziente)) {
        $dettagli_aggiuntivi[] = "Paziente: " . $paziente;
    }
    if (!empty($emocomponente)) {
        $dettagli_aggiuntivi[] = "Tipo: " . $emocomponente;
    }
    if (!empty($emoglobina)) {
        $dettagli_aggiuntivi[] = "Emoglobina: " . $emoglobina;
    }
    if (!empty($note_inserite)) {
        $dettagli_aggiuntivi[] = $note_inserite;
    }

    $corpo_note = !empty($dettagli_aggiuntivi) ? implode(" - ", $dettagli_aggiuntivi) : "";

    if (!empty($corpo_note)) {
        $note_finali = "Inserito da " . $nome_operatore . ": " . $corpo_note;
    } else {
        $note_finali = "Inserito da " . $nome_operatore;
    }

    $dati = [
        'reparto' => $reparto,
        'turno_successivo' => $_POST['turno'],
        'note' => $note_finali,
        'stato' => 'Da ritirare',
        'inserito_da' => $nome_operatore,
        'notifica_inviata' => false
    ];

    $risultato = esegui_post_api('ritiri_sangue', $dati);

    if ($risultato !== null) {
        $messaggio = "Ritiro inserito con successo!";
        $tipo_messaggio = "success";

        // Invio automatico della notifica push a tutti i dispositivi registrati
        $titolo_push = "🩸 Nuovo Ritiro: " . $reparto;
        $testo_push = "Richiesta inserita da " . $nome_operatore . " (" . ($emocomponente ?: 'Emocomponente') . ")";
        invia_notifica_push_fcm($titolo_push, $testo_push);

    } else {
        $messaggio = "Errore durante l'inserimento. Verifica la connessione al database.";
        $tipo_messaggio = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Nuovo Ritiro</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 15px; background: #f4f4f9; margin: 0; }
        .form-card { background: white; padding: 20px; border-radius: 12px; max-width: 500px; margin: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; text-align: center; color: #333; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; font-weight: 600; color: #555; }
        input, select, textarea { width: 100%; padding: 14px; margin-top: 5px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 16px; }
        button { width: 100%; padding: 16px; margin-top: 25px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 18px; font-weight: bold; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 6px; text-align: center; margin-bottom: 15px; }
        .error { color: #721c24; background: #f8d7da; padding: 10px; border-radius: 6px; text-align: center; margin-bottom: 15px; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="form-card">
        <h1>Inserisci Ritiro</h1>
        <?php if(!empty($messaggio)): ?>
            <div class="<?php echo $tipo_messaggio; ?>"><?php echo $messaggio; ?></div>
        <?php endif; ?>
        <form method="POST">
            <label>ID Richiesta</label>
            <input type="text" name="id_richiesta" placeholder="es. 12345">
            <label>Cognome e Nome Paziente</label>
            <input type="text" name="paziente" placeholder="es. Rossi Mario" required>
            <label>Reparto</label>
            <input type="text" name="reparto" required>
            <label>Turno a cui è demandato il ritiro</label>
            <input type="text" name="turno" required>
            <label>Tipo Emocomponente</label>
            <select name="emocomponente" required>
                <option value="">-- Seleziona tipo --</option>
                <option value="Emazie Concentrate">Emazie Concentrate</option>
                <option value="Concentrato piastrinico">Concentrato piastrinico</option>
                <option value="Plasma">Plasma</option>
            </select>
            <label>Valore Emoglobina (g/dL)</label>
            <input type="text" name="emoglobina" placeholder="es. 10.5">
            <label>Note aggiuntive</label>
            <textarea name="note" rows="3"></textarea>
            <button type="submit">Salva Ritiro</button>
        </form>
        <a href="bacheca_ritiri.php" class="back-link">Torna alla bacheca</a>
    </div>
</body>
</html>
