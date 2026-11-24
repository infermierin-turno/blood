<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// 1. Estrazione sicura dei dati dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));
$is_super_admin = ($_SESSION['is_super_admin'] ?? $_SESSION['utente']['is_super_admin'] ?? false) || in_array($ruolo_lower, ['super_admin', 'admin', 'superadmin']);
$is_capo_personale = in_array($ruolo_lower, ['capo_personale', 'capopersonale']);

function is_profilo_medico_griglia(array $utente): bool {
    $campi = [
        $utente['ruolo'] ?? '',
        $utente['qualifica'] ?? '',
        $utente['profilo'] ?? ''
    ];
    foreach ($campi as $val) {
        $norm = strtolower(str_replace(['-', '_'], ' ', trim((string)$val)));
        if ($norm !== '' && preg_match('/\bmedico\b/', $norm)) {
            return true;
        }
    }
    return false;
}

function is_escluso_stampa_reperibilita(array $utente): bool {
    if (($utente['squadra_norm'] ?? '') === 'MATTINIERO') {
        return true;
    }
    $testo = strtolower(trim(
        ($utente['ruolo'] ?? '') . ' ' .
        ($utente['qualifica'] ?? '') . ' ' .
        ($utente['profilo'] ?? '') . ' ' .
        ($utente['squadra'] ?? '')
    ));
    if ($testo === '') {
        return false;
    }
    if (preg_match('/\bcpsi\b/i', $testo) || strpos($testo, 'coordinat') !== false || strpos($testo, 'mattin') !== false) {
        return true;
    }
    return false;
}

// 2. Reparti disponibili
$reparti_disponibili = [];
$resReparti = supabase_request("reparti?select=id,nome_reparto&order=nome_reparto.asc");
if (is_array($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

// 3. Reparto Selezionato
$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && $_GET['reparto_id'] !== '') {
        $reparto_selezionato = $_GET['reparto_id'];
    } elseif (!empty($reparto_id_utente)) {
        $reparto_selezionato = $reparto_id_utente;
    } elseif (!empty($reparti_disponibili)) {
        $reparto_selezionato = $reparti_disponibili[0]['id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org = supabase_request('organizzazioni', "?id=eq.$org_id_utente&select=nome_struttura");
    if (!empty($res_org) && is_array($res_org) && !isset($res_org['code'])) {
        $nome_struttura_corrente = $res_org[0]['nome_struttura'] ?? $res_org[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
    }
}

if ($reparto_selezionato !== '') {
    foreach ($reparti_disponibili as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
}

// Gestione Mese e Anno
$mese_selezionato = isset($_GET['mese']) ? max(1, min(12, intval($_GET['mese']))) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? max(2024, min(2030, intval($_GET['anno']))) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);

// Link switch rapido mesi
$tsMesePrec = mktime(0, 0, 0, $mese_selezionato - 1, 1, $anno_selezionato);
$tsMeseSucc = mktime(0, 0, 0, $mese_selezionato + 1, 1, $anno_selezionato);

$baseQueryPlanner = [];
if ($reparto_selezionato !== '') {
    $baseQueryPlanner['reparto_id'] = $reparto_selezionato;
}

$urlMesePrec = 'planner.php?' . http_build_query(array_merge($baseQueryPlanner, [
    'mese' => max(1, min(12, intval(date('n', $tsMesePrec)))),
    'anno' => max(2024, min(2030, intval(date('Y', $tsMesePrec))))
]));

$urlMeseSucc = 'planner.php?' . http_build_query(array_merge($baseQueryPlanner, [
    'mese' => max(1, min(12, intval(date('n', $tsMeseSucc)))),
    'anno' => max(2024, min(2030, intval(date('Y', $tsMeseSucc))))
]));

$urlMeseOggi = 'planner.php?' . http_build_query(array_merge($baseQueryPlanner, [
    'mese' => max(1, min(12, intval(date('n')))),
    'anno' => max(2024, min(2030, intval(date('Y'))))
]));

// 4. Colori e Descrizioni Dinamiche di TUTTE le Tipologie di Turno dal Database
$mappaColoriTurni = [];
$mappaNomiTurni = [];
$resTipologie = supabase_request("tipologie_turno?select=codice_breve,nome_turno,colore&order=codice_breve.asc");
if (is_array($resTipologie) && !isset($resTipologie['error'])) {
    foreach ($resTipologie as $t) {
        $cod = strtoupper(trim($t['codice_breve'] ?? ''));
        $nomeT = trim($t['nome_turno'] ?? '');
        $col = trim($t['colore'] ?? '');
        if (!empty($cod)) {
            if (!empty($col)) $mappaColoriTurni[$cod] = $col;
            if (!empty($nomeT)) $mappaNomiTurni[$cod] = $nomeT;
        }
    }
}

// 5. Recupero Collaboratori, Esclusione Profilo Medico e Filtraggio per Reparto
$mappaUtenti = [];
$listaCollaboratori = [];
$resCollab = supabase_request("staging_utenti?select=id,nome,ruolo,qualifica,reparto_id,squadra&order=nome.asc");

if (is_array($resCollab) && !isset($resCollab['error'])) {
    foreach ($resCollab as $c) {
        if (is_profilo_medico_griglia($c)) {
            continue;
        }

        $repCollabId = $c['reparto_id'] ?? $c['id_reparto'] ?? $c['reparto'] ?? '';
        if ($reparto_selezionato !== '' && (string)$repCollabId !== (string)$reparto_selezionato) {
            continue;
        }

        $rawSq = trim($c['squadra'] ?? '');
        $qualificaC = trim($c['qualifica'] ?? '') !== '' ? $c['qualifica'] : ($c['ruolo'] ?? '');
        $isMattiniero = (strpos(strtolower($rawSq), 'mattin') !== false || strpos(strtolower($qualificaC), 'mattin') !== false);

        if ($isMattiniero) {
            $c['squadra_norm'] = 'MATTINIERO';
        } elseif ($rawSq !== '') {
            $cleanSq = strtoupper(str_replace(['SQUADRA', 'TURNO', ' '], '', $rawSq));
            $c['squadra_norm'] = in_array($cleanSq, ['A', 'B', 'C', 'D', 'E']) ? $cleanSq : strtoupper($rawSq);
        } else {
            $c['squadra_norm'] = 'SENZA_SQUADRA';
        }

        $c['reparto_id'] = $repCollabId;
        $mappaUtenti[$c['id']] = $c;
        $listaCollaboratori[] = $c;
    }
}

// Ordinamento: prima A, B, C, D, E, poi Mattinieri, poi Senza Squadra
usort($listaCollaboratori, function($a, $b) {
    $sqA = $a['squadra_norm'];
    $sqB = $b['squadra_norm'];

    $ordine = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
    $pA = $ordine[$sqA] ?? ($sqA === 'MATTINIERO' ? 10 : 20);
    $pB = $ordine[$sqB] ?? ($sqB === 'MATTINIERO' ? 10 : 20);

    if ($pA !== $pB) return $pA - $pB;
    if ($sqA !== $sqB) return strcmp($sqA, $sqB);
    return strcmp($a['nome'] ?? '', $b['nome'] ?? '');
});

$idsUtenti = array_keys($mappaUtenti);

// 6. Recupero Pianificazione
$matricePianificazione = [];
if (!empty($idsUtenti)) {
    $d_inizio = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
    $d_fine = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);
    $resPian = supabase_request("pianificazione?data_inizio=lte.$d_fine&data_fine=gte.$d_inizio&select=*");

    if (is_array($resPian) && !isset($resPian['error'])) {
        foreach ($resPian as $p) {
            $uId = $p['utente_id'] ?? '';
            if (!isset($mappaUtenti[$uId]) || ($p['stato'] ?? '') === 'cancellato') continue;

            $gInizio = intval(substr($p['data_inizio'], 8, 2));
            $gFine = !empty($p['data_fine']) ? intval(substr($p['data_fine'], 8, 2)) : $gInizio;

            for ($g = $gInizio; $g <= $gFine; $g++) {
                foreach (explode(',', $p['tipo_evento'] ?? '') as $t) {
                    $tC = trim($t);
                    if (!empty($tC) && !in_array($tC, $matricePianificazione[$uId][$g] ?? [])) {
                        $matricePianificazione[$uId][$g][] = $tC;
                    }
                }
            }
        }
    }
}

// 7. Recupero Assenze Approvate
$assenzeMese = [];
if (!empty($idsUtenti)) {
    $resAss = supabase_request("assenze?stato=eq.approvato&data_inizio=lte.$d_fine&data_fine=gte.$d_inizio&select=*");
    if (is_array($resAss) && !isset($resAss['error'])) {
        foreach ($resAss as $ass) {
            $uId = $ass['utente_id'] ?? '';
            if (!isset($mappaUtenti[$uId])) continue;
            $gInz = max(1, intval(substr($ass['data_inizio'], 8, 2)));
            $gFin = min($giorni_nel_mese, intval(substr($ass['data_fine'], 8, 2)));
            $tipoAss = strtoupper(trim($ass['tipo_evento'] ?? $ass['tipo_assenza'] ?? 'F'));
            for ($gg = $gInz; $gg <= $gFin; $gg++) {
                $assenzeMese[$uId][$gg] = $tipoAss;
            }
        }
    }
}

// 8. Calcolo della Sequenza Dinamica per TUTTE le 5 squadre (A, B, C, D, E)
$utentiPerSquadra = [];
foreach ($listaCollaboratori as $c) {
    $sq = $c['squadra_norm'];
    if (in_array($sq, ['A', 'B', 'C', 'D', 'E'])) {
        $utentiPerSquadra[$sq][] = $c['id'];
    }
}

$cicloRotazione = ['M', 'P', 'N', 'S', 'R'];
$turniSquadraPerGiorno = [];
$sfasamentoDefault = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4];

foreach (['A', 'B', 'C', 'D', 'E'] as $sq) {
    $idsMembri = $utentiPerSquadra[$sq] ?? [];
    $primoTurno = null;
    $giornoPrimo = null;

    for ($g = 1; $g <= $giorni_nel_mese; $g++) {
        foreach ($idsMembri as $uId) {
            foreach ($matricePianificazione[$uId][$g] ?? [] as $t) {
                $tC = strtoupper(trim($t));
                if (in_array($tC, $cicloRotazione)) {
                    $primoTurno = $tC;
                    $giornoPrimo = $g;
                    break 3;
                }
            }
        }
    }

    if ($primoTurno !== null) {
        $idxBase = array_search($primoTurno, $cicloRotazione);
        for ($g = 1; $g <= $giorni_nel_mese; $g++) {
            $diff = $g - $giornoPrimo;
            $turniSquadraPerGiorno[$sq][$g] = $cicloRotazione[($idxBase + $diff) % 5];
        }
    } else {
        $idxBase = $sfasamentoDefault[$sq] ?? 0;
        for ($g = 1; $g <= $giorni_nel_mese; $g++) {
            $turniSquadraPerGiorno[$sq][$g] = $cicloRotazione[($idxBase + ($g - 1)) % 5];
        }
    }
}

// 9. Elenco Collaboratori per Stampa Reperibilità (esclude Mattinieri, CPSI e Coordinatori)
$codiciReperibilita = ['RN', 'RDM', 'RDP'];
$collaboratoriReperibilita = array_values(array_filter($listaCollaboratori, function($c) {
    return !is_escluso_stampa_reperibilita($c);
}));

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
$nomeMeseCorrente = $mesiNomi[$mese_selezionato] ?? '';
$larghezzaColGiornoStampa = round(100 / max(1, $giorni_nel_mese), 4);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pro-Tur - Planner Excel Style</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; color: #334155; font-family: system-ui, sans-serif; padding-bottom: 50px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); background: #fff; }
        .table-planner { border-collapse: separate; border-spacing: 0; width: 100%; table-layout: fixed; }
        .table-planner th, .table-planner td { text-align: center; vertical-align: middle; font-size: 0.75rem; padding: 4px 2px; border: 1px solid #cbd5e1; }
        .col-collaboratore { text-align: left !important; padding-left: 8px !important; width: 170px !important; background: #fff !important; position: sticky; left: 0; z-index: 4; }
        .th-giorno { width: 32px !important; background-color: #334155; color: #fff; position: sticky; top: 0; z-index: 3; }
        .th-giorno.weekend { background-color: #0f172a !important; color: #94a3b8 !important; }
        .weekend-col { background-color: #f1f5f9 !important; }
        .badge-turno { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 20px; font-size: 0.65rem; font-weight: 700; border-radius: 4px; }
        .cell-container { display: flex; flex-direction: row; align-items: center; justify-content: center; gap: 1px; flex-wrap: nowrap; }

        .sezione-stampa-reperibilita { display: none; }

        /* Sezioni di stampa, legenda e firme */
        .print-header-info { display: none; }
        .print-footer-signatures { display: none; }
        .print-legend { display: none; }
        .print-rep-notes { display: none; }

        @media print {
            @page { size: A4 landscape; margin: 4mm; }
            .print-hide { display: none !important; }
            body { background: #fff !important; font-size: 7pt !important; color: #000 !important; padding-bottom: 0 !important; margin: 0 !important; }
            .container-fluid { padding-left: 0 !important; padding-right: 0 !important; width: 100% !important; max-width: 100% !important; }
            .card { box-shadow: none !important; border: none !important; margin-bottom: 0 !important; }
            .table-responsive { overflow: visible !important; max-height: none !important; }

            /* Fit-to-page per Stampa Turni: zero debordamento a destra + compattazione su 2 pagine */
            body.print-mode-turni .sezione-stampa-reperibilita { display: none !important; }
            body.print-mode-turni .sezione-stampa-turni { display: block !important; width: 100% !important; }
            body.print-mode-turni .table-planner {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
            }
            body.print-mode-turni .table-planner th,
            body.print-mode-turni .table-planner td {
                padding: 1.5px 0.5px !important;
                font-size: 5.8pt !important;
                line-height: 1.05 !important;
                border: 0.75px solid #94a3b8 !important;
                overflow: hidden !important;
                word-wrap: break-word !important;
            }
            body.print-mode-turni .col-collaboratore {
                position: static !important;
                width: 96px !important;
                min-width: 96px !important;
                max-width: 96px !important;
                padding-left: 3px !important;
                padding-right: 2px !important;
            }
            body.print-mode-turni .col-collaboratore .fw-bold {
                font-size: 5.8pt !important;
                line-height: 1.05 !important;
            }
            body.print-mode-turni .col-collaboratore .text-muted {
                display: none !important;
            }
            body.print-mode-turni .th-giorno {
                position: static !important;
                width: auto !important;
                min-width: 0 !important;
                padding: 1px 0 !important;
            }
            body.print-mode-turni .badge-turno {
                width: 18px !important;
                height: 13px !important;
                font-size: 5pt !important;
                border-radius: 2px !important;
                padding: 0 !important;
            }
            body.print-mode-turni .print-header-info {
                margin-bottom: 4px !important;
                padding-bottom: 3px !important;
            }
            body.print-mode-turni .print-legend {
                margin-top: 3mm !important;
                font-size: 6pt !important;
            }
            body.print-mode-turni .print-legend-grid {
                grid-template-columns: repeat(6, 1fr) !important;
                gap: 2px 6px !important;
                padding: 3px 5px !important;
            }
            body.print-mode-turni .print-footer-signatures {
                margin-top: 4mm !important;
                font-size: 7.5pt !important;
            }
            body.print-mode-turni .signature-box {
                width: 210px !important;
                padding-top: 3px !important;
            }
            body.print-mode-turni .signature-box .text-muted {
                margin-top: 6px !important;
                font-size: 6.5pt !important;
            }

            /* Stampa Reperibilità: 1 pagina */
            body.print-mode-reperibilita .sezione-stampa-turni { display: none !important; }
            body.print-mode-reperibilita .sezione-stampa-reperibilita { display: block !important; width: 100% !important; page-break-inside: avoid; }
            body.print-mode-reperibilita .table-planner {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
            }
            body.print-mode-reperibilita .print-header-info {
                margin-bottom: 5px !important;
                padding-bottom: 3px !important;
            }
            body.print-mode-reperibilita .table-planner th,
            body.print-mode-reperibilita .table-planner td {
                padding: 1px 0.5px !important;
                font-size: 5.8pt !important;
                line-height: 1.05 !important;
                border: 0.75px solid #94a3b8 !important;
                overflow: hidden !important;
            }
            body.print-mode-reperibilita .col-collaboratore {
                position: static !important;
                width: 100px !important;
                min-width: 100px !important;
                max-width: 100px !important;
                padding-left: 3px !important;
            }
            body.print-mode-reperibilita .col-collaboratore .text-muted {
                display: none !important;
            }
            body.print-mode-reperibilita .th-giorno {
                position: static !important;
                width: auto !important;
                min-width: 0 !important;
            }
            body.print-mode-reperibilita .badge-turno {
                height: 13px !important;
                width: 20px !important;
                font-size: 5.2pt !important;
                border-radius: 2px !important;
            }
            body.print-mode-reperibilita .print-rep-notes {
                display: flex !important;
                justify-content: flex-start !important;
                gap: 18px !important;
                margin-top: 4px !important;
                padding: 3px 6px !important;
                border: 1px solid #cbd5e1 !important;
                background-color: #f8fafc !important;
                font-size: 6.5pt !important;
                page-break-inside: avoid;
            }
            body.print-mode-reperibilita .print-footer-signatures {
                margin-top: 5mm !important;
                font-size: 7.5pt !important;
            }
            body.print-mode-reperibilita .signature-box {
                width: 200px !important;
                padding-top: 2px !important;
            }
            body.print-mode-reperibilita .signature-box .text-muted {
                margin-top: 6px !important;
                font-size: 6.5pt !important;
            }

            .print-header-info {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 12px !important;
                border-bottom: 2px solid #0f172a;
                padding-bottom: 6px;
            }

            .print-legend {
                display: block !important;
                margin-top: 6mm !important;
                font-size: 7pt !important;
                page-break-inside: avoid;
            }
            .print-legend-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 3px 10px;
                border: 1px solid #cbd5e1;
                padding: 6px 8px;
                border-radius: 4px;
                background-color: #f8fafc;
            }
            .legend-item {
                display: flex;
                align-items: center;
                gap: 4px;
            }

            .print-footer-signatures {
                display: flex !important;
                justify-content: space-between !important;
                align-items: flex-end !important;
                margin-top: 12mm !important;
                font-size: 9pt !important;
                page-break-inside: avoid;
            }
            .signature-box {
                width: 250px;
                text-align: center;
                border-top: 1px solid #000;
                padding-top: 5px;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 print-hide">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Planner</a>
            <div class="ms-auto">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Filtri e Stampa -->
        <div class="card p-3 mb-3 print-hide">
            <form method="GET" action="planner.php" id="formFiltroPlanner" class="row g-2 align-items-center">
                <div class="col-lg-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-calendar3 text-primary"></i> <?php echo "$nomeMeseCorrente $anno_selezionato"; ?></h5>
                    <small class="text-muted">Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong></small>
                </div>
                <div class="col-lg-6 d-flex gap-2 flex-wrap align-items-center">
                    <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                        <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                            <option value="">Tutti i Reparti</option>
                            <?php foreach ($reparti_disponibili as $rep) { ?>
                                <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ((string)$reparto_selezionato === (string)$rep['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rep['nome_reparto']); ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>

                    <div class="btn-group btn-group-sm" role="group" aria-label="Switch veloce mesi">
                        <a href="<?php echo htmlspecialchars($urlMesePrec); ?>" class="btn btn-outline-secondary fw-bold" title="Mese Precedente">
                            <i class="bi bi-chevron-left"></i> Prec.
                        </a>
                        <a href="<?php echo htmlspecialchars($urlMeseOggi); ?>" class="btn btn-outline-primary fw-bold" title="Mese Corrente">
                            Oggi
                        </a>
                        <a href="<?php echo htmlspecialchars($urlMeseSucc); ?>" class="btn btn-outline-secondary fw-bold" title="Mese Successivo">
                            Succ. <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>

                    <select name="mese" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                        <?php foreach ($mesiNomi as $numM => $strM) { ?>
                            <option value="<?php echo $numM; ?>" <?php echo ($numM === $mese_selezionato) ? 'selected' : ''; ?>><?php echo $strM; ?></option>
                        <?php } ?>
                    </select>
                    <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                        <?php for ($a = 2024; $a <= 2030; $a++) { ?>
                            <option value="<?php echo $a; ?>" <?php echo ($a === $anno_selezionato) ? 'selected' : ''; ?>><?php echo $a; ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm px-3">Filtra</button>
                </div>
                <div class="col-lg-3 text-lg-end d-flex gap-2 justify-content-lg-end flex-wrap">
                    <button type="button" onclick="stampaPlanner('turni');" class="btn btn-secondary btn-sm fw-bold">
                        <i class="bi bi-printer"></i> Stampa Turni
                    </button>
                    <button type="button" onclick="stampaPlanner('reperibilita');" class="btn btn-primary btn-sm fw-bold" title="Stampa reperibilità escludendo Mattinieri, CPSI e Coordinatori">
                        <i class="bi bi-telephone-outbound"></i> Stampa Reperibilità
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabella Planner Excel Style (TURNI) -->
        <div class="sezione-stampa-turni">
            <div class="card">
                <div class="card-body p-0">

                    <!-- Intestazione visibile solo in STAMPA -->
                    <div class="print-header-info">
                        <div>
                            <h4 class="fw-bold mb-1" style="font-size: 10pt;"><?php echo htmlspecialchars($nome_struttura_corrente); ?></h4>
                            <div style="font-size: 8.5pt;">Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="font-size: 10pt; text-transform: uppercase;"><?php echo "$nomeMeseCorrente $anno_selezionato"; ?></div>
                            <div style="font-size: 7.5pt;" class="text-muted">Pianificazione Turni Mensile</div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-planner mb-0">
                            <colgroup>
                                <col style="width: 170px;">
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { ?>
                                    <col style="width: <?php echo $larghezzaColGiornoStampa; ?>%;">
                                <?php } ?>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="col-collaboratore">Nominativo</th>
                                    <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                        $w = date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato));
                                        $let = ['1'=>'L','2'=>'M','3'=>'M','4'=>'G','5'=>'V','6'=>'S','7'=>'D'][$w];
                                        $isWk = ($w >= 6);
                                    ?>
                                        <th class="th-giorno <?php echo $isWk ? 'weekend' : ''; ?>">
                                            <div><?php echo $g; ?></div>
                                            <div style="font-size:0.55rem; opacity:0.8;"><?php echo $let; ?></div>
                                        </th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaCollaboratori)) { ?>
                                    <tr><td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-4 text-muted">Nessun collaboratore trovato per questo reparto.</td></tr>
                                <?php } else {
                                    $squadraCorrente = null;
                                    $separatoreMattinieriStampato = false;

                                    foreach ($listaCollaboratori as $collab) {
                                        $uId = $collab['id'];
                                        $nome = $collab['nome'] ?? '';
                                        $qualifica = trim($collab['qualifica'] ?? '') !== '' ? $collab['qualifica'] : ($collab['ruolo'] ?? '');
                                        $sqNorma = $collab['squadra_norm'];
                                        $isRotazione = in_array($sqNorma, ['A', 'B', 'C', 'D', 'E']);
                                        $isMattiniero = ($sqNorma === 'MATTINIERO');

                                        // Inserimento riga di separazione prima dei Mattinieri / Senza Squadra
                                        if (($isMattiniero || $sqNorma === 'SENZA_SQUADRA') && !$separatoreMattinieriStampato) {
                                            $separatoreMattinieriStampato = true;
                                ?>
                                            <tr class="table-dark">
                                                <td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="fw-bold text-uppercase ps-3 py-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                                                    <i class="bi bi-person-badge me-1"></i> Personale Mattiniero / Oss
                                                </td>
                                            </tr>
                                <?php
                                        }

                                        // 1. Mostra la riga della sequenza dinamica UNA SOLA VOLTA per ciascuna squadra (A, B, C, D, E)
                                        if ($sqNorma !== $squadraCorrente) {
                                            $squadraCorrente = $sqNorma;
                                            if ($isRotazione) {
                                ?>
                                                <tr class="table-secondary">
                                                    <td class="col-collaboratore fw-bold text-uppercase text-dark bg-light">
                                                        <i class="bi bi-grid-3x3 text-primary me-1"></i> TURNO <?php echo $sqNorma; ?>
                                                    </td>
                                                    <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                                        $tSq = $turniSquadraPerGiorno[$sqNorma][$g] ?? '-';
                                                        $isWkSq = (date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato)) >= 6);
                                                    ?>
                                                        <td class="fw-bold text-dark bg-light <?php echo $isWkSq ? 'weekend-col' : ''; ?>"><?php echo htmlspecialchars($tSq); ?></td>
                                                    <?php } ?>
                                                </tr>
                                <?php
                                            }
                                        }
                                ?>
                                        <!-- 2. Riga Nominativo -->
                                        <tr>
                                            <td class="col-collaboratore">
                                                <div class="fw-bold text-dark text-truncate" title="<?php echo htmlspecialchars($nome); ?>"><?php echo htmlspecialchars($nome); ?></div>
                                                <div class="text-muted text-truncate" style="font-size: 0.6rem;"><?php echo htmlspecialchars($qualifica); ?></div>
                                            </td>
                                            <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                                $isWk = (date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato)) >= 6);
                                                $ass = $assenzeMese[$uId][$g] ?? null;
                                                $turni = $matricePianificazione[$uId][$g] ?? [];
                                            ?>
                                                <td class="<?php echo $isWk ? 'weekend-col' : ''; ?>">
                                                    <div class="cell-container">
                                                        <?php if (!empty($ass)) {
                                                            $colAss = $mappaColoriTurni[$ass] ?? '#6c757d';
                                                        ?>
                                                            <span class="badge-turno" style="background-color: <?php echo $colAss; ?>; color: #fff;"><?php echo htmlspecialchars($ass); ?></span>
                                                        <?php } elseif (!empty($turni)) {
                                                            $mostra = false;
                                                            foreach ($turni as $tSingolo) {
                                                                $tC = strtoupper(trim($tSingolo));
                                                                // Regola: Per mattinieri / senza squadra mostra tutto. Per turni a rotazione mostra solo eccezioni (escludendo M,P,N,S,R)
                                                                if ($isMattiniero || !$isRotazione || !in_array($tC, ['M', 'P', 'N', 'S', 'R'])) {
                                                                    $mostra = true;
                                                                    $colT = $mappaColoriTurni[$tC] ?? '#3b82f6';
                                                        ?>
                                                                    <span class="badge-turno" style="background-color: <?php echo $colT; ?>; color: #fff;"><?php echo htmlspecialchars($tC); ?></span>
                                                        <?php
                                                                }
                                                            }
                                                            if (!$mostra) { echo '<span class="text-muted opacity-25">·</span>'; }
                                                        } else {
                                                            echo '<span class="text-muted opacity-25">·</span>';
                                                        } ?>
                                                    </div>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Legenda Completa di TUTTE le 16 Tipologie di Turno visibile solo in STAMPA -->
                    <div class="print-legend">
                        <div class="fw-bold mb-1" style="font-size: 7pt; text-transform: uppercase;">Legenda Tipologie Turni ed Eccezioni:</div>
                        <div class="print-legend-grid">
                            <?php foreach ($mappaNomiTurni as $codiceLeg => $nomeLeg) { ?>
                                <div class="legend-item">
                                    <strong><?php echo htmlspecialchars($codiceLeg); ?></strong>: <span><?php echo htmlspecialchars($nomeLeg); ?></span>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Sezione Firme visibile solo in STAMPA -->
                    <div class="print-footer-signatures">
                        <div class="signature-box">
                            <div class="fw-bold">Il Coordinatore</div>
                            <div class="text-muted" style="font-size: 7pt; margin-top: 10px;">(Firma)</div>
                        </div>
                        <div class="signature-box">
                            <div class="fw-bold">Il Direttore UOC</div>
                            <div class="text-muted" style="font-size: 7pt; margin-top: 10px;">(Firma e Timbro)</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Tabella Stampa Separata REPERIBILITA (Esclusi Mattinieri, CPSI e Coordinatori) -->
        <div class="sezione-stampa-reperibilita">
            <div class="card">
                <div class="card-body p-0">

                    <div class="print-header-info">
                        <div>
                            <h4 class="fw-bold mb-1" style="font-size: 10pt;"><?php echo htmlspecialchars($nome_struttura_corrente); ?></h4>
                            <div style="font-size: 8.5pt;">Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong> — <em>Esclusi Mattinieri, CPSI e Coordinatori</em></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="font-size: 10pt; text-transform: uppercase;"><?php echo "$nomeMeseCorrente $anno_selezionato"; ?></div>
                            <div style="font-size: 7.5pt;" class="text-muted">Prospetto Reperibilità Mensile</div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-planner mb-0">
                            <colgroup>
                                <col style="width: 170px;">
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { ?>
                                    <col style="width: <?php echo $larghezzaColGiornoStampa; ?>%;">
                                <?php } ?>
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="col-collaboratore">Nominativo</th>
                                    <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                        $w = date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato));
                                        $let = ['1'=>'L','2'=>'M','3'=>'M','4'=>'G','5'=>'V','6'=>'S','7'=>'D'][$w];
                                        $isWk = ($w >= 6);
                                    ?>
                                        <th class="th-giorno <?php echo $isWk ? 'weekend' : ''; ?>">
                                            <div><?php echo $g; ?></div>
                                            <div style="font-size:0.5rem; opacity:0.8;"><?php echo $let; ?></div>
                                        </th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($collaboratoriReperibilita)) { ?>
                                    <tr><td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-3 text-muted">Nessun operatore idoneo trovato per la stampa reperibilità.</td></tr>
                                <?php } else {
                                    $squadraRepCorrente = null;
                                    foreach ($collaboratoriReperibilita as $collabRep) {
                                        $uIdR = $collabRep['id'];
                                        $nomeR = $collabRep['nome'] ?? '';
                                        $qualificaR = trim($collabRep['qualifica'] ?? '') !== '' ? $collabRep['qualifica'] : ($collabRep['ruolo'] ?? '');
                                        $sqNormaR = $collabRep['squadra_norm'];
                                        $isRotazioneR = in_array($sqNormaR, ['A', 'B', 'C', 'D', 'E']);

                                        if ($sqNormaR !== $squadraRepCorrente) {
                                            $squadraRepCorrente = $sqNormaR;
                                            if ($isRotazioneR) {
                                ?>
                                                <tr class="table-secondary">
                                                    <td class="col-collaboratore fw-bold text-uppercase text-dark bg-light">
                                                        TURNO <?php echo $sqNormaR; ?>
                                                    </td>
                                                    <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                                        $tSqR = $turniSquadraPerGiorno[$sqNormaR][$g] ?? '-';
                                                        $isWkSqR = (date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato)) >= 6);
                                                    ?>
                                                        <td class="fw-bold text-dark bg-light <?php echo $isWkSqR ? 'weekend-col' : ''; ?>"><?php echo htmlspecialchars($tSqR); ?></td>
                                                    <?php } ?>
                                                </tr>
                                <?php
                                            }
                                        }
                                ?>
                                        <tr>
                                            <td class="col-collaboratore">
                                                <div class="fw-bold text-dark text-truncate" title="<?php echo htmlspecialchars($nomeR); ?>"><?php echo htmlspecialchars($nomeR); ?></div>
                                                <div class="text-muted text-truncate" style="font-size: 0.6rem;"><?php echo htmlspecialchars($qualificaR); ?></div>
                                            </td>
                                            <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                                $isWkR = (date('N', mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato)) >= 6);
                                                $turniR = $matricePianificazione[$uIdR][$g] ?? [];
                                                $repTrovate = [];
                                                foreach ($turniR as $tSingoloR) {
                                                    $tCR = strtoupper(trim($tSingoloR));
                                                    if (in_array($tCR, $codiciReperibilita, true)) {
                                                        $repTrovate[] = $tCR;
                                                    }
                                                }
                                            ?>
                                                <td class="<?php echo $isWkR ? 'weekend-col' : ''; ?>">
                                                    <div class="cell-container">
                                                        <?php if (!empty($repTrovate)) {
                                                            foreach ($repTrovate as $codRep) {
                                                                $colRep = $mappaColoriTurni[$codRep] ?? '#6f42c1';
                                                        ?>
                                                                <span class="badge-turno" style="background-color: <?php echo htmlspecialchars($colRep); ?>; color: #fff;"><?php echo htmlspecialchars($codRep); ?></span>
                                                        <?php
                                                            }
                                                        } else {
                                                            echo '<span class="text-muted opacity-25">·</span>';
                                                        } ?>
                                                    </div>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Note Reperibilità in Stampa -->
                    <div class="print-rep-notes">
                        <div><strong>RN</strong>: NOTTE</div>
                        <div><strong>RDM</strong>: REP. MATTINA</div>
                        <div><strong>RDP</strong>: REP. POMERIGGIO</div>
                    </div>

                    <div class="print-footer-signatures">
                        <div class="signature-box">
                            <div class="fw-bold">Il Coordinatore</div>
                            <div class="text-muted" style="font-size: 7pt; margin-top: 10px;">(Firma)</div>
                        </div>
                        <div class="signature-box">
                            <div class="fw-bold">Il Direttore UOC</div>
                            <div class="text-muted" style="font-size: 7pt; margin-top: 10px;">(Firma e Timbro)</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function stampaPlanner(modalita) {
            document.body.classList.remove('print-mode-turni', 'print-mode-reperibilita');
            if (modalita === 'reperibilita') {
                document.body.classList.add('print-mode-reperibilita');
            } else {
                document.body.classList.add('print-mode-turni');
            }

            window.print();

            setTimeout(function() {
                document.body.classList.remove('print-mode-turni', 'print-mode-reperibilita');
            }, 400);
        }

        document.addEventListener('keydown', function(e) {
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
            if (e.key === 'ArrowLeft') {
                window.location.href = <?php echo json_encode($urlMesePrec); ?>;
            } else if (e.key === 'ArrowRight') {
                window.location.href = <?php echo json_encode($urlMeseSucc); ?>;
            }
        });
    </script>
</body>
</html>
