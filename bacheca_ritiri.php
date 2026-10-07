<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// /blood/bacheca_ritiri.php

// Forza il browser a non usare la cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$nome_operatore = $_SESSION['utente']['nome'] . ' ' . $_SESSION['utente']['cognome'];
$ruolo_utente = $_SESSION['utente']['ruolo'] ?? '';

// Definizione controllo sola lettura (rileva se il ruolo è viewer, sola_lettura o lettura)
$is_read_only = (strtolower($ruolo_utente) === 'viewer' || strtolower($ruolo_utente) === 'sola_lettura' || strtolower($ruolo_utente) === 'lettura');

require_once __DIR__ . '/api_helper_sangue.php';

// Funzione per mascherare il nome del paziente per privacy (es. "Rossi Mario" -> "R. M.")
function maschera_paziente($testo_note) {
    return preg_replace_callback('/Paziente:\s*([^\s-]+)(?:\s+([^\s-]+))?/i', function($matches) {
        $cognome = $matches[1] ?? '';
        $nome = $matches[2] ?? '';
        
        $iniziale_cognome = mb_substr($cognome, 0, 1) . '.';
        $iniziale_nome = !empty($nome) ? mb_substr($nome, 0, 1) . '.' : '';
        
        return "Paziente: " . trim($iniziale_cognome . ' ' . $iniziale_nome);
    }, $testo_note);
}

// Gestione delle richieste POST (Consegna SIT o Segna come Ritirato)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($is_read_only) {
        die("Accesso negato: l'utente corrente è in sola lettura.");
    }

    date_default_timezone_set('Europe/Rome');

    // Gestione Conferma Consegna al SIT
    if (isset($_POST['id_consegna_sit'])) {
        $id_da_consegnare = $_POST['id_consegna_sit'];
        $dati_consegna = [
            'consegnato_sit' => true,
            'consegnato_il' => date('Y-m-d H:i:s'),
            'consegnato_da' => $nome_operatore
        ];
        
        $risposta = esegui_patch_api('ritiri_sangue?id=eq.' . $id_da_consegnare, $dati_consegna);
        
        if (isset($risposta['code']) || $risposta === null) {
            die("Errore API: Impossibile registrare la consegna al SIT. Dettaglio: " . print_r($risposta, true));
        }
        
        er("Location: bacheca_ritiri.php");
        exit;
    }

    // Gestione Segna come Ritirato
    if (isset($_POST['id_ritiro'])) {
        $id_da_aggiornare = $_POST['id_ritiro'];
        $note_esistenti = $_POST['note_originali'] ?? '';
        $note_nuove = !empty($_POST['note_ritiro']) ? trim($_POST['note_ritiro']) : '';
        $note_finali = $note_esistenti;
        if (!empty($note_nuove)) {
            $note_finali .= ($note_esistenti ? " | " : "") . "" . $note_nuove;
        }
        
        $dati_aggiornamento = [
            'stato' => 'Ritirato',
            'accettato_da' => $nome_operatore,
            'ritirato_il' => date('Y-m-d H:i:s'),
            'note' => $note_finali,
            'notifica_inviata' => true
        ];
        
        $risposta = esegui_patch_api('ritiri_sangue?id=eq.' . $id_da_aggiornare, $dati_aggiornamento);
        
        if (isset($risposta['code']) || $risposta === null) {
            die("Errore API: Impossibile aggiornare. Verifica le credenziali nel file config_sangue.php. Dettaglio: " . print_r($risposta, true));
        }
        
        er("Location: bacheca_ritiri.php");
        exit;
    }
}

// Recupero dati filtrando direttamente per gli ultimi 3 giorni tramite PostgREST (gte)
date_default_timezone_set('Europe/Rome');
$data_limite_settimana = date('Y-m-d\T00:00:00', strtotime('-3 days'));
$dati = esegui_get_api("ritiri_sangue?created_at=gte.{$data_limite_settimana}&order=created_at.desc");

// --- CONTEGGIO RITIRI EFFETTUATI PER REPARTO NEGLI ULTIMI 3 GIORNI ---
$conteggio_ritiri_reparto = [];
if (is_array($dati)) {
    foreach ($dati as $item) {
        if (!empty($item['reparto']) && ($item['stato'] === 'Ritirato' || !empty($item['ritirato_il']))) {
            $rep_key = trim($item['reparto']);
            if (!isset($conteggio_ritiri_reparto[$rep_key])) {
                $conteggio_ritiri_reparto[$rep_key] = 0;
            }
            $conteggio_ritiri_reparto[$rep_key]++;
        }
    }
}

// --- MOTORE DI PREDIZIONE E ORDINAMENTO ASSOLUTO (OGGI) ---
$data_odierna = date('Y-m-d');

if (is_array($dati) && count($dati) > 0) {
    foreach ($dati as &$r) {
        $reparto_corrente = trim($r['reparto'] ?? '');
        $testo_note_item = $r['note'] ?? '';
        
        // Verifica se la richiesta appartiene alla data odierna
        $data_creazione_item = '';
        if (!empty($r['created_at'])) {
            $ts_c = strtotime($r['created_at']);
            if ($ts_c !== false) {
                $data_creazione_item = date('Y-m-d', $ts_c + 7200);
            }
        }
        
        $is_oggi = ($data_creazione_item === $data_odierna);
        $r['_is_oggi'] = $is_oggi;

        // Estrazione valore emoglobina (se non presente, diamo 999.0 in modo che finisca in fondo)
        $val_emoglobina = 999.0;
        $ha_emoglobina_specificata = false;
        if (!empty($testo_note_item) && preg_match('/Emoglobina:\s*([0-9]+([.,][0-9]+)?)/i', $testo_note_item, $m_emo)) {
            $val_emoglobina = floatval(str_replace(',', '.', $m_emo[1]));
            $ha_emoglobina_specificata = true;
        }
        $r['_val_emoglobina'] = $val_emoglobina;
        $r['_emoglobina_critica'] = ($ha_emoglobina_specificata && $val_emoglobina < 7.0);

        // Quante volte è andato questo reparto negli ultimi 3 giorni (default 0)
        $num_volte_andato = $conteggio_ritiri_reparto[$reparto_corrente] ?? 0;
        $r['_num_volte_andato'] = $num_volte_andato;
        $r['_predizione_consigliato'] = $is_oggi;
    }
    unset($r);

    // Ordinamento rigoroso:
    // 1. Prima le richieste di OGGI rispetto a quelle passate.
    // 2. Tra quelle di OGGI: ordinamento per valore di emoglobina in assoluto più basso (crescente: es. 6.2 prima di 8.5, 9.4 prima di 11).
    // 3. A parità di emoglobina, sale chi è andato di meno (numero di ritiri recenti minore).
    // 4. Per i giorni passati, mantiene l'ordine cronologico standard.
    usort($dati, function($a, $b) {
        $oggi_a = $a['_is_oggi'] ? 1 : 0;
        $oggi_b = $b['_is_oggi'] ? 1 : 0;

        if ($oggi_a !== $oggi_b) {
            return $oggi_b <=> $oggi_a; // Prima quelle di oggi
        }

        if ($oggi_a && $oggi_b) {
            // Criterio 1: Emoglobina più bassa in assoluto (valore numerico minore = priorità maggiore)
            $emo_a = $a['_val_emoglobina'];
            $emo_b = $b['_val_emoglobina'];
            if ($emo_a !== $emo_b) {
                return $emo_a <=> $emo_b;
            }

            // Criterio 2: A parità di emoglobina, chi è andato di meno (numero ritiri minore = priorità maggiore)
            $andato_a = $a['_num_volte_andato'];
            $andato_b = $b['_num_volte_andato'];
            if ($andato_a !== $andato_b) {
                return $andato_a <=> $andato_b;
            }
        }

        return 0;
    });
}

// --- POPOLIAMO IL BANNER DELLE PREDIZIONI ORA CHE $dati È ORDINATO CORRETTAMENTE ---
$consigliati_oggi = [];
if (is_array($dati)) {
    foreach ($dati as $r) {
        if (!empty($r['_is_oggi'])) {
            $reparto_corrente = trim($r['reparto'] ?? '');
            if (!isset($consigliati_oggi[$reparto_corrente])) {
                $val_emo = $r['_val_emoglobina'];
                $num_andato = $r['_num_volte_andato'];
                $motivo_str = ($val_emo !== 999.0) ? "Hb: $val_emo g/dL" : "Nessuna Hb specificata";
                $motivo_str .= " | Ritiri recenti: $num_andato";
                $consigliati_oggi[$reparto_corrente] = $motivo_str;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="manifest" href="manifest.json">
    <title>Bacheca Ritiri - Emoteca Pellegrini</title>
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --bg-main: #f1f5f9;
            --surface: #ffffff;
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #cbd5e1;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --success: #059669;
            --success-bg: #ecfdf5;
            --warning-bg: #fef3c7;
            --warning-text: #78350f;
            --info-bg: #e0f2fe;
            --info-text: #0369a1;
            --prediction-bg: #f5f3ff;
            --prediction-border: #8b5cf6;
            --prediction-text: #6d28d9;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 12px;
            background: var(--bg-main);
            margin: 0;
            color: var(--text-main);
            font-size: 16px;
            line-height: 1.5;
        }

        header {
            background: var(--surface);
            padding: 16px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 16px;
            border: 1px solid var(--border);
            border-top: 4px solid var(--primary);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        h1 {
            font-size: 1.25rem;
            color: var(--text-main);
            margin: 0;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .nav-links {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-nav {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
        }

        .btn-logout { background: #e2e8f0; color: var(--text-muted); border: 1px solid #cbd5e1; }
        .btn-pw { background: var(--info-bg); color: var(--info-text); border: 1px solid #bae6fd; }
        .btn-notif { background: var(--warning-bg); color: var(--warning-text); border: 1px solid #fcd34d; }

        .actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .btn-action {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: var(--surface);
            color: var(--primary-dark);
            padding: 14px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            border: 1px solid var(--border);
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .btn-action.primary { background: var(--primary); color: white; border: none; }
        .btn-action.secondary { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
        .btn-action.warning { background: #fef3c7; color: #92400e; border-color: #f59e0b; }
        .btn-action.turni { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; grid-column: span 2; }

        /* Banner Globale Unico in Alto */
        .global-prediction-banner {
            background: var(--prediction-bg);
            border: 1px solid var(--prediction-border);
            border-left: 6px solid #7c3aed;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
        .global-prediction-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--prediction-text);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .global-prediction-list {
            margin: 6px 0 0 0;
            padding-left: 20px;
            font-size: 0.88rem;
            color: #4c1d95;
        }

        .card {
            background: var(--surface);
            padding: 16px;
            margin-bottom: 14px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
        }

        .card.stato-attesa-sit { border-left: 6px solid var(--danger); }
        .card.stato-consegnato-sit { border-left: 6px solid #d97706; }
        .card.fatto { border-left: 6px solid var(--success); opacity: 0.95; background: #fafafa; }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .card-info { margin-bottom: 6px; font-size: 0.95rem; }
        .label { color: var(--text-muted); font-weight: 700; font-size: 0.75rem; text-transform: uppercase; }
        .valore-reparto { font-size: 1.15rem; font-weight: 800; color: var(--text-main); }
        .valore-turno { display: inline-block; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 0.85rem; }
        .orario { color: var(--text-muted); font-size: 0.8rem; font-style: italic; font-weight: 500; }

        .note-box {
            margin-top: 12px;
            padding: 12px;
            background: var(--warning-bg);
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 4px solid #d97706;
            color: var(--warning-text);
        }

        .note-critica { background: var(--danger-bg) !important; border-left-color: var(--danger) !important; color: #991b1b !important; font-weight: 600; }
        .avviso-piastrine { background: var(--info-bg) !important; border-left-color: var(--primary) !important; color: var(--info-text) !important; font-weight: 600; }
        .avviso-emazie-plasma { background: var(--success-bg) !important; border-left-color: var(--success) !important; color: #065f46 !important; font-weight: 600; }

        .operatore-box {
            margin-top: 12px;
            padding: 10px;
            background: var(--success-bg);
            border-radius: 6px;
            font-size: 0.85rem;
            color: #065f46;
            font-weight: 600;
            border: 1px solid #a7f3d0;
        }

        .consegnato-box {
            margin-top: 12px;
            padding: 10px;
            background: #fef3c7;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #78350f;
            font-weight: 600;
            border: 1px solid #fde68a;
            border-left: 4px solid #d97706;
        }

        .input-note {
            width: 100%;
            padding: 12px;
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #ffffff;
            font-family: inherit;
            font-size: 0.95rem;
            resize: vertical;
        }

        .btn-ritirato {
            width: 100%;
            padding: 14px;
            margin-top: 10px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
        }

        .btn-consegna {
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .badge-readonly {
            background: #e2e8f0;
            color: #334155;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 16px;
            border-left: 4px solid var(--text-muted);
            border: 1px solid var(--border);
        }

        .readonly-notice, .readonly-consegnato-notice {
            margin-top: 12px;
            font-size: 0.85rem;
            color: #78350f;
            font-weight: 600;
            text-align: center;
            background: var(--warning-bg);
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #fcd34d;
        }

        .empty-state {
            text-align: center;
            color: var(--text-muted);
            padding: 40px 20px;
            background: var(--surface);
            border-radius: 10px;
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <h1>Richieste emocomponenti</h1>
            <div class="nav-links">
                <button onclick="richiediPermessoNotifiche()" class="btn-nav btn-notif" title="Attiva notifiche push">🔔 Notifiche</button>
                <?php if (!$is_read_only): ?>
                    <a href="cambia_password.php" class="btn-nav btn-pw">🔑 Password</a>
                <?php endif; ?>
                <a href="logout.php" class="btn-nav btn-logout">🚪 Esci</a>
            </div>
        </div>
    </header>

    <?php if ($is_read_only): ?>
        <div class="badge-readonly">⚠ (Orario consegna rich. ordinarie: h 12.15 e h 16.30 - Sola lettura: <?php echo htmlspecialchars($nome_operatore); ?>)</div>
        <div class="actions-grid">
            <a href="non_assegnate.php" class="btn-action warning">Richieste non assegnate</a>
            <a href="emoteca.php" class="btn-action primary">📦 Emoteca / Scorta</a>
        </div>
    <?php else: ?>
        <div class="actions-grid">
            <a href="nuovo_ritiro.php" class="btn-action primary">+ Inserisci ritiro manuale</a>
            <a href="carico_scarico.php" class="btn-action">Gestione Carico/Scarico</a>
            <a href="storico_completo.php" class="btn-action secondary">Registro Movimenti</a>
            <a href="non_assegnate.php" class="btn-action warning">Richieste non assegnate</a>
            <a href="emovigilanza.php" class="btn-action warning">Emovigilanze da ritirare</a>
            <a href="emoteca.php" class="btn-action primary">📦 Emoteca / Scorta</a>
            <a href="emoteca_turni.php" class="btn-action turni">Turni pomeridiani personale Blocco Operatorio</a>
            <a href="inserimento_richieste.php" class="btn-action turni">Ceck Prelievi</a>
        </div>
    <?php endif; ?>

    <!-- BANNER UNICO IN ALTO CON L'ORDINAMENTO PREFERENZIALE ODIERNO -->
    <?php if (!empty($consigliati_oggi)): ?>
        <div class="global-prediction-banner">
            <div class="global-prediction-title">
                🤖 Ordine Consigliato per il ritiro sacche nel turno pomeridiano.
            </div>
            <div style="font-size: 0.85rem; color: #5b21b6; margin-bottom: 4px;">
                Criteri applicati: 1) Emoglobina più bassa in assoluto | 2) A parità di Hb, chi è andato di meno nei giorni scorsi.
            </div>
            <ul class="global-prediction-list">
                <?php foreach ($consigliati_oggi as $rep_cons => $motivo_cons): ?>
                    <li><strong><?php echo htmlspecialchars($rep_cons); ?></strong> <span style="font-size: 0.8rem; opacity: 0.9;">(<?php echo htmlspecialchars($motivo_cons); ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php if (is_array($dati) && count($dati) > 0): foreach ($dati as $r): ?>
        <?php
            $consegnato_sit = !empty($r['consegnato_sit']);

            $is_emoglobina_critica = $r['_emoglobina_critica'] ?? false;
            $testo_note = $r['note'] ?? '';

            $is_piastrine = false;
            if (!empty($testo_note) && (
                stripos($testo_note, 'Piastrine') !== false || 
                stripos($testo_note, 'Concentrato piastrinico') !== false ||
                stripos($testo_note, 'Tipo: Piastrine') !== false ||
                stripos($testo_note, 'Tipo: Concentrato piastrinico') !== false
            )) {
                $is_piastrine = true;
            }

            $is_emazie_plasma = false;
            if (!empty($testo_note) && (
                stripos($testo_note, 'Emazie Concentrate') !== false || 
                stripos($testo_note, 'Plasma') !== false ||
                stripos($testo_note, 'Tipo: Emazie Concentrate') !== false ||
                stripos($testo_note, 'Tipo: Plasma') !== false
            )) {
                $is_emazie_plasma = true;
            }

            $testo_note_visualizzato = maschera_paziente($testo_note);

            $created_formatted = 'N/D';
            if (!empty($r['created_at'])) {
                $ts_created = strtotime($r['created_at']);
                if ($ts_created !== false) {
                    $created_formatted = date('Y-m-d H:i:s', $ts_created + 7200);
                } else {
                    $created_formatted = $r['created_at'];
                }
            }

            $orario_ritiro_formattato = 'N/D';
            if (!empty($r['ritirato_il'])) {
                $ts_ritiro = strtotime($r['ritirato_il']);
                if ($ts_ritiro !== false) {
                    $orario_ritiro_formattato = date('Y-m-d H:i:s', $ts_ritiro);
                } else {
                    $orario_ritiro_formattato = $r['ritirato_il'];
                }
            }

            $orario_consegna_formattato = 'N/D';
            if (!empty($r['consegnato_il'])) {
                $ts_cons = strtotime($r['consegnato_il']);
                if ($ts_cons !== false) {
                    $orario_consegna_formattato = date('Y-m-d H:i:s', $ts_cons);
                } else {
                    $orario_consegna_formattato = $r['consegnato_il'];
                }
            }

            if ($r['stato'] == 'Ritirato') {
                $classe_card = 'fatto';
            } elseif ($consegnato_sit) {
                $classe_card = 'stato-consegnato-sit';
            } else {
                $classe_card = 'stato-attesa-sit';
            }
        ?>
        <div class="card <?php echo $classe_card; ?>">
            <div class="card-header-row">
                <div class="card-info" style="margin-bottom:0;">
                    <span class="label">Reparto</span><br>
                    <span class="valore-reparto"><?php echo htmlspecialchars($r['reparto']); ?></span>
                </div>
                <div style="text-align: right;">
                    <span class="label">Turno</span><br>
                    <span class="valore-turno"><?php echo htmlspecialchars($r['turno_successivo']); ?></span>
                </div>
            </div>
            
            <div class="orario" style="margin-bottom: 8px;">Inserito il: <?php echo htmlspecialchars($created_formatted); ?></div>
            
            <?php if ($is_piastrine): ?>
                <div class="note-box avviso-piastrine">
                    🌡️ <strong>Trasporto Piastrine:</strong> Temperatura ambiente e possibilmente in agitazione! (No ghiaccio, No frigo)
                </div>
            <?php endif; ?>

            <?php if ($is_emazie_plasma): ?>
                <div class="note-box avviso-emazie-plasma">
                    ❄️ <strong>Trasporto Emazie/Plasma:</strong> Temperatura tra 4-6 °C (± 2 °C. Se Plasma avvisare alla consegna che devono essere utilizzate quanto Prima!)
                </div>
            <?php endif; ?>

            <?php if (!empty($testo_note_visualizzato)): ?>
                <div class="note-box <?php echo $is_emoglobina_critica ? 'note-critica' : ''; ?>">
                    <?php if ($is_emoglobina_critica): ?>
                        <div style="font-size: 1rem; margin-bottom: 4px;">🚨 <strong>ATTENZIONE: Emoglobina Bassa! (< 7 g/dL)</strong></div>
                    <?php endif; ?>
                    <strong>Note:</strong> <?php echo htmlspecialchars($testo_note_visualizzato); ?>
                </div>
            <?php endif; ?>

            <?php if ($consegnato_sit): ?>
                <div class="consegnato-box">
                    📦 Consegnato al SIT da: <strong><?php echo htmlspecialchars($r['consegnato_da'] ?? 'N/D'); ?></strong><br>
                    <span style="font-size: 0.75rem; color: #78350f; opacity: 0.85;">Data consegna: <?php echo htmlspecialchars($orario_consegna_formattato); ?></span>
                </div>
            <?php elseif ($r['stato'] != 'Ritirato'): ?>
                <?php if (!$is_read_only): ?>
                    <form method="POST">
                        <input type="hidden" name="id_consegna_sit" value="<?php echo htmlspecialchars($r['id']); ?>">
                        <button type="submit" class="btn-consegna">📦 Conferma: Consegnato al SIT (<?php echo htmlspecialchars($nome_operatore); ?>)</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($r['stato'] == 'Ritirato' && !empty($r['accettato_da'])): ?>
                <div class="operatore-box">
                    ✓ Sacca ritirata. Procedura validata da: <strong><?php echo htmlspecialchars($r['accettato_da']); ?></strong><br>
                    <span style="font-size: 0.75rem; color: #065f46; opacity: 0.85;">Data: <?php echo htmlspecialchars($orario_ritiro_formattato); ?></span>
                </div>
            <?php elseif ($r['stato'] == 'Da ritirare'): ?>
                <?php if (!$is_read_only): ?>
                    <?php if ($consegnato_sit): ?>
                        <form method="POST">
                            <input type="hidden" name="id_ritiro" value="<?php echo htmlspecialchars($r['id']); ?>">
                            <input type="hidden" name="note_originali" value="<?php echo htmlspecialchars($r['note'] ?? ''); ?>">
                            <textarea name="note_ritiro" class="input-note" placeholder="Eventuali note sul ritiro (es. operatore, temperatura...)" rows="2"></textarea>
                            <button type="submit" class="btn-ritirato">✓ Segna come Ritirato</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!$consegnato_sit): ?>
                        <div class="readonly-notice">
                            Stato: richiesta presa in carico in attesa di consegna al S. Paolo.
                        </div>
                    <?php else: ?>
                        <div class="readonly-consegnato-notice">
                            Stato: richiesta consegnata in attesa di assegnazione (Contattare il SIT San Paolo int. 7872)
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; else: ?>
        <div class="empty-state">
            <p style="margin:0; font-weight: 500;">Nessun ritiro presente in bacheca negli ultimi 3 giorni.</p>
        </div>
    <?php endif; ?>

    <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-messaging-compat.js"></script>

    <script>
        const firebaseConfig = {
            apiKey: "AIzaSyD0RidVKjyRvYFd4ootXi5VWM28qVezwpo",
            authDomain: "emotecaapp.firebaseapp.com",
            projectId: "emotecaapp",
            storageBucket: "emotecaapp.firebasestorage.app",
            messagingSenderId: "955424631104",
            appId: "1:955424631104:web:d43214d2cd055b426eb2a5"
        };

        if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
        }
        const messaging = firebase.messaging();

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('firebase-messaging-sw.js')
                .then((registration) => {
                    console.log('Service Worker registrato:', registration.scope);
                })
                .catch((err) => {
                    console.log('Service Worker fallito: ', err);
                });
        }

        function richiediPermessoNotifiche() {
            if (!('Notification' in window)) {
                alert('Questo browser non supporta le notifiche desktop.');
                return;
            }

            Notification.requestPermission().then((permission) => {
                if (permission === 'granted') {
                    messaging.getToken({ 
                        vapidKey: 'BKhRHAH4cctir9Lo0B_KJsfYbv1YZ9FpmMWoXO7V13FL1aEgwNLy_SsG3AgnOu273Y2GphPWiXKZzZ9rVIBznZ8' 
                    }).then((currentToken) => {
                        if (currentToken) {
                            salvaTokenNelDatabase(currentToken);
                        }
                    }).catch((err) => {
                        console.error('Errore recupero token:', err);
                    });
                } else {
                    alert('Permesso per le notifiche negato.');
                }
            });
        }

        function salvaTokenNelDatabase(token) {
            fetch('salva_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fcm_token: token }),
            })
            .then(response => response.json())
            .then(data => {
                alert('Notifiche push attivate con successo su questo dispositivo!');
            })
            .catch((error) => {
                console.error('Errore salvataggio token:', error);
            });
        }

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                window.location.reload(true);
            }
        });
    </script>
</body>
</html>
