<?php
// /blood/api_helper_sangue.php
require_once 'config_sangue.php';

if (!function_exists('ottieni_header_api')) {
    function ottieni_header_api() {
        return [
            "apikey: " . SUPABASE_KEY,
            "Authorization: Bearer " . SUPABASE_KEY,
            "Content-Type: application/json",
            "Prefer: return=representation"
        ];
    }
}

if (!function_exists('esegui_get_api')) {
    function esegui_get_api($endpoint) {
        $url = SUPABASE_URL . "/rest/v1/" . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ottieni_header_api());
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);          // Timeout massimo di esecuzione a 10 secondi
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);    // Timeout di connessione a 5 secondi
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}

if (!function_exists('esegui_post_api')) {
    function esegui_post_api($endpoint, $dati) {
        $url = SUPABASE_URL . "/rest/v1/" . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dati));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ottieni_header_api());
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);          // Timeout massimo di esecuzione a 10 secondi
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);    // Timeout di connessione a 5 secondi
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}

if (!function_exists('esegui_patch_api')) {
    function esegui_patch_api($endpoint, $dati) {
        $url = SUPABASE_URL . "/rest/v1/" . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dati));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ottieni_header_api());
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);          // Timeout massimo di esecuzione a 10 secondi
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);    // Timeout di connessione a 5 secondi
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}

/**
 * Funzione aggiornata per includere Paziente, Numero Sacca e gestione log di controllo
 */
if (!function_exists('registra_log')) {
    function registra_log($codice, $azione, $dettaglio, $paziente = '', $numero_sacca = '') {
        if (isset($_SESSION['utente']['id'])) {
            $dati = [
                "utente_id"    => $_SESSION['utente']['id'],
                "codice_barre" => $codice,
                "azione"       => $azione,
                "dettaglio"    => $dettaglio,
                "paziente"     => $paziente,
                "numero_sacca" => $numero_sacca
            ];
            
            // Logghiamo l'invio su un file per vedere se parte
            error_log("TENTATIVO INVIO LOG: " . json_encode($dati));
            
            $risposta = esegui_post_api("log_movimenti", $dati);
            
            // Logghiamo la risposta di Supabase
            error_log("RISPOSTA SUPABASE: " . json_encode($risposta));
            
            return $risposta;
        }
    }
}

if (!function_exists('verifica_login_locale')) {
    function verifica_login_locale($email, $password) {
        $utente = esegui_get_api("utenti?email=eq." . urlencode($email) . "&select=*");
        if (!empty($utente) && isset($utente[0]['password_hash'])) {
            if (password_verify($password, $utente[0]['password_hash'])) {
                return $utente[0];
            }
        }
        return false;
    }
}

/**
 * Invia una notifica push tramite Firebase Cloud Messaging (FCM)
 * 
 * @param string $fcm_token Il token del dispositivo mobile dell'operatore
 * @param string $titolo Titolo della notifica
 * @param string $messaggio Corpo del messaggio
 * @return array Risultato dell'invio
 */
if (!function_exists('invia_notifica_push_fcm')) {
    function invia_notifica_push_fcm($fcm_token, $titolo, $messaggio) {
        // La chiave Server FCM (la trovi nelle impostazioni del progetto Firebase -> Cloud Messaging)
        $server_key = defined('FCM_SERVER_KEY') ? FCM_SERVER_KEY : '';
        
        if (empty($fcm_token) || empty($server_key)) {
            return ["error" => true, "message" => "Token FCM o Chiave Server mancanti."];
        }

        $url = 'https://fcm.googleapis.com/fcm/send';
        
        $fields = [
            'to' => $fcm_token,
            'notification' => [
                'title' => $titolo,
                'body' => $messaggio,
                'sound' => 'default'
            ]
        ];

        $headers = [
            'Authorization: key=' . $server_key,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400) {
            return ["error" => true, "status_code" => $http_code, "message" => $result];
        }

        return ["error" => false, "response" => json_decode($result, true)];
    }
}
?>
