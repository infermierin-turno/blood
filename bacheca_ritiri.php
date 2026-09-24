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
    // Cerca "Paziente: [Cognome] [Nome]" all'interno delle note
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
        
        header("Location: bacheca_ritiri.php");
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
            'notifica_inviata' => true  // <-- Imposta la notifica a true per evitare l'invio della mail
        ];
        
        // Esecuzione PATCH con controllo errori
        $risposta = esegui_patch_api('ritiri_sangue?id=eq.' . $id_da_aggiornare, $dati_aggiornamento);
        
        // Debug: se l'API restituisce un errore, lo mostriamo invece di ricaricare
        if (isset($risposta['code']) || $risposta === null) {
            die("Errore API: Impossibile aggiornare. Verifica le credenziali nel file config_sangue.php. Dettaglio: " . print_r($risposta, true));
        }
        
        header("Location: bacheca_ritiri.php");
        exit;
    }
}

// Recupero dati filtrando direttamente per gli ultimi 3 giorni tramite PostgREST (gte)
date_default_timezone_set('Europe/Rome');
$data_limite_settimana = date('Y-m-d\T00:00:00', strtotime('-3 days'));
$dati = esegui_get_api("ritiri_sangue?created_at=gte.{$data_limite_settimana}&order=created_at.desc");
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bacheca Ritiri - Emoteca Pellegrini</title>
    <style>
        :root {
            /* Palette Clinica e Ospedalera Rinnovata */
            --primary: #0284c7;        /* Blu istituzionale / sanitario pulito */
            --primary-dark: #0369a1;
            --bg-main: #f1f5f9;       /* Grigio chiaro asettico e riposante per reparti */
            --surface: #ffffff;
            --text-main: #0f172a;     /* Testo scuro ad alto contrasto per leggibilità rapida */
            --text-muted: #475569;
            --border: #cbd5e1;        /* Bordi definiti ma non aggressivi */
            
            /* Codici Colore Semidatori Sanitari */
            --danger: #dc2626;        /* Rosso clinico allerta (In attesa / Critico) */
            --danger-bg: #fef2f2;
            --success: #059669;       /* Verde ospedaliero sicurezza (Completato / Validato) */
            --success-bg: #ecfdf5;
            --warning-bg: #fef3c7;    /* Giallo ambra / Ocra (In transito / Consegnato SIT) */
            --warning-text: #78350f;
            --info-bg: #e0f2fe;       /* Azzurro diagnostico (Piastrine / Info) */
            --info-text: #0369a1;
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
            transition: background 0.2s;
        }

        .btn-logout { background: #e2e8f0; color: var(--text-muted); border: 1px solid #cbd5e1; }
        .btn-logout:hover { background: #cbd5e1; }
        .btn-pw { background: var(--info-bg); color: var(--info-text); border: 1px solid #bae6fd; }

        .actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .actions-grid.full {
            grid-template-columns: 1fr;
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
            transition: all 0.2s;
        }

        .btn-action:hover {
            background: #f8fafc;
            border-color: var(--primary);
        }

        .btn-action.primary {
            background: var(--primary);
            color: white;
            border: none;
            grid-column: span 2;
        }
        .btn-action.primary:hover {
            background: var(--primary-dark);
        }

        .btn-action.secondary {
            background: #f3e8ff;
            color: #6b21a8;
            border-color: #d8b4fe;
        }

        .btn-action.warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #f59e0b;
            grid-column: span 1;
        }

        .btn-action.turni {
            background: #ecfdf5;
            color: #065f46;
            border-color: #a7f3d0;
            grid-column: span 2;
        }
        .btn-action.turni:hover {
            background: #d1fae5;
        }

        /* Stati dei bordi della card con standard clinico ad alto contrasto */
        .card {
            background: var(--surface);
            padding: 16px;
            margin-bottom: 14px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
        }

        /* 1. In attesa di consegna al SIT -> Bordo Sinistro ROSSO CLINICO */
        .card.stato-attesa-sit {
            border-left: 6px solid var(--danger);
        }

        /* 2. Consegnata al SIT / Da ritirare -> Bordo Sinistro GIALLO AMBRA OCRA */
        .card.stato-consegnato-sit {
            border-left: 6px solid #d97706;
        }

        /* 3. Ritirata -> Bordo Sinistro VERDE OSPEDALIERO */
        .card.fatto {
            border-left: 6px solid var(--success);
            opacity: 0.95;
            background: #fafafa;
        }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .card-info {
            margin-bottom: 6px;
            font-size: 0.95rem;
        }

        .label {
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .valore-reparto {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .valore-turno {
            display: inline-block;
            background: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .orario {
            color: var(--text-muted);
            font-size: 0.8rem;
            font-style: italic;
            font-weight: 500;
        }

        .note-box {
            margin-top: 12px;
            padding: 12px;
            background: var(--warning-bg);
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 4px solid #d97706;
            color: var(--warning-text);
        }

        .note-critica {
            background: var(--danger-bg) !important;
            border-left-color: var(--danger) !important;
            color: #991b1b !important;
            font-weight: 600;
        }

        .avviso-piastrine {
            background: var(--info-bg) !important;
            border-left-color: var(--primary) !important;
            color: var(--info-text) !important;
            font-weight: 600;
        }

        .avviso-emazie-plasma {
            background: var(--success-bg) !important;
            border-left-color: var(--success) !important;
            color: #065f46 !important;
            font-weight: 600;
        }

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
            box-sizing: border-box;
        }

        .input-note:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
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
            box-shadow: 0 2px 4px rgba(5, 150, 105, 0.2);
            transition: background 0.2s;
        }

        .btn-ritirato:hover {
            background: #047857;
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
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.2);
            transition: background 0.2s;
        }

        .btn-consegna:hover {
            background: var(--primary-dark);
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

        .readonly-notice {
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

        .readonly-consegnato-notice {
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
                <?php if (!$is_read_only): ?>
                    <a href="cambia_password.php" class="btn-nav btn-pw">🔑 Password</a>
                <?php endif; ?>
                <a href="logout.php" class="btn-nav btn-logout">🚪 Esci</a>
            </div>
        </div>
    </header>

    <?php if ($is_read_only): ?>
        <div class="badge-readonly">⚠️ (Orario consegna rich. ordinarie: h 12.15 e h 16.30 - Stati - Rosso: in attesa consegna al S. Paolo - Giallo: In attesa assegnazione - Verde: Ritirata e Validata. Sola lettura: <?php echo htmlspecialchars($nome_operatore); ?>)</div>
        <div class="actions-grid">
            <a href="non_assegnate.php" class="btn-action warning">Richieste non assegnate</a>
            <a href="emoteca.php" class="btn-action primary" style="grid-column: span 1;">📦 Emoteca / Scorta</a>
        </div>
    <?php else: ?>
        <div class="actions-grid">
            <a href="nuovo_ritiro.php" class="btn-action primary">+ Inserisci ritiro manuale</a>
            <a href="carico_scarico.php" class="btn-action">Gestione Carico/Scarico</a>
            <a href="storico_completo.php" class="btn-action secondary">Registro Movimenti</a>
            <a href="non_assegnate.php" class="btn-action warning">Richieste non assegnate</a>
            <a href="emovigilanza.php" class="btn-action warning">Emovigilanze da ritirare</a>
            <a href="emoteca.php" class="btn-action primary" style="grid-column: span 2;">📦 Emoteca / Scorta</a>
            <a href="emoteca_turni.php" class="btn-action turni">Turni pomeridiani personale Blocco Operatorio</a>
            <a href="inserimento_richieste.php" class="btn-action turni">Ceck Prelievi</a>
        </div>
    <?php endif; ?>
    
    <?php if (is_array($dati) && count($dati) > 0): foreach ($dati as $r): ?>
        <?php
            $consegnato_sit = !empty($r['consegnato_sit']);

            // Controllo se nelle note è presente un valore di emoglobina inferiore a 7
            $is_emoglobina_critica = false;
            $testo_note = $r['note'] ?? '';
            if (!empty($testo_note) && preg_match('/Emoglobina:\s*([0-9]+([.,][0-9]+)?)/i', $testo_note, $matches)) {
                $val_emo = floatval(str_replace(',', '.', $matches[1]));
                if ($val_emo < 7.0) {
                    $is_emoglobina_critica = true;
                }
            }

            // Controllo se il tipo di emocomponente o le note indicano Piastrine / Concentrato piastrinico
            $is_piastrine = false;
            if (!empty($testo_note) && (
                stripos($testo_note, 'Piastrine') !== false || 
                stripos($testo_note, 'Concentrato piastrinico') !== false ||
                stripos($testo_note, 'Tipo: Piastrine') !== false ||
                stripos($testo_note, 'Tipo: Concentrato piastrinico') !== false
            )) {
                $is_piastrine = true;
            }

            // Controllo se il tipo di emocomponente o le note indicano Emazie Concentrate o Plasma
            $is_emazie_plasma = false;
            if (!empty($testo_note) && (
                stripos($testo_note, 'Emazie Concentrate') !== false || 
                stripos($testo_note, 'Plasma') !== false ||
                stripos($testo_note, 'Tipo: Emazie Concentrate') !== false ||
                stripos($testo_note, 'Tipo: Plasma') !== false
            )) {
                $is_emazie_plasma = true;
            }

            // Mascheramento del nome paziente per privacy nelle note visualizzate
            $testo_note_visualizzato = maschera_paziente($testo_note);

            // Conversione corretta dell'orario di inserimento (created_at da UTC a ora italiana +2h)
            $created_formatted = 'N/D';
            if (!empty($r['created_at'])) {
                $ts_created = strtotime($r['created_at']);
                if ($ts_created !== false) {
                    $created_formatted = date('Y-m-d H:i:s', $ts_created + 7200);
                } else {
                    $created_formatted = $r['created_at'];
                }
            }

            // Orario di ritiro salvato in locale
            $orario_ritiro_formattato = 'N/D';
            if (!empty($r['ritirato_il'])) {
                $ts_ritiro = strtotime($r['ritirato_il']);
                if ($ts_ritiro !== false) {
                    $orario_ritiro_formattato = date('Y-m-d H:i:s', $ts_ritiro);
                } else {
                    $orario_ritiro_formattato = $r['ritirato_il'];
                }
            }

            // Orario di consegna SIT salvato in locale
            $orario_consegna_formattato = 'N/D';
            if (!empty($r['consegnato_il'])) {
                $ts_cons = strtotime($r['consegnato_il']);
                if ($ts_cons !== false) {
                    $orario_consegna_formattato = date('Y-m-d H:i:s', $ts_cons);
                } else {
                    $orario_consegna_formattato = $r['consegnato_il'];
                }
            }

            // Determinazione della classe CSS della card in base allo stato
            if ($r['stato'] == 'Ritirato') {
                $classe_card = 'fatto'; // Verde ospedaliero
            } elseif ($consegnato_sit) {
                $classe_card = 'stato-consegnato-sit'; // Giallo ocra / ambra
            } else {
                $classe_card = 'stato-attesa-sit'; // Rosso clinico
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

            <!-- Sezione Consegna al SIT -->
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

    <script>
        // Ricaricamento soft basato su visibilitychange:
        // Ricarica la pagina appena l'utente riprende in mano il dispositivo/tablet e riapre la scheda del browser.
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                window.location.reload(true);
            }
        });
    </script>
</body>
</html>
