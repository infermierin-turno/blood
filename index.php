<?php
session_start();
// Nota: Qui NON mettiamo il controllo session_start con redirect perché è la pagina di login
require_once __DIR__ . '/config_sangue.php';
require_once __DIR__ . '/api_helper_sangue.php';

$messaggio = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Recupero utente dalla tabella 'utenti'
    $risultato = esegui_get_api("utenti?email=eq." . urlencode($email));

    if ($risultato && is_array($risultato) && count($risultato) > 0) {
        $user = $risultato[0]; 
        
        // Verifica della password
        if (password_verify($password, $user['password_hash'])) {
            // Login riuscito, salviamo l'utente nella sessione
            $_SESSION['utente'] = $user;
            
            // Redirect alla bacheca
            header("Location: bacheca_ritiri.php");
            exit;
        } else {
            $messaggio = "Password errata.";
        }
    } else {
        $messaggio = "Email non trovata.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Login - Accesso Sistema</title>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg w-full max-w-sm">
        <h1 class="text-xl font-bold mb-6 text-center text-blue-600">Login Sistema Sangue</h1>
        
        <?php if($messaggio): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4 text-center text-sm font-semibold">
                <?php echo $messaggio; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Email</label>
                <input type="email" name="email" required placeholder="Inserisci email" class="w-full p-3 sm:p-4 border-2 border-gray-200 rounded-xl focus:border-blue-500 outline-none transition">
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase ml-1">Password</label>
                <input type="password" name="password" required placeholder="Inserisci password" class="w-full p-3 sm:p-4 border-2 border-gray-200 rounded-xl focus:border-blue-500 outline-none transition">
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-4 rounded-xl font-bold hover:bg-blue-700 transition mt-2">Entra nel sistema</button>
        </form>
    </div>
</body>
</html>