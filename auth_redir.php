<?php
session_start();

// Controllo diagnostico: verifica se il parametro 'data' arriva da Tophost
if (!isset($_GET['data'])) {
    die("DIAGNOSTICA: Il parametro 'data' non è arrivato su Render. L'URL ricevuto è incompleto o il redirect da Tophost non ha passato il token.");
}

$decoded_data = urldecode($_GET['data']);
$json_decoded = base64_decode($decoded_data);
$utente = json_decode($json_decoded, true);

if (!$utente || !is_array($utente)) {
    echo "Dati ricevuti grezzi: " . htmlspecialchars($_GET['data']) . "<br>";
    echo "Dati decodificati: " . htmlspecialchars($json_decoded) . "<br>";
    die("DIAGNOSTICA: Impossibile decodificare il payload JSON dell'utente.");
}

// Se arriviamo qui, l'utente è stato letto correttamente
$_SESSION['utente'] = $utente;
session_write_close();

// Reindirizza alla bacheca dei ritiri
header("Location: bacheca_ritiri.php");
exit;
