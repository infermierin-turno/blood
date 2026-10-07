<?php
// Funzione per generare un'icona PNG con croce medica
function crea_icona($dimensione, $nome_file) {
    $img = imagecreatetruecolor($dimensione, $dimensione);
    
    // Colori (Blu istituzionale e Bianco)
    $blu = imagecolorallocate($img, 37, 99, 235);
    $bianco = imagecolorallocate($img, 255, 255, 255);
    
    // Sfondo
    imagefilledrectangle($img, 0, 0, $dimensione, $dimensione, $blu);
    
    // Dimensioni proporzionali per la croce bianca
    $spessore = (int)($dimensione * 0.22);
    $lunghezza = (int)($dimensione * 0.65);
    
    $offset_l = (int)(($dimensione - $lunghezza) / 2);
    $offset_s = (int)(($dimensione - $spessore) / 2);
    
    // Barra verticale della croce
    imagefilledrectangle($img, $offset_s, $offset_l, $offset_s + $spessore, $offset_l + $lunghezza, $bianco);
    // Barra orizzontale della croce
    imagefilledrectangle($img, $offset_l, $offset_s, $offset_l + $lunghezza, $offset_s + $spessore, $bianco);
    
    // Salvataggio file PNG
    imagepng($img, $nome_file);
    imagedestroy($img);
}

// Genera le due icone richieste dal manifest
crea_icona(192, 'icon-192.png');
crea_icona(512, 'icon-512.png');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Generazione Icone PWA</title>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-2xl shadow-xl text-center max-w-md">
        <div class="text-4xl mb-3">✅</div>
        <h1 class="text-xl font-bold text-gray-800 mb-2">Icone Generate con Successo!</h1>
        <p class="text-gray-600 text-sm mb-6">I file <b>icon-192.png</b> e <b>icon-512.png</b> sono stati creati correttamente nella cartella principale.</p>
        <a href="bacheca_ritiri.php" class="bg-blue-600 text-white font-bold px-4 py-2 rounded-lg hover:bg-blue-700">Torna all'applicazione</a>
    </div>
</body>
</html>
