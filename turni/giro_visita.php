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

function supabase_gv_request($endpoint, $method = 'GET', $data = null) {
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

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data !== null) {
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
        $ultimo_errore_supabase = "cURL: " . $error;
        error_log("Errore cURL Supabase (giro_visita): " . $error);
        return $method === 'GET' ? [] : false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }

    $ultimo_errore_supabase = "HTTP {$httpCode} - " . $response;
    error_log("Errore HTTP Supabase (giro_visita) [{$httpCode}]: " . $response);
    return $method === 'GET' ? [] : false;
}

$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$nome_utente_corrente = $_SESSION['nome'] ?? $_SESSION['utente']['nome'] ?? '';
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
$puo_gestire = ($is_coordinatore || $is_super_admin || $is_capo_personale);

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_gv_request("staging_utenti?id=eq." . rawurlencode($utente_id) . "&select=organizzazione_id,reparto_id");
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
$resReparti = supabase_gv_request($urlReparti);
if (is_array($resReparti) && !isset($resReparti['error'])) {
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
$posto_evidenziato_id = (isset($_GET['posto_id']) && is_uuid($_GET['posto_id'])) ? $_GET['posto_id'] : '';
$stato_filtro_dash = isset($_GET['stato']) ? trim($_GET['stato']) : '';

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

$statiVisita = [
    'In attesa di visita'        => ['badge' => 'bg-secondary', 'icon' => 'bi-hourglass-split'],
    'Visitato'                   => ['badge' => 'bg-success',   'icon' => 'bi-check-circle-fill'],
    'Da medicare'                => ['badge' => 'bg-warning text-dark', 'icon' => 'bi-bandaid-fill'],
    'Esami/Consulenze in corso'  => ['badge' => 'bg-info text-dark',    'icon' => 'bi-clipboard2-pulse-fill'],
    'Dimissione in valutazione'  => ['badge' => 'bg-primary',   'icon' => 'bi-box-arrow-right'],
    'Dimissione protetta'        => ['badge' => 'bg-dark',      'icon' => 'bi-shield-check']
];

$tagAzioni = [
    'Richiesti esami di routine',
    'Richiesti esami urgenza',
    'Richiesto emocromo di controllo',
    'Richiesta Emotrasfusione',
    'Controllo CV',
    'Controllo CVC',
    'Terapia Antidolorifica',
    'Mobilizzazione',
    'Medicazione',
    'In attesa intervento',
    'Controllare esami/consulenza',
    'Inviata mail per dimissione in struttura',
    'In attesa decisione parenti su tipo dimissione',
    'Contattati Assistenti Sociali'
];

$prioritaValide = ['Normale', 'Urgente', 'Dimissione'];

$messaggio = $_SESSION['flash_messaggio'] ?? '';
$tipo_alert = $_SESSION['flash_tipo'] ?? '';
unset($_SESSION['flash_messaggio'], $_SESSION['flash_tipo']);

$redirectParams = [
    'reparto_id' => $reparto_selezionato,
    'data_giro'  => $data_giro,
    'filtro'     => $filtro_rapido
];
if ($ricerca_testo !== '') {
    $redirectParams['q'] = $ricerca_testo;
}
if ($posto_evidenziato_id !== '') {
    $redirectParams['posto_id'] = $posto_evidenziato_id;
}
$redirectUrl = "giro_visita.php?" . http_build_query($redirectParams);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($reparto_selezionato)) {
    $csrf_token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $csrf_token)) {
        http_response_code(419);
        exit('Token CSRF non valido.');
    }

    $azione = $_POST['azione'] ?? '';

    if ($azione === 'salva_posto_letto') {
        $posto_id = trim($_POST['posto_id'] ?? '');
        $stanza = trim($_POST['stanza'] ?? '');
        $letto = trim($_POST['letto'] ?? '');
        $ordine_letto = max(1, intval($_POST['ordine_letto'] ?? 1));
        $sigla_paziente = strtoupper(trim($_POST['sigla_paziente'] ?? ''));
        $diagnosi_sintetica = trim($_POST['diagnosi_sintetica'] ?? '');
        $data_intervento_raw = trim($_POST['data_intervento'] ?? '');
        $data_intervento = is_valid_date($data_intervento_raw) ? $data_intervento_raw : null;
        $stato_visita = trim($_POST['stato_visita'] ?? 'In attesa di visita');
        $priorita = trim($_POST['priorita'] ?? 'Normale');
        $note_stato = trim($_POST['note_stato'] ?? '');
        $attivo = isset($_POST['attivo']) ? true : false;

        if (!array_key_exists($stato_visita, $statiVisita)) {
            $stato_visita = 'In attesa di visita';
        }
        if (!in_array($priorita, $prioritaValide, true)) {
            $priorita = 'Normale';
        }

        if ($stanza === '' && $letto === '') {
            $_SESSION['flash_messaggio'] = "Inserisci almeno la stanza o il numero/lettera del letto.";
            $_SESSION['flash_tipo'] = "danger";
        } elseif (!empty($posto_id) && is_uuid($posto_id)) {
            $patchData = [
                'stanza' => $stanza,
                'letto' => $letto,
                'ordine_letto' => $ordine_letto,
                'sigla_paziente' => $sigla_paziente,
                'diagnosi_sintetica' => $diagnosi_sintetica,
                'data_intervento' => $data_intervento,
                'stato_visita' => $stato_visita,
                'priorita' => $priorita,
                'note_stato' => $note_stato,
                'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null,
                'attivo' => $attivo
            ];
            $res = supabase_gv_request("posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato), 'PATCH', $patchData);
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Posto letto aggiornato.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante l'aggiornamento del posto letto. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        } else {
            $postData = [
                'organizzazione_id' => is_uuid($org_id_utente) ? $org_id_utente : null,
                'reparto_id' => $reparto_selezionato,
                'stanza' => $stanza,
                'letto' => $letto,
                'ordine_letto' => $ordine_letto,
                'sigla_paziente' => $sigla_paziente,
                'diagnosi_sintetica' => $diagnosi_sintetica,
                'data_intervento' => $data_intervento,
                'stato_visita' => $stato_visita,
                'priorita' => $priorita,
                'note_stato' => $note_stato,
                'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null,
                'attivo' => true
            ];
            $res = supabase_gv_request("posti_letto_reparto", 'POST', $postData);
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Nuovo posto letto / degente aggiunto.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante l'inserimento del posto letto. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'stato_rapido') {
        $posto_id = trim($_POST['posto_id'] ?? '');
        $nuovo_stato = trim($_POST['stato_visita'] ?? '');
        if (is_uuid($posto_id) && array_key_exists($nuovo_stato, $statiVisita)) {
            $res = supabase_gv_request(
                "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                'PATCH',
                [
                    'stato_visita' => $nuovo_stato,
                    'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null
                ]
            );
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Stato aggiornato a: {$nuovo_stato}.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante l'aggiornamento rapido dello stato. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'libera_letto') {
        $posto_id = trim($_POST['posto_id'] ?? '');
        if (is_uuid($posto_id)) {
            supabase_gv_request(
                "giro_visita_consegne?posto_letto_id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro),
                'DELETE'
            );

            $res = supabase_gv_request(
                "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                'PATCH',
                [
                    'sigla_paziente' => '',
                    'diagnosi_sintetica' => '',
                    'data_intervento' => null,
                    'stato_visita' => 'In attesa di visita',
                    'priorita' => 'Normale',
                    'note_stato' => '',
                    'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null
                ]
            );
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Letto liberato e consegne del degente rimosse dalla card.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante l'azzeramento del letto. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'dimetti_paziente') {
        $posto_id = trim($_POST['posto_id'] ?? '');
        if (is_uuid($posto_id)) {
            $resLettura = supabase_gv_request(
                "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=*"
            );
            $plAttuale = (!empty($resLettura) && is_array($resLettura)) ? $resLettura[0] : null;

            if ($plAttuale) {
                $labelLettoUscita = trim(($plAttuale['stanza'] ?? '') . ' - ' . ($plAttuale['letto'] ?? ''), ' -');
                if ($labelLettoUscita === '') {
                    $labelLettoUscita = 'Posto letto';
                }

                $partiNota = ["Dimissione registrata", "Postazione: " . $labelLettoUscita];
                if (!empty($plAttuale['sigla_paziente'])) {
                    $partiNota[] = "Sigla: " . trim($plAttuale['sigla_paziente']);
                }
                if (!empty($plAttuale['diagnosi_sintetica'])) {
                    $partiNota[] = "Inquadramento: " . trim($plAttuale['diagnosi_sintetica']);
                }
                if (!empty($plAttuale['data_intervento']) && is_valid_date($plAttuale['data_intervento'])) {
                    $partiNota[] = "Data intervento: " . date('d/m/Y', strtotime($plAttuale['data_intervento']));
                }
                if (!empty($plAttuale['stato_visita'])) {
                    $partiNota[] = "Stato all'uscita: " . trim($plAttuale['stato_visita']);
                }
                if (!empty($plAttuale['note_stato'])) {
                    $partiNota[] = "Note: " . trim($plAttuale['note_stato']);
                }
                $notaDimissione = implode(" | ", $partiNota);

                supabase_gv_request(
                    "giro_visita_consegne?posto_letto_id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro),
                    'DELETE'
                );

                $resCons = supabase_gv_request("giro_visita_consegne", 'POST', [
                    'organizzazione_id' => is_uuid($org_id_utente) ? $org_id_utente : null,
                    'reparto_id' => $reparto_selezionato,
                    'posto_letto_id' => $posto_id,
                    'data_giro' => $data_giro,
                    'utente_id' => is_uuid($utente_id) ? $utente_id : null,
                    'assegnato_a_id' => null,
                    'tag_azione' => 'Codice 56 / Riabilitazione',
                    'nota' => $notaDimissione,
                    'completata' => false,
                    'completata_da_id' => null,
                    'completata_at' => null
                ]);

                $resPatch = supabase_gv_request(
                    "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                    'PATCH',
                    [
                        'sigla_paziente' => '',
                        'diagnosi_sintetica' => '',
                        'data_intervento' => null,
                        'stato_visita' => 'In attesa di visita',
                        'priorita' => 'Normale',
                        'note_stato' => '',
                        'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null
                    ]
                );

                if ($resPatch !== false && $resCons !== false) {
                    $_SESSION['flash_messaggio'] = "Dimissione registrata nel riquadro di fine pagina e posto letto liberato.";
                    $_SESSION['flash_tipo'] = "success";
                } elseif ($resPatch !== false) {
                    $_SESSION['flash_messaggio'] = "Posto letto liberato, ma si è verificato un errore nel registrare la nota di dimissione. (" . $ultimo_errore_supabase . ")";
                    $_SESSION['flash_tipo'] = "warning";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante la dimissione del degente. (" . $ultimo_errore_supabase . ")";
                    $_SESSION['flash_tipo'] = "danger";
                }
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'aggiungi_consegna') {
        $posto_id = trim($_POST['posto_letto_id'] ?? '');
        $tag_azione = trim($_POST['tag_azione'] ?? 'Altro');
        $nota = trim($_POST['nota'] ?? '');
        $assegnato_a = trim($_POST['assegnato_a_id'] ?? '');

        if ($tag_azione === '') {
            $tag_azione = 'Altro';
        }
        $assegnatoUuid = is_uuid($assegnato_a) ? $assegnato_a : null;

        if (!is_uuid($posto_id) || $nota === '') {
            $_SESSION['flash_messaggio'] = "Seleziona un posto letto e inserisci una nota operativa.";
            $_SESSION['flash_tipo'] = "danger";
        } else {
            $payloadConsegna = [
                'organizzazione_id' => is_uuid($org_id_utente) ? $org_id_utente : null,
                'reparto_id' => $reparto_selezionato,
                'posto_letto_id' => $posto_id,
                'data_giro' => $data_giro,
                'utente_id' => is_uuid($utente_id) ? $utente_id : null,
                'assegnato_a_id' => $assegnatoUuid,
                'tag_azione' => $tag_azione,
                'nota' => $nota,
                'completata' => false
            ];
            $res = supabase_gv_request("giro_visita_consegne", 'POST', $payloadConsegna);
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Consegna operativa registrata.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante il salvataggio della consegna. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'toggle_consegna') {
        $consegna_id = trim($_POST['consegna_id'] ?? '');
        $nuovo_stato_comp = isset($_POST['completata']) && $_POST['completata'] === '1';

        if (is_uuid($consegna_id)) {
            $resCheck = supabase_gv_request(
                "giro_visita_consegne?id=eq." . rawurlencode($consegna_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,completata"
            );
            $consEsistente = (!empty($resCheck) && is_array($resCheck)) ? $resCheck[0] : null;

            if ($consEsistente && !empty($consEsistente['completata']) && !$nuovo_stato_comp) {
                $_SESSION['flash_messaggio'] = "Questa azione è già stata segnata come fatta e non può essere annullata.";
                $_SESSION['flash_tipo'] = "warning";
            } else {
                $patchComp = [
                    'completata' => $nuovo_stato_comp,
                    'completata_da_id' => $nuovo_stato_comp && is_uuid($utente_id) ? $utente_id : null,
                    'completata_at' => $nuovo_stato_comp ? date('c') : null
                ];
                $res = supabase_gv_request(
                    "giro_visita_consegne?id=eq." . rawurlencode($consegna_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                    'PATCH',
                    $patchComp
                );
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = $nuovo_stato_comp
                        ? "Consegna segnata come completata da " . ($nome_utente_corrente !== '' ? $nome_utente_corrente : 'utente') . "."
                        : "Consegna riaperta.";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'aggiornamento della consegna. (" . $ultimo_errore_supabase . ")";
                    $_SESSION['flash_tipo'] = "danger";
                }
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'elimina_consegna') {
        $consegna_id = trim($_POST['consegna_id'] ?? '');
        if (is_uuid($consegna_id)) {
            $resCheck = supabase_gv_request(
                "giro_visita_consegne?id=eq." . rawurlencode($consegna_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,completata"
            );
            $consEsistente = (!empty($resCheck) && is_array($resCheck)) ? $resCheck[0] : null;

            if ($consEsistente && !empty($consEsistente['completata'])) {
                $_SESSION['flash_messaggio'] = "Impossibile eliminare un'azione già segnata come completata.";
                $_SESSION['flash_tipo'] = "warning";
            } else {
                $res = supabase_gv_request(
                    "giro_visita_consegne?id=eq." . rawurlencode($consegna_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                    'DELETE'
                );
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = "Consegna eliminata.";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'eliminazione della consegna. (" . $ultimo_errore_supabase . ")";
                    $_SESSION['flash_tipo'] = "danger";
                }
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }
}

$mappaUtenti = [];
if (!empty($reparto_selezionato)) {
    $resUt = supabase_gv_request("staging_utenti?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,squadra&order=nome.asc");
    if (is_array($resUt)) {
        foreach ($resUt as $u) {
            $mappaUtenti[$u['id']] = $u;
        }
    }
}
if (!empty($utente_id) && !isset($mappaUtenti[$utente_id]) && $nome_utente_corrente !== '') {
    $mappaUtenti[$utente_id] = ['id' => $utente_id, 'nome' => $nome_utente_corrente];
}

$personaleInTurno = [];
$idsInTurno = [];
if (!empty($reparto_selezionato)) {
    $inizioGiorno = $data_giro . ' 00:00:00';
    $fineGiorno = $data_giro . ' 23:59:59';
    $queryPian = "pianificazione?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_inizio=gte." . urlencode($inizioGiorno) . "&data_inizio=lte." . urlencode($fineGiorno) . "&select=utente_id,tipo_evento,stato";
    $resPian = supabase_gv_request($queryPian);

    if (is_array($resPian)) {
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
                    'id' => $uId,
                    'nome' => $uInfo['nome'] ?? 'Operatore',
                    'ruolo' => $uInfo['ruolo'] ?? $uInfo['qualifica'] ?? '',
                    'turni' => []
                ];
                $idsInTurno[] = $uId;
            }
            if (!in_array($codTurno, $personaleInTurno[$uId]['turni'], true)) {
                $personaleInTurno[$uId]['turni'][] = $codTurno;
            }
        }
    }
}

$postiLetto = [];
if (!empty($reparto_selezionato)) {
    $resPL = supabase_gv_request("posti_letto_reparto?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&attivo=eq.true&select=*&order=ordine_letto.asc,stanza.asc,letto.asc");
    if (is_array($resPL)) {
        $postiLetto = $resPL;
    }
}

$consegnePerLetto = [];
$dimissioniGiorno = [];
$totaleConsegneAperte = 0;
if (!empty($reparto_selezionato)) {
    $resCons = supabase_gv_request("giro_visita_consegne?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro) . "&select=*&order=completata.asc,created_at.desc");
    if (is_array($resCons)) {
        foreach ($resCons as $c) {
            $notaTesto = trim($c['nota'] ?? '');
            $isDimissione = (mb_stripos($notaTesto, 'Dimissione registrata') === 0);
            if ($isDimissione) {
                $dimissioniGiorno[] = $c;
                continue;
            }
            $plId = $c['posto_letto_id'] ?? '';
            if ($plId !== '') {
                $consegnePerLetto[$plId][] = $c;
            }
            if (empty($c['completata'])) {
                $totaleConsegneAperte++;
            }
        }
    }
}

$conteggiStati = [
    'totale' => count($postiLetto),
    'da_visitare' => 0,
    'visitati' => 0,
    'medicazioni_esami' => 0,
    'dimissioni' => 0
];

foreach ($postiLetto as $pl) {
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    if ($st === 'In attesa di visita') {
        $conteggiStati['da_visitare']++;
    } elseif ($st === 'Visitato') {
        $conteggiStati['visitati']++;
    } elseif (in_array($st, ['Da medicare', 'Esami/Consulenze in corso'], true)) {
        $conteggiStati['medicazioni_esami']++;
    } elseif (in_array($st, ['Dimissione in valutazione', 'Dimissione protetta'], true) || (($pl['priorita'] ?? '') === 'Dimissione')) {
        $conteggiStati['dimissioni']++;
    }
}

$postiLettoFiltrati = array_filter($postiLetto, function($pl) use ($filtro_rapido, $ricerca_testo, $consegnePerLetto, $stato_filtro_dash, $posto_evidenziato_id) {
    $plId = $pl['id'] ?? '';
    $sigla = trim($pl['sigla_paziente'] ?? '');
    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';
    $dataInt = trim($pl['data_intervento'] ?? '');
    $isOccupato = ($sigla !== '' || $diagnosi !== '' || $st !== 'In attesa di visita' || $prio !== 'Normale');

    if ($filtro_rapido === 'occupati' && !$isOccupato) {
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
    if ($filtro_rapido === 'consegne_aperte') {
        $haAperta = false;
        foreach ($consegnePerLetto[$plId] ?? [] as $c) {
            if (empty($c['completata'])) {
                $haAperta = true;
                break;
            }
        }
        if (!$haAperta) {
            return false;
        }
    }

    if ($stato_filtro_dash !== '' && $st !== $stato_filtro_dash && ($posto_evidenziato_id === '' || $plId !== $posto_evidenziato_id)) {
        return false;
    }

    if ($ricerca_testo !== '' && ($posto_evidenziato_id === '' || $plId !== $posto_evidenziato_id)) {
        $dataIntFormattata = is_valid_date($dataInt) ? date('d/m/Y', strtotime($dataInt)) : '';
        $haystack = mb_strtolower(
            ($pl['stanza'] ?? '') . ' ' .
            ($pl['letto'] ?? '') . ' ' .
            $sigla . ' ' .
            $diagnosi . ' ' .
            $dataInt . ' ' .
            $dataIntFormattata . ' ' .
            ($pl['note_stato'] ?? '') . ' ' .
            $st
        );
        if (mb_strpos($haystack, mb_strtolower($ricerca_testo)) === false) {
            return false;
        }
    }

    return true;
});
$postiLettoFiltrati = array_values($postiLettoFiltrati);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giro Visita - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f1f5f9; padding-bottom: 70px; color: #1e293b; font-family: system-ui, -apple-system, sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); background: #ffffff; }
        .card-letto { border-left: 5px solid #64748b; transition: transform 0.12s ease, box-shadow 0.12s ease; }
        .card-letto:hover { transform: translateY(-1px); box-shadow: 0 8px 14px -3px rgba(0,0,0,0.08); }
        .card-letto.prio-urgente { border-left-color: #dc3545; }
        .card-letto.prio-dimissione { border-left-color: #0d6efd; }
        .card-letto.stato-visitato { border-left-color: #198754; }
        .card-letto.evidenziata { outline: 3px solid #0d6efd; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15); }
        .consegna-item { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
        .consegna-item.consegna-fatta { background-color: #f0fdf4; border-color: #bbf7d0; }
        .consegna-testo-fatto { opacity: 0.8; text-decoration: line-through; }
        .pill-turno { font-size: 0.75rem; background-color: #e2e8f0; color: #0f172a; border-radius: 999px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 6px; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Giro Visita</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="dashboard_ricoveri.php">Dashboard Ricoveri</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="giro_visita.php">Giro Visita</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 px-md-4">

        <?php if ($messaggio !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo_alert ?: 'info'); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card p-3 mb-3">
            <div class="row g-3 align-items-center">
                <div class="col-lg-4">
                    <h2 class="mb-1 fw-bold text-dark fs-4 d-flex align-items-center gap-2">
                        <i class="bi bi-clipboard2-pulse text-primary"></i> Giro Visita di Reparto
                    </h2>
                    <div class="small text-muted">
                        <i class="bi bi-building"></i> <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_corrente); ?></strong>
                        &bull; Data: <strong class="text-dark"><?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?></strong>
                    </div>
                </div>
                <div class="col-lg-8">
                    <form method="GET" action="giro_visita.php" class="row g-2 justify-content-lg-end align-items-center">
                        <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro_rapido); ?>">
                        <?php if ($posto_evidenziato_id !== ''): ?>
                            <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($posto_evidenziato_id); ?>">
                        <?php endif; ?>
                        <?php if ($stato_filtro_dash !== ''): ?>
                            <input type="hidden" name="stato" value="<?php echo htmlspecialchars($stato_filtro_dash); ?>">
                        <?php endif; ?>

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
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca letto, sigla, data int..." value="<?php echo htmlspecialchars($ricerca_testo); ?>">
                        </div>

                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary" title="Filtra"><i class="bi bi-search"></i></button>
                            <?php if ($ricerca_testo !== '' || $filtro_rapido !== 'tutti' || $stato_filtro_dash !== '' || $posto_evidenziato_id !== ''): ?>
                                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>" class="btn btn-sm btn-outline-secondary" title="Azzera filtri"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalPostoLetto" onclick="preparaNuovoPostoLetto()">
                                <i class="bi bi-plus-lg"></i> Posto Letto
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>&filtro=tutti" class="text-decoration-none text-reset">
                    <div class="card p-2">
                        <div class="small text-muted fw-semibold">Posti Attivi</div>
                        <div class="fs-5 fw-bold"><?php echo (int)$conteggiStati['totale']; ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>&filtro=da_visitare" class="text-decoration-none text-reset">
                    <div class="card p-2">
                        <div class="small text-muted fw-semibold">In Attesa Visita</div>
                        <div class="fs-5 fw-bold text-secondary"><?php echo (int)$conteggiStati['da_visitare']; ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>&filtro=da_rivalutare" class="text-decoration-none text-reset">
                    <div class="card p-2">
                        <div class="small text-muted fw-semibold">Medicazioni / Esami / Urg.</div>
                        <div class="fs-5 fw-bold text-warning"><?php echo (int)$conteggiStati['medicazioni_esami']; ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>&filtro=dimissioni" class="text-decoration-none text-reset">
                    <div class="card p-2">
                        <div class="small text-muted fw-semibold">Dimissioni / Valutazione</div>
                        <div class="fs-5 fw-bold text-primary"><?php echo (int)$conteggiStati['dimissioni']; ?></div>
                    </div>
                </a>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-1 mb-3">
            <?php
            $filtriBar = [
                'tutti'           => 'Tutti',
                'occupati'        => 'Occupati',
                'da_visitare'     => 'Da visitare',
                'da_rivalutare'   => 'Rivalutare / Urgenti',
                'dimissioni'      => 'Dimissioni',
                'consegne_aperte' => 'Consegne aperte (' . (int)$totaleConsegneAperte . ')'
            ];
            foreach ($filtriBar as $kF => $lblF):
                $clsBtn = ($filtro_rapido === $kF) ? 'btn-dark' : 'btn-outline-secondary bg-white';
            ?>
                <a href="giro_visita.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>&data_giro=<?php echo urlencode($data_giro); ?>&filtro=<?php echo urlencode($kF); ?><?php echo $ricerca_testo !== '' ? '&q=' . urlencode($ricerca_testo) : ''; ?>" class="btn btn-sm <?php echo $clsBtn; ?>">
                    <?php echo htmlspecialchars($lblF); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($reparto_selezionato)): ?>
            <div class="alert alert-info">Nessun reparto configurato per l'utente.</div>
        <?php elseif (empty($postiLettoFiltrati)): ?>
            <div class="card p-4 text-center text-muted">Nessun posto letto trovato con i filtri attuali.</div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <?php foreach ($postiLettoFiltrati as $pl):
                    $plId = $pl['id'] ?? '';
                    $stanza = trim($pl['stanza'] ?? '');
                    $letto = trim($pl['letto'] ?? '');
                    $ordine = intval($pl['ordine_letto'] ?? 1);
                    $sigla = trim($pl['sigla_paziente'] ?? '');
                    $diagnosi = trim($pl['diagnosi_sintetica'] ?? '');
                    $dataInt = trim($pl['data_intervento'] ?? '');
                    $hasDataInt = is_valid_date($dataInt);
                    $stato = $pl['stato_visita'] ?? 'In attesa di visita';
                    $prio = $pl['priorita'] ?? 'Normale';
                    $noteStato = trim($pl['note_stato'] ?? '');
                    $attivo = !empty($pl['attivo']);

                    $labelPosto = trim($stanza . ($letto !== '' ? ' - ' . $letto : ''), ' -');
                    if ($labelPosto === '') {
                        $labelPosto = 'Posto letto';
                    }

                    $cardClass = 'card-letto';
                    if ($posto_evidenziato_id !== '' && $plId === $posto_evidenziato_id) {
                        $cardClass .= ' evidenziata';
                    }
                    if ($prio === 'Urgente') {
                        $cardClass .= ' prio-urgente';
                    } elseif ($prio === 'Dimissione' || in_array($stato, ['Dimissione in valutazione', 'Dimissione protetta'], true)) {
                        $cardClass .= ' prio-dimissione';
                    } elseif ($stato === 'Visitato') {
                        $cardClass .= ' stato-visitato';
                    }

                    $badgeStatoInfo = $statiVisita[$stato] ?? ['badge' => 'bg-secondary', 'icon' => 'bi-info-circle'];
                    $consLetto = $consegnePerLetto[$plId] ?? [];

                    $badgeInterventoClass = 'bg-light text-dark border';
                    $badgeInterventoLabel = '';
                    if ($hasDataInt) {
                        $dataIntFmt = date('d/m/Y', strtotime($dataInt));
                        if ($dataInt === $data_giro) {
                            $badgeInterventoClass = 'bg-danger text-white';
                            $badgeInterventoLabel = "Intervento oggi ({$dataIntFmt})";
                        } elseif ($dataInt < $data_giro) {
                            $badgeInterventoClass = 'bg-warning text-dark';
                            $badgeInterventoLabel = "Intervento: {$dataIntFmt}";
                        } else {
                            $badgeInterventoClass = 'bg-info text-dark';
                            $badgeInterventoLabel = "Intervento: {$dataIntFmt}";
                        }
                    }
                ?>
                    <div class="col-md-6 col-xl-4" id="posto-<?php echo htmlspecialchars($plId); ?>">
                        <div class="card p-3 h-100 <?php echo $cardClass; ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <span class="badge bg-dark fs-6"><?php echo htmlspecialchars($labelPosto); ?></span>
                                    <?php if ($sigla !== ''): ?>
                                        <span class="fw-bold fs-6 ms-1"><?php echo htmlspecialchars($sigla); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small ms-1">Posto libero</span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge <?php echo $badgeStatoInfo['badge']; ?>">
                                    <i class="bi <?php echo $badgeStatoInfo['icon']; ?>"></i> <?php echo htmlspecialchars($stato); ?>
                                </span>
                            </div>

                            <?php if ($hasDataInt): ?>
                                <div class="mb-2">
                                    <span class="badge <?php echo $badgeInterventoClass; ?>">
                                        <i class="bi bi-scissors"></i> <?php echo htmlspecialchars($badgeInterventoLabel); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($diagnosi !== ''): ?>
                                <div class="small fw-semibold mb-1"><?php echo htmlspecialchars($diagnosi); ?></div>
                            <?php endif; ?>

                            <?php if ($noteStato !== ''): ?>
                                <div class="small text-secondary mb-2"><i class="bi bi-journal-text"></i> <?php echo htmlspecialchars($noteStato); ?></div>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalPostoLetto"
                                    data-id="<?php echo htmlspecialchars($plId); ?>"
                                    data-stanza="<?php echo htmlspecialchars($stanza, ENT_QUOTES); ?>"
                                    data-letto="<?php echo htmlspecialchars($letto, ENT_QUOTES); ?>"
                                    data-ordine="<?php echo $ordine; ?>"
                                    data-sigla="<?php echo htmlspecialchars($sigla, ENT_QUOTES); ?>"
                                    data-diagnosi="<?php echo htmlspecialchars($diagnosi, ENT_QUOTES); ?>"
                                    data-data-intervento="<?php echo htmlspecialchars($hasDataInt ? $dataInt : '', ENT_QUOTES); ?>"
                                    data-stato="<?php echo htmlspecialchars($stato, ENT_QUOTES); ?>"
                                    data-priorita="<?php echo htmlspecialchars($prio, ENT_QUOTES); ?>"
                                    data-note="<?php echo htmlspecialchars($noteStato, ENT_QUOTES); ?>"
                                    data-attivo="<?php echo $attivo ? '1' : '0'; ?>"
                                    onclick="caricaPostoLettoInModale(this)"
                                >
                                    <i class="bi bi-pencil"></i> Modifica
                                </button>

                                <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                    <input type="hidden" name="azione" value="stato_rapido">
                                    <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                    <input type="hidden" name="stato_visita" value="Visitato">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Segna come visitato">
                                        <i class="bi bi-check2"></i> Visitato
                                    </button>
                                </form>

                                <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="d-inline" onsubmit="return confirm('Registrare la dimissione e liberare il posto letto?');">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                    <input type="hidden" name="azione" value="dimetti_paziente">
                                    <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark" title="Dimetti paziente">
                                        <i class="bi bi-box-arrow-right"></i> Dimetti
                                    </button>
                                </form>

                                <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="d-inline" onsubmit="return confirm('Liberare il posto letto e rimuovere le consegne del giorno?');">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                    <input type="hidden" name="azione" value="libera_letto">
                                    <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Libera letto">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>

                            <div class="border-top pt-2 mt-auto">
                                <div class="small fw-bold mb-2">Consegne (<?php echo count($consLetto); ?>)</div>

                                <?php if (!empty($consLetto)): ?>
                                    <div class="d-flex flex-column gap-2 mb-2">
                                        <?php foreach ($consLetto as $c):
                                            $cId = $c['id'] ?? '';
                                            $isFatta = !empty($c['completata']);
                                            $assId = $c['assegnato_a_id'] ?? '';
                                            $nomeAss = ($assId !== '' && isset($mappaUtenti[$assId])) ? ($mappaUtenti[$assId]['nome'] ?? '') : '';
                                        ?>
                                            <div class="consegna-item <?php echo $isFatta ? 'consegna-fatta' : ''; ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div class="small <?php echo $isFatta ? 'consegna-testo-fatto' : ''; ?>">
                                                        <span class="badge bg-secondary-subtle text-dark border me-1"><?php echo htmlspecialchars($c['tag_azione'] ?? 'Azione'); ?></span>
                                                        <?php echo htmlspecialchars($c['nota'] ?? ''); ?>
                                                        <?php if ($nomeAss !== ''): ?>
                                                            <div class="text-muted mt-1" style="font-size: 0.72rem;"><i class="bi bi-person-check"></i> Assegnata a: <?php echo htmlspecialchars($nomeAss); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex gap-1 flex-shrink-0">
                                                        <?php if (!$isFatta && is_uuid($cId)): ?>
                                                            <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="d-inline">
                                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                                                <input type="hidden" name="azione" value="toggle_consegna">
                                                                <input type="hidden" name="consegna_id" value="<?php echo htmlspecialchars($cId); ?>">
                                                                <input type="hidden" name="completata" value="1">
                                                                <button type="submit" class="btn btn-sm btn-success py-0 px-1" title="Segna completata"><i class="bi bi-check"></i></button>
                                                            </form>
                                                            <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="d-inline" onsubmit="return confirm('Eliminare questa consegna?');">
                                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                                                <input type="hidden" name="azione" value="elimina_consegna">
                                                                <input type="hidden" name="consegna_id" value="<?php echo htmlspecialchars($cId); ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Elimina consegna"><i class="bi bi-x"></i></button>
                                                            </form>
                                                        <?php elseif ($isFatta): ?>
                                                            <span class="badge bg-success"><i class="bi bi-check-all"></i> Fatta</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="row g-1">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                    <input type="hidden" name="azione" value="aggiungi_consegna">
                                    <input type="hidden" name="posto_letto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                    <div class="col-12">
                                        <select name="tag_azione" class="form-select form-select-sm">
                                            <?php foreach ($tagAzioni as $tag): ?>
                                                <option value="<?php echo htmlspecialchars($tag); ?>"><?php echo htmlspecialchars($tag); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <select name="assegnato_a_id" class="form-select form-select-sm">
                                            <option value="">Assegna a... (opzionale)</option>
                                            <?php foreach ($mappaUtenti as $u): ?>
                                                <option value="<?php echo htmlspecialchars($u['id']); ?>"><?php echo htmlspecialchars($u['nome'] ?? 'Operatore'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-9">
                                        <input type="text" name="nota" class="form-control form-control-sm" placeholder="Nuova consegna / nota..." required>
                                    </div>
                                    <div class="col-3">
                                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card p-3 h-100">
                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-people-fill text-primary"></i> Personale in Turno (<?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>)</h3>
                    <?php if (empty($personaleInTurno)): ?>
                        <div class="small text-muted">Nessun operatore registrato in turno per questa data.</div>
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
                    <h3 class="h6 fw-bold mb-2"><i class="bi bi-box-arrow-right text-success"></i> Dimissioni Registrate (<?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>)</h3>
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

    <div class="modal fade" id="modalPostoLetto" tabindex="-1" aria-labelledby="modalPostoLettoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" action="giro_visita.php?<?php echo htmlspecialchars(http_build_query($redirectParams)); ?>" class="modal-content">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                <input type="hidden" name="azione" value="salva_posto_letto">
                <input type="hidden" name="posto_id" id="pl_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalPostoLettoLabel">Gestione Posto Letto / Degente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-sm-4">
                        <label for="pl_stanza" class="form-label small fw-semibold">Stanza</label>
                        <input type="text" name="stanza" id="pl_stanza" class="form-control form-control-sm" placeholder="Es. Stanza 1">
                    </div>
                    <div class="col-sm-4">
                        <label for="pl_letto" class="form-label small fw-semibold">Letto</label>
                        <input type="text" name="letto" id="pl_letto" class="form-control form-control-sm" placeholder="Es. A / 1">
                    </div>
                    <div class="col-sm-4">
                        <label for="pl_ordine" class="form-label small fw-semibold">Ordine visualizzazione</label>
                        <input type="number" min="1" name="ordine_letto" id="pl_ordine" class="form-control form-control-sm" value="1">
                    </div>

                    <div class="col-sm-4">
                        <label for="pl_sigla" class="form-label small fw-semibold">Sigla Paziente</label>
                        <input type="text" name="sigla_paziente" id="pl_sigla" class="form-control form-control-sm" placeholder="Es. R.M. 1954">
                    </div>
                    <div class="col-sm-4">
                        <label for="pl_data_intervento" class="form-label small fw-semibold">
                            <i class="bi bi-scissors text-danger"></i> Data Intervento
                        </label>
                        <input type="date" name="data_intervento" id="pl_data_intervento" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-4">
                        <label for="pl_priorita" class="form-label small fw-semibold">Priorità</label>
                        <select name="priorita" id="pl_priorita" class="form-select form-select-sm">
                            <?php foreach ($prioritaValide as $pVal): ?>
                                <option value="<?php echo htmlspecialchars($pVal); ?>"><?php echo htmlspecialchars($pVal); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6">
                        <label for="pl_stato" class="form-label small fw-semibold">Stato Giro Visita</label>
                        <select name="stato_visita" id="pl_stato" class="form-select form-select-sm">
                            <?php foreach ($statiVisita as $stKey => $stInfo): ?>
                                <option value="<?php echo htmlspecialchars($stKey); ?>"><?php echo htmlspecialchars($stKey); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="attivo" id="pl_attivo" value="1" checked>
                            <label class="form-check-label small" for="pl_attivo">Posto letto attivo</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="pl_diagnosi" class="form-label small fw-semibold">Diagnosi Sintetica / Inquadramento</label>
                        <input type="text" name="diagnosi_sintetica" id="pl_diagnosi" class="form-control form-control-sm" placeholder="Es. Frattura pertrocanterica femore dx">
                    </div>

                    <div class="col-12">
                        <label for="pl_note" class="form-label small fw-semibold">Note di Stato / Giro Visita</label>
                        <textarea name="note_stato" id="pl_note" rows="2" class="form-control form-control-sm" placeholder="Indicazioni cliniche, preparazione pre-operatoria, dimissione..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Salva</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function preparaNuovoPostoLetto() {
            document.getElementById('modalPostoLettoLabel').textContent = 'Nuovo Posto Letto / Degente';
            document.getElementById('pl_id').value = '';
            document.getElementById('pl_stanza').value = '';
            document.getElementById('pl_letto').value = '';
            document.getElementById('pl_ordine').value = '1';
            document.getElementById('pl_sigla').value = '';
            document.getElementById('pl_data_intervento').value = '';
            document.getElementById('pl_priorita').value = 'Normale';
            document.getElementById('pl_stato').value = 'In attesa di visita';
            document.getElementById('pl_diagnosi').value = '';
            document.getElementById('pl_note').value = '';
            document.getElementById('pl_attivo').checked = true;
        }

        function caricaPostoLettoInModale(btn) {
            document.getElementById('modalPostoLettoLabel').textContent = 'Modifica Posto Letto / Degente';
            document.getElementById('pl_id').value = btn.getAttribute('data-id') || '';
            document.getElementById('pl_stanza').value = btn.getAttribute('data-stanza') || '';
            document.getElementById('pl_letto').value = btn.getAttribute('data-letto') || '';
            document.getElementById('pl_ordine').value = btn.getAttribute('data-ordine') || '1';
            document.getElementById('pl_sigla').value = btn.getAttribute('data-sigla') || '';
            document.getElementById('pl_data_intervento').value = btn.getAttribute('data-data-intervento') || '';
            document.getElementById('pl_priorita').value = btn.getAttribute('data-priorita') || 'Normale';
            document.getElementById('pl_stato').value = btn.getAttribute('data-stato') || 'In attesa di visita';
            document.getElementById('pl_diagnosi').value = btn.getAttribute('data-diagnosi') || '';
            document.getElementById('pl_note').value = btn.getAttribute('data-note') || '';
            document.getElementById('pl_attivo').checked = (btn.getAttribute('data-attivo') === '1');
        }

        <?php if ($posto_evidenziato_id !== ''): ?>
        document.addEventListener('DOMContentLoaded', function () {
            var target = document.getElementById('posto-<?php echo htmlspecialchars($posto_evidenziato_id, ENT_QUOTES); ?>');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
