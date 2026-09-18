<?php
session_start();
set_time_limit(300);
sql_execution_time_limit: ini_set('max_execution_time', '300');

if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Inclusione dell'helper per Supabase e configurazioni
require_once 'api_helper_sangue.php';

$messaggio = '';
$tipo_messaggio = '';
$risultato_json = null;

/**
 * Funzione 1: Usa OpenAI SOLO per interpretare le regole in linguaggio naturale 
 * e restituire una configurazione strutturata per ogni operatore.
 */
function interpreta_regole_openai($MeseAnno, $utenti_disponibili, $regole_reparto) {
    $apiKey = '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';
    
    if (empty($apiKey)) {
        return ['errore' => 'Chiave API OpenAI non configurata.'];
    }

    $prompt_sistema = "Sei un assistente per la pianificazione dei turni ospedalieri.\n"
                    . "Il tuo compito è analizzare le indicazioni del coordinatore e l'elenco degli operatori, restituendo un oggetto JSON strutturato con le impostazioni per ciascun operatore menzionato o attivo nel mese.\n"
                    . "Tipi di profilo gestiti:\n"
                    . "- 'mattiniero': lavora solo dal lunedì al sabato (esclude tassativamente le domeniche).\n"
                    . "- 'turnista': fa cicli completi (Mattina -> Pomeriggio -> Notte -> Smonto -> Riposo).\n"
                    . "Restituisci ESCLUSIVAMENTE un JSON valido con questa struttura:\n"
                    . "{\n"
                    . "  \"configurazioni\": [\n"
                    . "    {\n"
                    . "      \"utente_id\": \"UUID_OPERATORE\",\n"
                    . "      \"profilo\": \"mattiniero|turnista\",\n"
                    . "      \"turno_iniziale\": \"Mattina|Pomeriggio|Notte|Smonto|Riposo\",\n"
                    . "      \"indice_partenza_ciclo\": 0\n"
                    . "    }\n"
                    . "  ]\n"
                    . "}";

    $prompt_utente = "Mese: {$MeseAnno}\n"
                   . "Operatori:\n" . json_encode($utenti_disponibili) . "\n"
                   . "Regole fornite dal coordinatore:\n" . $regole_reparto;

    $payload = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $prompt_sistema],
            ['role' => 'user', 'content' => $prompt_utente]
        ],
        'temperature' => 0.0,
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['errore' => 'Errore di connessione cURL verso OpenAI: ' . $curl_error];
    }

    $data = json_decode($response, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return ['errore' => 'Risposta non valida ricevuta da OpenAI: ' . $response];
    }

    $content = $data['choices'][0]['message']['content'];
    $decoded_content = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['errore' => 'Errore nel parsing del JSON restituito da OpenAI.'];
    }

    return $decoded_content;
}

/**
 * Funzione 2: Motore Matematico in PHP per la generazione dei turni del mese.
 * Garantisce che i mattinieri riposino la domenica e gestisce il rientro da ferie.
 */
function genera_turni_matematicamente($mese_anno, $configurazioni_operatori, $assenze_db) {
    $turni_generati = [];
    
    // Mappa delle assenze per controllo rapido [utente_id][data] = true
    $mappa_assenze = [];
    if (is_array($assenze_db)) {
        foreach ($assenze_db as $ass) {
            $uid = $ass['utente_id'] ?? null;
            $d_start_str = $ass['data_inizio'] ?? null;
            $d_end_str = $ass['data_fine'] ?? null;
            
            if (!$uid || !$d_start_str || !$d_end_str) continue;

            try {
                $d_start = new DateTime($d_start_str);
                $d_end = new DateTime($d_end_str);
                while ($d_start <= $d_end) {
                    $mappa_assenze[$uid][$d_start->format('Y-m-d')] = true;
                    $d_start->modify('+1 day');
                }
            } catch (Exception $e) {
                // Salta date non valide
            }
        }
    }

    $ciclo_standard = ['Mattina', 'Pomeriggio', 'Notte', 'Smonto', 'Riposo'];
    $mappa_indici_ciclo = array_flip($ciclo_standard);

    try {
        $giorno_inizio = new DateTime($mese_anno . '-01');
        $giorno_fine = new DateTime($giorno_inizio->format('Y-m-t'));
    } catch (Exception $e) {
        return ['turni' => [], 'errore' => 'Formato mese non valido.'];
    }

    if (!is_array($configurazioni_operatori)) {
        return ['turni' => []];
    }

    foreach ($configurazioni_operatori as $conf) {
        $utente_id = $conf['utente_id'] ?? null;
        $profilo = $conf['profilo'] ?? 'turnista';
        $turno_corrente = $conf['turno_iniziale'] ?? 'Mattina';
        
        if (!$utente_id) continue;

        $indice_ciclo = isset($mappa_indici_ciclo[$turno_corrente]) ? $mappa_indici_ciclo[$turno_corrente] : 0;

        $current_date = clone $giorno_inizio;
        while ($current_date <= $giorno_fine) {
            $data_str = $current_date->format('Y-m-d');
            $giorno_settimana = $current_date->format('N'); // 1 (Lunedì) a 7 (Domenica)

            // 1. Se c'è ferie o malattia, salta l'assegnazione del turno in quel giorno
            if (isset($mappa_assenze[$utente_id][$data_str])) {
                if ($profilo === 'turnista') {
                    $indice_ciclo = ($indice_ciclo + 1) % count($ciclo_standard);
                }
                $current_date->modify('+1 day');
                continue;
            }

            // 2. Regola OPERATORE MATTINIERO: lavora lun-sab, salta tassativamente la domenica (7)
            if ($profilo === 'mattiniero') {
                if ($giorno_settimana == 7) {
                    // Domenica: riposo/nessun turno lavorativo per il mattiniero
                } else {
                    $turni_generati[] = [
                        'utente_id' => $utente_id,
                        'data' => $data_str,
                        'fascia_oraria' => 'Mattina'
                    ];
                }
            } 
            // 3. Regola TURNISTA standard a ciclo continuo
            else {
                $fascia_oggi = $ciclo_standard[$indice_ciclo];
                
                $turni_generati[] = [
                    'utente_id' => $utente_id,
                    'data' => $data_str,
                    'fascia_oraria' => $fascia_oggi
                ];

                $indice_ciclo = ($indice_ciclo + 1) % count($ciclo_standard);
            }

            $current_date->modify('+1 day');
        }
    }

    return ['turni' => $turni_generati];
}

// Gestione dell'invio del form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['genera_ai'])) {
    $mese_anno = $_POST['mese_anno'] ?? date('Y-m');
    $note_regole = $_POST['note_regole'] ?? '';

    // 1. Recupera tutti gli utenti da Supabase
    $utenti = esegui_get_api("utenti?select=id,nome,cognome,email,ruolo");
    if (!is_array($utenti) || empty($utenti)) {
        $messaggio = "Impossibile recuperare gli operatori da Supabase.";
        $tipo_messaggio = "danger";
    } else {
        $lista_utenti_semplice = [];
        foreach ($utenti as $u) {
            $lista_utenti_semplice[] = [
                'id' => $u['id'] ?? '',
                'nome_completo' => trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? '')),
                'ruolo' => $u['ruolo'] ?? 'Operatore'
            ];
        }

        // 2. Recupera le ferie/assenze del mese
        $data_inizio_mese = $mese_anno . '-01';
        $data_fine_mese = date('Y-m-t', strtotime($data_inizio_mese));
        
        $assenze_db = esegui_get_api("assenze?select=utente_id,data_inizio,data_fine,tipo&data_inizio=lte.{$data_fine_mese}&data_fine=gte.{$data_inizio_mese}");
        if (!is_array($assenze_db)) {
            $assenze_db = [];
        }

        // 3. Interpreta le regole tramite OpenAI
        $risposta_interpretazione = interpreta_regole_openai($mese_anno, $lista_utenti_semplice, $note_regole);

        if (isset($risposta_interpretazione['errore'])) {
            $messaggio = $risposta_interpretazione['errore'];
            $tipo_messaggio = "danger";
        } else {
            $configurazioni = $risposta_interpretazione['configurazioni'] ?? [];

            // 4. Esegue il calcolo matematico deterministico in PHP
            $risultato_finale = genera_turni_matematicamente($mese_anno, $configurazioni, $assenze_db);
            
            if (isset($risultato_finale['errore'])) {
                $messaggio = $risultato_finale['errore'];
                $tipo_messaggio = "danger";
            } else {
                $salvati_turni = 0;
                if (!empty($risultato_finale['turni']) && is_array($risultato_finale['turni'])) {
                    foreach ($risultato_finale['turni'] as $t) {
                        if (!empty($t['utente_id']) && !empty($t['data']) && !empty($t['fascia_oraria'])) {
                            $res = esegui_post_api("turni", [
                                'utente_id' => $t['utente_id'],
                                'data' => $t['data'],
                                'fascia_oraria' => $t['fascia_oraria']
                            ]);
                            if (!empty($res) && !isset($res['message']) && !isset($res['error'])) {
                                $salvati_turni++;
                            }
                        }
                    }
                }

                $messaggio = "Generazione turni completata con successo! Salvati su Supabase: <strong>{$salvati_turni}</strong> turni rispettando rigorosamente i riposi domenicali dei mattinieri e le rotazioni.";
                $tipo_messaggio = "success";
                $risultato_json = $risultato_finale;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Generatore Automatico Turni & Ferie con AI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Generatore Automatico Mensile (Ibrido AI + PHP)</h2>
            <div>
                <a href="gestione_turni_reparto.php" class="btn btn-outline-secondary me-2">Gestione Manuale</a>
                <a href="emoteca_turni.php" class="btn btn-outline-primary me-2">Vai a Emoteca (AI)</a>
                <a href="bacheca_ritiri.php" class="btn btn-outline-secondary">Dashboard</a>
            </div>
        </div>

        <?php if (!empty($messaggio)): ?>
            <div class="alert alert-<?php echo $tipo_messaggio; ?> alert-dismissible fade show" role="alert">
                <?php echo $messaggio; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Parametri e Regole Turnista</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="genera_ai" value="1">
                            
                            <div class="mb-3">
                                <label for="mese_anno" class="form-label">Seleziona Mese di Riferimento</label>
                                <input type="month" class="form-control" id="mese_anno" name="mese_anno" value="<?php echo date('Y-m'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="note_regole" class="form-label">Indicazioni per la Rotazione, Ferie e Rientri</label>
                                <textarea class="form-control" id="note_regole" name="note_regole" rows="7" placeholder="Es. Il Dr. Rossi è mattiniero. Colucci è mattiniero. Bossa parte con la mattina il giorno 1. Di Costanza parte con il pomeriggio il giorno 1."></textarea>
                                <div class="form-text">L'AI interpreterà le regole e il motore PHP calcolerà matematicamente i giorni, bloccando la domenica per i mattinieri.</div>
                            </div>

                            <button type="submit" class="btn btn-success w-100 py-2 fw-bold">Genera Turni Matematicamente</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Anteprima Risultato Generato</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($risultato_json): ?>
                            <div class="alert alert-info">Turni generati con successo. Ecco l'anteprima:</div>
                            <pre class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85rem;"><?php echo htmlspecialchars(json_encode($risultato_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                        <?php else: ?>
                            <p class="text-muted text-center py-5">Scrivi le indicazioni a sinistra e avvia la generazione.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>