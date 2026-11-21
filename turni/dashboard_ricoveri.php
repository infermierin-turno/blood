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

function is_valid_date($d) {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $parti = explode('-', $d);
    return checkdate((int)$parti[1], (int)$parti[2], (int)$parti[0]);
}

$ultimo_errore_supabase = '';

function supabase_dash_request($endpoint) {
    global $ultimo_errore_supabase;
    $ultimo_errore_supabase = '';

    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch = curl_init($url);

    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept-Profile: public',
        'Content-Profile: public'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        $ultimo_errore_supabase = "cURL: " . $error;
        error_log("Errore cURL Supabase (dashboard_ricoveri): " . $error);
        return [];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    $ultimo_errore_supabase = "HTTP {$httpCode} - " . $response;
    error_log("Errore HTTP Supabase (dashboard_ricoveri) [{$httpCode}]: " . $response);
    return [];
}

$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$nome_utente_corrente = $_SESSION['nome'] ?? $_SESSION['utente']['nome'] ?? '';
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? $_SESSION['utente']['is_super_admin'] ?? false;
if (!$is_super_admin && in_array($ruolo_lower, ['super_admin', 'admin', 'superadmin'], true)) {
    $is_super_admin = true;
}

$is_capo_personale = in_array($ruolo_lower, ['capo_personale', 'capopersonale'], true);

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_dash_request("staging_utenti?id=eq." . rawurlencode($utente_id) . "&select=organizzazione_id,reparto_id");
    if (!empty($resUCorr)) {
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
$resReparti = supabase_dash_request($urlReparti);
if (!empty($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && is_uuid($_GET['reparto_id'])) {
        $reparto_selezionato = $_GET['reparto_id'];
    } elseif (!empty($reparto_id_utente) && is_uuid($reparto_id_utente)) {
        $reparto_selezionato = $reparto_id_utente;
    } elseif (!empty($reparti_disponibili)) {
        $reparto_selezionato = $reparti_disponibili[0]['id'];
    }
} else {
    $reparto_selezionato = is_uuid($reparto_id_utente) ? $reparto_id_utente : '';
}

$data_giro = (isset($_GET['data_giro']) && is_valid_date($_GET['data_giro']))
    ? $_GET['data_giro']
    : date('Y-m-d');

$filtro_rapido = isset($_GET['filtro']) ? trim($_GET['filtro']) : 'tutti';
$ricerca_testo = isset($_GET['q']) ? trim($_GET['q']) : '';
$vista_layout = (isset($_GET['vista']) && $_GET['vista'] === 'schede') ? 'schede' : 'tabella';

$finestra_giorni = isset($_GET['giorni']) ? intval($_GET['giorni']) : 14;
if (!in_array($finestra_giorni, [1, 3, 7, 14, 30, 999], true)) {
    $finestra_giorni = 14;
}

$filtro_lista_op = isset($_GET['lista_op']) ? trim($_GET['lista_op']) : 'tutti';
if (!in_array($filtro_lista_op, ['tutti', 'oggi', 'prossimi'], true)) {
    $filtro_lista_op = 'tutti';
}

$nome_reparto_corrente = "Reparto non selezionato";
foreach ($reparti_disponibili as $rep) {
    if ((string)$rep['id'] === (string)$reparto_selezionato) {
        $nome_reparto_corrente = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
        if (empty($org_id_utente) && !empty($rep['organizzazione_id'])) {
            $org_id_utente = $rep['organizzazione_id'];
        }
        break;
    }
}

$statiBadge = [
    'In attesa di visita'       => ['cls' => 'bg-secondary',          'ico' => 'bi-hourglass-split'],
    'Visitato'                  => ['cls' => 'bg-success',            'ico' => 'bi-check-circle-fill'],
    'Da medicare'               => ['cls' => 'bg-warning text-dark',  'ico' => 'bi-bandaid-fill'],
    'Esami/Consulenze in corso' => ['cls' => 'bg-info text-dark',     'ico' => 'bi-clipboard2-pulse-fill'],
    'Dimissione in valutazione' => ['cls' => 'bg-primary',            'ico' => 'bi-box-arrow-right'],
    'Dimissione protetta'       => ['cls' => 'bg-dark',               'ico' => 'bi-shield-check']
];

$postiLetto = [];
if (!empty($reparto_selezionato)) {
    $postiLetto = supabase_dash_request(
        "posti_letto_reparto?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&attivo=eq.true&select=*&order=ordine_letto.asc,stanza.asc,letto.asc"
    );
}

$consegneApertePerLetto = [];
$consegneTotaliPerLetto = [];
$dimissioniGiorno = [];
$totaleConsegneAperte = 0;
$totaleConsegneTrascinate = 0;

if (!empty($reparto_selezionato)) {
    $resConsGiorno = supabase_dash_request(
        "giro_visita_consegne?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro) . "&select=*&order=completata.asc,created_at.desc"
    );
    $resConsArretrate = supabase_dash_request(
        "giro_visita_consegne?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=lt." . rawurlencode($data_giro) . "&completata=eq.false&select=*&order=data_giro.asc,created_at.asc"
    );

    $consegneCombinate = [];
    $idsConsegneVisti = [];

    foreach ($resConsArretrate as $c) {
        $cId = $c['id'] ?? '';
        $notaTesto = trim($c['nota'] ?? '');
        if (mb_stripos($notaTesto, 'Dimissione registrata') === 0) {
            continue;
        }
        if ($cId !== '' && isset($idsConsegneVisti[$cId])) {
            continue;
        }
        if ($cId !== '') {
            $idsConsegneVisti[$cId] = true;
        }
        $c['_arretrata'] = true;
        $consegneCombinate[] = $c;
    }

    foreach ($resConsGiorno as $c) {
        $cId = $c['id'] ?? '';
        $notaTesto = trim($c['nota'] ?? '');
        if (mb_stripos($notaTesto, 'Dimissione registrata') === 0) {
            $dimissioniGiorno[] = $c;
            continue;
        }
        if ($cId !== '' && isset($idsConsegneVisti[$cId])) {
            continue;
        }
        if ($cId !== '') {
            $idsConsegneVisti[$cId] = true;
        }
        $c['_arretrata'] = false;
        $consegneCombinate[] = $c;
    }

    foreach ($consegneCombinate as $c) {
        $plId = $c['posto_letto_id'] ?? '';
        if ($plId !== '') {
            $consegneTotaliPerLetto[$plId][] = $c;
            if (empty($c['completata'])) {
                $consegneApertePerLetto[$plId][] = $c;
                $totaleConsegneAperte++;
                if (!empty($c['_arretrata'])) {
                    $totaleConsegneTrascinate++;
                }
            }
        }
    }
}

$dataLimiteOp = ($finestra_giorni === 999)
    ? '9999-12-31'
    : date('Y-m-d', strtotime($data_giro . ' +' . ($finestra_giorni - 1) . ' days'));

$listaOperatoria = [];
$interventiOggiCount = 0;
$interventiProssimiCount = 0;

$kpi = [
    'posti_attivi'      => count($postiLetto),
    'occupati'          => 0,
    'liberi'            => 0,
    'da_visitare'       => 0,
    'da_rivalutare'     => 0,
    'dimissioni'        => 0,
    'consegne_aperte'   => $totaleConsegneAperte,
    'dimessi_giorno'    => count($dimissioniGiorno)
];

foreach ($postiLetto as $pl) {
    $plId = $pl['id'] ?? '';
    $stanza = trim($pl['stanza'] ?? '');
    $letto = trim($pl['letto'] ?? '');
    $sigla = trim($pl['sigla_paziente'] ?? '');
    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';
    $noteStato = trim($pl['note_stato'] ?? '');
    $dataInt = trim($pl['data_intervento'] ?? '');
    $hasDataInt = is_valid_date($dataInt);

    $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale' || $hasDataInt);
    if ($isOccupato) {
        $kpi['occupati']++;
    } else {
        $kpi['liberi']++;
    }

    if ($st === 'In attesa di visita') {
        $kpi['da_visitare']++;
    }
    if (in_array($st, ['Da medicare', 'Esami/Consulenze in corso'], true) || $prio === 'Urgente') {
        $kpi['da_rivalutare']++;
    }
    if (in_array($st, ['Dimissione in valutazione', 'Dimissione protetta'], true) || $prio === 'Dimissione') {
        $kpi['dimissioni']++;
    }

    if ($hasDataInt && $dataInt >= $data_giro && $dataInt <= $dataLimiteOp) {
        $isOggi = ($dataInt === $data_giro);
        if ($isOggi) {
            $interventiOggiCount++;
        } else {
            $interventiProssimiCount++;
        }

        $includeItem = true;
        if ($filtro_lista_op === 'oggi' && !$isOggi) {
            $includeItem = false;
        } elseif ($filtro_lista_op === 'prossimi' && $isOggi) {
            $includeItem = false;
        }

        if ($includeItem) {
            $labelLettoOp = trim($stanza . ($letto !== '' ? ' / ' . $letto : ''), ' /');
            if ($labelLettoOp === '') {
                $labelLettoOp = 'Posto';
            }
            $listaOperatoria[] = [
                'id' => $plId,
                'stanza' => $stanza,
                'letto' => $letto,
                'posto' => $labelLettoOp,
                'ordine_letto' => intval($pl['ordine_letto'] ?? 999),
                'sigla_paziente' => $sigla !== '' ? $sigla : 'Sigla n.d.',
                'diagnosi_sintetica' => $diagnosi,
                'data_intervento' => $dataInt,
                'is_oggi' => $isOggi,
                'stato_visita' => $st,
                'priorita' => $prio,
                'note_stato' => $noteStato,
                'consegne_aperte' => count($consegneApertePerLetto[$plId] ?? [])
            ];
        }
    }
}

usort($listaOperatoria, function ($a, $b) {
    if ($a['data_intervento'] !== $b['data_intervento']) {
        return strcmp($a['data_intervento'], $b['data_intervento']);
    }
    if ($a['ordine_letto'] !== $b['ordine_letto']) {
        return $a['ordine_letto'] <=> $b['ordine_letto'];
    }
    return strcmp($a['posto'], $b['posto']);
});

$postiFiltrati = array_values(array_filter($postiLetto, function($pl) use ($filtro_rapido, $ricerca_testo, $consegneApertePerLetto) {
    $plId = $pl['id'] ?? '';
    $sigla = trim($pl['sigla_paziente'] ?? '');
    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';
    $dataInt = trim($pl['data_intervento'] ?? '');
    $hasDataInt = is_valid_date($dataInt);
    $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale' || $hasDataInt);

    if ($filtro_rapido === 'occupati' && !$isOccupato) {
        return false;
    }
    if ($filtro_rapido === 'liberi' && $isOccupato) {
        return false;
    }
    if ($filtro_rapido === 'da_visitare' && $st !== 'In attesa di visita') {
        return false;
    }
    if ($filtro_rapido === 'da_rivalutare' && !in_array($st, ['Da medicare', 'Esami/Consulenze in corso'], true) && $prio !== 'Urgente') {
        return false;
    }
    if ($filtro_rapido === 'dimissioni' && !in_array($st, ['Dimissione in valutazione', 'Dimissione protetta'], true) && $prio !== 'Dimissione') {
        return false;
    }
    if ($filtro_rapido === 'consegne_aperte' && empty($consegneApertePerLetto[$plId])) {
        return false;
    }

    if ($ricerca_testo !== '') {
        $testoConsegne = '';
        foreach ($consegneApertePerLetto[$plId] ?? [] as $ca) {
            $testoConsegne .= ' ' . ($ca['tag_azione'] ?? '') . ' ' . ($ca['nota'] ?? '');
        }
        $dataIntFmt = $hasDataInt ? date('d/m/Y', strtotime($dataInt)) : '';
        $haystack = mb_strtolower(
            ($pl['stanza'] ?? '') . ' ' .
            ($pl['letto'] ?? '') . ' ' .
            $sigla . ' ' .
            $diagnosi . ' ' .
            $dataInt . ' ' .
            $dataIntFmt . ' ' .
            ($pl['note_stato'] ?? '') . ' ' .
            $st . ' ' .
            $prio . ' ' .
            $testoConsegne
        );
        if (mb_strpos($haystack, mb_strtolower($ricerca_testo)) === false) {
            return false;
        }
    }

    return true;
}));

function build_dash_url(array $overrides = []) {
    global $reparto_selezionato, $data_giro, $filtro_rapido, $ricerca_testo, $vista_layout, $finestra_giorni, $filtro_lista_op;
    $params = [
        'reparto_id' => $reparto_selezionato,
        'data_giro'  => $data_giro,
        'filtro'     => $filtro_rapido,
        'vista'      => $vista_layout,
        'giorni'     => $finestra_giorni,
        'lista_op'   => $filtro_lista_op
    ];
    if ($ricerca_testo !== '') {
        $params['q'] = $ricerca_testo;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return "dashboard_ricoveri.php?" . http_build_query($params);
}

function build_giro_visita_url(array $pl, $statoOverride = null, $dataOverride = null) {
    global $reparto_selezionato, $data_giro;
    $chiaveRicerca = trim($pl['sigla_paziente'] ?? '');
    if ($chiaveRicerca === '') {
        $chiaveRicerca = trim(($pl['stanza'] ?? '') . ' ' . ($pl['letto'] ?? ''));
    }

    $params = [
        'reparto_id' => $reparto_selezionato,
        'data_giro'  => ($dataOverride !== null && is_valid_date($dataOverride)) ? $dataOverride : $data_giro
    ];
    if (!empty($pl['id'])) {
        $params['posto_id'] = $pl['id'];
    }
    if ($chiaveRicerca !== '') {
        $params['q'] = $chiaveRicerca;
    }
    if ($statoOverride !== null && $statoOverride !== '') {
        $params['stato'] = $statoOverride;
    }

    $anchor = !empty($pl['id']) ? '#posto-' . rawurlencode($pl['id']) : '';
    return "giro_visita.php?" . http_build_query($params) . $anchor;
}

$giroVisitaOccupatiUrl = "giro_visita.php?" . http_build_query([
    'reparto_id' => $reparto_selezionato,
    'data_giro'  => $data_giro,
    'filtro'     => 'occupati'
]);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Ricoveri - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f1f5f9; color: #0f172a; font-family: system-ui, -apple-system, sans-serif; padding-bottom: 40px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06); background: #ffffff; }
        .kpi-card { border-left: 4px solid #64748b; transition: transform 0.12s ease; }
        .kpi-card:hover { transform: translateY(-1px); }
        .kpi-val { font-size: 1.55rem; font-weight: 800; line-height: 1.1; }
        .kpi-lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; font-weight: 700; }
        .row-urgente { background-color: #fff5f5 !important; }
        .row-dimissione { background-color: #eff6ff !important; }
        .row-libero { color: #64748b; }
        .row-intervento-oggi { background-color: #fff1f2 !important; }
        .table-schematica th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; background-color: #f8fafc; white-space: nowrap; }
        .table-schematica td { vertical-align: middle; font-size: 0.88rem; }
        .badge-letto { font-weight: 700; font-size: 0.82rem; padding: 0.35em 0.55em; }
        .badge-link { text-decoration: none; transition: transform 0.1s ease, filter 0.1s ease; display: inline-flex; align-items: center; gap: 4px; }
        .badge-link:hover { transform: translateY(-1px); filter: brightness(0.95); }
        .scheda-ricovero { border-left: 4px solid #64748b; }
        .scheda-ricovero.prio-urgente { border-left-color: #dc3545; }
        .scheda-ricovero.prio-dimissione { border-left-color: #0d6efd; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; padding-bottom: 0; }
            .card { box-shadow: none; border: 1px solid #cbd5e1; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 shadow-sm no-print">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Dashboard Ricoveri</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 px-md-4">

        <?php if (!empty($ultimo_errore_supabase)): ?>
            <div class="alert alert-warning py-2 small no-print">
                <i class="bi bi-exclamation-triangle"></i> Avviso lettura dati: <?php echo htmlspecialchars($ultimo_errore_supabase); ?>
            </div>
        <?php endif; ?>

        <div class="card p-3 mb-3">
            <div class="row g-2 align-items-center">
                <div class="col-lg-5">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="h5 mb-0 fw-bold">
                            <i class="bi bi-grid-1x2-fill text-primary"></i> Sintesi Ricoveri &amp; Lista Operatoria
                        </h1>
                        <span class="badge bg-secondary-subtle text-dark border">
                            <i class="bi bi-eye"></i> Read-Only
                        </span>
                    </div>
                    <div class="small text-muted mt-1">
                        <strong><?php echo htmlspecialchars($nome_reparto_corrente); ?></strong>
                        &bull; Data: <strong><?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?></strong>
                        &bull; Visualizzati: <strong><?php echo count($postiFiltrati); ?></strong> / <?php echo count($postiLetto); ?>
                    </div>
                </div>

                <div class="col-lg-7 no-print">
                    <form method="GET" action="dashboard_ricoveri.php" class="row g-2 justify-content-lg-end align-items-center">
                        <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro_rapido); ?>">
                        <input type="hidden" name="vista" value="<?php echo htmlspecialchars($vista_layout); ?>">
                        <input type="hidden" name="lista_op" value="<?php echo htmlspecialchars($filtro_lista_op); ?>">

                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)): ?>
                            <div class="col-sm-5 col-md-3">
                                <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <?php foreach ($reparti_disponibili as $rep): ?>
                                        <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ((string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-sm-4 col-md-3">
                            <input type="date" name="data_giro" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data_giro); ?>" onchange="this.form.submit()">
                        </div>

                        <div class="col-sm-4 col-md-2">
                            <select name="giorni" class="form-select form-select-sm" onchange="this.form.submit()" title="Orizzonte lista operatoria">
                                <option value="1" <?php echo $finestra_giorni === 1 ? 'selected' : ''; ?>>Solo data</option>
                                <option value="3" <?php echo $finestra_giorni === 3 ? 'selected' : ''; ?>>3 gg</option>
                                <option value="7" <?php echo $finestra_giorni === 7 ? 'selected' : ''; ?>>7 gg</option>
                                <option value="14" <?php echo $finestra_giorni === 14 ? 'selected' : ''; ?>>14 gg</option>
                                <option value="30" <?php echo $finestra_giorni === 30 ? 'selected' : ''; ?>>30 gg</option>
                                <option value="999" <?php echo $finestra_giorni === 999 ? 'selected' : ''; ?>>Tutti futuri</option>
                            </select>
                        </div>

                        <div class="col-sm-8 col-md-3">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca letto, sigla, data..." value="<?php echo htmlspecialchars($ricerca_testo); ?>">
                        </div>

                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary" title="Applica filtri"><i class="bi bi-search"></i></button>
                            <?php if ($ricerca_testo !== '' || $filtro_rapido !== 'tutti' || $filtro_lista_op !== 'tutti'): ?>
                                <a href="<?php echo htmlspecialchars(build_dash_url(['q' => null, 'filtro' => 'tutti', 'lista_op' => 'tutti'])); ?>" class="btn btn-sm btn-outline-secondary" title="Reset filtri"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark" title="Stampa"><i class="bi bi-printer"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars($giroVisitaOccupatiUrl); ?>" class="text-decoration-none text-reset" title="Apri Giro Visita sui letti occupati">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #0f172a;">
                        <div class="kpi-lbl">Occupati / Posti <i class="bi bi-box-arrow-up-right ms-1"></i></div>
                        <div class="kpi-val"><?php echo (int)$kpi['occupati']; ?> <span class="fs-6 fw-normal text-muted">/ <?php echo (int)$kpi['posti_attivi']; ?></span></div>
                        <div class="small text-muted">Liberi: <strong><?php echo (int)$kpi['liberi']; ?></strong> &bull; Apri Giro</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'da_visitare'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #64748b;">
                        <div class="kpi-lbl">Da Visitare</div>
                        <div class="kpi-val text-secondary"><?php echo (int)$kpi['da_visitare']; ?></div>
                        <div class="small text-muted">In attesa giro</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['lista_op' => 'oggi'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #dc3545;">
                        <div class="kpi-lbl">Interventi Oggi</div>
                        <div class="kpi-val text-danger"><?php echo (int)$interventiOggiCount; ?></div>
                        <div class="small text-muted">Data <?php echo htmlspecialchars(date('d/m', strtotime($data_giro))); ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['lista_op' => 'prossimi'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #0d6efd;">
                        <div class="kpi-lbl">Prossimi Interventi</div>
                        <div class="kpi-val text-primary"><?php echo (int)$interventiProssimiCount; ?></div>
                        <div class="small text-muted">Finestra <?php echo $finestra_giorni === 999 ? 'futura' : (int)$finestra_giorni . ' gg'; ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'consegne_aperte'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #f59e0b;">
                        <div class="kpi-lbl">Consegne Aperte</div>
                        <div class="kpi-val text-warning"><?php echo (int)$kpi['consegne_aperte']; ?></div>
                        <div class="small text-muted">
                            <?php if ($totaleConsegneTrascinate > 0): ?>
                                Incl. <strong><?php echo (int)$totaleConsegneTrascinate; ?></strong> arretrat<?php echo $totaleConsegneTrascinate === 1 ? 'a' : 'e'; ?>
                            <?php else: ?>
                                Da completare
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <div class="card p-2 h-100 kpi-card" style="border-left-color: #198754;">
                    <div class="kpi-lbl">Dimessi Oggi</div>
                    <div class="kpi-val text-success"><?php echo (int)$kpi['dimessi_giorno']; ?></div>
                    <div class="small text-muted">Data <?php echo htmlspecialchars(date('d/m', strtotime($data_giro))); ?></div>
                </div>
            </div>
        </div>

        <div class="card p-3 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h6 fw-bold mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-scissors text-danger"></i> Lista Operatoria (Oggi e Prossimi Giorni)
                        <span class="badge bg-dark"><?php echo count($listaOperatoria); ?></span>
                    </h2>
                    <div class="small text-muted">
                        Dal <?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>
                        <?php if ($finestra_giorni === 999): ?>
                            (tutte le date future)
                        <?php elseif ($finestra_giorni === 1): ?>
                            (solo data selezionata)
                        <?php else: ?>
                            al <?php echo htmlspecialchars(date('d/m/Y', strtotime($dataLimiteOp))); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-1 no-print">
                    <a href="<?php echo htmlspecialchars(build_dash_url(['lista_op' => 'tutti'])); ?>" class="btn btn-sm <?php echo $filtro_lista_op === 'tutti' ? 'btn-dark' : 'btn-outline-secondary bg-white'; ?>">
                        Tutti (<?php echo (int)($interventiOggiCount + $interventiProssimiCount); ?>)
                    </a>
                    <a href="<?php echo htmlspecialchars(build_dash_url(['lista_op' => 'oggi'])); ?>" class="btn btn-sm <?php echo $filtro_lista_op === 'oggi' ? 'btn-danger' : 'btn-outline-danger bg-white'; ?>">
                        Oggi (<?php echo (int)$interventiOggiCount; ?>)
                    </a>
                    <a href="<?php echo htmlspecialchars(build_dash_url(['lista_op' => 'prossimi'])); ?>" class="btn btn-sm <?php echo $filtro_lista_op === 'prossimi' ? 'btn-primary' : 'btn-outline-primary bg-white'; ?>">
                        Prossimi (<?php echo (int)$interventiProssimiCount; ?>)
                    </a>
                </div>
            </div>

            <?php if (empty($reparto_selezionato)): ?>
                <div class="alert alert-info mb-0 py-2 small">Nessun reparto associato all'utenza corrente.</div>
            <?php elseif (empty($listaOperatoria)): ?>
                <div class="alert alert-light border text-muted mb-0 py-2 small">
                    Nessun intervento programmato nell'intervallo selezionato. Puoi impostare la <strong>Data Intervento</strong> da <strong>Giro Visita</strong> aprendo la modifica di un posto letto.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-schematica mb-0">
                        <thead>
                            <tr>
                                <th>Data Intervento</th>
                                <th>Postazione</th>
                                <th>Paziente</th>
                                <th>Diagnosi / Indicazione</th>
                                <th>Stato / Note</th>
                                <th class="text-end no-print">Scheda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($listaOperatoria as $op):
                                $dataIntFmt = date('d/m/Y', strtotime($op['data_intervento']));
                                $isOggi = !empty($op['is_oggi']);
                                $linkCardOp = build_giro_visita_url([
                                    'id' => $op['id'],
                                    'sigla_paziente' => $op['sigla_paziente'] !== 'Sigla n.d.' ? $op['sigla_paziente'] : '',
                                    'stanza' => $op['stanza'],
                                    'letto' => $op['letto']
                                ], null, $op['data_intervento']);
                            ?>
                                <tr class="<?php echo $isOggi ? 'row-intervento-oggi' : ''; ?>">
                                    <td class="fw-bold text-nowrap">
                                        <?php echo htmlspecialchars($dataIntFmt); ?>
                                        <?php if ($isOggi): ?>
                                            <span class="badge bg-danger ms-1">OGGI</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge bg-dark badge-letto"><?php echo htmlspecialchars($op['posto']); ?></span>
                                    </td>
                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars($op['sigla_paziente']); ?>
                                    </td>
                                    <td>
                                        <?php if ($op['diagnosi_sintetica'] !== ''): ?>
                                            <?php echo htmlspecialchars($op['diagnosi_sintetica']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $stInfoOp = $statiBadge[$op['stato_visita']] ?? ['cls' => 'bg-secondary', 'ico' => 'bi-info-circle']; ?>
                                        <span class="badge <?php echo $stInfoOp['cls']; ?>">
                                            <i class="bi <?php echo $stInfoOp['ico']; ?>"></i> <?php echo htmlspecialchars($op['stato_visita']); ?>
                                        </span>
                                        <?php if ($op['consegne_aperte'] > 0): ?>
                                            <span class="badge bg-warning text-dark ms-1"><?php echo (int)$op['consegne_aperte']; ?> cons.</span>
                                        <?php endif; ?>
                                        <?php if ($op['note_stato'] !== ''): ?>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($op['note_stato']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap no-print">
                                        <a href="<?php echo htmlspecialchars($linkCardOp); ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Apri in Giro Visita">
                                            <i class="bi bi-arrow-up-right"></i> Giro
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="d-flex flex-wrap gap-1 no-print">
                    <?php
                    $labelConsegneFiltro = 'Consegne (' . (int)$totaleConsegneAperte . ')';
                    if ($totaleConsegneTrascinate > 0) {
                        $labelConsegneFiltro .= ' · ' . (int)$totaleConsegneTrascinate . ' arretrat' . ($totaleConsegneTrascinate === 1 ? 'a' : 'e');
                    }
                    $filtri = [
                        'tutti'           => 'Tutti',
                        'occupati'        => 'Occupati',
                        'liberi'          => 'Liberi',
                        'da_visitare'     => 'Da visitare',
                        'da_rivalutare'   => 'Rivalutare / Urg.',
                        'dimissioni'      => 'Dimissioni',
                        'consegne_aperte' => $labelConsegneFiltro
                    ];
                    foreach ($filtri as $k => $lbl):
                        $attivoFiltro = ($filtro_rapido === $k);
                    ?>
                        <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => $k])); ?>" class="btn btn-sm <?php echo $attivoFiltro ? 'btn-dark' : 'btn-outline-secondary bg-white'; ?>">
                            <?php echo htmlspecialchars($lbl); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="btn-group btn-group-sm no-print" role="group">
                    <a href="<?php echo htmlspecialchars(build_dash_url(['vista' => 'tabella'])); ?>" class="btn <?php echo $vista_layout === 'tabella' ? 'btn-primary' : 'btn-outline-primary bg-white'; ?>">
                        <i class="bi bi-table"></i> Tabella
                    </a>
                    <a href="<?php echo htmlspecialchars(build_dash_url(['vista' => 'schede'])); ?>" class="btn <?php echo $vista_layout === 'schede' ? 'btn-primary' : 'btn-outline-primary bg-white'; ?>">
                        <i class="bi bi-grid-3x3-gap"></i> Schede
                    </a>
                </div>
            </div>

            <?php if (empty($reparto_selezionato)): ?>
                <div class="alert alert-info mb-0">Nessun reparto configurato per l'utente.</div>
            <?php elseif (empty($postiFiltrati)): ?>
                <div class="alert alert-light border text-center text-muted mb-0">Nessun posto letto corrispondente ai filtri selezionati.</div>
            <?php elseif ($vista_layout === 'schede'): ?>
                <div class="row g-3">
                    <?php foreach ($postiFiltrati as $pl):
                        $plId = $pl['id'] ?? '';
                        $stanza = trim($pl['stanza'] ?? '');
                        $letto = trim($pl['letto'] ?? '');
                        $sigla = trim($pl['sigla_paziente'] ?? '');
                        $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
                        $st = $pl['stato_visita'] ?? 'In attesa di visita';
                        $prio = $pl['priorita'] ?? 'Normale';
                        $noteStato = trim($pl['note_stato'] ?? '');
                        $dataInt = trim($pl['data_intervento'] ?? '');
                        $hasDataInt = is_valid_date($dataInt);
                        $consAperte = $consegneApertePerLetto[$plId] ?? [];
                        $arretrateLetto = 0;
                        foreach ($consAperte as $caCheck) {
                            if (!empty($caCheck['_arretrata'])) {
                                $arretrateLetto++;
                            }
                        }

                        $labelLetto = trim($stanza . ($letto !== '' ? ' / ' . $letto : ''), ' /');
                        if ($labelLetto === '') {
                            $labelLetto = 'Posto';
                        }

                        $clsScheda = 'scheda-ricovero';
                        if ($prio === 'Urgente') {
                            $clsScheda .= ' prio-urgente';
                        } elseif ($prio === 'Dimissione' || in_array($st, ['Dimissione in valutazione', 'Dimissione protetta'], true)) {
                            $clsScheda .= ' prio-dimissione';
                        }

                        $stInfo = $statiBadge[$st] ?? ['cls' => 'bg-secondary', 'ico' => 'bi-info-circle'];
                        $linkScheda = build_giro_visita_url($pl);
                    ?>
                        <div class="col-md-6 col-xl-4">
                            <div class="card p-3 h-100 <?php echo $clsScheda; ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <span class="badge bg-dark badge-letto"><?php echo htmlspecialchars($labelLetto); ?></span>
                                        <span class="fw-bold ms-1"><?php echo htmlspecialchars($sigla !== '' ? $sigla : 'Libero'); ?></span>
                                    </div>
                                    <span class="badge <?php echo $stInfo['cls']; ?>">
                                        <i class="bi <?php echo $stInfo['ico']; ?>"></i> <?php echo htmlspecialchars($st); ?>
                                    </span>
                                </div>

                                <?php if ($hasDataInt): ?>
                                    <div class="mb-2">
                                        <span class="badge <?php echo $dataInt === $data_giro ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                            <i class="bi bi-scissors"></i> Int.: <?php echo htmlspecialchars(date('d/m/Y', strtotime($dataInt))); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($diagnosi !== ''): ?>
                                    <div class="small fw-semibold mb-1"><?php echo htmlspecialchars($diagnosi); ?></div>
                                <?php endif; ?>

                                <?php if ($noteStato !== ''): ?>
                                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($noteStato); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($consAperte)): ?>
                                    <div class="border-top pt-2 mt-auto mb-2">
                                        <div class="small fw-bold text-warning-emphasis mb-1">
                                            Consegne aperte (<?php echo count($consAperte); ?>)
                                            <?php if ($arretrateLetto > 0): ?>
                                                <span class="badge bg-warning text-dark ms-1"><?php echo (int)$arretrateLetto; ?> arretrat<?php echo $arretrateLetto === 1 ? 'a' : 'e'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <ul class="list-unstyled small mb-0">
                                            <?php foreach ($consAperte as $ca):
                                                $dCaRaw = trim($ca['data_giro'] ?? '');
                                                $dCaFmt = is_valid_date($dCaRaw) ? date('d/m', strtotime($dCaRaw)) : '';
                                            ?>
                                                <li class="mb-1">
                                                    <?php if (!empty($ca['_arretrata']) && $dCaFmt !== ''): ?>
                                                        <span class="badge bg-warning text-dark me-1">del <?php echo htmlspecialchars($dCaFmt); ?></span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ca['tag_azione'] ?? 'Azione'); ?></span>
                                                    <?php echo htmlspecialchars($ca['nota'] ?? ''); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-auto pt-2 text-end no-print">
                                    <a href="<?php echo htmlspecialchars($linkScheda); ?>" class="btn btn-sm btn-outline-primary">
                                        Apri in Giro Visita <i class="bi bi-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-schematica mb-0">
                        <thead>
                            <tr>
                                <th>Postazione</th>
                                <th>Paziente</th>
                                <th>Diagnosi / Inquadramento</th>
                                <th>Intervento</th>
                                <th>Stato Visita</th>
                                <th>Consegne Aperte</th>
                                <th class="text-end no-print">Scheda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postiFiltrati as $pl):
                                $plId = $pl['id'] ?? '';
                                $stanza = trim($pl['stanza'] ?? '');
                                $letto = trim($pl['letto'] ?? '');
                                $sigla = trim($pl['sigla_paziente'] ?? '');
                                $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
                                $st = $pl['stato_visita'] ?? 'In attesa di visita';
                                $prio = $pl['priorita'] ?? 'Normale';
                                $noteStato = trim($pl['note_stato'] ?? '');
                                $dataInt = trim($pl['data_intervento'] ?? '');
                                $hasDataInt = is_valid_date($dataInt);
                                $consAperte = $consegneApertePerLetto[$plId] ?? [];
                                $arretrateLetto = 0;
                                foreach ($consAperte as $caCheck) {
                                    if (!empty($caCheck['_arretrata'])) {
                                        $arretrateLetto++;
                                    }
                                }

                                $labelLetto = trim($stanza . ($letto !== '' ? ' / ' . $letto : ''), ' /');
                                if ($labelLetto === '') {
                                    $labelLetto = 'Posto';
                                }

                                $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale' || $hasDataInt);
                                $rowClass = '';
                                if ($prio === 'Urgente') {
                                    $rowClass = 'row-urgente';
                                } elseif ($prio === 'Dimissione' || in_array($st, ['Dimissione in valutazione', 'Dimissione protetta'], true)) {
                                    $rowClass = 'row-dimissione';
                                } elseif (!$isOccupato) {
                                    $rowClass = 'row-libero';
                                }

                                $stInfo = $statiBadge[$st] ?? ['cls' => 'bg-secondary', 'ico' => 'bi-info-circle'];
                                $linkScheda = build_giro_visita_url($pl);
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td class="text-nowrap">
                                        <span class="badge bg-dark badge-letto"><?php echo htmlspecialchars($labelLetto); ?></span>
                                    </td>
                                    <td class="fw-semibold">
                                        <?php echo $sigla !== '' ? htmlspecialchars($sigla) : '<span class="text-muted fw-normal">Libero</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($diagnosi !== ''): ?>
                                            <div><?php echo htmlspecialchars($diagnosi); ?></div>
                                        <?php endif; ?>
                                        <?php if ($noteStato !== ''): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($noteStato); ?></div>
                                        <?php endif; ?>
                                        <?php if ($diagnosi === '' && $noteStato === ''): ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if ($hasDataInt): ?>
                                            <span class="badge <?php echo $dataInt === $data_giro ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                                <?php echo htmlspecialchars(date('d/m/Y', strtotime($dataInt))); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge <?php echo $stInfo['cls']; ?>">
                                            <i class="bi <?php echo $stInfo['ico']; ?>"></i> <?php echo htmlspecialchars($st); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (empty($consAperte)): ?>
                                            <span class="text-muted small">0</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><?php echo count($consAperte); ?></span>
                                            <?php if ($arretrateLetto > 0): ?>
                                                <span class="badge bg-danger ms-1"><?php echo (int)$arretrateLetto; ?> arretr.</span>
                                            <?php endif; ?>
                                            <div class="small mt-1">
                                                <?php foreach ($consAperte as $ca):
                                                    $dCaRaw = trim($ca['data_giro'] ?? '');
                                                    $dCaFmt = is_valid_date($dCaRaw) ? date('d/m', strtotime($dCaRaw)) : '';
                                                ?>
                                                    <div>
                                                        <?php if (!empty($ca['_arretrata']) && $dCaFmt !== ''): ?>
                                                            <span class="badge bg-warning text-dark">del <?php echo htmlspecialchars($dCaFmt); ?></span>
                                                        <?php endif; ?>
                                                        <strong><?php echo htmlspecialchars($ca['tag_azione'] ?? ''); ?>:</strong>
                                                        <?php echo htmlspecialchars($ca['nota'] ?? ''); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap no-print">
                                        <a href="<?php echo htmlspecialchars($linkScheda); ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Apri in Giro Visita">
                                            <i class="bi bi-arrow-up-right"></i> Giro
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
