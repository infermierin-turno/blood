<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// /blood/richieste_consegnate_non_ritirate.php

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$nome_operatore = $_SESSION['utente']['nome'] . ' ' . $_SESSION['utente']['cognome'];

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

// Calcoliamo la data di inizio degli ultimi 2 giorni (escludendo l'orario o partendo dall'inizio di ieri)
date_default_timezone_set('Europe/Rome');
$data_limite = date('Y-m-d', strtotime('-2 days')) . 'T00:00:00';

// Chiamata API a Supabase con filtri multipli:
// 1. created_at >= $data_limite (ultimi 2 giorni + oggi)
// 2. consegnato_sit = eq.true (consegnate al SIT)
// 3. stato = ne.Ritirato (ma non ancora ritirate)
$endpoint = "ritiri_sangue?created_at=gte." . urlencode($data_limite) . "&consegnato_sit=eq.true&stato=neq.Ritirato&order=created_at.desc";
$dati = esegui_get_api($endpoint);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Consegnate non ritirate - Emoteca Pellegrini</title>
    <style>
        :root {
            --primary: #0f766e;
            --bg-main: #f8fafc;
            --surface: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --warning-bg: #fffbeb;
            --warning-text: #b45309;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

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
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 16px;
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            font-size: 1.1rem;
            color: var(--text-main);
            margin: 0;
            font-weight: 700;
        }

        .btn-back {
            background: #f1f5f9;
            color: var(--text-muted);
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid var(--border);
        }

        .card {
            background: var(--surface);
            padding: 16px;
            margin-bottom: 14px;
            border-radius: 12px;
            border-left: 5px solid #f59e0b;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border-top: 1px solid var(--border);
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .label {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .valore-reparto {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .valore-turno {
            display: inline-block;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .orario {
            color: var(--text-muted);
            font-size: 0.8rem;
            font-style: italic;
            margin-bottom: 8px;
        }

        .note-box {
            margin-top: 10px;
            padding: 10px;
            background: var(--warning-bg);
            border-radius: 8px;
            font-size: 0.9rem;
            border-left: 3px solid #f59e0b;
            color: var(--warning-text);
        }

        .consegnato-box {
            margin-top: 10px;
            padding: 10px;
            background: #eff6ff;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #1e40af;
            font-weight: 600;
            border: 1px solid #bfdbfe;
            border-left: 3px solid #3b82f6;
        }

        .empty-state {
            text-align: center;
            color: var(--text-muted);
            padding: 40px 20px;
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <header>
        <h1>Richieste Consegnate (Non Assegnate)</h1>
        <a href="bacheca_ritiri.php" class="btn-back">← Torna alla Bacheca</a>
    </header>

    <?php if (is_array($dati) && count($dati) > 0): foreach ($dati as $r): ?>
        <?php
            // Correzione orario inserimento (UTC -> +2h)
            $created_formatted = 'N/D';
            if (!empty($r['created_at'])) {
                $ts_created = strtotime($r['created_at']);
                if ($ts_created !== false) {
                    $created_formatted = date('Y-m-d H:i:s', $ts_created + 7200);
                } else {
                    $created_formatted = $r['created_at'];
                }
            }

            // Orario consegna SIT
            $orario_consegna_formattato = 'N/D';
            if (!empty($r['consegnato_il'])) {
                $ts_cons = strtotime($r['consegnato_il']);
                if ($ts_cons !== false) {
                    $orario_consegna_formattato = date('Y-m-d H:i:s', $ts_cons);
                } else {
                    $orario_consegna_formattato = $r['consegnato_il'];
                }
            }

            // Mascheramento del nome paziente per privacy nelle note visualizzate
            $testo_note = $r['note'] ?? '';
            $testo_note_visualizzato = maschera_paziente($testo_note);
        ?>
        <div class="card">
            <div class="card-header-row">
                <div>
                    <span class="label">Reparto</span><br>
                    <span class="valore-reparto"><?php echo htmlspecialchars($r['reparto']); ?></span>
                </div>
                <div style="text-align: right;">
                    <span class="label">Turno</span><br>
                    <span class="valore-turno"><?php echo htmlspecialchars($r['turno_successivo']); ?></span>
                </div>
            </div>
            
            <div class="orario">Inserito il: <?php echo htmlspecialchars($created_formatted); ?></div>
            
            <?php if (!empty($testo_note_visualizzato)): ?>
                <div class="note-box">
                    <strong>Note:</strong> <?php echo htmlspecialchars($testo_note_visualizzato); ?>
                </div>
            <?php endif; ?>

            <div class="consegnato-box">
                📦 Consegnato al SIT da: <strong><?php echo htmlspecialchars($r['consegnato_da'] ?? 'N/D'); ?></strong><br>
                <span style="font-size: 0.75rem; opacity: 0.8;">Data consegna: <?php echo htmlspecialchars($orario_consegna_formattato); ?></span>
            </div>
        </div>
    <?php endforeach; else: ?>
        <div class="empty-state">
            <p style="margin:0; font-weight: 500;">Nessuna richiesta consegnata e non ritirata negli ultimi 2 giorni.</p>
        </div>
    <?php endif; ?>
</body>
</html>