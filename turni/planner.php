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
$mese_selezionato = isset($_GET['mese']) ? intval($_GET['mese']) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? intval($_GET['anno']) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);

// Colori Turni da Supabase
$mappaColoriTurni = [];
$resTipologie = supabase_request("tipologie_turno?select=codice_breve,colore");
if (is_array($resTipologie) && !isset($resTipologie['error'])) {
    foreach ($resTipologie as $t) {
        $cod = strtoupper(trim($t['codice_breve'] ?? ''));
        $col = trim($t['colore'] ?? '');
        if (!empty($cod) && !empty($col)) $mappaColoriTurni[$cod] = $col;
    }
}

// 4. Recupero Collaboratori e Normalizzazione Squadre
$mappaUtenti = [];
$resCollab = supabase_request("staging_utenti?select=id,nome,ruolo,qualifica,reparto_id,squadra&order=nome.asc");

if (is_array($resCollab) && !isset($resCollab['error'])) {
    foreach ($resCollab as $c) {
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
    }
}

// Ordinamento: prima A, B, C, D, E, poi Mattinieri, poi Senza Squadra
usort($resCollab, function($a, $b) use ($mappaUtenti) {
    if (!isset($mappaUtenti[$a['id']]) || !isset($mappaUtenti[$b['id']])) return 0;
    $sqA = $mappaUtenti[$a['id']]['squadra_norm'];
    $sqB = $mappaUtenti[$b['id']]['squadra_norm'];

    $ordine = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
    $pA = $ordine[$sqA] ?? ($sqA === 'MATTINIERO' ? 10 : 20);
    $pB = $ordine[$sqB] ?? ($sqB === 'MATTINIERO' ? 10 : 20);

    if ($pA !== $pB) return $pA - $pB;
    if ($sqA !== $sqB) return strcmp($sqA, $sqB);
    return strcmp($a['nome'] ?? '', $b['nome'] ?? '');
});

$listaCollaboratori = array_values(array_intersect_key($resCollab, array_flip(array_keys($mappaUtenti))));
$idsUtenti = array_keys($mappaUtenti);

// 5. Recupero Pianificazione (Turni reali creati in turni.php)
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

// 6. Recupero Assenze Approvate (Ferie, Malattia, 104, ecc.)
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

// 7. Calcolo della Sequenza Orizzontale per Squadra (A, B, C, D, E)
$utentiPerSquadra = [];
foreach ($listaCollaboratori as $c) {
    $sq = $mappaUtenti[$c['id']]['squadra_norm'];
    if (in_array($sq, ['A', 'B', 'C', 'D', 'E'])) {
        $utentiPerSquadra[$sq][] = $c['id'];
    }
}

$cicloRotazione = ['M', 'P', 'N', 'S', 'R'];
$turniSquadraPerGiorno = [];
foreach ($utentiPerSquadra as $sq => $idsMembri) {
    $primoTurno = null; $giornoPrimo = null;
    for ($g = 1; $g <= $giorni_nel_mese; $g++) {
        foreach ($idsMembri as $uId) {
            foreach ($matricePianificazione[$uId][$g] ?? [] as $t) {
                $tC = strtoupper(trim($t));
                if (in_array($tC, ['M', 'P', 'N', 'S', 'R'])) {
                    $turniSquadraPerGiorno[$sq][$g] = $tC;
                    if ($primoTurno === null) { $primoTurno = $tC; $giornoPrimo = $g; }
                    break 2;
                }
            }
        }
    }
    // Fallback automatico rotazione se necessario
    if ($primoTurno !== null) {
        $idxBase = array_search($primoTurno, $cicloRotazione);
        if ($idxBase !== false) {
            for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                if (empty($turniSquadraPerGiorno[$sq][$g])) {
                    $turniSquadraPerGiorno[$sq][$g] = $cicloRotazione[($idxBase + ($g - $giornoPrimo)) % 5];
                }
            }
        }
    }
}

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
$nomeMeseCorrente = $mesiNomi[$mese_selezionato] ?? '';
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
        @media print {
            @page { size: A4 landscape; margin: 6mm; }
            .print-hide { display: none !important; }
            body { background: #fff !important; font-size: 7pt !important; }
            .card { box-shadow: none !important; border: none !important; }
            .table-planner th, .table-planner td { padding: 2px 1px !important; font-size: 6.5pt !important; border: 1px solid #94a3b8 !important; }
            .col-collaboratore { position: static !important; width: 120px !important; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 print-hide">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Planner</a>
            <div class="ms-auto">
                <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
                <a href="turni.php" class="btn btn-outline-light btn-sm">Gestione Turni</a>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Filtri e Stampa -->
        <div class="card p-3 mb-3 print-hide">
            <form method="GET" action="planner.php" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-calendar3 text-primary"></i> <?php echo "$nomeMeseCorrente $anno_selezionato"; ?></h5>
                    <small class="text-muted">Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong></small>
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                        <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Tutti i Reparti</option>
                            <?php foreach ($reparti_disponibili as $rep) { ?>
                                <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato == $rep['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rep['nome_reparto']); ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                    <select name="mese" class="form-select form-select-sm">
                        <?php foreach ($mesiNomi as $numM => $strM) { ?>
                            <option value="<?php echo $numM; ?>" <?php echo ($numM === $mese_selezionato) ? 'selected' : ''; ?>><?php echo $strM; ?></option>
                        <?php } ?>
                    </select>
                    <select name="anno" class="form-select form-select-sm">
                        <?php for ($a = 2024; $a <= 2030; $a++) { ?>
                            <option value="<?php echo $a; ?>" <?php echo ($a === $anno_selezionato) ? 'selected' : ''; ?>><?php echo $a; ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm px-3">Filtra</button>
                </div>
                <div class="col-md-2 text-end">
                    <button onclick="window.print();" class="btn btn-secondary btn-sm fw-bold"><i class="bi bi-printer"></i> Stampa</button>
                </div>
            </form>
        </div>

        <!-- Tabella Planner Excel Style -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-planner mb-0">
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
                                <tr><td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-4 text-muted">Nessun collaboratore trovato.</td></tr>
                            <?php } else {
                                $squadraCorrente = null;
                                foreach ($listaCollaboratori as $collab) {
                                    $uId = $collab['id'];
                                    $nome = $collab['nome'] ?? '';
                                    $qualifica = trim($collab['qualifica'] ?? '') !== '' ? $collab['qualifica'] : ($collab['ruolo'] ?? '');
                                    $sqNorma = $mappaUtenti[$uId]['squadra_norm'];
                                    $isRotazione = in_array($sqNorma, ['A', 'B', 'C', 'D', 'E']);
                                    $isMattiniero = ($sqNorma === 'MATTINIERO');

                                    // 1. Mostra la riga della sequenza UNA SOLA VOLTA per ogni squadra
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
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
