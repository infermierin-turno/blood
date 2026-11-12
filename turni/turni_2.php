<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

require_once 'config.php';

function is_uuid($v) {
    return is_string($v) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
}

function supabase_v2_request($endpoint, $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch = curl_init($url);
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept-Profile: public',
        'Content-Profile: public'
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Errore cURL Supabase V2: " . $error);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return $method === 'GET' ? [] : false;
}

$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? $_SESSION['utente']['is_super_admin'] ?? false;
if (!$is_super_admin && in_array($ruolo_lower, ['super_admin', 'admin', 'superadmin'])) {
    $is_super_admin = true;
}

$is_capo_personale = in_array($ruolo_lower, ['capo_personale', 'capopersonale']);
$is_coordinatore = ($ruolo_lower === 'coordinatore');

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_v2_request("staging_utenti?id=eq." . rawurlencode($utente_id) . "&select=organizzazione_id,reparto_id");
    if (!empty($resUCorr) && is_array($resUCorr)) {
        if (empty($org_id_utente)) {
            $org_id_utente = $resUCorr[0]['organizzazione_id'] ?? null;
        }
        if (empty($reparto_id_utente)) {
            $reparto_id_utente = $resUCorr[0]['reparto_id'] ?? null;
        }
    }
}

$reparti_disponibili = [];
$urlReparti = "reparti?select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
if ($is_capo_personale && !empty($org_id_utente)) {
    $urlReparti = "reparti?organizzazione_id=eq." . rawurlencode($org_id_utente) . "&select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
}
$resReparti = supabase_v2_request($urlReparti);
if (is_array($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && is_uuid($_GET['reparto_id'])) {
        $reparto_selezionato = $_GET['reparto_id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

$filtro_ruolo = isset($_GET['filtro_ruolo']) ? trim($_GET['filtro_ruolo']) : '';
$filtro_squadra = isset($_GET['filtro_squadra']) ? trim($_GET['filtro_squadra']) : '';

$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti" : "Reparto non assegnato";
if ($reparto_selezionato !== '') {
    foreach ($reparti_disponibili as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
}

$messaggio = $_SESSION['flash_messaggio'] ?? '';
$tipo_alert = $_SESSION['flash_tipo'] ?? '';
unset($_SESSION['flash_messaggio'], $_SESSION['flash_tipo']);

$queryTipologie = "tipologie_turno?select=*";
$tipologieTurni = supabase_v2_request($queryTipologie);

if (!is_array($tipologieTurni) || empty($tipologieTurni)) {
    $tipologieTurni = [
        ['id' => '1', 'nome_turno' => 'Mattina', 'codice_breve' => 'M', 'colore' => '#0d6efd'],
        ['id' => '2', 'nome_turno' => 'Pomeriggio', 'codice_breve' => 'P', 'colore' => '#ffc107'],
        ['id' => '3', 'nome_turno' => 'Notte', 'codice_breve' => 'N', 'colore' => '#6610f2'],
        ['id' => '4', 'nome_turno' => 'Smonto', 'codice_breve' => 'S', 'colore' => '#fd7e14'],
        ['id' => '5', 'nome_turno' => 'Riposo', 'codice_breve' => 'R', 'colore' => '#198754'],
        ['id' => '6', 'nome_turno' => 'Ferie', 'codice_breve' => 'F', 'colore' => '#20c997']
    ];
}

$mappaColoriTurni = [];
$validCodiciTurno = [];
foreach ($tipologieTurni as $t) {
    $codice = strtoupper(trim($t['codice_breve'] ?? ''));
    $coloreHex = $t['colore'] ?? $t['colore_hex'] ?? '#6c757d';
    if (!empty($codice)) {
        $mappaColoriTurni[$codice] = $coloreHex;
        $validCodiciTurno[] = $codice;
    }
}
if (!isset($mappaColoriTurni['F'])) {
    $mappaColoriTurni['F'] = '#20c997';
    $validCodiciTurno[] = 'F';
}

function verificaVincoliTurnoV2($utente_id, $data_str, $nuovo_codice, $turno_escludere_id = null) {
    $inizioGiorno = $data_str . ' 00:00:00';
    $fineGiorno = $data_str . ' 23:59:59';
    $urlGiorno = "pianificazione?utente_id=eq." . rawurlencode($utente_id) . "&data_inizio=gte." . urlencode($inizioGiorno) . "&data_inizio=lte." . urlencode($fineGiorno) . "&select=*";
    
    $turniGiorno = supabase_v2_request($urlGiorno);
    if (!is_array($turniGiorno)) {
        $turniGiorno = [];
    }

    if (!empty($turno_escludere_id)) {
        $turniGiorno = array_filter($turniGiorno, function($t) use ($turno_escludere_id) {
            return (string)$t['id'] !== (string)$turno_escludere_id;
        });
    }

    $nuovoCodiceUpper = strtoupper(trim($nuovo_codice));
    $turniEsistentiCodici = [];

    foreach ($turniGiorno as $tg) {
        $turniEsistentiCodici[] = strtoupper(trim($tg['tipo_evento']));
    }

    if (!empty($turniEsistentiCodici)) {
        if (count($turniEsistentiCodici) === 1) {
            $esistente = $turniEsistentiCodici[0];
            $consentito = false;
            if ($esistente === 'M' && $nuovoCodiceUpper === 'P') $consentito = true;
            if ($esistente === 'P' && $nuovoCodiceUpper === 'M') $consentito = true;

            if (!$consentito) {
                return "ATTENZIONE: È già presente un turno ({$esistente}) in questa giornata. È consentito aggiungere un secondo turno solo combinando Mattina (M) e Pomeriggio (P).";
            }
        } else {
            return "ATTENZIONE: Raggiunto il limite massimo di turni assegnabili in questa giornata.";
        }
    }
    return true;
}

$mappaUtenti = [];
if ($is_super_admin) {
    $urlUtenti = "staging_utenti?select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=squadra.asc,nome.asc";
    if ($reparto_selezionato !== '') {
        $urlUtenti = "staging_utenti?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=squadra.asc,nome.asc";
    }
} elseif ($is_capo_personale) {
    if (!empty($org_id_utente)) {
        $urlUtenti = "staging_utenti?organizzazione_id=eq." . rawurlencode($org_id_utente) . ($reparto_selezionato !== '' ? "&reparto_id=eq." . rawurlencode($reparto_selezionato) : "") . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=squadra.asc,nome.asc";
    } else {
        $urlUtenti = "";
    }
} else {
    $urlUtenti = !empty($reparto_id_utente) ? "staging_utenti?reparto_id=eq." . rawurlencode($reparto_id_utente) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=squadra.asc,nome.asc" : "";
}

if (!empty($urlUtenti)) {
    $resCollab = supabase_v2_request($urlUtenti);
    if (is_array($resCollab) && !isset($resCollab['error'])) {
        foreach ($resCollab as $c) {
            $rC_low = strtolower(str_replace([' ', '-'], '_', $c['ruolo'] ?? ''));
            if (in_array($rC_low, ['super_admin', 'admin', 'superadmin', 'capo_personale', 'capopersonale'])) continue;
            // Normalizziamo la squadra se vuota
            if (empty($c['squadra'])) {
                $c['squadra'] = 'Senza Squadra';
            }
            $mappaUtenti[$c['id']] = $c;
        }
    }
}

$listaCollaboratori = array_values($mappaUtenti);

$ruoliDisponibili = [];
$squadreDisponibili = [];
foreach ($listaCollaboratori as $c) {
    $rText = trim($c['ruolo'] ?? $c['qualifica'] ?? '');
    if (!empty($rText) && !in_array($rText, $ruoliDisponibili)) $ruoliDisponibili[] = $rText;
    $sText = trim($c['squadra'] ?? '');
    if (!empty($sText) && !in_array($sText, $squadreDisponibili)) $squadreDisponibili[] = $sText;
}
sort($ruoliDisponibili);
sort($squadreDisponibili);

if (!empty($filtro_ruolo)) {
    $listaCollaboratori = array_filter($listaCollaboratori, function($c) use ($filtro_ruolo) {
        return strcasecmp(trim($c['ruolo'] ?? $c['qualifica'] ?? ''), $filtro_ruolo) === 0;
    });
    $listaCollaboratori = array_values($listaCollaboratori);
}

if (!empty($filtro_squadra)) {
    $listaCollaboratori = array_filter($listaCollaboratori, function($c) use ($filtro_squadra) {
        return strcasecmp(trim($c['squadra'] ?? ''), $filtro_squadra) === 0;
    });
    $listaCollaboratori = array_values($listaCollaboratori);
}

// Ordinamento rigoroso per Squadra e poi per Nome
usort($listaCollaboratori, function($a, $b) {
    $sqA = trim($a['squadra'] ?? 'Senza Squadra');
    $sqB = trim($b['squadra'] ?? 'Senza Squadra');
    if ($sqA !== $sqB) {
        return strcasecmp($sqA, $sqB);
    }
    return strcasecmp($a['nome'] ?? '', $b['nome'] ?? '');
});

$idsUtentiReparto = [];
foreach ($listaCollaboratori as $collab) {
    $idsUtentiReparto[] = $collab['id'];
}

$mese_selezionato = isset($_GET['mese']) ? max(1, min(12, intval($_GET['mese']))) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? max(2024, min(2030, intval($_GET['anno']))) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);
$primo_giorno_tab = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
$ultimo_giorno_tab = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);
$primo_giorno_prossimo_tab = date('Y-m-d', mktime(0, 0, 0, $mese_selezionato + 1, 1, $anno_selezionato));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $csrf_token)) {
        http_response_code(419);
        exit('Token CSRF non valido.');
    }

    if (!($is_coordinatore || $is_super_admin || $is_capo_personale)) {
        http_response_code(403);
        exit('Accesso non autorizzato.');
    }

    $azione = $_POST['azione'] ?? '';
    $nota_input = trim($_POST['nota_turno'] ?? '');

    if ($azione === 'cancella_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        if (is_uuid($turno_id)) {
            $idsEsc = !empty($idsUtentiReparto) ? implode(',', array_map('rawurlencode', $idsUtentiReparto)) : '00000000-0000-0000-0000-000000000000';
            $res = supabase_v2_request("pianificazione?id=eq." . rawurlencode($turno_id) . "&utente_id=in.($idsEsc)", 'DELETE');
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Turno cancellato con successo!";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante la cancellazione.";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: turni_v2.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo, 'filtro_squadra' => $filtro_squadra]));
        exit;
    }

    if ($azione === 'aggiungi_turno_cella') {
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = strtoupper(trim($_POST['valore'] ?? ''));

        if (is_uuid($utente_cell) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_cell) && in_array($nuovo_valore, $validCodiciTurno)) {
            $checkVincoli = verificaVincoliTurnoV2($utente_cell, $data_cell, $nuovo_valore);
            if ($checkVincoli === true) {
                $datiPost = [
                    'organizzazione_id' => $org_id_utente ?: null,
                    'reparto_id' => $reparto_selezionato ?: $reparto_id_utente,
                    'utente_id' => $utente_cell,
                    'data_inizio' => $data_cell . ' 00:00:00+00',
                    'data_fine' => $data_cell . ' 23:59:59+00',
                    'tipo_evento' => $nuovo_valore,
                    'stato' => 'Approvato',
                    'note' => !empty($nota_input) ? $nota_input : 'Inserito da turni_v2'
                ];
                $res = supabase_v2_request("pianificazione", 'POST', $datiPost);
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = "Turno aggiunto con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'inserimento nel database.";
                    $_SESSION['flash_tipo'] = "danger";
                }
            } else {
                $_SESSION['flash_messaggio'] = $checkVincoli;
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: turni_v2.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo, 'filtro_squadra' => $filtro_squadra]));
        exit;
    }

    if ($azione === 'modifica_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = strtoupper(trim($_POST['valore'] ?? ''));

        if (is_uuid($turno_id) && is_uuid($utente_cell) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_cell) && in_array($nuovo_valore, $validCodiciTurno)) {
            $checkVincoli = verificaVincoliTurnoV2($utente_cell, $data_cell, $nuovo_valore, $turno_id);
            if ($checkVincoli === true) {
                $datiPatch = [
                    'tipo_evento' => $nuovo_valore,
                    'stato' => 'Approvato',
                    'note' => !empty($nota_input) ? $nota_input : 'Modificato da turni_v2'
                ];
                $idsEsc = !empty($idsUtentiReparto) ? implode(',', array_map('rawurlencode', $idsUtentiReparto)) : '00000000-0000-0000-0000-000000000000';
                $res = supabase_v2_request("pianificazione?id=eq." . rawurlencode($turno_id) . "&utente_id=in.($idsEsc)", 'PATCH', $datiPatch);
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = "Turno aggiornato con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'aggiornamento.";
                    $_SESSION['flash_tipo'] = "danger";
                }
            } else {
                $_SESSION['flash_messaggio'] = $checkVincoli;
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: turni_v2.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo, 'filtro_squadra' => $filtro_squadra]));
        exit;
    }
}

$turniMeseData = [];
$assenzeMeseData = [];
if (!empty($idsUtentiReparto)) {
    $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
    $turniMeseData = supabase_v2_request("pianificazione?select=*&data_inizio=gte.$primo_giorno_tab&data_inizio=lt.$primo_giorno_prossimo_tab&utente_id=in.($idsEsc)");
    $assenzeMeseData = supabase_v2_request("assenze?select=*&data_inizio=lte.$ultimo_giorno_tab&data_fine=gte.$primo_giorno_tab&utente_id=in.($idsEsc)");
}

$mappaTurniGriglia = [];
if (is_array($turniMeseData)) {
    foreach ($turniMeseData as $tm) {
        $uId = $tm['utente_id'];
        $giornoNum = intval(date('d', strtotime($tm['data_inizio'])));
        $mappaTurniGriglia[$uId][$giornoNum][] = [
            'id' => $tm['id'],
            'tipo_evento' => $tm['tipo_evento'],
            'stato' => $tm['stato'] ?? 'Approvato',
            'fonte' => 'pianificazione'
        ];
    }
}

if (is_array($assenzeMeseData)) {
    foreach ($assenzeMeseData as $as) {
        $uId = $as['utente_id'];
        $tipoEvento = $as['tipo_evento'] ?? $as['tipo_assenza'] ?? 'F';
        $dInizio = max($primo_giorno_tab, substr($as['data_inizio'], 0, 10));
        $dFine = min($ultimo_giorno_tab, substr($as['data_fine'] ?? $as['data_inizio'], 0, 10));
        
        $curr = strtotime($dInizio);
        $end = strtotime($dFine);
        while ($curr <= $end) {
            $giornoNum = intval(date('d', $curr));
            $mappaTurniGriglia[$uId][$giornoNum][] = [
                'id' => $as['id'] ?? 'ass_' . rand(1000,9999),
                'tipo_evento' => $tipoEvento,
                'stato' => $as['stato'] ?? 'In attesa',
                'fonte' => 'assenze'
            ];
            $curr = strtotime('+1 day', $curr);
        }
    }
}

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Turni PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f6f9; padding-bottom: 60px; color: #212529; font-family: system-ui, -apple-system, sans-serif; }
        .card { border: none; border-radius: 10px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); background: #ffffff; }
        
        /* Tabella pulita e spaziosa */
        .table-turni { border-collapse: separate; border-spacing: 0; width: 100%; table-layout: fixed; }
        .table-turni th, .table-turni td { text-align: center; vertical-align: middle; font-size: 0.78rem; padding: 4px 2px !important; border-color: #dee2e6 !important; }
        .table-turni tbody tr { height: 42px; }
        .table-turni tbody tr:hover:not(.tr-sezione-squadra) { background-color: #f8f9fa !important; }
        
        .cella-interattiva { cursor: pointer; height: 42px; }
        .cella-interattiva:hover { background-color: #e9ecef !important; }
        
        .col-operatore-sticky {
            position: sticky; left: 0; background-color: #ffffff; z-index: 3;
            text-align: left !important; min-width: 200px; width: 200px; padding-left: 12px !important;
            border-right: 2px solid #adb5bd !important;
        }
        .tr-sezione-squadra td {
            background-color: #e9ecef !important;
            font-weight: bold;
            text-align: left !important;
            padding: 6px 12px !important;
            font-size: 0.8rem;
            color: #495057;
            border-top: 2px solid #ced4da !important;
        }
        
        /* Badge turni ordinati e ben visibili */
        .badge-turno {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 18px; font-size: 0.65rem; font-weight: 700; color: #fff;
            border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); margin: 1px auto;
        }
        .badge-in-attesa { border: 2px dashed #ffc107 !important; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Reparto: <?php echo htmlspecialchars($nome_reparto_selezionato); ?></a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="turni_v2.php">Tabellone Turni</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <!-- BARRA FILTRI SUPERIORE PULITA -->
        <div class="card shadow-sm mb-4 p-3">
            <form method="GET" action="turni_v2.php" class="row g-2 align-items-center justify-content-between">
                <div class="col-auto">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-calendar3 text-primary"></i> Tabellone <?php echo htmlspecialchars($mesiNomi[$mese_selezionato] . ' ' . $anno_selezionato); ?></h5>
                </div>
                <div class="col-auto d-flex gap-2 flex-wrap align-items-center">
                    <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                        <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Tutti i Reparti</option>
                            <?php foreach ($reparti_disponibili as $rep) { ?>
                                <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rep['nome_reparto'] ?? 'Reparto'); ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>

                    <select name="filtro_ruolo" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Tutti i Ruoli --</option>
                        <?php foreach ($ruoliDisponibili as $rOpt) { ?>
                            <option value="<?php echo htmlspecialchars($rOpt); ?>" <?php echo (strcasecmp($filtro_ruolo, $rOpt) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rOpt); ?></option>
                        <?php } ?>
                    </select>

                    <select name="filtro_squadra" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Tutte le Squadre --</option>
                        <?php foreach ($squadreDisponibili as $sOpt) { ?>
                            <option value="<?php echo htmlspecialchars($sOpt); ?>" <?php echo (strcasecmp($filtro_squadra, $sOpt) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sOpt); ?></option>
                        <?php } ?>
                    </select>

                    <select name="mese" class="form-select form-select-sm" onchange="this.form.submit()" style="width: 110px;">
                        <?php foreach ($mesiNomi as $numM => $strM) { ?>
                            <option value="<?php echo $numM; ?>" <?php echo ($numM === $mese_selezionato) ? 'selected' : ''; ?>><?php echo $strM; ?></option>
                        <?php } ?>
                    </select>
                    
                    <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()" style="width: 90px;">
                        <?php for ($a = 2024; $a <= 2030; $a++) { ?>
                            <option value="<?php echo $a; ?>" <?php echo ($a === $anno_selezionato) ? 'selected' : ''; ?>><?php echo $a; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo_alert); ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php } ?>

        <!-- TABELLA PRINCIPALE DIVISA PER SQUADRA -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 72vh;">
                    <table class="table table-bordered table-turni m-0">
                        <thead class="table-dark sticky-top" style="z-index: 4;">
                            <tr>
                                <th class="col-operatore-sticky bg-dark text-white">Operatore / Giorno</th>
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { 
                                    $timestampG = mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato);
                                    $isFestivo = (date('N', $timestampG) == 7);
                                ?>
                                    <th class="<?php echo $isFestivo ? 'bg-danger text-white' : ''; ?>" style="width: 36px;">
                                        <div style="font-size: 0.65rem; opacity: 0.85;"><?php echo date('D', $timestampG); ?></div>
                                        <div style="font-size: 0.85rem; font-weight: bold;"><?php echo $g; ?></div>
                                    </th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaCollaboratori)) { ?>
                                <tr>
                                    <td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center text-muted py-4">
                                        Nessun operatore trovato con i filtri selezionati.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php 
                                $ultimaSquadra = null;
                                foreach ($listaCollaboratori as $collab) { 
                                    $uId = $collab['id'];
                                    $nomeCollab = $collab['nome'] ?? 'Operatore';
                                    $ruoloCollab = $collab['ruolo'] ?? $collab['qualifica'] ?? '';
                                    $squadraCollab = trim($collab['squadra'] ?? 'Senza Squadra');

                                    // Se cambia squadra, inseriamo una riga di separazione/intestazione
                                    if ($ultimaSquadra !== $squadraCollab) {
                                        $ultimaSquadra = $squadraCollab;
                                ?>
                                    <tr class="tr-sezione-squadra">
                                        <td colspan="<?php echo $giorni_nel_mese + 1; ?>">
                                            <i class="bi bi-people-fill me-1"></i> Squadra: <?php echo htmlspecialchars($squadraCollab); ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                    <tr>
                                        <td class="col-operatore-sticky" title="<?php echo htmlspecialchars($nomeCollab); ?>">
                                            <div class="text-truncate fw-bold text-dark" style="max-width: 185px; font-size: 0.8rem;"><?php echo htmlspecialchars($nomeCollab); ?></div>
                                            <div class="text-muted" style="font-size: 0.65rem;">
                                                <?php echo htmlspecialchars($ruoloCollab); ?>
                                            </div>
                                        </td>
                                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { 
                                            $dataCellStr = sprintf('%04d-%02d-%02d', $anno_selezionato, $mese_selezionato, $g);
                                            $eventiGiorno = $mappaTurniGriglia[$uId][$g] ?? [];
                                            $eventiJsonEsc = htmlspecialchars(json_encode($eventiGiorno, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                        ?>
                                            <td class="cella-interattiva" onclick="apriModaleV2('<?php echo htmlspecialchars($uId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($nomeCollab, ENT_QUOTES); ?>', '<?php echo $dataCellStr; ?>', '<?php echo $eventiJsonEsc; ?>')">
                                                <div class="d-flex flex-row align-items-center justify-content-center gap-1">
                                                    <?php foreach ($eventiGiorno as $ev) {
                                                        $codEv = strtoupper(trim($ev['tipo_evento'] ?? ''));
                                                        $coloreBg = $mappaColoriTurni[$codEv] ?? '#6c757d';
                                                        $isInAttesa = (strcasecmp($ev['stato'] ?? '', 'Approvato') !== 0);
                                                    ?>
                                                        <span class="badge-turno <?php echo $isInAttesa ? 'badge-in-attesa' : ''; ?>" style="background-color: <?php echo htmlspecialchars($coloreBg); ?>;" title="<?php echo htmlspecialchars($codEv); ?>">
                                                            <?php echo htmlspecialchars($codEv); ?>
                                                        </span>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- MODALE CRUD TURNI V2 -->
    <div class="modal fade" id="modalTurniV2" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="titoloModaleV2">Gestione Turno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="formModaleV2" action="turni_v2.php">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <div class="modal-body small">
                        <input type="hidden" name="utente_id" id="v2_utente_id">
                        <input type="hidden" name="data" id="v2_data">
                        <input type="hidden" name="turno_id" id="v2_turno_id">
                        <input type="hidden" name="azione" id="v2_azione" value="aggiungi_turno_cella">

                        <div class="mb-3" id="v2_wrapper_valore">
                            <label class="form-label fw-bold">Seleziona Tipo Turno</label>
                            <select name="valore" id="v2_valore" class="form-select form-select-sm">
                                <option value="">-- Seleziona Turno --</option>
                                <?php foreach ($tipologieTurni as $tTip) { 
                                    $cBr = strtoupper(trim($tTip['codice_breve'] ?? ''));
                                    $nTrn = $tTip['nome_turno'] ?? $cBr;
                                ?>
                                    <option value="<?php echo htmlspecialchars($cBr); ?>"><?php echo htmlspecialchars($cBr); ?> - <?php echo htmlspecialchars($nTrn); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Note / Annotazioni</label>
                            <input type="text" name="nota_turno" id="v2_nota" class="form-control form-control-sm" placeholder="Eventuali annotazioni">
                        </div>

                        <div id="v2_avviso_assenza" class="alert alert-warning py-2 mb-3" style="display: none;">
                            <i class="bi bi-info-circle-fill"></i> Questo evento deriva dal modulo Assenze e non può essere modificato direttamente da qui.
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" id="v2_btn_elimina" class="btn btn-sm btn-outline-danger w-50 fw-bold" style="display: none;" onclick="impostaAzioneV2('cancella_turno_cella')">
                                <i class="bi bi-trash"></i> Elimina Turno
                            </button>
                            <button type="submit" id="v2_btn_salva" class="btn btn-sm btn-primary w-50 fw-bold" style="display: none;" onclick="impostaAzioneV2('modifica_turno_cella')">
                                <i class="bi bi-check-lg"></i> Aggiorna
                            </button>
                            <button type="submit" id="v2_btn_aggiungi" class="btn btn-sm btn-success w-100 fw-bold" style="display: none;" onclick="impostaAzioneV2('aggiungi_turno_cella')">
                                <i class="bi bi-plus-circle"></i> Assegna Turno
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function impostaAzioneV2(azioneStr) {
            document.getElementById('v2_azione').value = azioneStr;
            if (azioneStr === 'cancella_turno_cella') {
                document.getElementById('v2_valore').required = false;
            } else {
                document.getElementById('v2_valore').required = true;
            }
        }

        function apriModaleV2(utenteId, nomeOperatore, dataStr, eventiJson) {
            document.getElementById('v2_utente_id').value = utenteId;
            document.getElementById('v2_data').value = dataStr;
            document.getElementById('v2_nota').value = '';
            
            let dataFmt = dataStr.split('-').reverse().join('/');
            let eventi = [];
            try { eventi = JSON.parse(eventiJson); } catch(e) { eventi = []; }

            let selectValore = document.getElementById('v2_valore');
            let inputTurnoId = document.getElementById('v2_turno_id');
            let btnElimina = document.getElementById('v2_btn_elimina');
            let btnSalva = document.getElementById('v2_btn_salva');
            let btnAggiungi = document.getElementById('v2_btn_aggiungi');
            let avvisoAssenza = document.getElementById('v2_avviso_assenza');

            if (eventi.length > 0) {
                let evento = eventi[0];
                if (evento.fonte === 'assenze') {
                    document.getElementById('titoloModaleV2').innerText = "Visualizza Assenza - " + nomeOperatore + " (" + dataFmt + ")";
                    inputTurnoId.value = '';
                    selectValore.value = evento.tipo_evento;
                    selectValore.disabled = true;
                    btnElimina.style.display = 'none';
                    btnSalva.style.display = 'none';
                    btnAggiungi.style.display = 'none';
                    avvisoAssenza.style.display = 'block';
                } else {
                    document.getElementById('titoloModaleV2').innerText = "Modifica / Elimina Turno - " + nomeOperatore + " (" + dataFmt + ")";
                    inputTurnoId.value = evento.id;
                    selectValore.value = evento.tipo_evento;
                    selectValore.disabled = false;
                    btnElimina.style.display = 'block';
                    btnSalva.style.display = 'block';
                    btnAggiungi.style.display = 'none';
                    avvisoAssenza.style.display = 'none';
                }
            } else {
                document.getElementById('titoloModaleV2').innerText = "Assegna Turno - " + nomeOperatore + " (" + dataFmt + ")";
                inputTurnoId.value = '';
                selectValore.value = '';
                selectValore.disabled = false;
                btnElimina.style.display = 'none';
                btnSalva.style.display = 'none';
                btnAggiungi.style.display = 'block';
                avvisoAssenza.style.display = 'none';
            }

            let modalEl = document.getElementById('modalTurniV2');
            let modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    </script>
</body>
</html>
