<?php session_start(); if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

// Recupero l'intero registro dei movimenti, ordinato per data
$dati = esegui_get_api("registro_sangue?order=data_carico.desc");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Storico Completo Registro</title>
</head>
<body class="bg-gray-100 p-2 sm:p-4">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-4 sm:mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-200 gap-3">
            <h1 class="text-lg sm:text-xl font-bold text-gray-800">Storico Completo</h1>
            <a href="bacheca_ritiri.php" class="w-full sm:w-auto bg-blue-600 text-white px-4 py-2 rounded-lg font-bold text-center hover:bg-blue-700 transition text-sm">Torna alla Gestione</a>
        </div>

        <!-- Tabella -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[11px] sm:text-sm text-left">
                    <thead class="bg-gray-50 border-b border-gray-200 uppercase text-gray-500 font-bold">
                        <tr>
                            <th class="px-2 py-3 sm:px-4">Data Carico</th>
                            <th class="px-2 py-3 sm:px-4">Codice</th>
                            <th class="px-2 py-3 sm:px-4">Sacca</th>
                            <th class="px-2 py-3 sm:px-4">Paziente</th>
                            <th class="px-2 py-3 sm:px-4">Gruppo</th>
                            <th class="px-2 py-3 sm:px-4">Comp.</th>
                            <th class="px-2 py-3 sm:px-4">Reparto</th>
                            <th class="px-2 py-3 sm:px-4">Stato</th>
                            <th class="px-2 py-3 sm:px-4">Data Scarico</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (is_array($dati) && !empty($dati)): ?>
                            <?php foreach($dati as $r): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-2 py-3 sm:px-4 text-gray-600 whitespace-nowrap">
                                    <?php echo isset($r['data_carico']) ? date('d/m H:i', strtotime($r['data_carico'])) : '-'; ?>
                                </td>
                                <td class="px-2 py-3 sm:px-4 font-mono"><?php echo htmlspecialchars($r['codice_barre'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4 font-mono"><?php echo htmlspecialchars($r['numero_sacca'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4 truncate max-w-[100px]"><?php echo htmlspecialchars($r['paziente'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4 font-bold"><?php echo htmlspecialchars($r['gruppo_sanguigno'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4 truncate max-w-[80px]"><?php echo htmlspecialchars($r['tipo_emocomponente'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4 truncate max-w-[80px]"><?php echo htmlspecialchars($r['reparto'] ?? '-'); ?></td>
                                <td class="px-2 py-3 sm:px-4">
                                    <span class="px-2 py-1 rounded-full font-bold text-[9px] uppercase <?php echo ($r['tipo_operazione'] === 'CARICO') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                                        <?php echo htmlspecialchars($r['tipo_operazione'] ?? 'N/D'); ?>
                                    </span>
                                </td>
                                <td class="px-2 py-3 sm:px-4 text-gray-600 whitespace-nowrap">
                                    <?php echo isset($r['data_scarico']) ? date('d/m H:i', strtotime($r['data_scarico'])) : '-'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="px-6 py-10 text-center text-gray-500">Nessun dato presente nel registro.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>