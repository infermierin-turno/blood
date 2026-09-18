<?php session_start(); if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

// Calcolo data 30 giorni fa per il filtro
$data_limite = date('Y-m-d', strtotime('-30 days'));

// Recupero gli ultimi 50 movimenti dal log
$logs = esegui_get_api("log_movimenti?data_ora=gte." . $data_limite . "&order=data_ora.desc&limit=50");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Ultimi 50 Movimenti</title>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-5xl mx-auto">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-200">
            <h1 class="text-xl font-bold text-gray-800">Ultimi 50 Movimenti</h1>
            <a href="gestione_movimenti.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-bold hover:bg-blue-700 transition">Torna indietro</a>
        </div>

        <!-- Tabella -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 border-b border-gray-200 uppercase text-[10px] text-gray-500 font-bold">
                        <tr>
                            <th class="px-4 py-4">Data/Ora</th>
                            <th class="px-4 py-4">Azione</th>
                            <th class="px-4 py-4">Codice</th>
                            <th class="px-4 py-4">Paziente</th>
                            <th class="px-4 py-4">Sacca</th>
                            <th class="px-4 py-4">Gruppo</th>
                            <th class="px-4 py-4">Data Carico</th>
                            <th class="px-4 py-4">Data Scarico</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (is_array($logs) && !empty($logs)): ?>
                            <?php foreach($logs as $l): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-4 py-4 text-gray-600 whitespace-nowrap text-xs">
                                    <?php echo isset($l['data_ora']) ? date('d/m H:i', strtotime($l['data_ora'])) : '-'; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <span class="px-2 py-1 rounded-full font-bold text-[10px] uppercase 
                                        <?php echo ($l['azione'] === 'CARICO') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo htmlspecialchars($l['azione'] ?? 'N/D'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 font-mono text-gray-700 font-bold text-xs"><?php echo htmlspecialchars($l['codice_barre'] ?? '-'); ?></td>
                                <td class="px-4 py-4 text-gray-800 text-xs"><?php echo htmlspecialchars($l['paziente'] ?? '-'); ?></td>
                                <td class="px-4 py-4 font-mono text-gray-700 text-xs"><?php echo htmlspecialchars($l['numero_sacca'] ?? '-'); ?></td>
                                <td class="px-4 py-4 font-bold text-gray-800 text-xs"><?php echo htmlspecialchars($l['gruppo_sanguigno'] ?? 'N/D'); ?></td>
                                <td class="px-4 py-4 text-gray-600 text-xs">
                                    <?php echo isset($l['data_carico']) ? date('d/m/Y', strtotime($l['data_carico'])) : '-'; ?>
                                </td>
                                <td class="px-4 py-4 text-gray-600 text-xs">
                                    <?php echo isset($l['data_scarico']) ? date('d/m/Y', strtotime($l['data_scarico'])) : '-'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                                    Nessuna operazione trovata negli ultimi 30 giorni.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>