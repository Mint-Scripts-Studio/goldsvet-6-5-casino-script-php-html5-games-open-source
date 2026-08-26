<?php
/**
 * API Slots Network - Pragmatic Play (Mint Scripts Studio)
 * @version 2.2
 */

class SlotsAPI {
    private $api_url = 'https://mintscripts.net/';
    private $partner;
    private $api_key;
    private $providers = array('pragmatic', 'hacksaw');
    private $currency = 'USD';
    public $debug = false;
    private $log_file;
    
    public function __construct($partner, $api_key, $providers = array('pragmatic', 'hacksaw')) {
        $this->partner = $partner;
        $this->api_key = $api_key;
        $this->providers = is_array($providers) ? $providers : explode(',', $providers);
        $this->currency = 'USD';
        
        $this->log_file = dirname(__FILE__) . '/slots_debug.log';
        $this->loadSettings();
    }
    
    private function loadSettings() {
        global $dbs;
        if (!$dbs) return;
        
        $settings = $dbs->getRow("SELECT * FROM slots_api_settings WHERE id = 1");
        if ($settings) {
            $this->partner = isset($settings['partner']) ? $settings['partner'] : $this->partner;
            $this->api_key = isset($settings['api_key']) ? $settings['api_key'] : $this->api_key;
            if (!empty($settings['providers'])) {
                $this->providers = explode(',', $settings['providers']);
            }
            $this->currency = isset($settings['currency']) ? $settings['currency'] : 'USD';
            $this->debug = isset($settings['debug']) ? (bool)$settings['debug'] : false;
        }
    }
    
    public function setDebug($debug = true) {
        $this->debug = (bool)$debug;
        return $this;
    }
    
    public function getProviders() {
        return $this->providers;
    }
    
    public function setProviders($providers) {
        $this->providers = is_array($providers) ? $providers : explode(',', $providers);
    }
    
    public function getGames() {
        $all_games = array();
        foreach ($this->providers as $provider) {
            $games = $this->getGamesByProvider($provider);
            if ($games && is_array($games)) {
                foreach ($games as $game) {
                    if (!isset($game['provider'])) {
                        $game['provider'] = $this->getProviderName($provider);
                    }
                    if (!isset($game['provider_code'])) {
                        $game['provider_code'] = $provider;
                    }
                    $all_games[] = $game;
                }
            }
        }
        return $all_games;
    }
    
    private function getProviderName($code) {
        $names = array(
            'pragmatic' => 'Pragmatic Play',
            'hacksaw' => 'Hacksaw Gaming',
            'nownowgaming' => 'NowNow Gaming',
            'bullshark' => 'BullShark Games',
            'backseat' => 'BackSeat Gaming'
        );
        return isset($names[$code]) ? $names[$code] : $code;
    }
    
    private function getGamesByProvider($provider) {
        $url = 'https://mintscripts.net/api/allgamelist?partner=' . $this->partner . '&api_key=' . $this->api_key . '&provider=' . $provider;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->logDebug("API Request: Provider=$provider, HTTP=$httpCode");
        
        if ($error || $httpCode != 200) {
            return array();
        }
        
        $result = json_decode($response, true);
        if (isset($result['slots']) && is_array($result['slots'])) {
            return $result['slots'];
        }
        return array();
    }
    
    public function launchGame($symbol, $player_id, $provider = 'pragmatic', $lang = 'ru', $region = 'NONE', $gametype = 'real', $freespins = null) {
        $data = array(
            'symbol' => $symbol,
            'provider' => $provider,
            'currency' => $this->currency,
            'partner' => $this->partner,
            'api_key' => $this->api_key,
            'player_id' => (string)$player_id,
            'lang' => $lang,
            'region' => $region,
            'gametype' => $gametype
        );
        
        if ($freespins) {
            $data['freespins'] = $freespins;
        }
        
        $url = $this->api_url . '/api/playGame.do';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            return array('success' => false, 'error' => 'CURL Error: ' . $error);
        }
        
        if ($httpCode != 200) {
            return array('success' => false, 'error' => 'HTTP Error: ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['status']) && $result['status'] == 'ok') {
            return array(
                'success' => true,
                'link' => isset($result['link']) ? $result['link'] : '',
                'session_id' => isset($result['meta']['session_id']) ? $result['meta']['session_id'] : null,
                'balance' => isset($result['meta']['balance']) ? $result['meta']['balance'] : null
            );
        }
        
        return array(
            'success' => false,
            'error' => isset($result['message']) ? $result['message'] : 'Unknown error'
        );
    }
    

    private function getDirectDbConnection() {
        global $dbs;
        
      
        if (isset($dbs->conn) && $dbs->conn instanceof mysqli) {
            return $dbs->conn;
        }
        
        
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        
        if ($mysqli->connect_error) {
            $this->logDebug("DIRECT DB CONNECTION FAILED: " . $mysqli->connect_error);
            return null;
        }
        
        $mysqli->set_charset("utf8mb4");
        return $mysqli;
    }
    
    public function handleCallback($data) {
        $this->logDebug("========================================");
        $this->logDebug("INCOMING CALLBACK: " . json_encode($data));
        
        $method = isset($data['method']) ? $data['method'] : '';
        $api_key = isset($data['api_key']) ? $data['api_key'] : '';
        $player_id = isset($data['player_id']) ? (int)$data['player_id'] : 0;
        
        if ($api_key !== $this->api_key) {
            $this->logDebug("ERROR: Invalid API key. Received: " . $api_key);
            return ['status' => 'error', 'message' => 'Invalid API key'];
        }
        
        global $dbs;
        if (!$dbs) {
            $this->logDebug("ERROR: Database connection failed");
            return ['status' => 'error', 'message' => 'Database connection failed'];
        }
        
        $user = $dbs->getRow("SELECT * FROM users WHERE id = ?i", $player_id);
        if (!$user) {
            $this->logDebug("ERROR: Player not found (ID: $player_id)");
            return ['status' => 'error', 'message' => 'Player not found (ID: ' . $player_id . ')'];
        }
        
        $isDemo = false;
        if (isset($data['meta']['gametype'])) {
            $isDemo = ($data['meta']['gametype'] == 'demo');
        } else if (isset($user['demomode']) && $user['demomode'] == 1) {
            $isDemo = true;
        }
        
        $balanceField = $isDemo ? 'demobalance' : 'balance';
        
        if (!isset($user[$balanceField])) {
            $this->logDebug("WARNING: Column '$balanceField' does not exist. Falling back to 'balance'");
            if ($isDemo && isset($user['balance'])) {
                $balanceField = 'balance';
            } else {
                $this->logDebug("FATAL: No balance field available");
                return ['status' => 'error', 'message' => 'Database schema error: missing balance field'];
            }
        }
        
        $initialBalance = (float)$user[$balanceField];
        $this->logDebug("Player $player_id: method=$method, demo=" . ($isDemo ? 'yes' : 'no') . ", field=$balanceField, initial_balance=$initialBalance");
        
        switch ($method) {
            case 'session_info':
            case 'game_switch':
            case 'round_info':

                $mysqli = $this->getDirectDbConnection();
                if ($mysqli) {
                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ?");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $balance = isset($row[$balanceField]) ? (float)$row[$balanceField] : 0;
                    $stmt->close();
                } else {
                    $freshUser = $dbs->getRow("SELECT `$balanceField` as current_balance FROM users WHERE id = ?i", $player_id);
                    $balance = isset($freshUser['current_balance']) ? (float)$freshUser['current_balance'] : 0;
                }
                
                $this->logDebug("Returning balance: $balance");
                return ['status' => 'ok', 'balance' => $balance, 'currency' => $this->currency];
                
            case 'bet':
                $amount = isset($data['amount']) ? (float)$data['amount'] : 0;
                $this->logDebug("BET ATTEMPT: amount=$amount");
                
                if ($amount <= 0) {
                    $this->logDebug("BET: Amount <= 0, returning current balance");
                    $mysqli = $this->getDirectDbConnection();
                    if ($mysqli) {
                        $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ?");
                        $stmt->bind_param("i", $player_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        $balance = isset($row[$balanceField]) ? (float)$row[$balanceField] : 0;
                        $stmt->close();
                    } else {
                        $freshUser = $dbs->getRow("SELECT `$balanceField` as current_balance FROM users WHERE id = ?i", $player_id);
                        $balance = isset($freshUser['current_balance']) ? (float)$freshUser['current_balance'] : 0;
                    }
                    return ['status' => 'ok', 'balance' => $balance, 'currency' => $this->currency];
                }
                

                $mysqli = $this->getDirectDbConnection();
                if (!$mysqli) {
                    $this->logDebug("BET FAILED: Cannot get direct DB connection");
                    return ['status' => 'error', 'message' => 'Database connection failed'];
                }
                
                $mysqli->begin_transaction();
                
                try {

                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $stmt->close();
                    
                    if (!$row) {
                        throw new Exception("User row not found during lock");
                    }
                    
                    $currentBalance = (float)$row[$balanceField];
                    $this->logDebug("BET: Locked balance=$currentBalance");
                    
                    if ($currentBalance < $amount) {
                        $mysqli->rollback();
                        $this->logDebug("BET FAILED: Insufficient funds. Balance=$currentBalance, Required=$amount");
                        return ['status' => 'error', 'message' => 'INSUFFICIENT_FUNDS', 'balance' => $currentBalance, 'currency' => $this->currency];
                    }
                    

                    $newBalance = $currentBalance - $amount;
                    $stmt = $mysqli->prepare("UPDATE users SET `$balanceField` = ? WHERE id = ?");
                    $stmt->bind_param("di", $newBalance, $player_id);
                    $stmt->execute();
                    $affected_rows = $stmt->affected_rows;
                    $stmt->close();
                    
                    $this->logDebug("BET: UPDATE executed, affected_rows=$affected_rows");
                    
                    $mysqli->commit();
                    $this->logDebug("BET: COMMIT executed");
                    

                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ?");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $verify_row = $result->fetch_assoc();
                    $stmt->close();
                    
                    $actualDbBalance = isset($verify_row[$balanceField]) ? (float)$verify_row[$balanceField] : -1;
                    
                    if (abs($actualDbBalance - $newBalance) > 0.01) {
                        $this->logDebug("CRITICAL ERROR: Balance NOT updated in DB! Expected=$newBalance, Actual=$actualDbBalance");
                    } else {
                        $this->logDebug("BET SUCCESS: Balance verified in DB. New balance=$newBalance");
                    }
                    
                    $this->logTransaction($player_id, 'bet', $amount, $newBalance, $data);
                    
                    return ['status' => 'ok', 'balance' => $newBalance, 'currency' => $this->currency];
                    
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $this->logDebug("BET EXCEPTION: " . $e->getMessage());
                    return ['status' => 'error', 'message' => 'Internal error: ' . $e->getMessage()];
                }
                
            case 'win':
                $amount = isset($data['amount']) ? (float)$data['amount'] : 0;
                $this->logDebug("WIN ATTEMPT: amount=$amount");
                
                $mysqli = $this->getDirectDbConnection();
                if (!$mysqli) {
                    $this->logDebug("WIN FAILED: Cannot get direct DB connection");
                    return ['status' => 'error', 'message' => 'Database connection failed'];
                }
                
                $mysqli->begin_transaction();
                
                try {
                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $stmt->close();
                    
                    if (!$row) {
                        throw new Exception("User row not found during lock");
                    }
                    
                    $currentBalance = (float)$row[$balanceField];
                    $this->logDebug("WIN: Locked balance=$currentBalance");
                    
                    $newBalance = $currentBalance + $amount;
                    
                    if ($amount > 0) {
                        $stmt = $mysqli->prepare("UPDATE users SET `$balanceField` = ? WHERE id = ?");
                        $stmt->bind_param("di", $newBalance, $player_id);
                        $stmt->execute();
                        $affected_rows = $stmt->affected_rows;
                        $stmt->close();
                        
                        $this->logDebug("WIN: UPDATE executed, affected_rows=$affected_rows");
                    }
                    
                    $mysqli->commit();
                    $this->logDebug("WIN: COMMIT executed");
                    
 
                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ?");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $verify_row = $result->fetch_assoc();
                    $stmt->close();
                    
                    $actualDbBalance = isset($verify_row[$balanceField]) ? (float)$verify_row[$balanceField] : -1;
                    
                    if ($amount > 0 && abs($actualDbBalance - $newBalance) > 0.01) {
                        $this->logDebug("CRITICAL ERROR: Win NOT updated in DB! Expected=$newBalance, Actual=$actualDbBalance");
                    } else {
                        $this->logDebug("WIN SUCCESS: Balance verified in DB. New balance=$newBalance");
                    }
                    
                    $this->logTransaction($player_id, 'win', $amount, $newBalance, $data);
                    
                    return ['status' => 'ok', 'balance' => $newBalance, 'currency' => $this->currency];
                    
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $this->logDebug("WIN EXCEPTION: " . $e->getMessage());
                    return ['status' => 'error', 'message' => 'Internal error: ' . $e->getMessage()];
                }
                
            case 'cancel':
            case 'refund':
                $amount = isset($data['amount']) ? (float)$data['amount'] : 0;
                $this->logDebug("CANCEL/REFUND ATTEMPT: amount=$amount");
                
                if ($amount <= 0) {
                    $this->logDebug("CANCEL: Amount <= 0, nothing to refund");
                    return ['status' => 'ok', 'message' => 'Nothing to refund'];
                }
                
                $mysqli = $this->getDirectDbConnection();
                if (!$mysqli) {
                    return ['status' => 'error', 'message' => 'Database connection failed'];
                }
                
                $mysqli->begin_transaction();
                
                try {
                    $stmt = $mysqli->prepare("SELECT `$balanceField` FROM users WHERE id = ? FOR UPDATE");
                    $stmt->bind_param("i", $player_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $stmt->close();
                    
                    if (!$row) throw new Exception("User row not found");
                    
                    $currentBalance = (float)$row[$balanceField];
                    $newBalance = $currentBalance + $amount;
                    
                    $stmt = $mysqli->prepare("UPDATE users SET `$balanceField` = ? WHERE id = ?");
                    $stmt->bind_param("di", $newBalance, $player_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $mysqli->commit();
                    
                    $this->logDebug("CANCEL/REFUND SUCCESS: amount=$amount, new_balance=$newBalance");
                    $this->logTransaction($player_id, 'refund', $amount, $newBalance, $data);
                    
                    return ['status' => 'ok', 'balance' => $newBalance, 'currency' => $this->currency];
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $this->logDebug("CANCEL/REFUND EXCEPTION: " . $e->getMessage());
                    return ['status' => 'error', 'message' => 'Refund error: ' . $e->getMessage()];
                }
                
            default:
                $this->logDebug("UNKNOWN METHOD: $method");
                return ['status' => 'error', 'message' => 'Unknown method: ' . $method];
        }
    }
    
    private function logTransaction($playerId, $type, $amount, $balance, $data) {
        global $dbs;
        if (!$dbs) return;
        
        try {
            $dbs->query("INSERT INTO slots_transactions 
                (player_id, type, amount, balance, meta, created_at) 
                VALUES (?i, ?s, ?f, ?f, ?s, NOW())",
                $playerId, $type, $amount, $balance, json_encode($data)
            );
        } catch (Exception $e) {
            $this->createTransactionsTable();
            try {
                $dbs->query("INSERT INTO slots_transactions 
                    (player_id, type, amount, balance, meta, created_at) 
                    VALUES (?i, ?s, ?f, ?f, ?s, NOW())",
                    $playerId, $type, $amount, $balance, json_encode($data)
                );
            } catch (Exception $e2) {
                $this->logDebug("Failed to log transaction: " . $e2->getMessage());
            }
        }
    }
    
    private function createTransactionsTable() {
        global $dbs;
        if (!$dbs) return;
        
        $sql = "CREATE TABLE IF NOT EXISTS `slots_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `player_id` int(11) NOT NULL,
            `type` varchar(20) NOT NULL,
            `amount` decimal(14,2) NOT NULL,
            `balance` decimal(14,2) NOT NULL,
            `meta` text DEFAULT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `player_id` (`player_id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
        
        $dbs->query($sql);
    }
    
    private function logDebug($message) {
        $log_line = date('Y-m-d H:i:s') . " [SLOTS] " . $message . "\n";
        
        @file_put_contents($this->log_file, $log_line, FILE_APPEND);
        @error_log("[SLOTS] " . $message);
        
        if (isset($_GET['showlogs']) && $_GET['showlogs'] == '1') {
            echo "<pre style='color:#0f0;background:#000;padding:2px;margin:0;font-size:11px;'>" . htmlspecialchars($message) . "</pre>";
            @ob_flush();
            @flush();
        }
    }
    
    public function showLastLogs($lines = 50) {
        if (!file_exists($this->log_file)) {
            return "Лог-файл не найден: " . $this->log_file;
        }
        
        $all = file($this->log_file);
        $last = array_slice($all, -$lines);
        return implode('', $last);
    }
}