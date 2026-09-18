<?php
// File: /blood/ricevi_booking.php
require_once __DIR__ . '/api_helper_sangue.php';

// Legge i dati inviati da Power Automate in formato JSON
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if ($data && isset($data['reparto'])) {
    
    date_default_timezone_set('Europe/Rome');
    
    // Pulizia e calcolo turno
    $reparto_pulito = trim(is_array($data['reparto']) ? $data['reparto'][0] : $data['reparto']);
    $orario_input = isset($data['orario_invio']) ? $data['orario_invio'] : date('H:i');
    
    $parti_ora = explode(':', $orario_input);
    $ora = (int)($parti_ora[0] ?? 0);
    $minuti = (int)($parti_ora[1] ?? 0);
    $minuti_totali = ($ora * 60) + $minuti;
    
    // Logica Turni: 08:00 (480) a 12:30 (750) = Pomeriggio, Notte altrimenti
    $turno_calcolato = ($minuti_totali >= 480 && $minuti_totali <= 750) ? 'Pomeriggio' : 'Notte';
    
    // Mappatura esatta delle colonne del database (Tabella: ritiri_sangue)
    $dati_da_inserire = [
        'reparto'          => $reparto_pulito,
        'turno_successivo' => $turno_calcolato,
        'note'             => $data['note'] ?? '',
        'stato'            => 'Da ritirare',
        'notifica_inviata' => false
        // 'created_at' viene gestito dal default del DB
        // 'id' viene generato automaticamente dal DB
    ];
    
    // Inserimento tramite API helper
    $risultato = esegui_post_api('ritiri_sangue', $dati_da_inserire);
    
    // Debug log per monitorare cosa succede (utile per Power Automate)
    file_put_contents('debug_supabase.log', "DATA INVIATA: " . json_encode($dati_da_inserire) . "\nRISPOSTA API: " . json_encode($risultato) . "\n", FILE_APPEND);
    
    // Verifica del successo (se non c'è una chiave 'code' nella risposta, l'operazione è avvenuta)
    if($risultato !== false && !isset($risultato['code'])) {
        http_response_code(200);
        echo "OK - Turno: " . $turno_calcolato;
    } else {
        http_response_code(500);
        echo "Errore DB - " . ($risultato['message'] ?? 'Errore sconosciuto');
    }
} else {
    http_response_code(400);
    echo "Errore Dati - Campo reparto mancante";
}
?>