<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Configurazione diretta tramite le variabili d'ambiente di Render (senza bisogno di file fisici)
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', getenv('SUPABASE_URL'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', getenv('SUPABASE_KEY'));
}

require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = '';
$errore = '';

// Gestione creazione o modifica utente (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    
    if ($azione === 'salva') {
        $id = $_POST['id'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $cognome = trim($_POST['cognome'] ?? '');
        $ruolo = trim($_POST['ruolo'] ?? '');

        if (empty($email) || empty($nome) || empty($cognome) || empty($ruolo)) {
            $errore = "Compila tutti i campi obbligatori.";
        } else {
            $dati_utente = [
                'email' => $email,
                'nome' => $nome,
                'cognome' => $cognome,
                'ruolo' => $ruolo
            ];

            // Se è stata inserita una nuova password, la aggiorniamo con password_hash
            if (!empty($password)) {
                $dati_utente['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            if (!empty($id)) {
                // Modifica utente esistente nella tabella 'utenti'
                $risposta = esegui_patch_api('utenti?id=eq.' . $id, $dati_utente);
                if (is_array($risposta) && isset($risposta['code'])) {
                    $errore = "Errore durante l'aggiornamento dell'utente: " . ($risposta['message'] ?? '');
                } else {
                    $messaggio = "Utente aggiornato con successo!";
                }
            } else {
                // Nuovo utente: la password è obbligatoria in creazione
                if (empty($password)) {
                    $errore = "La password è obbligatoria per i nuovi utenti.";
                } else {
                    $dati_utente['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $risposta = esegui_post_api('utenti', $dati_utente);
                    if (is_array($risposta) && isset($risposta['code'])) {
                        $errore = "Errore durante la creazione dell'utente: " . ($risposta['message'] ?? '');
                    } else {
                        $messaggio = "Utente creato con successo!";
                    }
                }
            }
        }
    } elseif ($azione === 'elimina') {
        $id_elimina = $_POST['id'] ?? '';
        if (!empty($id_elimina)) {
            $risposta = esegui_delete_api('utenti?id=eq.' . $id_elimina);
            if (is_array($risposta) && isset($risposta['code'])) {
                $errore = "Errore durante l'eliminazione dell'utente.";
            } else {
                $messaggio = "Utente eliminato con successo!";
            }
        }
    }
}

// Recupero la lista degli utenti dalla tabella corretta 'utenti'
$utenti = esegui_get_api("utenti?order=cognome.asc");
if (!is_array($utenti) || isset($utenti['code'])) {
    $utenti = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Gestione Utenti</title>
</head>
<body class="bg-gray-100 p-4">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Gestione Utenti</h1>
            <a href="bacheca_ritiri.php" class="text-blue-600 font-bold underline">← Torna alla Bacheca</a>
        </div>

        <?php if (!empty($messaggio)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded" role="alert">
                <p><?php echo htmlspecialchars($messaggio); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($errore)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded" role="alert">
                <p><?php echo htmlspecialchars($errore); ?></p>
            </div>
        <?php endif; ?>

        <!-- Form Inserimento / Modifica Utente -->
        <div class="bg-white p-6 rounded-xl shadow mb-6">
            <h2 class="text-lg font-bold mb-4" id="form-title">Aggiungi Nuovo Utente</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="azione" value="salva">
                <input type="hidden" id="id" name="id" value="">

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email (Username)</label>
                    <input type="email" id="email" name="email" required class="w-full p-2 border rounded border-gray-300">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Password <span id="pwd-hint" class="text-gray-400 font-normal">(lascia vuoto per non modificare)</span></label>
                    <input type="password" id="password" name="password" class="w-full p-2 border rounded border-gray-300">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nome</label>
                    <input type="text" id="nome" name="nome" required class="w-full p-2 border rounded border-gray-300">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cognome</label>
                    <input type="text" id="cognome" name="cognome" required class="w-full p-2 border rounded border-gray-300">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Ruolo</label>
                    <select id="ruolo" name="ruolo" required class="w-full p-2 border rounded border-gray-300">
                        <option value="operatore">Operatore</option>
                        <option value="viewer">Sola Lettura (Viewer)</option>
                        <option value="admin">Amministratore</option>
                    </select>
                </div>

                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="bg-blue-600 text-white font-bold px-4 py-2 rounded hover:bg-blue-700">Salva Utente</button>
                    <button type="button" onclick="resetForm()" class="bg-gray-300 text-gray-700 font-bold px-4 py-2 rounded hover:bg-gray-400">Annulla</button>
                </div>
            </form>
        </div>

        <!-- Tabella Elenco Utenti -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-200 text-gray-700 text-xs uppercase font-bold">
                        <th class="p-3">Nome e Cognome</th>
                        <th class="p-3">Email</th>
                        <th class="p-3">Ruolo</th>
                        <th class="p-3 text-center">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (is_array($utenti) && count($utenti) > 0): ?>
                        <?php foreach ($utenti as $u): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-3 font-semibold"><?php echo htmlspecialchars(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? '')); ?></td>
                                <td class="p-3 text-gray-600"><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 text-xs font-bold rounded bg-gray-100 text-gray-800">
                                        <?php echo htmlspecialchars($u['ruolo'] ?? ''); ?>
                                    </span>
                                </td>
                                <td class="p-3 text-center flex justify-center gap-2">
                                    <button onclick="modificaUtente('<?php echo $u['id']; ?>', '<?php echo addslashes($u['email'] ?? ''); ?>', '<?php echo addslashes($u['nome'] ?? ''); ?>', '<?php echo addslashes($u['cognome'] ?? ''); ?>', '<?php echo addslashes($u['ruolo'] ?? ''); ?>')" class="bg-yellow-500 text-white px-3 py-1 rounded text-xs font-bold hover:bg-yellow-600">Modifica</button>
                                    <form method="POST" onsubmit="return confirm('Sei sicuro di voler eliminare questo utente?');" style="display:inline;">
                                        <input type="hidden" name="azione" value="elimina">
                                        <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded text-xs font-bold hover:bg-red-700">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="p-4 text-center text-gray-500">Nessun utente trovato.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function modificaUtente(id, email, nome, cognome, ruolo) {
            document.getElementById('id').value = id;
            document.getElementById('email').value = email;
            document.getElementById('nome').value = nome;
            document.getElementById('cognome').value = cognome;
            document.getElementById('ruolo').value = ruolo;
            document.getElementById('password').value = '';
            document.getElementById('form-title').innerText = 'Modifica Utente';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetForm() {
            document.getElementById('id').value = '';
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
            document.getElementById('nome').value = '';
            document.getElementById('cognome').value = '';
            document.getElementById('ruolo').selectedIndex = 0;
            document.getElementById('form-title').innerText = 'Aggiungi Nuovo Utente';
        }
    </script>
</body>
</html>
