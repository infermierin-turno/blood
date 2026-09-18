<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

$url = 'https://pellegrini.onrender.com/analizza-scorte';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Attende fino a 15 secondi per il risveglio del servizio Render
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

$data = null;
if ($response && $http_code == 200) {
    $data = json_decode($response, true);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Emoteca - ProMan</title>
</head>
<body>
    <h1>Analisi Scorte Emoteca</h1>
    
    <?php if ($data && isset($data['stato']) && $data['stato'] == 'successo'): ?>
        <p>Totale sacche analizzate: <?php echo htmlspecialchars($data['totale_analizzate']); ?></p>
        
        <h2>Sacche Critiche / Prioritarie:</h2>
        <?php if (!empty($data['sacche_critiche'])): ?>
            <ul>
                <?php foreach ($data['sacche_critiche'] as $sacca): ?>
                    <li>ID Sacca: <?php echo htmlspecialchars($sacca['id']); ?> - <?php echo htmlspecialchars($sacca['messaggio']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Nessuna sacca critica rilevata al momento.</p>
        <?php endif; ?>
    <?php else: ?>
        <p style="color: red;">Errore di comunicazione con il servizio di calcolo Python.</p>
        <p><small>Dettagli tecnici: HTTP Code: <?php echo $http_code; ?> | Errore cURL: <?php echo htmlspecialchars($curl_error); ?></small></p>
    <?php endif; ?>
</body>
</html>