<?php
// /blood/admin_utenti.php
session_start();

// Controllo di accesso migliorato: verifica l'email all'interno dell'array di sessione
if (!isset($_SESSION['utente']) || (isset($_SESSION['utente']['email']) && $_SESSION['utente']['email'] !== 'gianden71@gmail.com')) {
    die("Accesso negato. Solo l'amministratore può aggiungere utenti.");
}

require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $nome = trim($_POST['nome']);
    $cognome = trim($_POST['cognome']);
    // Generiamo l'hash della password
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $nuovo_utente = [
        'email'         => $email,
        'password_hash' => $hash,
        'nome'          => $nome,
        'cognome'       => $cognome
    ];

    // Esecuzione inserimento tramite helper
    $risposta = esegui_post_api('utenti', $nuovo_utente);
    
    // Verifica se la risposta indica un errore (se l'helper restituisce un array con 'code')
    if (isset($risposta['code'])) {
        $messaggio = "Errore durante la creazione: " . $risposta['message'];
    } else {
        $messaggio = "Utente $nome $cognome creato con successo!";
    }
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
    <div class="max-w-md mx-auto bg-white p-6 rounded-2xl shadow-lg">
        <h1 class="text-xl font-bold mb-6 text-center">Nuovo Operatore</h1>
        
        <?php if($messaggio): ?>
            <div class="p-3 mb-4 rounded-lg text-sm text-center <?php echo strpos($messaggio, 'Errore') !== false ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                <?php echo $messaggio; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="text" name="nome" placeholder="Nome" required class="w-full p-3 border rounded-xl">
            <input type="text" name="cognome" placeholder="Cognome" required class="w-full p-3 border rounded-xl">
            <input type="email" name="email" placeholder="Email" required class="w-full p-3 border rounded-xl">
            <input type="password" name="password" placeholder="Password Temporanea" required class="w-full p-3 border rounded-xl">
            <button type="submit" class="w-full bg-green-600 text-white py-3 rounded-xl font-bold hover:bg-green-700 transition">Crea Utente</button>
        </form>
        
        <div class="mt-6 text-center">
            <a href="bacheca_ritiri.php" class="text-blue-600 font-bold underline">Torna alla bacheca</a>
        </div>
    </div>
</body>
</html>