<?php
// /blood/logout.php
session_start();

// 1. Svuota l'array delle sessioni
$_SESSION = array();

// 2. Se ci sono cookie di sessione, eliminali
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Distruggi la sessione sul server
session_destroy();

// 4. Reindirizza al login
header("Location: index.php");
exit;
?>