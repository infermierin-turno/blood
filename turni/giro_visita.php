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

$data_giro = (isset($_GET['data_giro']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['data_giro']))
    ? $_GET['data_giro']
    : date('Y-m-d');

$filtro_rapido = isset($_GET['filtro']) ? trim($_GET['filtro']) : 'tutti';
$ricerca_testo = isset($_GET['q']) ? trim($_GET['q']) : '';

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
    'Mobilizzazione',
    'Antidolorifica',
    'Medicazione',
    'Sala Operatoria',
    'Codice 56 / Riabilitazione',
    'Consulenza/Esami',
    'Altro'
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
            $res = supabase_gv_request(
                "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                'PATCH',
                [
                    'sigla_paziente' => '',
                    'diagnosi_sintetica' => '',
                    'stato_visita' => 'In attesa di visita',
                    'priorita' => 'Normale',
                    'note_stato' => '',
                    'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null
                ]
            );
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Letto liberato / azzerato per nuovo ingresso.";
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
                $partiNota = ["Dimissione registrata"];
                if (!empty($plAttuale['sigla_paziente'])) {
                    $partiNota[] = "Sigla: " . trim($plAttuale['sigla_paziente']);
                }
                if (!empty($plAttuale['diagnosi_sintetica'])) {
                    $partiNota[] = "Inquadramento: " . trim($plAttuale['diagnosi_sintetica']);
                }
                if (!empty($plAttuale['stato_visita'])) {
                    $partiNota[] = "Stato all'uscita: " . trim($plAttuale['stato_visita']);
                }
                if (!empty($plAttuale['note_stato'])) {
                    $partiNota[] = "Note: " . trim($plAttuale['note_stato']);
                }
                $notaDimissione = implode(" | ", $partiNota);

                $resCons = supabase_gv_request("giro_visita_consegne", 'POST', [
                    'organizzazione_id' => is_uuid($org_id_utente) ? $org_id_utente : null,
                    'reparto_id' => $reparto_selezionato,
                    'posto_letto_id' => $posto_id,
                    'data_giro' => $data_giro,
                    'utente_id' => is_uuid($utente_id) ? $utente_id : null,
                    'assegnato_a_id' => null,
                    'tag_azione' => 'Codice 56 / Riabilitazione',
                    'nota' => $notaDimissione,
                    'completata' => true,
                    'completata_da_id' => is_uuid($utente_id) ? $utente_id : null,
                    'completata_at' => date('c')
                ]);

                $resPatch = supabase_gv_request(
                    "posti_letto_reparto?id=eq." . rawurlencode($posto_id) . "&reparto_id=eq." . rawurlencode($reparto_selezionato),
                    'PATCH',
                    [
                        'sigla_paziente' => '',
                        'diagnosi_sintetica' => '',
                        'stato_visita' => 'In attesa di visita',
                        'priorita' => 'Normale',
                        'note_stato' => '',
                        'ultimo_aggiornamento_da' => is_uuid($utente_id) ? $utente_id : null
                    ]
                );

                if ($resPatch !== false && $resCons !== false) {
                    $_SESSION['flash_messaggio'] = "Dimissione registrata nella giornata e posto letto liberato.";
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

        if (!in_array($tag_azione, $tagAzioni, true)) {
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
                $_SESSION['flash_messaggio'] = $nuovo_stato_comp ? "Consegna segnata come completata." : "Consegna riaperta.";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante l'aggiornamento della consegna. (" . $ultimo_errore_supabase . ")";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    if ($azione === 'elimina_consegna') {
        $consegna_id = trim($_POST['consegna_id'] ?? '');
        if (is_uuid($consegna_id)) {
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
$totaleConsegneAperte = 0;
if (!empty($reparto_selezionato)) {
    $resCons = supabase_gv_request("giro_visita_consegne?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&data_giro=eq." . rawurlencode($data_giro) . "&select=*&order=completata.asc,created_at.desc");
    if (is_array($resCons)) {
        foreach ($resCons as $c) {
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

$postiLettoFiltrati = array_filter($postiLetto, function($pl) use ($filtro_rapido, $ricerca_testo, $consegnePerLetto) {
    $plId = $pl['id'] ?? '';
    $st = $pl['stato_visita'] ?? 'In attesa di visita';
    $prio = $pl['priorita'] ?? 'Normale';

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

    if ($ricerca_testo !== '') {
        $haystack = mb_strtolower(
            ($pl['stanza'] ?? '') . ' ' .
            ($pl['letto'] ?? '') . ' ' .
            ($pl['sigla_paziente'] ?? '') . ' ' .
            ($pl['diagnosi_sintetica'] ?? '') . ' ' .
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
        .consegna-item { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
        .consegna-completata { opacity: 0.65; text-decoration: line-through; }
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
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="giro_visita.php">Giro Visita</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 px-md-4">

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
                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                            <div class="col-12 col-sm-auto">
                                <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <?php foreach ($reparti_disponibili as $rep) {
                                        $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ((string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($labelRep); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        <?php } ?>
                        <div class="col-6 col-sm-auto">
                            <input type="date" name="data_giro" class="form-control form-control-sm" value="<?php echo htmlspecialchars($data_giro); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="col-6 col-sm-auto">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Cerca stanza, sigla, diagnosi..." value="<?php echo htmlspecialchars($ricerca_testo); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary fw-bold"><i class="bi bi-search"></i> Filtra</button>
                        </div>
                        <?php if (!empty($reparto_selezionato)) { ?>
                            <div class="col-auto">
                                <button type="button" class="btn btn-sm btn-success fw-bold" onclick="apriModaleLetto(null)">
                                    <i class="bi bi-plus-lg"></i> Nuovo Letto
                                </button>
                            </div>
                        <?php } ?>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo_alert ?: 'info'); ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php } ?>

        <?php if (empty($reparto_selezionato)) { ?>
            <div class="alert alert-warning shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> Nessun reparto associato all'utenza o selezionato.
            </div>
        <?php } else { ?>

            <div class="card p-3 mb-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <span class="fw-bold small text-secondary">
                        <i class="bi bi-people-fill text-primary"></i> Team in turno nel giorno <?php echo htmlspecialchars(date('d/m/Y', strtotime($data_giro))); ?>:
                    </span>
                    <span class="small text-muted">Consegne aperte oggi: <strong class="text-danger"><?php echo intval($totaleConsegneAperte); ?></strong></span>
                </div>
                <?php if (empty($personaleInTurno)) { ?>
                    <div class="small text-muted fst-italic">Nessun operatore registrato in pianificazione per la data selezionata (esclusi riposi/assenze).</div>
                <?php } else { ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($personaleInTurno as $pt) { ?>
                            <span class="pill-turno">
                                <strong><?php echo htmlspecialchars($pt['nome']); ?></strong>
                                <?php if (!empty($pt['ruolo'])) { ?>
                                    <span class="text-muted">(<?php echo htmlspecialchars($pt['ruolo']); ?>)</span>
                                <?php } ?>
                                <span class="badge bg-primary"><?php echo htmlspecialchars(implode('/', $pt['turni'])); ?></span>
                            </span>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <?php
                $filtriBottoni = [
                    'tutti'           => ['label' => 'Tutti i letti', 'count' => $conteggiStati['totale'], 'cls' => 'btn-outline-dark'],
                    'da_visitare'     => ['label' => 'Da visitare', 'count' => $conteggiStati['da_visitare'], 'cls' => 'btn-outline-secondary'],
                    'da_rivalutare'   => ['label' => 'Medicazioni / Esami / Urgenti', 'count' => $conteggiStati['medicazioni_esami'], 'cls' => 'btn-outline-warning'],
                    'dimissioni'      => ['label' => 'Dimissioni / Cod. 56', 'count' => $conteggiStati['dimissioni'], 'cls' => 'btn-outline-primary'],
                    'consegne_aperte' => ['label' => 'Consegne aperte', 'count' => $totaleConsegneAperte, 'cls' => 'btn-outline-danger']
                ];
                foreach ($filtriBottoni as $keyF => $infoF) {
                    $attivoF = ($filtro_rapido === $keyF);
                    $urlF = "giro_visita.php?" . http_build_query([
                        'reparto_id' => $reparto_selezionato,
                        'data_giro'  => $data_giro,
                        'filtro'     => $keyF,
                        'q'          => $ricerca_testo
                    ]);
                ?>
                    <a href="<?php echo htmlspecialchars($urlF); ?>" class="btn btn-sm <?php echo $attivoF ? 'btn-dark fw-bold' : $infoF['cls']; ?>">
                        <?php echo htmlspecialchars($infoF['label']); ?>
                        <span class="badge bg-light text-dark ms-1"><?php echo intval($infoF['count']); ?></span>
                    </a>
                <?php } ?>
            </div>

            <?php if (empty($postiLettoFiltrati)) { ?>
                <div class="card p-4 text-center text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    Nessun posto letto trovato con i filtri selezionati. Puoi aggiungerne uno con il pulsante <strong>Nuovo Letto</strong>.
                </div>
            <?php } else { ?>
                <div class="row g-3">
                    <?php foreach ($postiLettoFiltrati as $pl) {
                        $plId = $pl['id'];
                        $statoAtt = $pl['stato_visita'] ?? 'In attesa di visita';
                        $prioAtt = $pl['priorita'] ?? 'Normale';
                        $stMeta = $statiVisita[$statoAtt] ?? ['badge' => 'bg-secondary', 'icon' => 'bi-circle'];
                        $consLetto = $consegnePerLetto[$plId] ?? [];

                        $classeCard = 'card-letto';
                        if ($prioAtt === 'Urgente') {
                            $classeCard .= ' prio-urgente';
                        } elseif ($prioAtt === 'Dimissione') {
                            $classeCard .= ' prio-dimissione';
                        } elseif ($statoAtt === 'Visitato') {
                            $classeCard .= ' stato-visitato';
                        }

                        $plJson = htmlspecialchars(json_encode($pl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                        $labelPos = trim(($pl['stanza'] ?? '') . ' - ' . ($pl['letto'] ?? ''), ' -');
                        if ($labelPos === '') {
                            $labelPos = 'Posto letto';
                        }
                        $haDegente = (!empty(trim($pl['sigla_paziente'] ?? '')) || !empty(trim($pl['diagnosi_sintetica'] ?? '')) || !empty(trim($pl['note_stato'] ?? '')));
                    ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="card h-100 p-3 <?php echo $classeCard; ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <span class="fw-bold fs-6 text-dark">
                                            <i class="bi bi-door-open text-secondary"></i> <?php echo htmlspecialchars($labelPos); ?>
                                        </span>
                                        <?php if (!empty($pl['sigla_paziente'])) { ?>
                                            <span class="badge bg-light text-dark border ms-1 fw-bold"><?php echo htmlspecialchars($pl['sigla_paziente']); ?></span>
                                        <?php } ?>
                                        <?php if ($prioAtt === 'Urgente') { ?>
                                            <span class="badge bg-danger ms-1">Urgente</span>
                                        <?php } elseif ($prioAtt === 'Dimissione') { ?>
                                            <span class="badge bg-primary ms-1">Dimissione</span>
                                        <?php } ?>
                                    </div>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" onclick="apriModaleLetto('<?php echo $plJson; ?>')" title="Modifica posto letto / stato">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button type="button" class="btn btn-xs btn-outline-success py-0 px-2" onclick="apriModaleConsegna('<?php echo htmlspecialchars($plId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($labelPos, ENT_QUOTES); ?>')" title="Nuova consegna">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <span class="badge <?php echo $stMeta['badge']; ?>" style="font-size: 0.75rem;">
                                        <i class="bi <?php echo $stMeta['icon']; ?>"></i> <?php echo htmlspecialchars($statoAtt); ?>
                                    </span>
                                </div>

                                <?php if (!empty($pl['diagnosi_sintetica'])) { ?>
                                    <div class="small fw-semibold text-dark mb-1">
                                        <?php echo htmlspecialchars($pl['diagnosi_sintetica']); ?>
                                    </div>
                                <?php } ?>

                                <?php if (!empty($pl['note_stato'])) { ?>
                                    <div class="small text-muted mb-2">
                                        <i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($pl['note_stato']); ?>
                                    </div>
                                <?php } ?>

                                <div class="d-flex gap-2 mb-2">
                                    <?php if ($statoAtt !== 'Visitato') { ?>
                                        <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>" class="flex-fill m-0">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                            <input type="hidden" name="azione" value="stato_rapido">
                                            <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                            <input type="hidden" name="stato_visita" value="Visitato">
                                            <button type="submit" class="btn btn-sm btn-outline-success w-100 py-1 fw-semibold">
                                                <i class="bi bi-check2-circle"></i> Visitato
                                            </button>
                                        </form>
                                    <?php } ?>

                                    <?php if ($haDegente || in_array($statoAtt, ['Dimissione in valutazione', 'Dimissione protetta'], true) || $prioAtt === 'Dimissione') { ?>
                                        <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>" class="flex-fill m-0" onsubmit="return confirm('Confermi la dimissione per <?php echo htmlspecialchars($labelPos, ENT_QUOTES); ?>? Verrà salvata una nota di dimissione nella giornata e il letto verrà liberato.');">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                            <input type="hidden" name="azione" value="dimetti_paziente">
                                            <input type="hidden" name="posto_id" value="<?php echo htmlspecialchars($plId); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary w-100 py-1 fw-semibold">
                                                <i class="bi bi-box-arrow-right"></i> Dimetti
                                            </button>
                                        </form>
                                    <?php } ?>
                                </div>

                                <div class="mt-auto pt-2 border-top">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-bold text-secondary">Consegne del giorno (<?php echo count($consLetto); ?>)</span>
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="apriModaleConsegna('<?php echo htmlspecialchars($plId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($labelPos, ENT_QUOTES); ?>')">
                                            + Aggiungi
                                        </button>
                                    </div>

                                    <?php if (empty($consLetto)) { ?>
                                        <div class="small text-muted fst-italic">Nessuna consegna registrata per oggi.</div>
                                    <?php } else { ?>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ($consLetto as $cons) {
                                                $isComp = !empty($cons['completata']);
                                                $autoreNome = $mappaUtenti[$cons['utente_id'] ?? '']['nome'] ?? '';
                                                $assegnatoNome = $mappaUtenti[$cons['assegnato_a_id'] ?? '']['nome'] ?? '';
                                            ?>
                                                <div class="consegna-item d-flex justify-content-between align-items-start gap-2">
                                                    <div class="d-flex align-items-start gap-2 <?php echo $isComp ? 'consegna-completata' : ''; ?>">
                                                        <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>" class="m-0">
                                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                                            <input type="hidden" name="azione" value="toggle_consegna">
                                                            <input type="hidden" name="consegna_id" value="<?php echo htmlspecialchars($cons['id']); ?>">
                                                            <input type="hidden" name="completata" value="<?php echo $isComp ? '0' : '1'; ?>">
                                                            <input type="checkbox" class="form-check-input mt-1" <?php echo $isComp ? 'checked' : ''; ?> onchange="this.form.submit()" title="Segna completata">
                                                        </form>
                                                        <div class="small">
                                                            <span class="badge bg-info text-dark me-1"><?php echo htmlspecialchars($cons['tag_azione'] ?? 'Altro'); ?></span>
                                                            <span class="text-dark"><?php echo htmlspecialchars($cons['nota'] ?? ''); ?></span>
                                                            <?php if ($assegnatoNome !== '' || $autoreNome !== '') { ?>
                                                                <div class="text-muted" style="font-size: 0.72rem;">
                                                                    <?php if ($assegnatoNome !== '') { ?>
                                                                        <span>Assegnata a: <strong><?php echo htmlspecialchars($assegnatoNome); ?></strong></span>
                                                                    <?php } ?>
                                                                    <?php if ($autoreNome !== '') { ?>
                                                                        <span> &bull; da <?php echo htmlspecialchars($autoreNome); ?></span>
                                                                    <?php } ?>
                                                                </div>
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                    <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>" class="m-0" onsubmit="return confirm('Eliminare questa consegna?');">
                                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                                                        <input type="hidden" name="azione" value="elimina_consegna">
                                                        <input type="hidden" name="consegna_id" value="<?php echo htmlspecialchars($cons['id']); ?>">
                                                        <button type="submit" class="btn btn-xs btn-link text-danger p-0" title="Elimina consegna">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>

        <?php } ?>
    </div>

    <div class="modal fade" id="modalLetto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="modalLettoTitolo">Posto Letto / Stato Giro Visita</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <input type="hidden" name="azione" value="salva_posto_letto">
                    <input type="hidden" name="posto_id" id="ml_posto_id">
                    <div class="modal-body small">
                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label fw-bold">Stanza</label>
                                <input type="text" name="stanza" id="ml_stanza" class="form-control form-control-sm" placeholder="Es. Stanza 1">
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-bold">Letto</label>
                                <input type="text" name="letto" id="ml_letto" class="form-control form-control-sm" placeholder="Es. Letto A">
                            </div>
                            <div class="col-3">
                                <label class="form-label fw-bold">Ordine</label>
                                <input type="number" name="ordine_letto" id="ml_ordine" class="form-control form-control-sm" value="1" min="1">
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label fw-bold">Sigla / Iniziali</label>
                                <input type="text" name="sigla_paziente" id="ml_sigla" class="form-control form-control-sm" placeholder="Es. M.R.">
                            </div>
                            <div class="col-7">
                                <label class="form-label fw-bold">Priorità</label>
                                <select name="priorita" id="ml_priorita" class="form-select form-select-sm">
                                    <?php foreach ($prioritaValide as $pr) { ?>
                                        <option value="<?php echo htmlspecialchars($pr); ?>"><?php echo htmlspecialchars($pr); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold">Stato Giro Visita</label>
                            <select name="stato_visita" id="ml_stato" class="form-select form-select-sm">
                                <?php foreach ($statiVisita as $stNome => $stInfo) { ?>
                                    <option value="<?php echo htmlspecialchars($stNome); ?>"><?php echo htmlspecialchars($stNome); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold">Diagnosi / Inquadramento sintetico</label>
                            <input type="text" name="diagnosi_sintetica" id="ml_diagnosi" class="form-control form-control-sm" placeholder="Es. Frattura femore dx in attesa di intervento">
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold">Note di stato / giro</label>
                            <textarea name="note_stato" id="ml_note" class="form-control form-control-sm" rows="2" placeholder="Sintesi veloce del giro visita..."></textarea>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="attivo" id="ml_attivo" value="1" checked>
                            <label class="form-check-label" for="ml_attivo">Posto letto attivo nel reparto</label>
                        </div>
                    </div>
                    <div class="modal-footer py-2 justify-content-between">
                        <button type="submit" id="btnLiberaLetto" class="btn btn-sm btn-outline-warning fw-bold" style="display: none;" onclick="return svuotaCampiLetto()">
                            <i class="bi bi-eraser"></i> Libera Letto
                        </button>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-sm btn-primary fw-bold"><i class="bi bi-check-lg"></i> Salva</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalConsegna" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="modalConsegnaTitolo">Nuova Consegna Operativa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <input type="hidden" name="azione" value="aggiungi_consegna">
                    <input type="hidden" name="posto_letto_id" id="mc_posto_id">
                    <div class="modal-body small">
                        <div class="mb-2">
                            <label class="form-label fw-bold">Categoria / Tag Azione</label>
                            <select name="tag_azione" id="mc_tag" class="form-select form-select-sm" required>
                                <?php foreach ($tagAzioni as $tg) { ?>
                                    <option value="<?php echo htmlspecialchars($tg); ?>"><?php echo htmlspecialchars($tg); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold">Assegna a operatore (opzionale)</label>
                            <select name="assegnato_a_id" id="mc_assegnato" class="form-select form-select-sm">
                                <option value="">-- Non assegnata a singolo --</option>
                                <?php foreach ($personaleInTurno as $pt) { ?>
                                    <option value="<?php echo htmlspecialchars($pt['id']); ?>">
                                        <?php echo htmlspecialchars($pt['nome']); ?> (In turno: <?php echo htmlspecialchars(implode('/', $pt['turni'])); ?>)
                                    </option>
                                <?php } ?>
                                <?php foreach ($mappaUtenti as $uId => $uInfo) {
                                    if (isset($personaleInTurno[$uId])) continue;
                                ?>
                                    <option value="<?php echo htmlspecialchars($uId); ?>">
                                        <?php echo htmlspecialchars($uInfo['nome'] ?? 'Operatore'); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold">Nota / Consegna operativa</label>
                            <textarea name="nota" id="mc_nota" class="form-control form-control-sm" rows="3" required placeholder="Es. Mobilizzazione con fisioterapista, medicazione ferita, avvio pratica Codice 56..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="bi bi-plus-circle"></i> Salva Consegna</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function apriModaleLetto(plJson) {
            let btnLibera = document.getElementById('btnLiberaLetto');
            if (!plJson) {
                document.getElementById('modalLettoTitolo').innerText = "Nuovo Posto Letto / Degente";
                document.getElementById('ml_posto_id').value = '';
                document.getElementById('ml_stanza').value = '';
                document.getElementById('ml_letto').value = '';
                document.getElementById('ml_ordine').value = '1';
                document.getElementById('ml_sigla').value = '';
                document.getElementById('ml_priorita').value = 'Normale';
                document.getElementById('ml_stato').value = 'In attesa di visita';
                document.getElementById('ml_diagnosi').value = '';
                document.getElementById('ml_note').value = '';
                document.getElementById('ml_attivo').checked = true;
                btnLibera.style.display = 'none';
            } else {
                let pl = typeof plJson === 'string' ? JSON.parse(plJson) : plJson;
                document.getElementById('modalLettoTitolo').innerText = "Gestione " + ((pl.stanza || '') + ' ' + (pl.letto || '')).trim();
                document.getElementById('ml_posto_id').value = pl.id || '';
                document.getElementById('ml_stanza').value = pl.stanza || '';
                document.getElementById('ml_letto').value = pl.letto || '';
                document.getElementById('ml_ordine').value = pl.ordine_letto || 1;
                document.getElementById('ml_sigla').value = pl.sigla_paziente || '';
                document.getElementById('ml_priorita').value = pl.priorita || 'Normale';
                document.getElementById('ml_stato').value = pl.stato_visita || 'In attesa di visita';
                document.getElementById('ml_diagnosi').value = pl.diagnosi_sintetica || '';
                document.getElementById('ml_note').value = pl.note_stato || '';
                document.getElementById('ml_attivo').checked = (pl.attivo !== false);
                btnLibera.style.display = 'inline-block';
            }
            let modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLetto'));
            modal.show();
        }

        function svuotaCampiLetto() {
            if (!confirm('Azzerare i dati del paziente e riportare il letto in stato iniziale?')) {
                return false;
            }
            document.getElementById('ml_sigla').value = '';
            document.getElementById('ml_diagnosi').value = '';
            document.getElementById('ml_note').value = '';
            document.getElementById('ml_priorita').value = 'Normale';
            document.getElementById('ml_stato').value = 'In attesa di visita';
            return true;
        }

        function apriModaleConsegna(postoId, labelPos) {
            document.getElementById('modalConsegnaTitolo').innerText = "Nuova Consegna - " + labelPos;
            document.getElementById('mc_posto_id').value = postoId;
            document.getElementById('mc_tag').value = 'Mobilizzazione';
            document.getElementById('mc_assegnato').value = '';
            document.getElementById('mc_nota').value = '';
            let modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConsegna'));
            modal.show();
        }
    </script>
</body>
</html>
