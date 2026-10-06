<?php
session_start();
if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }

// Configurazione diretta tramite le variabili d'ambiente di Render (senza bisogno di file fisici)
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

require_once __DIR__ . '/api_helper_sangue.php';

// Recuperiamo le sacche in carico
$sacche = esegui_get_api("registro_sangue?tipo_operazione=eq.CARICO&data_scarico=is.null&order=data_scadenza_sacca.asc");

$oggi = new DateTime();
$alert_giorni = 7;

// Inizializziamo i contatori
$totale = 0;
$in_scadenza = 0;
$scadute = 0;

if (is_array($sacche)) {
    foreach ($sacche as $s) {
        $totale++;
        
        // Gestione sicura della data di scadenza della sacca
        $scad_sacca = !empty($s['data_scadenza_sacca']) ? new DateTime($s['data_scadenza_sacca']) : null;
        $scad_ts = !empty($s['data_type_screen']) ? new DateTime($s['data_type_screen']) : null;
        
        $giorni_sacca = 999;
        if ($scad_sacca) {
            $diff_sacca = $oggi->diff($scad_sacca);
            $giorni_sacca = (int)$diff_sacca->format('%r%a');
        }
        
        $giorni_ts = 999;
        if ($scad_ts) {
            $diff_ts = $oggi->diff($scad_ts);
            $giorni_ts = (int)$diff_ts->format('%r%a');
        }
        
        if (($scad_sacca && $giorni_sacca < 0) || ($scad_ts && $giorni_ts < 0)) {
            $scadute++;
        } elseif (($scad_sacca && $giorni_sacca <= $alert_giorni) || ($scad_ts && $giorni_ts <= $alert_giorni)) {
            $in_scadenza++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Stato Emoteca</title>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6 text-center">Stato Emoteca</h1>
        
        <div class="grid grid-cols-3 gap-3 mb-6">
            <div class="bg-white p-3 rounded-xl shadow text-center border-t-4 border-gray-400">
                <div class="text-xs text-gray-500 font-bold uppercase">Totale</div>
                <div class="text-2xl font-black"><?php echo $totale; ?></div>
            </div>
            <div class="bg-white p-3 rounded-xl shadow text-center border-t-4 border-orange-500">
                <div class="text-xs text-gray-500 font-bold uppercase">In scadenza</div>
                <div class="text-2xl font-black text-orange-600"><?php echo $in_scadenza; ?></div>
            </div>
            <div class="bg-white p-3 rounded-xl shadow text-center border-t-4 border-red-600">
                <div class="text-xs text-gray-500 font-bold uppercase">Scadute</div>
                <div class="text-2xl font-black text-red-600"><?php echo $scadute; ?></div>
            </div>
        </div>
        
        <div class="mb-6 text-center flex flex-col gap-2">
            <a href="bacheca_ritiri.php" class="text-blue-600 font-bold underline">← Torna alla Bacheca</a>
            <a href="storico_completo.php" class="text-gray-600 font-bold underline">Visualizza Storico Movimenti</a>
        </div>

        <?php if (empty($sacche)): ?>
            <div class="bg-white p-6 rounded-2xl shadow text-center text-gray-500">Nessuna sacca presente.</div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($sacche as $s): 
                    // Logica Privacy
                    $nome_completo = htmlspecialchars($s['paziente'] ?? 'Sconosciuto');
                    $parti = explode(" ", $nome_completo);
                    $nome_puntato = (count($parti) >= 2) ? strtoupper(substr($parti[0], 0, 1)) . ". " . strtoupper(substr($parti[1], 0, 1)) . "." : strtoupper(substr($nome_completo, 0, 1)) . ".";

                    $scad_sacca = !empty($s['data_scadenza_sacca']) ? new DateTime($s['data_scadenza_sacca']) : null;
                    $scad_ts = !empty($s['data_type_screen']) ? new DateTime($s['data_type_screen']) : null;
                    
                    $giorni_sacca = 999;
                    if ($scad_sacca) {
                        $diff_sacca = $oggi->diff($scad_sacca);
                        $giorni_sacca = (int)$diff_sacca->format('%r%a');
                    }
                    
                    $giorni_ts = 999;
                    if ($scad_ts) {
                        $diff_ts = $oggi->diff($scad_ts);
                        $giorni_ts = (int)$diff_ts->format('%r%a');
                    }
                    
                    $colore = "border-green-500 bg-white";
                    if (($scad_sacca && $giorni_sacca < 0) || ($scad_ts && $giorni_ts < 0)) {
                        $colore = "border-red-600 bg-red-100";
                    } elseif (($scad_sacca && $giorni_sacca <= $alert_giorni) || ($scad_ts && $giorni_ts <= $alert_giorni)) {
                        $colore = "border-orange-500 bg-orange-50";
                    }
                ?>
                <div class="p-4 border-l-8 rounded-lg shadow-sm <?php echo $colore; ?>">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-lg"><?php echo htmlspecialchars($s['gruppo_sanguigno']); ?> | <?php echo htmlspecialchars($s['tipo_emocomponente']); ?></span>
                        <span class="text-xs font-mono font-bold bg-gray-200 px-2 py-1 rounded"><?php echo substr($s['codice_barre'], -6); ?></span>
                    </div>
                    <div class="text-sm mt-1">Paziente: <b><?php echo $nome_puntato; ?></b> | Destinazione: <b><?php echo htmlspecialchars($s['reparto']); ?></b></div>
                    <div class="grid grid-cols-2 mt-2 text-xs font-bold">
                        <div class="<?php echo ($scad_sacca && $giorni_sacca <= $alert_giorni) ? 'text-red-600' : 'text-gray-500'; ?>">
                            Scad. Sacca: <?php echo $scad_sacca ? date('d/m/y', strtotime($s['data_scadenza_sacca'])) : 'N/D'; ?>
                        </div>
                        <div class="<?php echo ($scad_ts && $giorni_ts <= $alert_giorni) ? 'text-red-600' : 'text-gray-500'; ?>">
                            Scad. T/S: <?php echo $scad_ts ? date('d/m/y', strtotime($s['data_type_screen'])) : 'N/D'; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
