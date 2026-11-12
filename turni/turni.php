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

function supabase_turni_request($endpoint, $method = 'GET', $data = null) {
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
        error_log("Errore cURL Supabase: " . $error);
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return $method === 'GET' ? [] : false;
}

function call_python_engine_genera($payload = []) {
    $url = 'https://blood-protur.onrender.com/genera-turni';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Errore cURL: " . $error];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return is_array($decoded) ? $decoded : ['success' => true, 'response' => $response];
    }

    return [
        'success' => false, 
        'error' => "HTTP Code: $httpCode", 
        'response' => $response,
        'raw_output' => $response
    ];
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
    $resUCorr = supabase_turni_request("staging_utenti?id=eq." . rawurlencode($utente_id) . "&select=organizzazione_id,reparto_id");
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
$resReparti = supabase_turni_request($urlReparti);
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

$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti della Struttura" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org_filtered = supabase_turni_request("organizzazioni?id=eq." . rawurlencode($org_id_utente) . "&select=nome_struttura");
    if (!empty($res_org_filtered) && is_array($res_org_filtered)) {
        $nome_struttura_corrente = $res_org_filtered[0]['nome_struttura'] ?? $res_org_filtered[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
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

$messaggio = $_SESSION['flash_messaggio'] ?? '';
$tipo_alert = $_SESSION['flash_tipo'] ?? '';
unset($_SESSION['flash_messaggio'], $_SESSION['flash_tipo']);

$queryTipologie = "tipologie_turno?select=*";
$tipologieTurni = supabase_turni_request($queryTipologie);

if (!is_array($tipologieTurni) || empty($tipologieTurni)) {
    $tipologieTurni = [
        ['id' => '1', 'nome_turno' => 'Mattina', 'codice_breve' => 'M', 'colore' => '#0d6efd', 'ora_inizio' => '07:00:00', 'ora_fine' => '14:00:00'],
        ['id' => '2', 'nome_turno' => 'Pomeriggio', 'codice_breve' => 'P', 'colore' => '#ffc107', 'ora_inizio' => '14:00:00', 'ora_fine' => '21:00:00'],
        ['id' => '3', 'nome_turno' => 'Notte', 'codice_breve' => 'N', 'colore' => '#6610f2', 'ora_inizio' => '21:00:00', 'ora_fine' => '07:00:00'],
        ['id' => '4', 'nome_turno' => 'Smonto', 'codice_breve' => 'S', 'colore' => '#fd7e14', 'ora_inizio' => '07:00:00', 'ora_fine' => '14:00:00'],
        ['id' => '5', 'nome_turno' => 'Riposo', 'codice_breve' => 'R', 'colore' => '#198754', 'ora_inizio' => '00:00:00', 'ora_fine' => '23:59:59'],
        ['id' => '6', 'nome_turno' => 'Ferie', 'codice_breve' => 'F', 'colore' => '#20c997', 'ora_inizio' => '00:00:00', 'ora_fine' => '23:59:59']
    ];
}

$mappaColoriTurni = [];
$mappaOrariTurni = [];
$validCodiciTurno = [];
foreach ($tipologieTurni as $t) {
    $codice = strtoupper(trim($t['codice_breve'] ?? ''));
    $coloreHex = $t['colore'] ?? $t['colore_hex'] ?? $t['color'] ?? '#6c757d';
    if (!empty($codice)) {
        $mappaColoriTurni[$codice] = $coloreHex;
        $validCodiciTurno[] = $codice;
        $mappaOrariTurni[$codice] = [
            'inizio' => $t['ora_inizio'] ?? '08:00:00',
            'fine' => $t['ora_fine'] ?? '14:00:00'
        ];
    }
}
if (!isset($mappaColoriTurni['F'])) {
    $mappaColoriTurni['F'] = '#20c997';
    $validCodiciTurno[] = 'F';
}

function verificaVincoliTurno($utente_id, $data_str, $nuovo_codice, $turno_escludere_id = null) {
    $inizioGiorno = $data_str . ' 00:00:00';
    $fineGiorno = $data_str . ' 23:59:59';
    $urlGiorno = "pianificazione?utente_id=eq." . rawurlencode($utente_id) . "&data_inizio=gte." . urlencode($inizioGiorno) . "&data_inizio=lte." . urlencode($fineGiorno) . "&select=*";
    
    $turniGiorno = supabase_turni_request($urlGiorno);
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
        $tipoEsistente = strtoupper(trim($tg['tipo_evento']));
        $turniEsistentiCodici[] = $tipoEsistente;
    }

    if (!empty($turniEsistentiCodici)) {
        if (count($turniEsistentiCodici) === 1) {
            $esistente = $turniEsistentiCodici[0];
            $consentito = false;

            if ($esistente === 'M' && $nuovoCodiceUpper === 'P') {
                $consentito = true;
            } elseif ($esistente === 'P' && $nuovoCodiceUpper === 'M') {
                $consentito = true;
            }

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
    $urlUtenti = "staging_utenti?select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    if ($reparto_selezionato !== '') {
        $urlUtenti = "staging_utenti?reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    }
} elseif ($is_capo_personale) {
    if (!empty($org_id_utente)) {
        if ($reparto_selezionato !== '') {
            $urlUtenti = "staging_utenti?organizzazione_id=eq." . rawurlencode($org_id_utente) . "&reparto_id=eq." . rawurlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        } else {
            $urlUtenti = "staging_utenti?organizzazione_id=eq." . rawurlencode($org_id_utente) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        }
    } else {
        $urlUtenti = "";
    }
} else {
    if (!empty($reparto_id_utente)) {
        $urlUtenti = "staging_utenti?reparto_id=eq." . rawurlencode($reparto_id_utente) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    } else {
        $urlUtenti = "";
    }
}

if (!empty($urlUtenti)) {
    $resCollab = supabase_turni_request($urlUtenti);
    if (is_array($resCollab) && !isset($resCollab['error'])) {
        foreach ($resCollab as $c) {
            $rC_low = strtolower(str_replace([' ', '-'], '_', $c['ruolo'] ?? ''));
            if (in_array($rC_low, ['super_admin', 'admin', 'superadmin', 'capo_personale', 'capopersonale'])) {
                continue;
            }
            $mappaUtenti[$c['id']] = $c;
        }
    }
}

$listaCollaboratori = array_values($mappaUtenti);

$ruoliDisponibili = [];
foreach ($listaCollaboratori as $c) {
    $rText = trim($c['ruolo'] ?? $c['qualifica'] ?? '');
    if (!empty($rText) && !in_array($rText, $ruoliDisponibili)) {
        $ruoliDisponibili[] = $rText;
    }
}
sort($ruoliDisponibili);

if (!empty($filtro_ruolo)) {
    $listaCollaboratori = array_filter($listaCollaboratori, function($c) use ($filtro_ruolo) {
        $rC = trim($c['ruolo'] ?? $c['qualifica'] ?? '');
        return strcasecmp($rC, $filtro_ruolo) === 0;
    });
    $listaCollaboratori = array_values($listaCollaboratori);
}

usort($listaCollaboratori, function($a, $b) {
    $sqA = trim($a['squadra'] ?? '');
    $sqB = trim($b['squadra'] ?? '');
    
    if ($sqA !== $sqB) {
        if ($sqA === '') return 1;
        if ($sqB === '') return -1;
        return strcasecmp($sqA, $sqB);
    }
    
    $getPesoRuolo = function($collab) {
        $ruolo = strtolower(trim($collab['ruolo'] ?? $collab['qualifica'] ?? ''));
        $squadra = strtolower(trim($collab['squadra'] ?? ''));
        
        if (strpos($ruolo, 'mattinier') !== false || strpos($squadra, 'mattinier') !== false) {
            return 1;
        }
        if (strpos($ruolo, 'oss') !== false) {
            return 2;
        }
        if (strpos($ruolo, 'coordinat') !== false) {
            return 3;
        }
        return 4;
    };
    
    $pesoA = $getPesoRuolo($a);
    $pesoB = $getPesoRuolo($b);
    
    if ($pesoA !== $pesoB) {
        return $pesoA - $pesoB;
    }
    
    return strcasecmp($a['nome'] ?? '', $b['nome'] ?? '');
});

$mappaUtentiOrdinata = [];
$idsUtentiReparto = [];
foreach ($listaCollaboratori as $collab) {
    $mappaUtentiOrdinata[$collab['id']] = $collab;
    $idsUtentiReparto[] = $collab['id'];
}
$mappaUtenti = $mappaUtentiOrdinata;

$mese_selezionato = isset($_GET['mese']) ? max(1, min(12, intval($_GET['mese']))) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? max(2024, min(2030, intval($_GET['anno']))) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);
$primo_giorno_tab = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
$ultimo_giorno_tab = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);
$timestampPrimoGiornoProssimo = mktime(0, 0, 0, $mese_selezionato + 1, 1, $anno_selezionato);
$primo_giorno_prossimo_tab = date('Y-m-d', $timestampPrimoGiornoProssimo);

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

    if ($azione === 'cancella_turni_tranne_ferie') {
        if (!empty($idsUtentiReparto)) {
            $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
            $protetti = '(F,FERIE,104,PE,PER,PERMESSO,FOR,CORSO)';
            $queryDel = "pianificazione?utente_id=in.($idsEsc)&data_inizio=gte.$primo_giorno_tab&data_inizio=lt.$primo_giorno_prossimo_tab&tipo_evento=not.in.$protetti";
            supabase_turni_request($queryDel, 'DELETE');
            
            $_SESSION['flash_messaggio'] = "Pulizia completata con successo (Ferie e permessi protetti).";
            $_SESSION['flash_tipo'] = "success";
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    if ($azione === 'cancella_turni_singolo_operatore') {
        $utente_target_id = trim($_POST['utente_target_id'] ?? '');
        if (is_uuid($utente_target_id) && in_array($utente_target_id, $idsUtentiReparto)) {
            $protetti = '(F,FERIE,104,PE,PER,PERMESSO,FOR,CORSO)';
            $queryDelOp = "pianificazione?utente_id=eq." . rawurlencode($utente_target_id) . "&data_inizio=gte.$primo_giorno_tab&data_inizio=lt.$primo_giorno_prossimo_tab&tipo_evento=not.in.$protetti";
            supabase_turni_request($queryDelOp, 'DELETE');
            
            $_SESSION['flash_messaggio'] = "Mese pulito con successo per l'operatore selezionato.";
            $_SESSION['flash_tipo'] = "success";
        } else {
            $_SESSION['flash_messaggio'] = "Operatore non valido o non autorizzato.";
            $_SESSION['flash_tipo'] = "danger";
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    // CANCELLAZIONE TURNO (NESSUN VINCOLO NECESSARIO)
    if ($azione === 'cancella_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        if (is_uuid($turno_id)) {
            $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
            $res = supabase_turni_request("pianificazione?id=eq." . rawurlencode($turno_id) . "&utente_id=in.($idsEsc)", 'DELETE');
            if ($res !== false) {
                $_SESSION['flash_messaggio'] = "Turno cancellato con successo!";
                $_SESSION['flash_tipo'] = "success";
            } else {
                $_SESSION['flash_messaggio'] = "Errore durante la cancellazione del turno.";
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    if ($azione === 'aggiungi_turno_cella') {
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = strtoupper(trim($_POST['valore'] ?? ''));

        if (is_uuid($utente_cell) && in_array($utente_cell, $idsUtentiReparto) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_cell) && in_array($nuovo_valore, $validCodiciTurno)) {
            $checkVincoli = verificaVincoliTurno($utente_cell, $data_cell, $nuovo_valore);
            if ($checkVincoli === true) {
                $repIdAssegnato = $mappaUtenti[$utente_cell]['reparto_id'] ?? $reparto_id_utente;
                $orgIdAssegnato = $mappaUtenti[$utente_cell]['organizzazione_id'] ?? $org_id_utente;
                
                $datiPost = [
                    'organizzazione_id' => !empty($orgIdAssegnato) ? $orgIdAssegnato : null,
                    'reparto_id' => !empty($repIdAssegnato) ? $repIdAssegnato : null,
                    'utente_id' => $utente_cell,
                    'data_inizio' => $data_cell . ' 00:00:00+00',
                    'data_fine' => $data_cell . ' 23:59:59+00',
                    'tipo_evento' => $nuovo_valore,
                    'stato' => 'Approvato',
                    'note' => !empty($nota_input) ? $nota_input : 'Aggiunta turno da modale tabellone'
                ];
                $res = supabase_turni_request("pianificazione", 'POST', $datiPost);
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = "Turno aggiunto con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'inserimento del turno nel database.";
                    $_SESSION['flash_tipo'] = "danger";
                }
            } else {
                $_SESSION['flash_messaggio'] = $checkVincoli;
                $_SESSION['flash_tipo'] = "danger";
            }
        } else {
            $_SESSION['flash_messaggio'] = "Dati non validi per l'inserimento.";
            $_SESSION['flash_tipo'] = "danger";
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    if ($azione === 'modifica_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = strtoupper(trim($_POST['valore'] ?? ''));
        $nuovo_stato = trim($_POST['stato'] ?? 'Approvato');

        if (is_uuid($turno_id) && is_uuid($utente_cell) && in_array($utente_cell, $idsUtentiReparto) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_cell) && in_array($nuovo_valore, $validCodiciTurno)) {
            $checkVincoli = verificaVincoliTurno($utente_cell, $data_cell, $nuovo_valore, $turno_id);
            if ($checkVincoli === true) {
                $datiPatch = [
                    'tipo_evento' => $nuovo_valore,
                    'stato' => in_array($nuovo_stato, ['Approvato', 'In attesa', 'Rifiutato']) ? $nuovo_stato : 'Approvato',
                    'note' => !empty($nota_input) ? $nota_input : 'Modificato da modale tabellone'
                ];
                $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
                $res = supabase_turni_request("pianificazione?id=eq." . rawurlencode($turno_id) . "&utente_id=in.($idsEsc)", 'PATCH', $datiPatch);
                if ($res !== false) {
                    $_SESSION['flash_messaggio'] = "Turno aggiornato con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante la modifica del turno.";
                    $_SESSION['flash_tipo'] = "danger";
                }
            } else {
                $_SESSION['flash_messaggio'] = $checkVincoli;
                $_SESSION['flash_tipo'] = "danger";
            }
        } else {
            $_SESSION['flash_messaggio'] = "Dati non validi per la modifica.";
            $_SESSION['flash_tipo'] = "danger";
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    if ($azione === 'assegna_turno') {
        $collaboratore_selezionato = trim($_POST['collaboratore_id'] ?? '');
        $tipologia_turno_id = strtoupper(trim($_POST['tipologia_turno_id'] ?? ''));
        $data_inizio = trim($_POST['data_inizio'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if (is_uuid($collaboratore_selezionato) && in_array($collaboratore_selezionato, $idsUtentiReparto) && in_array($tipologia_turno_id, $validCodiciTurno) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inizio)) {
            $checkVincoli = verificaVincoliTurno($collaboratore_selezionato, $data_inizio, $tipologia_turno_id);
            if ($checkVincoli === true) {
                $repIdAss = $mappaUtenti[$collaboratore_selezionato]['reparto_id'] ?? $reparto_id_utente;
                $orgIdAss = $mappaUtenti[$collaboratore_selezionato]['organizzazione_id'] ?? $org_id_utente;
                
                $datiPianificazione = [
                    'organizzazione_id' => !empty($orgIdAss) ? $orgIdAss : null,
                    'reparto_id' => !empty($repIdAss) ? $repIdAss : null,
                    'utente_id' => $collaboratore_selezionato,
                    'data_inizio' => $data_inizio . ' 00:00:00+00',
                    'data_fine' => $data_inizio . ' 23:59:59+00',
                    'tipo_evento' => $tipologia_turno_id,
                    'stato' => 'Approvato',
                    'note' => !empty($note) ? $note : "Assegnazione manuale"
                ];

                $resp = supabase_turni_request("pianificazione", 'POST', $datiPianificazione);
                if ($resp !== false) {
                    $_SESSION['flash_messaggio'] = "Turno assegnato con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $_SESSION['flash_messaggio'] = "Errore durante l'assegnazione del turno.";
                    $_SESSION['flash_tipo'] = "danger";
                }
            } else {
                $_SESSION['flash_messaggio'] = $checkVincoli;
                $_SESSION['flash_tipo'] = "danger";
            }
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }

    if ($azione === 'genera_turni_ai') {
        $data_inizio_gen = trim($_POST['data_inizio_gen'] ?? '');
        $data_fine_gen = trim($_POST['data_fine_gen'] ?? '');
        $collaboratore_gen = trim($_POST['collaboratore_gen'] ?? ''); 
        $turno_partenza = strtoupper(trim($_POST['turno_partenza'] ?? '')); 
        $sequenza_custom = trim($_POST['sequenza_turni'] ?? '');
        $riposo_domenicale = isset($_POST['riposo_domenicale']) ? true : false; 
        $rispetta_ferie = isset($_POST['rispetta_ferie']) ? true : false;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inizio_gen) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fine_gen) && $data_inizio_gen <= $data_fine_gen) {
            $clean_org_id = (!empty($org_id_utente)) ? $org_id_utente : null;
            $clean_rep_id = (!empty($reparto_selezionato)) ? $reparto_selezionato : null;
            
            $operatori_target = [];
            if (!empty($collaboratore_gen) && is_uuid($collaboratore_gen) && in_array($collaboratore_gen, $idsUtentiReparto)) {
                $operatori_target = [$collaboratore_gen];
            } else {
                $operatori_target = $idsUtentiReparto;
            }

            if (!empty($operatori_target)) {
                $sequenza_array = [];
                if (!empty($sequenza_custom)) {
                    $partiSeq = explode(',', $sequenza_custom);
                    foreach ($partiSeq as $p) {
                        $pClean = strtoupper(trim($p));
                        if ($pClean !== '' && in_array($pClean, $validCodiciTurno)) {
                            $sequenza_array[] = $pClean;
                        }
                    }
                }

                $payloadAI = [
                    'organizzazione_id' => $clean_org_id,
                    'reparto_id' => $clean_rep_id,
                    'data_inizio' => $data_inizio_gen,
                    'data_fine' => $data_fine_gen,
                    'utente_id' => (!empty($collaboratore_gen)) ? $collaboratore_gen : null,
                    'operatori_ids' => $operatori_target,
                    'utenti_ids' => $operatori_target,
                    'turno_partenza' => (!empty($turno_partenza) && in_array($turno_partenza, $validCodiciTurno)) ? $turno_partenza : null,
                    'sequenza_turni' => !empty($sequenza_array) ? $sequenza_array : ['M', 'P', 'N', 'S', 'R'],
                    'rispetta_ferie' => $rispetta_ferie,
                    'riposo_domenicale' => $riposo_domenicale 
                ];

                $risultatoAI = call_python_engine_genera($payloadAI);
                if (isset($risultatoAI['success']) && $risultatoAI['success'] === true) {
                    $_SESSION['flash_messaggio'] = "Generazione turni tramite AI completata con successo!";
                    $_SESSION['flash_tipo'] = "success";
                } else {
                    $detErr = $risultatoAI['response'] ?? $risultatoAI['error'] ?? 'Sconosciuto';
                    $_SESSION['flash_messaggio'] = "Errore dal motore AI: " . htmlspecialchars($detErr);
                    $_SESSION['flash_tipo'] = "danger";
                }
            }
        }
        header("Location: turni.php?" . http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo]));
        exit;
    }
}

$queryTurniMese = "pianificazione?select=*&data_inizio=gte." . $primo_giorno_tab . "&data_inizio=lt." . $primo_giorno_prossimo_tab;
if (!empty($idsUtentiReparto)) {
    $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
    $queryTurniMese .= "&utente_id=in.($idsEsc)";
} else {
    $queryTurniMese .= "&utente_id=eq.00000000-0000-0000-0000-000000000000";
}
$turniMeseData = supabase_turni_request($queryTurniMese);

$queryAssenzeMese = "assenze?select=*&data_inizio=lte." . $ultimo_giorno_tab . "&data_fine=gte." . $primo_giorno_tab;
if (!empty($idsUtentiReparto)) {
    $idsEsc = implode(',', array_map('rawurlencode', $idsUtentiReparto));
    $queryAssenzeMese .= "&utente_id=in.($idsEsc)";
} else {
    $queryAssenzeMese .= "&utente_id=eq.00000000-0000-0000-0000-000000000000";
}
$assenzeMeseData = supabase_turni_request($queryAssenzeMese);

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
        $statoAssenza = $as['stato'] ?? 'In attesa';
        
        $dInizio = max($primo_giorno_tab, substr($as['data_inizio'], 0, 10));
        $dFine = min($ultimo_giorno_tab, substr($as['data_fine'] ?? $as['data_inizio'], 0, 10));
        
        $currentTimestamp = strtotime($dInizio);
        $endTimestamp = strtotime($dFine);
        
        while ($currentTimestamp <= $endTimestamp) {
            $giornoNum = intval(date('d', $currentTimestamp));
            
            $giaPresente = false;
            if (isset($mappaTurniGriglia[$uId][$giornoNum])) {
                foreach ($mappaTurniGriglia[$uId][$giornoNum] as $evEsistente) {
                    if (strcasecmp($evEsistente['tipo_evento'], $tipoEvento) === 0) {
                        $giaPresente = true;
                        break;
                    }
                }
            }
            
            if (!$giaPresente) {
                $mappaTurniGriglia[$uId][$giornoNum][] = [
                    'id' => $as['id'] ?? 'ass_' . rand(1000,9999),
                    'tipo_evento' => $tipoEvento,
                    'stato' => $statoAssenza,
                    'fonte' => 'assenze'
                ];
            }
            
            $currentTimestamp = strtotime('+1 day', $currentTimestamp);
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
    <title>Gestione Turni - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f1f5f9; padding-bottom: 70px; color: #1e293b; font-family: system-ui, -apple-system, sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); background: #ffffff; }
        
        .table-turni { border-collapse: separate; border-spacing: 0; table-layout: fixed; width: 100%; }
        .table-turni th, .table-turni td { text-align: center; vertical-align: middle; font-size: 0.73rem; padding: 2px 2px !important; line-height: 1.1; border-color: #e2e8f0 !important; overflow: hidden; }
        .table-turni tbody tr { transition: background-color 0.15s ease; height: 32px; }
        .table-turni tbody tr:hover { background-color: #f8fafc !important; }
        .table-turni tbody tr:hover td.col-operatore-sticky { background-color: #f1f5f9 !important; }
        
        .cella-interattiva { cursor: pointer; white-space: nowrap; display: table-cell; vertical-align: middle; text-align: center; height: 32px; }
        .cella-interattiva:hover { background-color: #e2e8f0 !important; }
        
        .col-operatore-sticky { position: sticky; left: 0; background-color: #ffffff; z-index: 2; text-align: left !important; min-width: 175px; max-width: 175px; width: 175px; font-weight: 600; font-size: 0.75rem; padding-left: 8px !important; border-right: 2px solid #cbd5e1 !important; }
        
        .badge-container { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; }

        .badge-turno {
            display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 15px; font-size: 0.6rem; font-weight: 700; color: #fff;
            border-radius: 3px; box-shadow: 0 1px 1px rgba(0,0,0,0.05); letter-spacing: -0.5px; margin: 0 auto;
        }
        .badge-in-attesa {
            border: 2px dashed #f59e0b !important;
            opacity: 0.9;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Gestione Turni Ospedalieri</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link active" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <div class="card shadow-sm mb-4 p-3">
            <div class="row align-items-center g-3">
                <div class="col-md-4">
                    <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2 fs-4">
                        <i class="bi bi-calendar-check text-primary"></i> Assegnazione Turni
                    </h2>
                    <span class="small text-muted">
                        <i class="bi bi-building"></i> Reparto: <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong>
                        <?php if (!empty($filtro_ruolo)) { ?>
                            | Filtro Ruolo: <span class="badge bg-primary"><?php echo htmlspecialchars($filtro_ruolo); ?></span>
                        <?php } ?>
                    </span>
                </div>
                <div class="col-md-8 text-md-end">
                    <form method="GET" action="turni.php" class="d-inline-flex gap-2 align-items-center flex-wrap justify-content-end">
                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                            <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                                <option value="">Tutti i Reparti</option>
                                <?php foreach ($reparti_disponibili as $rep) { 
                                    $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                                ?>
                                    <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelRep); ?></option>
                                <?php } ?>
                            </select>
                        <?php } ?>

                        <select name="filtro_ruolo" class="form-select form-select-sm border-primary fw-semibold" onchange="this.form.submit()" style="width: auto;">
                            <option value="">-- Tutti i Ruoli (OSS, Infermieri, ecc.) --</option>
                            <?php foreach ($ruoliDisponibili as $rOpt) { ?>
                                <option value="<?php echo htmlspecialchars($rOpt); ?>" <?php echo (strcasecmp($filtro_ruolo, $rOpt) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rOpt); ?></option>
                            <?php } ?>
                        </select>

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
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo htmlspecialchars($tipo_alert); ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i> <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php } ?>

        <div class="card shadow-sm mb-4 p-3">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold small text-secondary">Legenda Turni:</span>
                    <?php foreach ($tipologieTurni as $tTip) { 
                        $cBreve = strtoupper(trim($tTip['codice_breve'] ?? ''));
                        $cColore = $tTip['colore'] ?? '#6c757d';
                        $cNome = $tTip['nome_turno'] ?? $cBreve;
                    ?>
                        <span class="badge" style="background-color: <?php echo htmlspecialchars($cColore); ?>; font-size: 0.7rem;" title="<?php echo htmlspecialchars($cNome); ?>">
                            <?php echo htmlspecialchars($cBreve); ?>: <?php echo htmlspecialchars($cNome); ?>
                        </span>
                    <?php } ?>
                </div>
                <div>
                    <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale) { ?>
                        <button type="button" class="btn btn-sm btn-outline-danger fw-bold" data-bs-toggle="modal" data-bs-target="#modalPulisciTurni">
                            <i class="bi bi-trash3"></i> Pulisci Mese (Tranne Ferie/104)
                        </button>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh;">
                    <table class="table table-bordered table-turni m-0">
                        <thead class="table-dark sticky-top" style="z-index: 3;">
                            <tr>
                                <th class="col-operatore-sticky bg-dark text-white">Operatore / Giorno</th>
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { 
                                    $timestampG = mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato);
                                    $nomeGiornoW = date('N', $timestampG); 
                                    $isFestivo = ($nomeGiornoW == 7);
                                ?>
                                    <th class="<?php echo $isFestivo ? 'bg-danger text-white' : ''; ?>" style="width: 32px; min-width: 32px;">
                                        <div style="font-size: 0.65rem;"><?php echo date('D', $timestampG); ?></div>
                                        <div style="font-size: 0.8rem; font-weight: bold;"><?php echo $g; ?></div>
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
                                <?php foreach ($listaCollaboratori as $collab) { 
                                    $uId = $collab['id'];
                                    $nomeCollab = $collab['nome'] ?? 'Operatore';
                                    $ruoloCollab = $collab['ruolo'] ?? $collab['qualifica'] ?? '';
                                    $squadraCollab = $collab['squadra'] ?? '';
                                ?>
                                    <tr>
                                        <td class="col-operatore-sticky" title="<?php echo htmlspecialchars($nomeCollab); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="text-truncate fw-bold" style="max-width: 130px;"><?php echo htmlspecialchars($nomeCollab); ?></div>
                                                <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale) { ?>
                                                    <button type="button" class="btn btn-xs btn-outline-danger p-0 px-1" style="font-size: 0.65rem;" onclick="apriModalePulisciSingolo('<?php echo htmlspecialchars($uId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($nomeCollab, ENT_QUOTES); ?>')" title="Pulisci mese operatore">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php } ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.65rem;">
                                                <?php echo htmlspecialchars($ruoloCollab); ?> <?php echo !empty($squadraCollab) ? '('.htmlspecialchars($squadraCollab).')' : ''; ?>
                                            </div>
                                        </td>
                                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) { 
                                            $dataCellStr = sprintf('%04d-%02d-%02d', $anno_selezionato, $mese_selezionato, $g);
                                            $eventiGiorno = $mappaTurniGriglia[$uId][$g] ?? [];
                                            $eventiJsonEsc = htmlspecialchars(json_encode($eventiGiorno, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                        ?>
                                            <td class="cella-interattiva" onclick="apriModaleTurno('<?php echo htmlspecialchars($uId, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($nomeCollab, ENT_QUOTES); ?>', '<?php echo $dataCellStr; ?>', '<?php echo $eventiJsonEsc; ?>')">
                                                <div class="badge-container">
                                                    <?php if (!empty($eventiGiorno)) { 
                                                        foreach ($eventiGiorno as $ev) {
                                                            $codEv = strtoupper(trim($ev['tipo_evento'] ?? ''));
                                                            $coloreBg = $mappaColoriTurni[$codEv] ?? '#6c757d';
                                                            $isInAttesa = (strcasecmp($ev['stato'] ?? '', 'Approvato') !== 0);
                                                            $isAssenza = (($ev['fonte'] ?? '') === 'assenze');
                                                    ?>
                                                        <span class="badge-turno <?php echo $isInAttesa ? 'badge-in-attesa' : ''; ?>" style="background-color: <?php echo htmlspecialchars($coloreBg); ?>;" title="<?php echo htmlspecialchars($codEv . ' (' . ($isAssenza ? 'Assenza' : 'Turno') . ' - ' . ($ev['stato'] ?? '') . ')'); ?>">
                                                            <?php echo htmlspecialchars($codEv); ?>
                                                        </span>
                                                    <?php 
                                                        }
                                                    } 
                                                    ?>
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

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100 p-3">
                    <h5 class="card-title fs-6 fw-bold mb-3"><i class="bi bi-person-plus text-primary"></i> Assegnazione Singola</h5>
                    <form method="POST" action="turni.php?<?php echo htmlspecialchars(http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo])); ?>">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                        <input type="hidden" name="azione" value="assegna_turno">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Collaboratore</label>
                            <select name="collaboratore_id" class="form-select form-select-sm" required>
                                <option value="">-- Seleziona Operatore --</option>
                                <?php foreach ($listaCollaboratori as $collab) { ?>
                                    <option value="<?php echo htmlspecialchars($collab['id']); ?>"><?php echo htmlspecialchars($collab['nome']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Tipologia Turno</label>
                            <select name="tipologia_turno_id" class="form-select form-select-sm" required>
                                <option value="">-- Seleziona Turno --</option>
                                <?php foreach ($tipologieTurni as $tTip) { 
                                    $cBr = strtoupper(trim($tTip['codice_breve'] ?? ''));
                                    $nTrn = $tTip['nome_turno'] ?? $cBr;
                                ?>
                                    <option value="<?php echo htmlspecialchars($cBr); ?>"><?php echo htmlspecialchars($cBr); ?> - <?php echo htmlspecialchars($nTrn); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Data Turno</label>
                            <input type="date" name="data_inizio" class="form-control form-control-sm" required value="<?php echo sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Note (Opzionale)</label>
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Eventuali annotazioni">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold"><i class="bi bi-check2-circle"></i> Assegna Turno</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm h-100 p-3">
                    <h5 class="card-title fs-6 fw-bold mb-3"><i class="bi bi-robot text-success"></i> Generazione Automatica Turni (AI)</h5>
                    <form method="POST" action="turni.php?<?php echo htmlspecialchars(http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo])); ?>">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                        <input type="hidden" name="azione" value="genera_turni_ai">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Data Inizio</label>
                                <input type="date" name="data_inizio_gen" class="form-control form-control-sm" required value="<?php echo sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Data Fine</label>
                                <input type="date" name="data_fine_gen" class="form-control form-control-sm" required value="<?php echo sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese); ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Target Operatore (Lascia vuoto per tutto il reparto)</label>
                            <select name="collaboratore_gen" class="form-select form-select-sm">
                                <option value="">-- Tutto il Reparto --</option>
                                <?php foreach ($listaCollaboratori as $collab) { ?>
                                    <option value="<?php echo htmlspecialchars($collab['id']); ?>"><?php echo htmlspecialchars($collab['nome']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Turno di Partenza</label>
                                <input type="text" name="turno_partenza" class="form-control form-control-sm" placeholder="Es. M">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Sequenza personalizzata</label>
                                <input type="text" name="sequenza_turni" class="form-control form-control-sm" placeholder="M, P, N, S, R">
                            </div>
                        </div>
                        <div class="mb-3 d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="riposo_domenicale" id="riposo_domenicale" value="1" checked>
                                <label class="form-check-label small" for="riposo_domenicale">Riposo Domenicale</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="rispetta_ferie" id="rispetta_ferie" value="1" checked>
                                <label class="form-check-label small" for="rispetta_ferie">Rispetta Ferie/Assenze</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success w-100 fw-bold"><i class="bi bi-cpu"></i> Avvia Generazione Turni</button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <!-- MODALE GESTIONE TURNO CELLA -->
    <div class="modal fade" id="modalGestioneTurno" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold" id="modaleTitolo">Gestione Turno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="formGestioneTurno" action="turni.php?<?php echo htmlspecialchars(http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo])); ?>">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <div class="modal-body small">
                        <input type="hidden" name="utente_id" id="modale_utente_id">
                        <input type="hidden" name="data" id="modale_data">
                        <input type="hidden" name="turno_id" id="modale_turno_id">
                        <!-- Campo azione dinamico -->
                        <input type="hidden" name="azione" id="modale_azione" value="aggiungi_turno_cella">

                        <div class="mb-3" id="wrapperSelectValore">
                            <label class="form-label fw-bold" id="labelTipoTurno">Seleziona Tipo Turno / Evento</label>
                            <select name="valore" id="modale_valore" class="form-select form-select-sm">
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($tipologieTurni as $tTip) { 
                                    $cBr = strtoupper(trim($tTip['codice_breve'] ?? ''));
                                    $nTrn = $tTip['nome_turno'] ?? $cBr;
                                ?>
                                    <option value="<?php echo htmlspecialchars($cBr); ?>"><?php echo htmlspecialchars($cBr); ?> - <?php echo htmlspecialchars($nTrn); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Note / Annotazione</label>
                            <input type="text" name="nota_turno" id="modale_nota" class="form-control form-control-sm" placeholder="Eventuali note per questo turno">
                        </div>

                        <div id="avvisoAssenza" class="alert alert-warning py-2 mb-3" style="display: none; font-size: 0.8rem;">
                            <i class="bi bi-info-circle-fill"></i> Questo evento deriva dal modulo <strong>Assenze</strong>. La modifica o l'eliminazione diretta non è consentita da questa vista tabellare.
                        </div>

                        <!-- BOTTONI AZIONE DINAMICI -->
                        <div class="d-flex gap-2" id="containerBottoniAzione">
                            <button type="submit" id="btnElimina" class="btn btn-sm btn-outline-danger w-50 fw-bold" style="display: none;" onclick="impostaAzione('cancella_turno_cella')">
                                <i class="bi bi-trash"></i> Elimina Turno
                            </button>
                            <button type="submit" id="btnSalva" class="btn btn-sm btn-primary w-50 fw-bold" onclick="impostaAzione('modifica_turno_cella')">
                                <i class="bi bi-check-lg"></i> Aggiorna
                            </button>
                            <button type="submit" id="btnAggiungi" class="btn btn-sm btn-success w-100 fw-bold" style="display: none;" onclick="impostaAzione('aggiungi_turno_cella')">
                                <i class="bi bi-plus-circle"></i> Assegna / Aggiungi Turno
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODALE PULIZIA MESE REPARTO -->
    <div class="modal fade" id="modalPulisciTurni" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold">Conferma Pulizia Mese</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="turni.php?<?php echo htmlspecialchars(http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo])); ?>">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <input type="hidden" name="azione" value="cancella_turni_tranne_ferie">
                    <div class="modal-body small">
                        <p>Stai per eliminare tutti i turni del mese di <strong><?php echo htmlspecialchars($nomeMeseCorrente . ' ' . $anno_selezionato); ?></strong> per la selezione attiva.</p>
                        <p class="text-success fw-bold mb-0"><i class="bi bi-shield-check"></i> Ferie, permessi, 104 e corsi protetti NON verranno cancellati.</p>
                    </div>
                    <div class="modal-footer py-1">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-sm btn-danger fw-bold">Conferma Pulizia</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODALE PULIZIA MESE SINGOLO OPERATORE -->
    <div class="modal fade" id="modalPulisciSingolo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title fs-6 fw-bold">Pulisci Turni Operatore</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="turni.php?<?php echo htmlspecialchars(http_build_query(['mese' => $mese_selezionato, 'anno' => $anno_selezionato, 'reparto_id' => $reparto_selezionato, 'filtro_ruolo' => $filtro_ruolo])); ?>">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">
                    <input type="hidden" name="azione" value="cancella_turni_singolo_operatore">
                    <input type="hidden" name="utente_target_id" id="input_utente_target_id">
                    <div class="modal-body small">
                        <p>Stai per eliminare tutti i turni del mese di <strong><?php echo htmlspecialchars($nomeMeseCorrente . ' ' . $anno_selezionato); ?></strong> per l'operatore: <strong id="nomeOperatoreTarget"></strong>.</p>
                        <p class="text-success fw-bold mb-0"><i class="bi bi-shield-check"></i> Ferie, permessi e 104 protetti NON verranno cancellati.</p>
                    </div>
                    <div class="modal-footer py-1">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-sm btn-danger fw-bold">Conferma Pulizia Operatore</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function impostaAzione(azioneStr) {
            document.getElementById('modale_azione').value = azioneStr;
            // Se eliminiamo, disattivo il required sul select del valore per evitare blocchi del browser
            if (azioneStr === 'cancella_turno_cella') {
                document.getElementById('modale_valore').required = false;
            } else {
                document.getElementById('modale_valore').required = true;
            }
        }

        function apriModaleTurno(utenteId, nomeOperatore, dataStr, eventiJson) {
            document.getElementById('modale_utente_id').value = utenteId;
            document.getElementById('modale_data').value = dataStr;
            document.getElementById('modale_nota').value = '';
            
            let dataFmt = dataStr.split('-').reverse().join('/');
            let eventi = [];
            try {
                eventi = JSON.parse(eventiJson);
            } catch(e) {
                eventi = [];
            }

            let selectValore = document.getElementById('modale_valore');
            let inputTurnoId = document.getElementById('modale_turno_id');
            let btnElimina = document.getElementById('btnElimina');
            let btnSalva = document.getElementById('btnSalva');
            let btnAggiungi = document.getElementById('btnAggiungi');
            let avvisoAssenza = document.getElementById('avvisoAssenza');

            if (eventi.length > 0) {
                let evento = eventi[0];
                let isAssenza = (evento.fonte === 'assenze');

                if (isAssenza) {
                    document.getElementById('modaleTitolo').innerText = "Visualizza Assenza - " + nomeOperatore + " (" + dataFmt + ")";
                    inputTurnoId.value = '';
                    selectValore.value = evento.tipo_evento;
                    selectValore.disabled = false;
                    
                    btnElimina.style.display = 'none';
                    btnSalva.style.display = 'none';
                    btnAggiungi.style.display = 'none';
                    avvisoAssenza.style.display = 'block';
                } else {
                    let tipoEsistente = evento.tipo_evento.toUpperCase();
                    document.getElementById('modaleTitolo').innerText = "Gestione Turno - " + nomeOperatore + " (" + dataFmt + ")";
                    inputTurnoId.value = evento.id;
                    selectValore.value = evento.tipo_evento;
                    selectValore.disabled = false;
                    
                    btnElimina.style.display = 'block';
                    btnSalva.style.display = 'block';
                    btnAggiungi.style.display = 'none';
                    avvisoAssenza.style.display = 'none';

                    // Se c'è 1 solo turno (M o P), possiamo anche permettere l'aggiunta del secondo compatibile
                    if (eventi.length === 1 && (tipoEsistente === 'M' || tipoEsistente === 'P')) {
                        // Mostriamo anche il pulsante per aggiungere un secondo turno se l'utente vuole invece aggiungerne un altro
                        // Ma lasciamo attivi Elimina e Aggiorna
                    }
                }
            } else {
                document.getElementById('modaleTitolo').innerText = "Aggiungi Turno - " + nomeOperatore + " (" + dataFmt + ")";
                inputTurnoId.value = '';
                selectValore.value = '';
                selectValore.disabled = false;
                
                btnElimina.style.display = 'none';
                btnSalva.style.display = 'none';
                btnAggiungi.style.display = 'block';
                avvisoAssenza.style.display = 'none';
            }

            let modalEl = document.getElementById('modalGestioneTurno');
            let modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }

        function apriModalePulisciSingolo(utenteId, nomeOperatore) {
            document.getElementById('input_utente_target_id').value = utenteId;
            document.getElementById('nomeOperatoreTarget').innerText = nomeOperatore;
            let modalEl = document.getElementById('modalPulisciSingolo');
            let modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    </script>
</body>
</html>
