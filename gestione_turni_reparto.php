<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Inclusione dell'helper esistente per comunicare con Supabase
require_once 'api_helper_sangue.php';

$messaggio = '';
$tipo_messaggio = '';

// Gestione mese e anno corrente per il calendario
$mese = isset($_GET['mese']) ? intval($_GET['mese']) : date('n');
$anno = isset($_GET['anno']) ? intval($_GET['anno']) : date('Y');

if ($mese < 1) { $mese = 12; $anno--; }
if ($mese > 12) { $mese = 1; $anno++; }

$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese, $anno);
$data_inizio_mese = sprintf('%04d-%02d-01', $anno, $mese);
$data_fine_mese = sprintf('%04d-%02d-%02d', $anno, $mese, $giorni_nel_mese);

// Gestione inserimento Turno o Assenza tramite POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    $data_riferimento = $_POST['data_riferimento'] ?? date('Y-m-d');

    if ($azione === 'inserisci_turno') {
        $utente_id = $_POST['utente_id_turno'] ?? '';
        $fascia_oraria = $_POST['fascia_oraria'] ?? 'Pomeriggio';

        if (empty($utente_id) || empty($data_riferimento)) {
            $messaggio = "Compila tutti i campi obbligatori per il turno (operatore e data).";
            $tipo_messaggio = "danger";
        } else {
            $payload_turno = [
                'utente_id' => $utente_id,
                'data' => $data_riferimento,
                'fascia_oraria' => $fascia_oraria
            ];

            $res = esegui_post_api("turni", $payload_turno);

            if (!empty($res) && !isset($res['message']) && !isset($res['error'])) {
                $messaggio = "Turno di <strong>{$fascia_oraria}</strong> registrato con successo per la data {$data_riferimento}.";
                $tipo_messaggio = "success";
            } else {
                $messaggio = "Errore durante il salvataggio del turno su Supabase (verifica che non esista già un doppione per lo stesso giorno).";
                $tipo_messaggio = "danger";
            }
        }

    } elseif ($azione === 'inserisci_assenza') {
        $utente_id = $_POST['utente_id_assenza'] ?? '';
        $tipo_assenza = $_POST['tipo_assenza'] ?? 'ferie';
        $data_fine = $_POST['data_fine'] ?? $data_riferimento;

        if (empty($utente_id) || empty($data_riferimento)) {
            $messaggio = "Compila tutti i campi obbligatori per l'assenza (operatore e data).";
            $tipo_messaggio = "danger";
        } else {
            if ($data_fine < $data_riferimento) {
                $data_fine = $data_riferimento;
            }

            $payload_assenza = [
                'utente_id' => $utente_id,
                'data_inizio' => $data_riferimento,
                'data_fine' => $data_fine,
                'tipo' => $tipo_assenza
            ];

            $res = esegui_post_api("assenze", $payload_assenza);

            if (!empty($res) && !isset($res['message']) && !isset($res['error'])) {
                $messaggio = ucfirst($tipo_assenza) . " registrata con successo dal {$data_riferimento} al {$data_fine}.";
                $tipo_messaggio = "success";
            } else {
                $messaggio = "Errore durante il salvataggio dell'assenza su Supabase.";
                $tipo_messaggio = "danger";
            }
        }
    }
}

// Recupera la lista degli utenti per i menu a tendina e per le righe del calendario
$utenti = esegui_get_api("utenti?select=id,nome,cognome,email,ruolo&order=cognome.asc");
if (!is_array($utenti)) {
    $utenti = [];
}

// Recupera i turni del mese corrente
$turni_mese = esegui_get_api("turni?select=id,data,fascia_oraria,utente_id&data=gte.{$data_inizio_mese}&data=lte.{$data_fine_mese}");
if (!is_array($turni_mese)) {
    $turni_mese = [];
}

// Organizza i turni in una matrice [utente_id][data] = fascia_oraria
$matrice_turni = [];
foreach ($turni_mese as $t) {
    $matrice_turni[$t['utente_id']][$t['data']] = $t['fascia_oraria'];
}

// Recupera le assenze che intersecano il mese corrente
$assenze_mese = esegui_get_api("assenze?select=id,data_inizio,data_fine,tipo,utente_id");
if (!is_array($assenze_mese)) {
    $assenze_mese = [];
}

// Organizza le assenze espandendole per ogni giorno del mese
$matrice_assenze = [];
foreach ($assenze_mese as $a) {
    $u_id = $a['utente_id'];
    $d_inizio = $a['data_inizio'];
    $d_fine = $a['data_fine'];
    $tipo = $a['tipo'];

    $corrente = strtotime($d_inizio);
    $fine = strtotime($d_fine);

    while ($corrente <= $fine) {
        $data_str = date('Y-m-d', $corrente);
        if ($data_str >= $data_inizio_mese && $data_str <= $data_fine_mese) {
            $matrice_assenze[$u_id][$data_str] = $tipo;
        }
        $corrente = strtotime("+1 day", $corrente);
    }
}

$nomi_mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Turni e Ferie Reparto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-calendar th, .table-calendar td {
            font-size: 0.8rem;
            text-align: center;
            vertical-align: middle;
            padding: 4px;
        }
        .table-calendar th.operatore-col, .table-calendar td.operatore-col {
            text-align: left;
            white-space: nowrap;
            font-weight: bold;
        }
        .turno-pomeriggio { background-color: #d1e7dd !important; color: #0f5132; font-weight: bold; }
        .turno-mattina { background-color: #cfe2ff !important; color: #084298; font-weight: bold; }
        .turno-notte { background-color: #f8d7da !important; color: #842029; font-weight: bold; }
        .assenza-ferie { background-color: #fff3cd !important; color: #664d03; font-weight: bold; }
        .assenza-malattia { background-color: #e2e3e5 !important; color: #383d41; font-weight: bold; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4 px-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Pianificazione Reparto (Turni & Ferie)</h2>
            <div>
                <a href="emoteca_turni.php" class="btn btn-outline-primary me-2">Vai a Genera Emoteca (AI)</a>
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
            </div>
        </div>

        <?php if (!empty($messaggio)): ?>
            <div class="alert alert-<?php echo $tipo_messaggio; ?> alert-dismissible fade show" role="alert">
                <?php echo $messaggio; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Colonna Sinistra: Moduli di Inserimento -->
            <div class="col-lg-4 mb-4">
                
                <!-- Card Assegnazione Turno -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">1. Assegna Turno in Reparto</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="azione" value="inserisci_turno">
                            
                            <div class="mb-3">
                                <label for="utente_id_turno" class="form-label">Operatore</label>
                                <select class="form-select" id="utente_id_turno" name="utente_id_turno" required>
                                    <option value="">Seleziona operatore...</option>
                                    <?php foreach ($utenti as $u): ?>
                                        <?php 
                                            $nome_completo = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? ''));
                                            if ($nome_completo === '') $nome_completo = $u['email'];
                                        ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($nome_completo); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="data_riferimento_turno" class="form-label">Data</label>
                                <input type="date" class="form-control" id="data_riferimento_turno" name="data_riferimento" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="fascia_oraria" class="form-label">Fascia Oraria</label>
                                <select class="form-select" id="fascia_oraria" name="fascia_oraria">
                                    <option value="Pomeriggio" selected>Pomeriggio (Richiesto per Emoteca)</option>
                                    <option value="Mattina">Mattina</option>
                                    <option value="Notte">Notte</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-success w-100">Registra Turno Reparto</button>
                        </form>
                    </div>
                </div>

                <!-- Card Inserimento Ferie / Malattia -->
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">2. Registra Assenza (Ferie / Malattia)</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="azione" value="inserisci_assenza">
                            
                            <div class="mb-3">
                                <label for="utente_id_assenza" class="form-label">Operatore</label>
                                <select class="form-select" id="utente_id_assenza" name="utente_id_assenza" required>
                                    <option value="">Seleziona operatore...</option>
                                    <?php foreach ($utenti as $u): ?>
                                        <?php 
                                            $nome_completo = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? ''));
                                            if ($nome_completo === '') $nome_completo = $u['email'];
                                        ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($nome_completo); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="data_riferimento_assenza" class="form-label">Data Inizio</label>
                                    <input type="date" class="form-control" id="data_riferimento_assenza" name="data_riferimento" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="data_fine" class="form-label">Data Fine</label>
                                    <input type="date" class="form-control" id="data_fine" name="data_fine" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="tipo_assenza" class="form-label">Tipo di Assenza</label>
                                <select class="form-select" id="tipo_assenza" name="tipo_assenza">
                                    <option value="ferie" selected>Ferie</option>
                                    <option value="malattia">Malattia</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-dark w-100">Registra Assenza</button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Colonna Destra: Calendario Mensile -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Calendario Reparto - <?php echo $nomi_mesi[$mese] . ' ' . $anno; ?></h5>
                        <div>
                            <?php 
                                $prev_mese = $mese - 1;
                                $prev_anno = $anno;
                                if ($prev_mese < 1) { $prev_mese = 12; $prev_anno--; }

                                $next_mese = $mese + 1;
                                $next_anno = $anno;
                                if ($next_mese > 12) { $next_mese = 1; $next_anno++; }
                            ?>
                            <a href="?mese=<?php echo $prev_mese; ?>&anno=<?php echo $prev_anno; ?>" class="btn btn-sm btn-light">&laquo; Mese Prec</a>
                            <a href="?mese=<?php echo date('n'); ?>&anno=<?php echo date('Y'); ?>" class="btn btn-sm btn-light">Oggi</a>
                            <a href="?mese=<?php echo $next_mese; ?>&anno=<?php echo $next_anno; ?>" class="btn btn-sm btn-light">Mese Succ &raquo;</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-calendar mb-0">
                                <thead>
                                    <tr>
                                        <th class="operatore-col">Operatore</th>
                                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): ?>
                                            <?php 
                                                $data_corrente_loop = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
                                                $giorno_settimana = date('N', strtotime($data_corrente_loop)); // 1 (Mon) to 7 (Sun)
                                                $is_festivo = ($giorno_settimana == 7);
                                            ?>
                                            <th class="<?php echo $is_festivo ? 'bg-danger text-white' : ''; ?>">
                                                <?php echo $g; ?><br>
                                                <small style="font-size: 0.65rem;"><?php echo ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'][$giorno_settimana-1]; ?></small>
                                            </th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($utenti)): ?>
                                        <tr><td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-4">Nessun operatore trovato.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($utenti as $u): ?>
                                            <?php 
                                                $u_id = $u['id'];
                                                $nome_completo = trim(($u['cognome'] ?? '') . ' ' . ($u['nome'] ?? ''));
                                                if ($nome_completo === '') $nome_completo = $u['email'];
                                            ?>
                                            <tr>
                                                <td class="operatore-col"><?php echo htmlspecialchars($nome_completo); ?></td>
                                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): ?>
                                                    <?php 
                                                        $data_corrente_loop = sprintf('%04d-%02d-%02d', $anno, $mese, $g);
                                                        $turno = $matrice_turni[$u_id][$data_corrente_loop] ?? '';
                                                        $assenza = $matrice_assenze[$u_id][$data_corrente_loop] ?? '';

                                                        $classe_css = '';
                                                        $testo_cella = '';

                                                        if (!empty($assenza)) {
                                                            if ($assenza === 'ferie') {
                                                                $classe_css = 'assenza-ferie';
                                                                $testo_cella = 'F';
                                                            } else {
                                                                $classe_css = 'assenza-malattia';
                                                                $testo_cella = 'Mal';
                                                            }
                                                        } elseif (!empty($turno)) {
                                                            if ($turno === 'Pomeriggio') {
                                                                $classe_css = 'turno-pomeriggio';
                                                                $testo_cella = 'P';
                                                            } elseif ($turno === 'Mattina') {
                                                                $classe_css = 'turno-mattina';
                                                                $testo_cella = 'M';
                                                            } elseif ($turno === 'Notte') {
                                                                $classe_css = 'turno-notte';
                                                                $testo_cella = 'N';
                                                            } else {
                                                                $testo_cella = substr($turno, 0, 1);
                                                            }
                                                        }
                                                    ?>
                                                    <td class="<?php echo $classe_css; ?>" title="<?php echo htmlspecialchars($nome_completo . ' - ' . $data_corrente_loop . (!empty($turno) ? ' Turno: '.$turno : '') . (!empty($assenza) ? ' Assenza: '.$assenza : '')); ?>">
                                                        <?php echo $testo_cella; ?>
                                                    </td>
                                                <?php endfor; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white py-2">
                        <div class="d-flex gap-3 align-items-center text-small" style="font-size: 0.85rem;">
                            <span><strong>Legenda:</strong></span>
                            <span><span class="badge bg-success text-white">P</span> Pomeriggio</span>
                            <span><span class="badge bg-primary text-white">M</span> Mattina</span>
                            <span><span class="badge bg-danger text-white">N</span> Notte</span>
                            <span><span class="badge bg-warning text-dark">F</span> Ferie</span>
                            <span><span class="badge bg-secondary text-white">Mal</span> Malattia</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>