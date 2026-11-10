<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Funzione di supporto personalizzata per le chiamate a Supabase con forzatura dello schema public
function supabase_ct_request($endpoint, $method = 'GET', $data = null) {
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return $method === 'GET' ? [] : false;
}

// 1. Estrazione sicura dei dati dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

// Ruoli e permessi
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

// Forza recupero organizzazione e reparto aggiornati da DB
$resUCorr = supabase_ct_request("staging_utenti?id=eq.$utente_id&select=organizzazione_id,reparto_id,qualifica,ruolo,squadra,nome");
if (!empty($resUCorr) && is_array($resUCorr)) {
    $org_id_utente = $resUCorr[0]['organizzazione_id'] ?? $org_id_utente;
    $reparto_id_utente = $resUCorr[0]['reparto_id'] ?? $reparto_id_utente;
    $utente_corrente_info = $resUCorr[0];
}

$reparto_selezionato = $_GET['reparto_id'] ?? ($is_coordinatore ? $reparto_id_utente : '');

$messaggio = '';
$tipo_alert = '';
$debug_info = [];

// GESTIONE AZIONI POST (Proposta di cambio)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'proponi_cambio') {
        $pianificazione_prop_id = trim($_POST['pianificazione_id'] ?? '');
        $utente_dest_id = trim($_POST['utente_destinatario_id'] ?? '');
        $note_proposta = trim($_POST['note'] ?? '');

        if (!empty($pianificazione_prop_id) && !empty($utente_dest_id)) {
            $datiCambio = [
                'organizzazione_id' => $org_id_utente,
                'reparto_id' => $reparto_id_utente,
                'utente_proponente_id' => $utente_id,
                'pianificazione_proponente_id' => $pianificazione_prop_id,
                'utente_destinatario_id' => $utente_dest_id,
                'stato' => 'in_attesa_collega',
                'note' => !empty($note_proposta) ? $note_proposta : 'Proposta di cambio turno'
            ];

            $respIns = supabase_ct_request("cambi_turno", 'POST', $datiCambio);
            if ($respIns !== false) {
                $messaggio = "Proposta di cambio turno inviata con successo al collega!";
                $tipo_alert = "success";
            } else {
                $messaggio = "Errore durante l'inserimento nella tabella cambi_turno su Supabase.";
                $tipo_alert = "danger";
            }
        } else {
            $messaggio = "Seleziona un tuo turno e il collega destinatario.";
            $tipo_alert = "danger";
        }
    }
}

// GESTIONE AZIONI GET (Accetta/Rifiuta/Approva)
if (isset($_GET['azione_ct']) && isset($_GET['id'])) {
    $ct_id = trim($_GET['id']);
    $azione_ct = trim($_GET['azione_ct']);

    $resCt = supabase_ct_request("cambi_turno?id=eq.$ct_id&select=*");
    if (!empty($resCt)) {
        $ctData = $resCt[0];

        if ($azione_ct === 'accetta_collega') {
            if ($ctData['utente_destinatario_id'] === $utente_id && $ctData['stato'] === 'in_attesa_collega') {
                supabase_ct_request("cambi_turno?id=eq.$ct_id", 'PATCH', ['stato' => 'in_attesa_coordinatore']);
                header("Location: cambi_turno.php?msg=accettato_collega");
                exit;
            }
        } elseif ($azione_ct === 'rifiuta_collega') {
            if ($ctData['utente_destinatario_id'] === $utente_id && $ctData['stato'] === 'in_attesa_collega') {
                supabase_ct_request("cambi_turno?id=eq.$ct_id", 'PATCH', ['stato' => 'rifiutato']);
                header("Location: cambi_turno.php?msg=rifiutato");
                exit;
            }
        } elseif ($azione_ct === 'elimina' || $azione_ct === 'ritira') {
            supabase_ct_request("cambi_turno?id=eq.$ct_id", 'DELETE');
            header("Location: cambi_turno.php?msg=eliminato");
            exit;
        } elseif ($azione_ct === 'approva_coordinatore' && ($is_coordinatore || $is_super_admin || $is_capo_personale)) {
            $pian_prop_id = $ctData['pianificazione_proponente_id'];
            $idProp = $ctData['utente_proponente_id'];
            $idDest = $ctData['utente_destinatario_id'];

            supabase_ct_request("pianificazione?id=eq.$pian_prop_id", 'PATCH', [
                'utente_id' => $idDest,
                'note' => 'Turno scambiato da ' . $idProp . ' a ' . $idDest . ' (Approvato da Coordinatore)'
            ]);

            supabase_ct_request("cambi_turno?id=eq.$ct_id", 'PATCH', ['stato' => 'approvato']);
            header("Location: cambi_turno.php?msg=approvato_definitivo");
            exit;
        } elseif ($azione_ct === 'rifiuta_coordinatore' && ($is_coordinatore || $is_super_admin || $is_capo_personale)) {
            supabase_ct_request("cambi_turno?id=eq.$ct_id", 'PATCH', ['stato' => 'rifiutato']);
            header("Location: cambi_turno.php?msg=rifiutato");
            exit;
        }
    }
}

// RACCOLTA DATI E DEBUG
$debug_info['utente_id'] = $utente_id;
$debug_info['reparto_id'] = $reparto_id_utente;
$debug_info['qualifica'] = $utente_corrente_info['qualifica'] ?? 'N/D';

// 1. Test recupero turni pianificazione per utente loggato
$resMieiTurni = supabase_ct_request("pianificazione?utente_id=eq.$utente_id&select=*&limit=50");
$debug_info['query_turni_eseguita'] = "pianificazione?utente_id=eq.$utente_id&select=*&limit=50";
$debug_info['turni_trovati_count'] = is_array($resMieiTurni) ? count($resMieiTurni) : 'Errore o non array';
$debug_info['raw_turni'] = $resMieiTurni;
$mieiTurniFuturi = is_array($resMieiTurni) ? $resMieiTurni : [];

// 2. Test recupero colleghi compatibili
$colleghiCompatibili = [];
if (!empty($reparto_id_utente)) {
    $mioReparto = $reparto_id_utente;
    $resColl = supabase_ct_request("staging_utenti?reparto_id=eq.$mioReparto&id=neq.$utente_id&select=id,nome,qualifica,ruolo,squadra");
    $debug_info['colleghi_reparto_raw'] = $resColl;
    if (is_array($resColl)) {
        foreach ($resColl as $col) {
            $colleghiCompatibili[] = $col; // Mostriamo tutti i colleghi del reparto per evitare filtri restrittivi iniziali
        }
    }
}
$debug_info['colleghi_compatibili_count'] = count($colleghiCompatibili);

// 3. Elenco richieste cambio turno
$listaCambi = [];
if ($is_coordinatore || $is_super_admin || $is_capo_personale) {
    $resC = supabase_ct_request("cambi_turno?select=*&order=created_at.desc");
    if (is_array($resC)) $listaCambi = $resC;
} else {
    $resC1 = supabase_ct_request("cambi_turno?utente_proponente_id=eq.$utente_id&select=*");
    $resC2 = supabase_ct_request("cambi_turno?utente_destinatario_id=eq.$utente_id&select=*");
    $listaCambi = array_merge(is_array($resC1)?$resC1:[], is_array($resC2)?$resC2:[]);
}

$mappaTuttiUtenti = [];
$resAllU = supabase_ct_request("staging_utenti?select=id,nome,qualifica,ruolo");
if (is_array($resAllU)) {
    foreach ($resAllU as $u) {
        $mappaTuttiUtenti[$u['id']] = $u;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Cambi Turno - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; padding-bottom: 70px; color: #334155; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); background: #ffffff; }
        .debug-box { background: #1e293b; color: #38bdf8; font-family: monospace; font-size: 12px; padding: 15px; border-radius: 8px; margin-bottom: 20px; max-height: 250px; overflow-y: auto; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Cambi Turno</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link" href="ferie.php">Ferie e Assenze</a></li>
                    <li class="nav-item"><a class="nav-link active" href="cambi_turno.php">Cambi Turno</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <!-- BOX DI DEBUG IN TEMPO REALE -->
        <div class="card shadow-sm mb-4 border-start border-4 border-info p-3">
            <h6 class="fw-bold text-dark mb-2"><i class="bi bi-bug-fill text-info"></i> Pannello di Debug Protur (Cambi Turno)</h6>
            <div class="debug-box">
                <?php echo "<pre>" . print_r($debug_info, true) . "</pre>"; ?>
            </div>
            <span class="small text-muted">Se <strong>turni_trovati_count</strong> è 0, significa che la tabella `pianificazione` non contiene record per il tuo `utente_id` o il campo utente_id non corrisponde.</span>
        </div>

        <div class="card shadow-sm mb-4 p-3">
            <div class="row align-items-center g-3">
                <div class="col-md-7">
                    <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-arrow-left-right text-primary"></i> <?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Gestione e Approvazione Cambi Turno' : 'Proponi e Gestisci i tuoi Cambi Turno'; ?>
                    </h2>
                    <span class="small text-muted">
                        <i class="bi bi-person-badge"></i> Operatore: <strong class="text-dark"><?php echo htmlspecialchars($utente_corrente_info['nome'] ?? 'Utente'); ?></strong> | 
                        Ruolo: <strong class="text-dark"><?php echo ucfirst(htmlspecialchars($ruolo_raw)); ?></strong>
                    </span>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'accettato_collega'): ?>
            <div class="alert alert-success py-2 small shadow-sm" role="alert">Hai accettato la proposta di cambio. Ora è in attesa dell'approvazione del coordinatore.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'approvato_definitivo'): ?>
            <div class="alert alert-success py-2 small shadow-sm" role="alert">Cambio turno approvato e registrato con successo nel planner!</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'rifiutato'): ?>
            <div class="alert alert-warning py-2 small shadow-sm" role="alert">La richiesta di cambio turno è stata rifiutata.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminato'): ?>
            <div class="alert alert-info py-2 small shadow-sm" role="alert">Richiesta eliminata con successo.</div>
        <?php endif; ?>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small fw-bold shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill"></i> <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <!-- VISTA DIPENDENTE: PROPOSIZIONE NUOVO CAMBIO -->
        <?php if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale): ?>
        <div class="card shadow-sm mb-4 bg-white p-3">
            <div class="card-header bg-white pb-2 px-0 border-bottom">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-plus-circle"></i> Proponi un Cambio Turno a un Collega</h5>
            </div>
            <div class="card-body px-0">
                <?php if (empty($mieiTurniFuturi)): ?>
                    <p class="text-muted small mb-0">Nessun turno trovato nella tabella pianificazione per il tuo utente.</p>
                <?php elseif (empty($colleghiCompatibili)): ?>
                    <p class="text-muted small mb-0">Non ci sono colleghi nel tuo reparto.</p>
                <?php else: ?>
                    <form method="POST" action="cambi_turno.php">
                        <input type="hidden" name="azione" value="proponi_cambio">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="pianificazione_id" class="form-label small fw-bold">Seleziona il tuo Turno da Scambiare</label>
                                <select class="form-select form-select-sm" id="pianificazione_id" name="pianificazione_id" required>
                                    <option value="">-- Seleziona turno --</option>
                                    <?php foreach ($mieiTurniFuturi as $t) { 
                                        $dataFmt = date('d/m/Y', strtotime($t['data_inizio']));
                                        $tipoEv = $t['tipo_evento'] ?? 'Turno';
                                    ?>
                                        <option value="<?php echo $t['id']; ?>">
                                            <?php echo $dataFmt; ?> - <?php echo htmlspecialchars($tipoEv); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="utente_destinatario_id" class="form-label small fw-bold">Collega Destinatario</label>
                                <select class="form-select form-select-sm" id="utente_destinatario_id" name="utente_destinatario_id" required>
                                    <option value="">-- Seleziona collega --</option>
                                    <?php foreach ($colleghiCompatibili as $c) { ?>
                                        <option value="<?php echo $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['nome']); ?> (<?php echo htmlspecialchars($c['qualifica'] ?? $c['ruolo'] ?? 'Operatore'); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="note" class="form-label small fw-bold">Motivazione / Note</label>
                                <input type="text" class="form-control form-control-sm" id="note" name="note" placeholder="Es. Motivi personali">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold"><i class="bi bi-send"></i> Invia</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ELENCO CAMBI TURNO (BACHECA) -->
        <div class="card shadow-sm bg-white p-3">
            <h5 class="mb-3 fs-6 fw-bold"><i class="bi bi-list-task"></i> Bacheca Richieste e Stato Cambi Turno</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Proponente</th>
                            <th>Turno Proponente</th>
                            <th>Collega Destinatario</th>
                            <th>Stato Attuale</th>
                            <th>Note</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listaCambi)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Nessuna richiesta di cambio turno presente.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($listaCambi as $ct): 
                                $idProp = $ct['utente_proponente_id'] ?? '';
                                $idDest = $ct['utente_destinatario_id'] ?? '';
                                $nomeProp = $mappaTuttiUtenti[$idProp]['nome'] ?? 'Utente';
                                $nomeDest = $mappaTuttiUtenti[$idDest]['nome'] ?? 'Utente';
                                $statoCt = $ct['stato'] ?? 'in_attesa_collega';

                                $turnoDataStr = 'Turno pianificato';
                                $resPianProp = supabase_ct_request("pianificazione?id=eq." . $ct['pianificazione_proponente_id'] . "&select=*");
                                if (!empty($resPianProp)) {
                                    $tp = $resPianProp[0];
                                    $turnoDataStr = date('d/m/Y', strtotime($tp['data_inizio'])) . ' (' . ($tp['tipo_evento'] ?? 'Turno') . ')';
                                }

                                $badgeStato = 'bg-secondary';
                                $testoStato = 'In attesa';
                                if ($statoCt === 'in_attesa_collega') {
                                    $badgeStato = 'bg-warning text-dark';
                                    $testoStato = 'In attesa di risposta collega';
                                } elseif ($statoCt === 'in_attesa_coordinatore') {
                                    $badgeStato = 'bg-info text-dark';
                                    $testoStato = 'In attesa di approvazione Coordinatore';
                                } elseif ($statoCt === 'approvato') {
                                    $badgeStato = 'bg-success';
                                    $testoStato = 'Approvato';
                                } elseif ($statoCt === 'rifiutato') {
                                    $badgeStato = 'bg-danger';
                                    $testoStato = 'Rifiutato';
                                }
                            ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($nomeProp); ?></td>
                                    <td><?php echo $turnoDataStr; ?></td>
                                    <td><?php echo htmlspecialchars($nomeDest); ?></td>
                                    <td><span class="badge <?php echo $badgeStato; ?>"><?php echo $testoStato; ?></span></td>
                                    <td><?php echo htmlspecialchars($ct['note'] ?? '-'); ?></td>
                                    <td class="text-end">
                                        <?php if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale): ?>
                                            <?php if ($idDest === $utente_id && $statoCt === 'in_attesa_collega'): ?>
                                                <a href="cambi_turno.php?azione_ct=accetta_collega&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-success py-0 px-1" title="Accetta"><i class="bi bi-check-lg"></i> Accetta</a>
                                                <a href="cambi_turno.php?azione_ct=rifiuta_collega&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-danger py-0 px-1" title="Rifiuta"><i class="bi bi-x-lg"></i> Rifiuta</a>
                                            <?php elseif ($idProp === $utente_id && ($statoCt === 'in_attesa_collega' || $statoCt === 'in_attesa_coordinatore')): ?>
                                                <a href="cambi_turno.php?azione_ct=ritira&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('Vuoi ritirare questa proposta di cambio?');" title="Ritira"><i class="bi bi-trash"></i> Ritira</a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($statoCt === 'in_attesa_coordinatore'): ?>
                                                <a href="cambi_turno.php?azione_ct=approva_coordinatore&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-success py-0 px-1" title="Approva Cambio"><i class="bi bi-check"></i> Approva</a>
                                                <a href="cambi_turno.php?azione_ct=rifiuta_coordinatore&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-danger py-0 px-1" title="Rifiuta Cambio"><i class="bi bi-x"></i> Rifiuta</a>
                                            <?php endif; ?>
                                            <a href="cambi_turno.php?azione_ct=elimina&id=<?php echo $ct['id']; ?>" class="btn btn-sm btn-outline-dark py-0 px-1" onclick="return confirm('Eliminare questa richiesta?');" title="Elimina"><i class="bi bi-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
