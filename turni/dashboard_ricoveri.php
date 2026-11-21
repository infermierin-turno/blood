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

$data_giro = (isset($_GET['data_giro']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_giro']))
    ? $_GET['data_giro']
    : date('Y-m-d');

$filtro_rapido = isset($_GET['filtro']) ? trim($_GET['filtro']) : 'tutti';
$ricerca_testo = isset($_GET['q']) ? trim($_GET['q']) : '';
$vista_layout = (isset($_GET['vista']) && $_GET['vista'] === 'schede') ? 'schede' : 'tabella';

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

$mappaUtenti = [];
if (!empty($reparto_selezionato)) {
    $resUt = supabase_dash_request("staging_utenti?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica&order=nome.asc");
    foreach ($resUt as $u) {
        if (!empty($u['id'])) {
            $mappaUtenti[$u['id']] = $u;
        }
    }
}

$personaleInTurno = [];
if (!empty($reparto_selezionato)) {
    $inizioGiorno = $data_giro . ' 00:00:00';
    $fineGiorno = $data_giro . ' 23:59:59';
    $queryPian = "pianificazione?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_inizio=gte." . urlencode($inizioGiorno) . "&data_inizio=lte." . urlencode($fineGiorno) . "&select=utente_id,tipo_evento";
    $resPian = supabase_dash_request($queryPian);
    $codiciAssenzaRiposo = ['R', 'RN', 'F', 'FERIE', '104', 'PE', 'PER', 'PERMESSO', 'S'];

    foreach ($resPian as $p) {
        $uId = $p['utente_id'] ?? '';
        $codTurno = strtoupper(trim($p['tipo_evento'] ?? ''));
        if ($uId === '' || $codTurno === '' || in_array($codTurno, $codiciAssenzaRiposo, true)) {
            continue;
        }
        $uInfo = $mappaUtenti[$uId] ?? null;
        if (!$uInfo) {
            continue;
        }
        if (!isset($personaleInTurno[$uId])) {
            $personaleInTurno[$uId] = [
                'nome' => $uInfo['nome'] ?? 'Operatore',
                'turni' => []
            ];
        }
        if (!in_array($codTurno, $personaleInTurno[$uId]['turni'], true)) {
            $personaleInTurno[$uId]['turni'][] = $codTurno;
        }
    }
}

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

if (!empty($reparto_selezionato)) {
    $resCons = supabase_dash_request(
        "giro_visita_consegne?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro) . "&select=*&order=completata.asc,created_at.desc"
    );
    foreach ($resCons as $c) {
        $notaTesto = trim($c['nota'] ?? '');
        if (mb_stripos($notaTesto, 'Dimissione registrata') === 0) {
            $dimissioniGiorno[] = $c;
            continue;
        }
        $plId = $c['posto_letto_id'] ?? '';
        if ($plId !== '') {
            $consegneTotaliPerLetto[$plId][] = $c;
            if (empty($c['completata'])) {
                $consegneApertePerLetto[$plId][] = $c;
                $totaleConsegneAperte++;
            }
        }
    }
}

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
    $sigla = trim($pl['sigla_paziente'] ?? '');
    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';

    $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale');
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
}

$postiFiltrati = array_values(array_filter($postiLetto, function($pl) use ($filtro_rapido, $ricerca_testo, $consegneApertePerLetto) {
    $plId = $pl['id'] ?? '';
    $sigla = trim($pl['sigla_paziente'] ?? '');
    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';
    $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale');

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
        $haystack = mb_strtolower(
            ($pl['stanza'] ?? '') . ' ' .
            ($pl['letto'] ?? '') . ' ' .
            $sigla . ' ' .
            $diagnosi . ' ' .
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
    global $reparto_selezionato, $data_giro, $filtro_rapido, $ricerca_testo, $vista_layout;
    $params = [
        'reparto_id' => $reparto_selezionato,
        'data_giro'  => $data_giro,
        'filtro'     => $filtro_rapido,
        'vista'      => $vista_layout
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
        .table-schematica th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #475569; background-color: #f8fafc; white-space: nowrap; }
        .table-schematica td { vertical-align: middle; font-size: 0.88rem; }
        .badge-letto { font-weight: 700; font-size: 0.82rem; padding: 0.35em 0.55em; }
        .pill-turno { font-size: 0.72rem; background-color: #e2e8f0; color: #0f172a; border-radius: 999px; padding: 3px 9px; display: inline-flex; align-items: center; gap: 5px; }
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
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link" href="giro_visita.php">Giro Visita</a></li>
                    <li class="nav-item"><a class="nav-link active" href="dashboard_ricoveri.php">Dashboard Ricoveri</a></li>
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
                            <i class="bi bi-grid-1x2-fill text-primary"></i> Sintesi Ricoveri (Sola Lettura)
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

                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)): ?>
                            <div class="col-sm-5 col-md-4">
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

                        <div class="col-sm-8 col-md-3">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca letto, sigla, diagnosi..." value="<?php echo htmlspecialchars($ricerca_testo); ?>">
                        </div>

                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary" title="Applica filtri"><i class="bi bi-search"></i></button>
                            <?php if ($ricerca_testo !== '' || $filtro_rapido !== 'tutti'): ?>
                                <a href="<?php echo htmlspecialchars(build_dash_url(['q' => null, 'filtro' => 'tutti'])); ?>" class="btn btn-sm btn-outline-secondary" title="Reset filtri"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark" title="Stampa schematica"><i class="bi bi-printer"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'occupati'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #0f172a;">
                        <div class="kpi-lbl">Occupati / Posti</div>
                        <div class="kpi-val"><?php echo (int)$kpi['occupati']; ?> <span class="fs-6 fw-normal text-muted">/ <?php echo (int)$kpi['posti_attivi']; ?></span></div>
                        <div class="small text-muted">Liberi: <strong><?php echo (int)$kpi['liberi']; ?></strong></div>
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
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'da_rivalutare'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #dc3545;">
                        <div class="kpi-lbl">Urgenti / Esami / Med.</div>
                        <div class="kpi-val text-danger"><?php echo (int)$kpi['da_rivalutare']; ?></div>
                        <div class="small text-muted">Rivalutazione attiva</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'dimissioni'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #0d6efd;">
                        <div class="kpi-lbl">Area Dimissioni</div>
                        <div class="kpi-val text-primary"><?php echo (int)$kpi['dimissioni']; ?></div>
                        <div class="small text-muted">Valutaz. / Protetta</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => 'consegne_aperte'])); ?>" class="text-decoration-none text-reset">
                    <div class="card p-2 h-100 kpi-card" style="border-left-color: #f59e0b;">
                        <div class="kpi-lbl">Consegne Aperte</div>
                        <div class="kpi-val text-warning"><?php echo (int)$kpi['consegne_aperte']; ?></div>
                        <div class="small text-muted">Da completare</div>
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

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
            <div class="d-flex flex-wrap gap-1">
                <?php
                $pillFiltri = [
                    'tutti'           => 'Tutti',
                    'occupati'        => 'Occupati',
                    'liberi'          => 'Liberi',
                    'da_visitare'     => 'Da visitare',
                    'da_rivalutare'   => 'Urgenti / Esami / Med.',
                    'dimissioni'      => 'Dimissioni',
                    'consegne_aperte' => 'Consegne aperte'
                ];
                foreach ($pillFiltri as $keyF => $lblF):
                    $active = ($filtro_rapido === $keyF) ? 'btn-dark' : 'btn-outline-secondary bg-white';
                ?>
                    <a href="<?php echo htmlspecialchars(build_dash_url(['filtro' => $keyF])); ?>" class="btn btn-sm <?php echo $active; ?>">
                        <?php echo htmlspecialchars($lblF); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="btn-group btn-group-sm" role="group">
                <a href="<?php echo htmlspecialchars(build_dash_url(['vista' => 'tabella'])); ?>" class="btn <?php echo $vista_layout === 'tabella' ? 'btn-primary' : 'btn-outline-primary bg-white'; ?>">
                    <i class="bi bi-table"></i> Tabella
                </a>
                <a href="<?php echo htmlspecialchars(build_dash_url(['vista' => 'schede'])); ?>" class="btn <?php echo $vista_layout === 'schede' ? 'btn-primary' : 'btn-outline-primary bg-white'; ?>">
                    <i class="bi bi-columns-gap"></i> Schede
                </a>
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>" class="btn btn-outline-dark bg-white">
                    <i class="bi bi-pencil-square"></i> Vai a Giro Visita
                </a>
            </div>
        </div>

        <?php if (empty($reparto_selezionato)): ?>
            <div class="alert alert-info">Nessun reparto associato all'utenza corrente.</div>
        <?php elseif (empty($postiFiltrati)): ?>
            <div class="card p-4 text-center text-muted">
                Nessun posto letto corrisponde ai filtri selezionati.
            </div>
        <?php elseif ($vista_layout === 'tabella'): ?>
            <div class="card overflow-hidden mb-3">
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-schematica mb-0">
                        <thead>
                            <tr>
                                <th>Postazione</th>
                                <th>Paziente</th>
                                <th>Diagnosi / Inquadramento</th>
                                <th>Stato / Priorità</th>
                                <th>Note Giro Visita</th>
                                <th>Consegne Aperte</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postiFiltrati as $pl):
                                $plId = $pl['id'] ?? '';
                                $stanza = trim($pl['stanza'] ?? '');
                                $letto = trim($pl['letto'] ?? '');
                                $labelLetto = trim($stanza . ($letto !== '' ? ' / ' . $letto : ''), ' /');
                                if ($labelLetto === '') {
                                    $labelLetto = 'Posto';
                                }
                                $sigla = trim($pl['sigla_paziente'] ?? '');
                                $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
                                $stato = $pl['stato_visita'] ?? 'In attesa di visita';
                                $prio = $pl['priorita'] ?? 'Normale';
                                $noteStato = trim($pl['note_stato'] ?? '');
                                $isOccupato = ($sigla !== '' || $diagnosi !== '' || $stato !== 'In attesa di visita' || $prio !== 'Normale');

                                $badgeInfo = $statiBadge[$stato] ?? ['cls' => 'bg-secondary', 'ico' => 'bi-info-circle'];
                                $rowClass = '';
                                if ($prio === 'Urgente') {
                                    $rowClass = 'row-urgente';
                                } elseif ($prio === 'Dimissione' || in_array($stato, ['Dimissione in valutazione', 'Dimissione protetta'], true)) {
                                    $rowClass = 'row-dimissione';
                                } elseif (!$isOccupato) {
                                    $rowClass = 'row-libero';
                                }
                                $aperteLetto = $consegneApertePerLetto[$plId] ?? [];
                            ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td class="text-nowrap">
                                        <span class="badge bg-dark badge-letto"><?php echo htmlspecialchars($labelLetto); ?></span>
                                    </td>
                                    <td class="fw-semibold">
                                        <?php if ($sigla !== ''): ?>
                                            <?php echo htmlspecialchars($sigla); ?>
                                        <?php elseif ($isOccupato): ?>
                                            <span class="text-muted fst-italic">Sigla n.d.</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border">LIBERO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $diagnosi !== '' ? htmlspecialchars($diagnosi) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <span class="badge <?php echo $badgeInfo['cls']; ?>">
                                            <i class="bi <?php echo $badgeInfo['ico']; ?>"></i> <?php echo htmlspecialchars($stato); ?>
                                        </span>
                                        <?php if ($prio === 'Urgente'): ?>
                                            <span class="badge bg-danger ms-1">URGENTE</span>
                                        <?php elseif ($prio === 'Dimissione'): ?>
                                            <span class="badge bg-primary ms-1">DIMISSIONE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $noteStato !== '' ? htmlspecialchars($noteStato) : '<span class="text-muted">—</span>'; ?></td>
                                    <td>
                                        <?php if (empty($aperteLetto)): ?>
                                            <span class="text-muted small">Nessuna</span>
                                        <?php else: ?>
                                            <ul class="list-unstyled mb-0 small">
                                                <?php foreach ($aperteLetto as $ca): ?>
                                                    <li class="mb-1">
                                                        <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($ca['tag_azione'] ?? 'Azione'); ?></span>
                                                        <?php echo htmlspecialchars($ca['nota'] ?? ''); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-2 mb-3">
                <?php foreach ($postiFiltrati as $pl):
                    $plId = $pl['id'] ?? '';
                    $stanza = trim($pl['stanza'] ?? '');
                    $letto = trim($pl['letto'] ?? '');
                    $labelLetto = trim($stanza . ($letto !== '' ? ' / ' . $letto : ''), ' /');
                    if ($labelLetto === '') {
                        $labelLetto = 'Posto';
                    }
                    $sigla = trim($pl['sigla_paziente'] ?? '');
                    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
                    $stato = $pl['stato_visita'] ?? 'In attesa di visita';
                    $prio = $pl['priorita'] ?? 'Normale';
                    $noteStato = trim($pl['note_stato'] ?? '');
                    $badgeInfo = $statiBadge[$stato] ?? ['cls' => 'bg-secondary', 'ico' => 'bi-info-circle'];
                    $aperteLetto = $consegneApertePerLetto[$plId] ?? [];

                    $cardClass = 'scheda-ricovero';
                    if ($prio === 'Urgente') {
                        $cardClass .= ' prio-urgente';
                    } elseif ($prio === 'Dimissione' || in_array($stato, ['Dimissione in valutazione', 'Dimissione protetta'], true)) {
                        $cardClass .= ' prio-dimissione';
                    }
                ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card p-3 h-100 <?php echo $cardClass; ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <span class="badge bg-dark badge-letto"><?php echo htmlspecialchars($labelLetto); ?></span>
                                    <span class="fw-bold ms-1"><?php echo $sigla !== '' ? htmlspecialchars($sigla) : '<span class="text-muted fw-normal">Posto libero</span>'; ?></span>
                                </div>
                                <span class="badge <?php echo $badgeInfo['cls']; ?>">
                                    <i class="bi <?php echo $badgeInfo['ico']; ?>"></i> <?php echo htmlspecialchars($stato); ?>
                                </span>
                            </div>

                            <?php if ($diagnosi !== ''): ?>
                                <div class="small fw-semibold mb-1"><?php echo htmlspecialchars($diagnosi); ?></div>
                            <?php endif; ?>

                            <?php if ($noteStato !== ''): ?>
                                <div class="small text-secondary mb-2"><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($noteStato); ?></div>
                            <?php endif; ?>

                            <?php if (!empty($aperteLetto)): ?>
                                <div class="border-top pt-2 mt-auto">
                                    <div class="small fw-bold text-warning-emphasis mb-1">Consegne aperte (<?php echo count($aperteLetto); ?>)</div>
                                    <?php foreach ($aperteLetto as $ca): ?>
                                        <div class="small mb-1">
                                            <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($ca['tag_azione'] ?? 'Azione'); ?></span>
                                            <?php echo htmlspecialchars($ca['nota'] ?? ''); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card p-3 h-100">
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-people-fill text-primary"></i> Personale in Turno (<?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>)</h2>
                    <?php if (empty($personaleInTurno)): ?>
                        <div class="small text-muted">Nessun operatore registrato in turno per la data selezionata.</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($personaleInTurno as $op): ?>
                                <span class="pill-turno">
                                    <strong><?php echo htmlspecialchars($op['nome']); ?></strong>
                                    <span class="text-muted"><?php echo htmlspecialchars(implode(', ', $op['turni'])); ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card p-3 h-100">
                    <h2 class="h6 fw-bold mb-2"><i class="bi bi-box-arrow-right text-success"></i> Dimissioni Registrate (<?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>)</h2>
                    <?php if (empty($dimissioniGiorno)): ?>
                        <div class="small text-muted">Nessuna dimissione registrata per questa data.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($dimissioniGiorno as $dim): ?>
                                <li class="list-group-item px-0 py-1">
                                    <span class="badge bg-success-subtle text-success-emphasis border me-1"><?php echo htmlspecialchars($dim['tag_azione'] ?? 'Dimissione'); ?></span>
                                    <?php echo htmlspecialchars($dim['nota'] ?? ''); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
