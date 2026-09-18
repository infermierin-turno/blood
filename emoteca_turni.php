<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'api_helper_sangue.php';

// Recupera i dati dell'utente loggato per verificare il ruolo (medico vs altri)
$utente_sessione_email = $_SESSION['utente'];
if (is_array($utente_sessione_email)) {
    // Se la sessione memorizza un array (es. i dati completi dell'utente), estraiamo l'email se presente, altrimenti la codifichiamo o prendiamo il campo email
    $email_stringa = $utente_sessione_email['email'] ?? json_encode($utente_sessione_email);
} else {
    $email_stringa = (string)$utente_sessione_email;
}

$dati_utente_loggato = esegui_get_api("utenti?email=eq." . urlencode($email_stringa) . "&select=id,nome,cognome,ruolo");

// Se per qualche motivo la ricerca per email non produce risultati, proviamo a cercare per ID se l'array di sessione contiene un ID
if ((!is_array($dati_utente_loggato) || count($dati_utente_loggato) === 0) && is_array($utente_sessione_email) && isset($utente_sessione_email['id'])) {
    $dati_utente_loggato = esegui_get_api("utenti?id=eq." . urlencode((string)$utente_sessione_email['id']) . "&select=id,nome,cognome,ruolo");
}

$ruolo_utente = '';
if (is_array($dati_utente_loggato) && count($dati_utente_loggato) > 0 && isset($dati_utente_loggato[0]['ruolo'])) {
    $ruolo_utente = strtolower(trim($dati_utente_loggato[0]['ruolo']));
}

// Consideriamo abilitato alla modifica chi ha ruolo 'medico' o simile (es. coordinatore/medico) oppure se forziamo il controllo per sicurezza
$is_medico = ($ruolo_utente === 'medico' || $ruolo_utente === 'coordinatore' || strpos($ruolo_utente, 'medic') !== false || strpos($ruolo_utente, 'coordinat') !== false);

// Funzione di supporto per richieste HTTP generiche (es. DELETE) se non presente in api_helper_sangue.php
if (!function_exists('esegui_richiesta_api')) {
    function esegui_richiesta_api($endpoint, $metodo = 'GET', $dati = []) {
        $ch_url = $endpoint;
        if (strpos($endpoint, 'http') !== 0) {
            $ch_url = (defined('SUPABASE_URL') ? SUPABASE_URL : '') . "/rest/v1/" . ltrim($endpoint, '/');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ch_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($metodo));
        
        $headers = [
            'apikey: ' . (defined('SUPABASE_KEY') ? SUPABASE_KEY : (getenv('SUPABASE_KEY') ?: '')),
            'Authorization: Bearer ' . (defined('SUPABASE_KEY') ? SUPABASE_KEY : (getenv('SUPABASE_KEY') ?: '')),
            'Content-Type: application/json',
            'Prefer: return=representation'
        ];

        if (!empty($dati)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dati));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}

// Endpoint corretto rilevato dai log del server Python su Render
$render_api_url = "https://emoteca-solver.onrender.com/calcola-turno";

$messaggio = '';
$tipo_messaggio = '';

// Gestione chiamata AJAX per la generazione giorno per giorno con verifica disponibilità reale
if (isset($_GET['action']) && $_GET['action'] === 'genera_giorno_ajax') {
    header('Content-Type: application/json');
    if (!$is_medico) {
        echo json_encode(['success' => false, 'error' => 'Accesso negato: solo il personale medico può generare o modificare i turni. (Ruolo rilevato: "' . $ruolo_utente . '", Email: "' . $email_stringa . '")']);
        exit;
    }

    $data_turno = $_GET['data'] ?? date('Y-m-d');
    
    // Controllo preventivo: se è domenica, non va generato alcun turno
    $num_giorno_sett = date('N', strtotime($data_turno));
    if ($num_giorno_sett == 7) {
        echo json_encode(['success' => false, 'data' => $data_turno, 'error' => 'Le domeniche sono escluse dai turni di trasporto.']);
        exit;
    }

    // 1. Verifica se esiste già un turno per questa data
    $turno_esistente = esegui_get_api("turni_trasporti?data_turno=eq.{$data_turno}&select=id");
    if (!is_array($turno_esistente)) $turno_esistente = [];
    
    // 2. Prepara il payload verificando gli operatori realmente disponibili in base ai turni di reparto
    $payload_render = prepara_payload_per_render_equo($data_turno);

    // Controlla se ci sono operatori disponibili nella lista
    $operatori_disponibili = array_filter($payload_render['operatori'], function($op) {
        return $op['disponibile'] === true;
    });

    // Se non ci sono operatori disponibili, non inventare: segna come scoperto/nessun utente disponibile
    if (empty($operatori_disponibili)) {
        if (!empty($turno_esistente) && is_array($turno_esistente[0])) {
            $turno_id_esistente = $turno_esistente[0]['id'];
            esegui_patch_api("turni_trasporti?id=eq.{$turno_id_esistente}", ['utente_id' => null, 'stato' => 'scoperto']);
        } else {
            $dati_inserimento = [
                'data_turno' => $data_turno,
                'utente_id' => null,
                'fascia_oraria' => 'Pomeriggio',
                'stato' => 'scoperto'
            ];
            esegui_post_api("turni_trasporti", $dati_inserimento);
        }

        echo json_encode([
            'success' => true, 
            'data' => $data_turno, 
            'operatore' => 'Nessun utente disponibile',
            'nota' => 'Nessun operatore disponibile in questo giorno'
        ]);
        exit;
    }

    // DEBUG: Salva il payload cURL su file per diagnostica
    file_put_contents('debug_payload.txt', json_encode($payload_render, JSON_PRETTY_PRINT));

    // 3. Esegue la chiamata cURL al solver Python su Render
    $ch = curl_init($render_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload_render));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $api_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200 || !$api_response) {
        $id_assegnato = trova_utente_disponibile_equo($data_turno, $operatori_disponibili);
        
        if (!$id_assegnato) {
            if (!empty($turno_esistente) && is_array($turno_esistente[0])) {
                $turno_id_esistente = $turno_esistente[0]['id'];
                esegui_patch_api("turni_trasporti?id=eq.{$turno_id_esistente}", ['utente_id' => null, 'stato' => 'scoperto']);
            } else {
                esegui_post_api("turni_trasporti", [
                    'data_turno' => $data_turno,
                    'utente_id' => null,
                    'fascia_oraria' => 'Pomeriggio',
                    'stato' => 'scoperto'
                ]);
            }
            echo json_encode(['success' => true, 'data' => $data_turno, 'operatore' => 'Nessun utente disponibile']);
            exit;
        }

        $u_info_fb = esegui_get_api("utenti?id=eq.{$id_assegnato}&select=nome,cognome");
        $nome_assegnato = 'Operatore';
        if (is_array($u_info_fb) && count($u_info_fb) > 0 && is_array($u_info_fb[0])) {
            $nome_assegnato = trim(($u_info_fb[0]['nome'] ?? '') . ' ' . ($u_info_fb[0]['cognome'] ?? '')) ?: 'Operatore';
        }
        
        if (!empty($turno_esistente) && is_array($turno_esistente[0])) {
            $turno_id_esistente = $turno_esistente[0]['id'];
            esegui_patch_api("turni_trasporti?id=eq.{$turno_id_esistente}", ['utente_id' => $id_assegnato, 'stato' => 'programmato']);
        } else {
            $dati_inserimento = [
                'data_turno' => $data_turno,
                'utente_id' => $id_assegnato,
                'fascia_oraria' => 'Pomeriggio',
                'stato' => 'programmato'
            ];
            esegui_post_api("turni_trasporti", $dati_inserimento);
        }

        echo json_encode(['success' => true, 'data' => $data_turno, 'operatore' => $nome_assegnato, 'nota' => 'Assegnato tramite fallback locale tra gli disponibili']);
        exit;
    }

    $decoded_res = json_decode($api_response, true);
    
    if (!isset($decoded_res['id_utente_selezionato']) || empty($decoded_res['id_utente_selezionato']) || $decoded_res['id_utente_selezionato'] === '1') {
        $id_assegnato = trova_utente_disponibile_equo($data_turno, $operatori_disponibili);
        if (!$id_assegnato) {
            if (!empty($turno_esistente) && is_array($turno_esistente[0])) {
                $turno_id_esistente = $turno_esistente[0]['id'];
                esegui_patch_api("turni_trasporti?id=eq.{$turno_id_esistente}", ['utente_id' => null, 'stato' => 'scoperto']);
            } else {
                esegui_post_api("turni_trasporti", [
                    'data_turno' => $data_turno,
                    'utente_id' => null,
                    'fascia_oraria' => 'Pomeriggio',
                    'stato' => 'scoperto'
                ]);
            }
            echo json_encode(['success' => true, 'data' => $data_turno, 'operatore' => 'Nessun utente disponibile']);
            exit;
        }
        $u_info_fb = esegui_get_api("utenti?id=eq.{$id_assegnato}&select=nome,cognome");
        $nome_assegnato = 'Operatore';
        if (is_array($u_info_fb) && count($u_info_fb) > 0 && is_array($u_info_fb[0])) {
            $nome_assegnato = trim(($u_info_fb[0]['nome'] ?? '') . ' ' . ($u_info_fb[0]['cognome'] ?? '')) ?: 'Operatore';
        }
    } else {
        $id_assegnato = (string)$decoded_res['id_utente_selezionato'];
        $nome_assegnato = $decoded_res['nome_selezionato'] ?? 'Operatore';
    }

    if (!empty($turno_esistente) && is_array($turno_esistente[0])) {
        $turno_id_esistente = $turno_esistente[0]['id'];
        $res_patch = esegui_patch_api("turni_trasporti?id=eq.{$turno_id_esistente}", ['utente_id' => $id_assegnato, 'stato' => 'programmato']);
        
        if ($res_patch === false || (is_array($res_patch) && (isset($res_patch['message']) || isset($res_patch['error'])))) {
            echo json_encode([
                'success' => false, 
                'data' => $data_turno, 
                'error' => 'Errore PATCH Supabase fallito.',
                'supabase_response' => $res_patch
            ]);
            exit;
        }
    } else {
        $dati_inserimento = [
            'data_turno' => $data_turno,
            'utente_id' => $id_assegnato,
            'fascia_oraria' => 'Pomeriggio',
            'stato' => 'programmato'
        ];
        $res_post = esegui_post_api("turni_trasporti", $dati_inserimento);
        
        if ($res_post === false || (is_array($res_post) && (isset($res_post['message']) || isset($res_post['error'])))) {
            echo json_encode([
                'success' => false, 
                'data' => $data_turno, 
                'error' => 'Errore POST Supabase fallito.',
                'supabase_response' => $res_post,
                'payload_inviato' => $dati_inserimento
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'data' => $data_turno, 'operatore' => $nome_assegnato]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifica_turno_manuale'])) {
    if (!$is_medico) {
        $messaggio = "Accesso negato: solo il personale medico può modificare i turni. (Ruolo rilevato: '{$ruolo_utente}')";
        $tipo_messaggio = "danger";
    } else {
        $turno_id = $_POST['turno_id'] ?? '';
        $nuovo_utente_id = $_POST['nuovo_utente_id'] ?? '';
        $data_turno = $_POST['data_turno'] ?? '';

        if (empty($turno_id) && !empty($data_turno)) {
            $dati_inserimento = [
                'data_turno' => $data_turno,
                'utente_id' => !empty($nuovo_utente_id) ? $nuovo_utente_id : null,
                'fascia_oraria' => 'Pomeriggio',
                'stato' => !empty($nuovo_utente_id) ? 'programmato' : 'scoperto'
            ];
            $res_post = esegui_post_api("turni_trasporti", $dati_inserimento);
            
            if ($res_post === false || (is_array($res_post) && (isset($res_post['message']) || isset($res_post['error'])))) {
                $messaggio = "Errore durante la creazione manuale del turno su Supabase.";
                $tipo_messaggio = "danger";
            } else {
                $messaggio = "Turno inserito manualmente con successo.";
                $tipo_messaggio = "success";
                if (function_exists('registra_log')) {
                    registra_log('EMOTECA', 'INSERIMENTO_MANUALE', "Creato turno di trasporto per la data {$data_turno}");
                }
            }
        } elseif (!empty($turno_id)) {
            $update_data = [
                'utente_id' => !empty($nuovo_utente_id) ? $nuovo_utente_id : null,
                'stato' => !empty($nuovo_utente_id) ? 'programmato' : 'scoperto'
            ];
            $patch_res = esegui_patch_api("turni_trasporti?id=eq.{$turno_id}", $update_data);
            
            if (is_array($patch_res) && (isset($patch_res['message']) || isset($patch_res['error']))) {
                $messaggio = "Errore durante l'aggiornamento manuale del turno su Supabase.";
                $tipo_messaggio = "danger";
            } else {
                $messaggio = "Turno aggiornato manualmente con successo.";
                $tipo_messaggio = "success";
                if (function_exists('registra_log')) {
                    registra_log('EMOTECA', 'MODIFICA_MANUALE', "Turno ID {$turno_id} aggiornato");
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_turno_manuale'])) {
    if (!$is_medico) {
        $messaggio = "Accesso negato: solo il personale medico può eliminare i turni.";
        $tipo_messaggio = "danger";
    } else {
        $turno_id = $_POST['turno_id'] ?? '';
        if (!empty($turno_id)) {
            if (function_exists('esegui_richiesta_api')) {
                $del_res = esegui_richiesta_api("turni_trasporti?id=eq.{$turno_id}", 'DELETE');
            }
            $messaggio = "Turno di trasporto eliminato con successo.";
            $tipo_messaggio = "success";
            if (function_exists('registra_log')) {
                registra_log('EMOTECA', 'ELIMINAZIONE_TURNO', "Eliminato turno di trasporto ID {$turno_id}");
            }
        }
    }
}

// Funzione per trovare l'utente disponibile più equo basandosi unicamente su quelli validi
function trova_utente_disponibile_equo($data_riferimento, $operatori_disponibili) {
    if (empty($operatori_disponibili)) return null;

    $candidati_ids = [];
    foreach ($operatori_disponibili as $op) {
        if (!empty($op['id'])) {
            $candidati_ids[] = $op['id'];
        }
    }

    if (empty($candidati_ids)) return null;

    $data_limite_6mesi = date('Y-m-d', strtotime($data_riferimento . ' -6 months'));
    
    $conteggio_turni = [];
    foreach ($candidati_ids as $cid) {
        $conteggio_turni[$cid] = 0;
    }

    $storico = esegui_get_api("turni_trasporti?data_turno=gte.{$data_limite_6mesi}&data_turno=lt.{$data_riferimento}&select=utente_id");
    if (is_array($storico)) {
        foreach ($storico as $st) {
            if (!is_array($st)) continue;
            $uid_sto = $st['utente_id'] ?? '';
            if (isset($conteggio_turni[$uid_sto])) {
                $conteggio_turni[$uid_sto]++;
            }
        }
    }

    asort($conteggio_turni);
    reset($conteggio_turni);
    return key($conteggio_turni);
}

// Funzione di supporto per preparare il payload per Render verificando i turni di reparto
function prepara_payload_per_render_equo($data_riferimento) {
    $parts = explode('-', $data_riferimento);
    $anno = isset($parts[0]) ? intval($parts[0]) : intval(date('Y'));
    $mese = isset($parts[1]) ? intval($parts[1]) : intval(date('n'));
    $giorno = isset($parts[2]) ? intval($parts[2]) : intval(date('j'));

    $raw_utenti = esegui_get_api("utenti?select=id,nome,cognome");
    if (!is_array($raw_utenti)) $raw_utenti = [];

    $raw_turni_ospedale = esegui_get_api("turni?data=eq.{$data_riferimento}&select=utente_id,fascia_oraria");
    if (!is_array($raw_turni_ospedale)) $raw_turni_ospedale = [];

    $utenti_in_turno_ids = [];
    foreach ($raw_turni_ospedale as $t) {
        if (!is_array($t)) continue;
        $fascia = trim($t['fascia_oraria'] ?? '');
        if ($fascia === 'P' || strtolower($fascia) === 'p' || strpos(strtolower($fascia), 'pomeriggio') !== false || strpos(strtolower($fascia), 'pom') !== false) {
            if (!empty($t['utente_id'])) {
                $utenti_in_turno_ids[] = $t['utente_id'];
            }
        }
    }

    $lista_operatori = [];
    foreach ($raw_utenti as $u) {
        if (!is_array($u)) continue;
        $uid = $u['id'] ?? '';
        if (empty($uid)) continue;

        $disponibile = true;
        if (!empty($raw_turni_ospedale) && !in_array($uid, $utenti_in_turno_ids)) {
            $disponibile = false;
        }

        $lista_operatori[] = [
            'id' => (string)$uid,
            'nome' => trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')) ?: ($u['email'] ?? 'Operatore'),
            'disponibile' => $disponibile
        ];
    }

    return [
        'anno' => $anno,
        'mese' => $mese,
        'giorno' => $giorno,
        'operatori' => $lista_operatori
    ];
}

// Gestione visualizzazione mese corrente per la tabella dei turni trasporti
$mese_corrente = isset($_GET['mese']) ? intval($_GET['mese']) : intval(date('n'));
$anno_corrente = isset($_GET['anno']) ? intval($_GET['anno']) : intval(date('Y'));

if ($mese_corrente < 1) { $mese_corrente = 12; $anno_corrente--; }
if ($mese_corrente > 12) { $mese_corrente = 1; $anno_corrente++; }

$giorni_mese = cal_days_in_month(CAL_GREGORIAN, $mese_corrente, $anno_corrente);
$data_inizio_calc = sprintf('%04d-%02d-01', $anno_corrente, $mese_corrente);
$data_fine_calc = sprintf('%04d-%02d-%02d', $anno_corrente, $mese_corrente, $giorni_mese);

$turni_trasporti_mese = esegui_get_api("turni_trasporti?data_turno=gte.{$data_inizio_calc}&data_turno=lte.{$data_fine_calc}&select=id,data_turno,utente_id,fascia_oraria,stato");
if (!is_array($turni_trasporti_mese)) $turni_trasporti_mese = [];

$matrice_trasporti = [];
foreach ($turni_trasporti_mese as $tt) {
    $matrice_trasporti[$tt['data_turno']] = $tt;
}

$utenti_elenco = esegui_get_api("utenti?select=id,nome,cognome,email&order=cognome.asc");
if (!is_array($utenti_elenco)) $utenti_elenco = [];

$nomi_mesi_it = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Generazione Turni Emoteca & Trasporti (AI)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-sm td, .table-sm th { font-size: 0.85rem; vertical-align: middle; }
        .spinner-border-sm { width: 1rem; height: 1rem; border-width: 0.15em; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4 px-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Gestione Turni Emoteca (Solver AI & Equità)</h2>
            <div>
                <?php if (!$is_medico): ?>
                    <span class="badge bg-warning text-dark me-3 fs-6" title="Ruolo db: '<?php echo htmlspecialchars($ruolo_utente); ?>' | Email sessione: '<?php echo htmlspecialchars($email_stringa); ?>'">Modalità Sola Lettura (Verifica Ruolo)</span>
                    <a href="gestione_turni_reparto.php" class="btn btn-outline-primary me-2 disabled" tabindex="-1" aria-disabled="true">Torna a Turni Reparto</a>
                    <a href="genera_turni_mese_ai.php" class="btn btn-outline-secondary disabled" tabindex="-1" aria-disabled="true">Vai a Genera con AI</a>
                <?php else: ?>
                    <span class="badge bg-success text-white me-3 fs-6">Accesso Coordinatore Attivo</span>
                    <a href="gestione_turni_reparto.php" class="btn btn-outline-primary me-2">Torna a Turni Reparto</a>
                    <a href="genera_turni_mese_ai.php" class="btn btn-outline-secondary">Vai a Genera con AI</a>
                <?php endif; ?>
                <a href="bacheca_ritiri.php" class="btn btn-outline-secondary">Bacheca ritiri</a>
            </div>
        </div>

        <?php if (!empty($messaggio)): ?>
            <div class="alert alert-<?php echo $tipo_messaggio; ?> alert-dismissible fade show" role="alert">
                <?php echo $messaggio; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Pianificazione Mensile - <?php echo $nomi_mesi_it[$mese_corrente] . ' ' . $anno_corrente; ?></h5>
                <div>
                    <?php 
                        $p_m = $mese_corrente - 1; $p_a = $anno_corrente;
                        if ($p_m < 1) { $p_m = 12; $p_a--; }
                        $n_m = $mese_corrente + 1; $n_a = $anno_corrente;
                        if ($n_m > 12) { $n_m = 1; $n_a++; }
                    ?>
                    <a href="?mese=<?php echo $p_m; ?>&anno=<?php echo $p_a; ?>" class="btn btn-sm btn-light">&laquo; Mese Prec</a>
                    <a href="?mese=<?php echo date('n'); ?>&anno=<?php echo date('Y'); ?>" class="btn btn-sm btn-light">Oggi</a>
                    <a href="?mese=<?php echo $n_m; ?>&anno=<?php echo $n_a; ?>" class="btn btn-sm btn-light">Mese Succ &raquo;</a>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <?php if ($is_medico): ?>
                        <button type="button" class="btn btn-success" id="btnGeneraMeseIntero">Genera Intero Mese con AI (Escluse Domeniche)</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-success" disabled title="Funzione riservata ai medici">Genera Intero Mese con AI (Sola Lettura)</button>
                    <?php endif; ?>
                    <span id="progressoGenerazione" class="text-muted fw-bold"></span>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Data</th>
                                <th>Giorno</th>
                                <th>Operatore Assegnato (Emoteca)</th>
                                <th>Stato</th>
                                <th class="text-center">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($g = 1; $g <= $giorni_mese; $g++): ?>
                                <?php 
                                    $data_loop = sprintf('%04d-%02d-%02d', $anno_corrente, $mese_corrente, $g);
                                    $num_giorno_sett = date('N', strtotime($data_loop));
                                    $nomi_giorni = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
                                    $etichetta_giorno = $nomi_giorni[$num_giorno_sett - 1];
                                    $is_festivo = ($num_giorno_sett == 7); // Domenica

                                    $turno_info = $matrice_trasporti[$data_loop] ?? null;
                                    $operatore_assegnato_id = $turno_info['utente_id'] ?? '';
                                    $stato_turno = $turno_info['stato'] ?? '';
                                    
                                    $nome_operatore_corrente = 'Non assegnato';
                                    if (!empty($operatore_assegnato_id)) {
                                        foreach ($utenti_elenco as $ue) {
                                            if ($ue['id'] === $operatore_assegnato_id) {
                                                $nome_operatore_corrente = trim(($ue['cognome'] ?? '') . ' ' . ($ue['nome'] ?? ''));
                                                if ($nome_operatore_corrente === '') $nome_operatore_corrente = $ue['email'];
                                                break;
                                            }
                                        }
                                    } elseif ($stato_turno === 'scoperto' || (isset($turno_info) && empty($operatore_assegnato_id))) {
                                        $nome_operatore_corrente = 'Nessun utente disponibile';
                                    }
                                ?>
                                <tr id="row-<?php echo $data_loop; ?>" class="<?php echo $is_festivo ? 'table-secondary text-muted' : ($nome_operatore_corrente === 'Nessun utente disponibile' ? 'table-warning' : ''); ?>">
                                    <td class="fw-bold <?php echo $is_festivo ? 'text-danger' : ''; ?>"><?php echo $data_loop; ?></td>
                                    <td><?php echo $etichetta_giorno; ?></td>
                                    <td class="cell-operatore fw-semibold">
                                        <?php if ($is_festivo): ?>
                                            <span class="text-muted fst-italic">Festivo (Nessun turno)</span>
                                        <?php elseif ($nome_operatore_corrente === 'Nessun utente disponibile'): ?>
                                            <span class="text-danger fw-bold">Nessun utente disponibile</span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($nome_operatore_corrente); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_festivo): ?>
                                            <span class="badge bg-secondary">Chiuso</span>
                                        <?php elseif ($nome_operatore_corrente === 'Nessun utente disponibile' || $stato_turno === 'scoperto'): ?>
                                            <span class="badge bg-danger">Scoperto</span>
                                        <?php elseif (!empty($turno_info)): ?>
                                            <span class="badge bg-success">Turno assegnato</span>
                                        <?php else: ?>
                                            <span class="bg-danger">Nessun utente disponibile</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$is_festivo): ?>
                                            <?php if ($is_medico): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-genera-singolo" data-data="<?php echo $data_loop; ?>">Genera AI</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalModifica-<?php echo $g; ?>">Modifica</button>

                                                <?php if (!empty($turno_info)): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo turno?');">
                                                        <input type="hidden" name="elimina_turno_manuale" value="1">
                                                        <input type="hidden" name="turno_id" value="<?php echo $turno_info['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Elimina</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Sola lettura</span>
                                            <?php endif; ?>

                                            <?php if ($is_medico): ?>
                                            <!-- Modal Modifica/Inserimento Manuale -->
                                            <div class="modal fade text-start" id="modalModifica-<?php echo $g; ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Gestione Turno - <?php echo $data_loop; ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="modifica_turno_manuale" value="1">
                                                                <input type="hidden" name="turno_id" value="<?php echo $turno_info['id'] ?? ''; ?>">
                                                                <input type="hidden" name="data_turno" value="<?php echo $data_loop; ?>">
                                                                
                                                                <div class="mb-3">
                                                                    <label class="form-label">Seleziona Operatore (o lascia vuoto)</label>
                                                                    <select name="nuovo_utente_id" class="form-select">
                                                                        <option value="">Nessun utente disponibile / Scoperto</option>
                                                                        <?php foreach ($utenti_elenco as $u): ?>
                                                                            <?php 
                                                                                $nc = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? ''));
                                                                                if ($nc === '') $nc = $u['email'];
                                                                            ?>
                                                                            <option value="<?php echo $u['id']; ?>" <?php echo ($u['id'] === $operatore_assegnato_id) ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($nc); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                                                <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($is_medico): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const bottoniSingoli = document.querySelectorAll('.btn-genera-singolo');
            bottoniSingoli.forEach(btn => {
                btn.addEventListener('click', function() {
                    const data = this.getAttribute('data-data');
                    const row = document.getElementById('row-' + data);
                    const cellOp = row.querySelector('.cell-operatore');
                    
                    btn.disabled = true;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

                    fetch('?action=genera_giorno_ajax&data=' + data)
                        .then(response => response.json())
                        .then(dataRes => {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                            if (dataRes.success) {
                                cellOp.innerHTML = dataRes.operatore;
                                window.location.reload();
                            } else {
                                alert('Errore: ' + (dataRes.error || 'Impossibile generare il turno'));
                            }
                        })
                        .catch(err => {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                            alert('Errore di connessione al server.');
                        });
                });
            });

            const btnMeseIntero = document.getElementById('btnGeneraMeseIntero');
            if (btnMeseIntero) {
                btnMeseIntero.addEventListener('click', async function() {
                    if (!confirm('Vuoi generare automaticamente i turni per l\'intero mese escludendo le domeniche?')) return;
                    
                    const bottoni = document.querySelectorAll('.btn-genera-singolo');
                    const progresso = document.getElementById('progressoGenerazione');
                    btnMeseIntero.disabled = true;
                    
                    let completati = 0;
                    let totale = bottoni.length;

                    for (let btn of bottoni) {
                        const data = btn.getAttribute('data-data');
                        progresso.textContent = `Generazione in corso: ${completati + 1} di ${totale} (${data})...`;
                        
                        try {
                            const response = await fetch('?action=genera_giorno_ajax&data=' + data);
                            const resJson = await response.json();
                        } catch (e) {
                            console.error('Errore su data ' + data);
                        }
                        completati++;
                    }

                    progresso.textContent = 'Generazione completata! Ricaricamento pagina...';
                    window.location.reload();
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>