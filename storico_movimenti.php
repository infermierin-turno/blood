<?php session_start(); if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }

// CONTROLLO RUOLI
$ruoli_autorizzati = ['medico', 'infermiere'];
if (!isset($_SESSION['utente']['ruolo']) || !in_array($_SESSION['utente']['ruolo'], $ruoli_autorizzati)) {
    die("Accesso negato.");
}

require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

// GESTIONE INVIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    function pulisci_data($data) { return empty($data) ? null : str_replace('T', ' ', $data) . ':00'; }
    
    $codice = $_POST['codice_barre'];
    $operazione = $_POST['tipo_operazione'];
    $paziente = $_POST['paziente'];
    $sacca = $_POST['numero_sacca'];
    $utente_id = $_SESSION['utente']['id'];

    if ($operazione === 'CARICO') {
        $dati = [
            "codice_barre"       => $codice,
            "numero_sacca"       => $sacca,
            "paziente"           => $paziente,
            "tipo_operazione"    => $operazione,
            "tipo_emocomponente" => $_POST['tipo_emocomponente'],
            "gruppo_sanguigno"   => $_POST['gruppo_sanguigno'],
            "reparto"            => $_POST['reparto'],
            "data_carico"        => !empty($_POST['data_carico']) ? pulisci_data($_POST['data_carico']) : date('Y-m-d H:i:s'),
            "data_scadenza_sacca"=> pulisci_data($_POST['scadenza_sacca']),
            "data_type_screen"   => pulisci_data($_POST['scadenza_type_screen']),
            "utente_id"          => $utente_id
        ];
        $risposta = esegui_post_api("registro_sangue", $dati);
    } else {
        $dati_patch = ["data_scarico" => date('Y-m-d H:i:s'), "tipo_operazione" => "SCARICO", "utente_scarico_id" => $utente_id];
        $risposta = esegui_patch_api("registro_sangue?codice_barre=eq." . urlencode($codice), $dati_patch);
    }

    if (!isset($risposta['code'])) {
        registra_log($codice, $operazione, "Operazione completata", $paziente, $sacca);
        header("Location: gestione_movimenti.php?stato=successo");
    } else {
        header("Location: gestione_movimenti.php?stato=errore");
    }
    exit;
}

// RECUPERO ULTIMI MOVIMENTI PER LA TABELLA IN FONDO
$ultimi_movimenti = esegui_get_api("log_movimenti?order=data_ora.desc&limit=5");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <title>Registro Movimenti</title>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-md mx-auto bg-white p-6 rounded-2xl shadow-lg mb-6">
        <h1 class="text-xl font-bold mb-4 text-center">Gestione Movimenti</h1>
        <div id="reader" class="w-full mb-6 rounded-xl overflow-hidden shadow-inner"></div>
        
        <?php if(isset($_GET['stato'])): ?>
            <div class="p-3 rounded-lg mb-4 text-center text-sm font-semibold <?php echo ($_GET['stato'] == 'successo') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo ($_GET['stato'] == 'successo') ? "Movimento registrato con successo." : "Errore durante l'operazione."; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
            <input type="text" name="codice_barre" id="codice_barre" placeholder="Codice a barre" class="w-full p-4 border-2 border-blue-500 rounded-xl text-lg font-mono bg-blue-50" required>
            <select name="tipo_operazione" class="w-full p-3 border-2 border-blue-500 rounded-xl font-bold">
                <option value="CARICO">CARICO</option>
                <option value="SCARICO">SCARICO</option>
            </select>
            <input type="text" name="numero_sacca" placeholder="N° Sacca" class="w-full p-3 border rounded-xl" required>
            <input type="text" name="paziente" placeholder="Paziente" class="w-full p-3 border rounded-xl" required>
            <div class="grid grid-cols-2 gap-2">
                <select name="tipo_emocomponente" class="p-3 border rounded-xl"><option value="Emazie Conc.">Emazie Conc.</option><option value="Piastrine">Piastrine</option><option value="Plasma">Plasma</option></select>
                <select name="gruppo_sanguigno" class="p-3 border rounded-xl"><option value="0-">0-</option><option value="0+">0+</option><option value="A+">A+</option><option value="A-">A-</option><option value="B+">B+</option><option value="B-">B-</option><option value="AB+">AB+</option><option value="AB-">AB-</option></select>
            </div>
            <select name="reparto" class="w-full p-3 border rounded-xl font-bold">
                <option value="SCORTA B.O.">SCORTA B.O.</option>
                <option value="NEFROLOGIA">NEFROLOGIA</option>
                <option value="RIANIMAZIONE">RIANIMAZIONE</option>
            </select>
            <button type="submit" class="w-full bg-blue-600 text-white py-4 rounded-xl font-bold hover:bg-blue-700 transition">Conferma Operazione</button>
            <a href="storico_movimenti.php" class="block text-center text-blue-600 font-bold p-2 underline">Vai allo Storico Completo</a>
        </form>
    </div>

    <div class="max-w-md mx-auto bg-white p-4 rounded-2xl shadow-lg">
        <h2 class="text-lg font-bold mb-3">Ultimi 5 movimenti</h2>
        <table class="w-full text-xs">
            <thead><tr class="border-b text-gray-500"><th class="text-left py-2">Paziente</th><th class="text-left py-2">Sacca</th><th class="text-left py-2">Azione</th></tr></thead>
            <tbody>
                <?php if(is_array($ultimi_movimenti)) foreach($ultimi_movimenti as $m): ?>
                <tr class="border-b">
                    <td class="py-2"><?php echo htmlspecialchars($m['paziente'] ?? '-'); ?></td>
                    <td class="py-2"><?php echo htmlspecialchars($m['numero_sacca'] ?? '-'); ?></td>
                    <td class="py-2 font-bold <?php echo ($m['azione'] == 'CARICO') ? 'text-green-600' : 'text-red-600'; ?>"><?php echo htmlspecialchars($m['azione']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        let scanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 100} });
        scanner.render((text) => { document.getElementById('codice_barre').value = text; });
    </script>
</body>
</html>