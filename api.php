<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DATA_FILE = __DIR__ . '/data/tournament.json';
const SESSION_FILE = __DIR__ . '/data/sessions.json';
const CONFIG_FILE = __DIR__ . '/data/config.json';
const VERSION_FILE = __DIR__ . '/data/version.json';
const RELEASES_FILE = __DIR__ . '/data/releases.json';
const HISTORY_FILE = __DIR__ . '/data/history.json';
const UPLOADS_DIR = __DIR__ . '/data/uploads';
const UPDATES_DIR = __DIR__ . '/data/updates';
const ADMIN_PASSWORD = 'admin123';
const ENCRYPTION_SALT_FILE = __DIR__ . '/data/.encryption.salt';
const ENCRYPTION_ALGORITHM = 'aes-256-cbc';

function jsonResponse(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonFile(string $file, array $default): array {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default;
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function writeJsonFile(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Genera o recupera il salt per la derivazione della chiave di crittografia
 */
function getEncryptionSalt(): string {
    if (file_exists(ENCRYPTION_SALT_FILE)) {
        return file_get_contents(ENCRYPTION_SALT_FILE);
    }
    
    // Genera nuovo salt
    $salt = base64_encode(random_bytes(32));
    @mkdir(dirname(ENCRYPTION_SALT_FILE), 0777, true);
    file_put_contents(ENCRYPTION_SALT_FILE, $salt);
    chmod(ENCRYPTION_SALT_FILE, 0600); // Permessi stretti
    
    return $salt;
}

/**
 * Deriva la chiave di crittografia da password master usando PBKDF2
 */
function getEncryptionKey(string $password): string {
    $salt = getEncryptionSalt();
    // PBKDF2: 100,000 iterazioni, 32 bytes (256 bits)
    return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
}

/**
 * Crittografa un campo usando AES-256-CBC
 */
function encryptField(string $value, string $key): string {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($value, ENCRYPTION_ALGORITHM, $key, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        return ''; // Fallback se crittografia fallisce
    }
    
    // Formato: enc:base64(iv + encrypted)
    return 'enc:' . base64_encode($iv . $encrypted);
}

/**
 * Decrittografa un campo
 */
function decryptField(string $encrypted, string $key): string {
    if (strpos($encrypted, 'enc:') !== 0) {
        return $encrypted; // Non crittografato
    }
    
    try {
        $data = base64_decode(substr($encrypted, 4));
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $decrypted = openssl_decrypt($ciphertext, ENCRYPTION_ALGORITHM, $key, OPENSSL_RAW_DATA, $iv);
        
        return $decrypted !== false ? $decrypted : '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Crittografa i campi sensibili di config
 */
function encryptSensitiveFields(array $config, string $key): array {
    // Campi sensibili da crittografare
    $sensitiveFields = [
        'email.password',
        'email.username'
    ];
    
    foreach ($sensitiveFields as $path) {
        $parts = explode('.', $path);
        $current = &$config;
        
        // Navigare fino al penultimo livello
        foreach (array_slice($parts, 0, -1) as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        
        $lastPart = end($parts);
        if (isset($current[$lastPart]) && !empty($current[$lastPart])) {
            // Crittografa solo se non già crittografato
            if (strpos($current[$lastPart], 'enc:') !== 0) {
                $current[$lastPart] = encryptField($current[$lastPart], $key);
            }
        }
    }
    
    return $config;
}

/**
 * Decrittografa i campi sensibili di config
 */
function decryptSensitiveFields(array $config, string $key): array {
    // Campi sensibili da decrittografare
    $sensitiveFields = [
        'email.password',
        'email.username'
    ];
    
    foreach ($sensitiveFields as $path) {
        $parts = explode('.', $path);
        $current = &$config;
        
        // Navigare fino al penultimo livello
        foreach (array_slice($parts, 0, -1) as $part) {
            if (!isset($current[$part])) {
                continue 2; // Skip se il percorso non esiste
            }
            $current = &$current[$part];
        }
        
        $lastPart = end($parts);
        if (isset($current[$lastPart])) {
            $current[$lastPart] = decryptField($current[$lastPart], $key);
        }
    }
    
    return $config;
}

function getLogoFile(): string {
    // Se esiste un file di upload personalizzato, restituiscilo
    $uploadsDir = UPLOADS_DIR;
    if (is_dir($uploadsDir)) {
        $files = glob($uploadsDir . '/tournament-logo.*');
        if (!empty($files)) {
            return 'data/uploads/' . basename($files[0]);
        }
    }
    // Altrimenti restituisci il default
    return 'images/default/logo.png';
}

function getBackgroundFile(): string {
    // Se esiste un file di upload personalizzato, restituiscilo
    $uploadsDir = UPLOADS_DIR;
    if (is_dir($uploadsDir)) {
        $files = glob($uploadsDir . '/tournament-background.*');
        if (!empty($files)) {
            return 'data/uploads/' . basename($files[0]);
        }
    }
    // Altrimenti restituisci il default
    return 'images/default/bg.png';
}

function sendEmail(string $to, string $subject, string $body, string $from = ''): array {
    // Validazione email
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $logMessage = date('Y-m-d H:i:s') . " - FAILED: Email destinatario non valida: $to\n";
        @error_log($logMessage, 3, __DIR__ . '/data/email.log');
        return ['success' => false, 'error' => 'Email destinatario non valida', 'to' => $to];
    }
    
    // Prova prima con PHPMailer (se disponibile)
    $result = sendEmailViaPHPMailer($to, $subject, $body, $from);
    if ($result !== null) {
        return $result;
    }
    
    // Fallback: usa mail() nativa ma salva in coda se fallisce
    return sendEmailFallback($to, $subject, $body, $from);
}

/**
 * Invia email via PHPMailer (SMTP affidabile)
 * Ritorna null se PHPMailer non è disponibile
 */
function sendEmailViaPHPMailer(string $to, string $subject, string $body, string $from = ''): ?array {
    // Controlla se PHPMailer è disponibile
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        return null;
    }
    
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        
        // Leggi la configurazione email da config.json
        $config = readConfig();
        $emailConfig = $config['email'] ?? [];
        
        // Se email è disabilitata, non inviare
        if (!($emailConfig['enabled'] ?? false)) {
            $logMessage = date('Y-m-d H:i:s') . " - SKIPPED: Email disabilitata nella configurazione\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');
            return ['success' => false, 'error' => 'Email non configurata nel pannello admin', 'to' => $to, 'queue' => true];
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Imposta i parametri SMTP direttamente da config
        $host = trim((string)($emailConfig['host'] ?? ''));
        $port = (int)($emailConfig['port'] ?? 587);
        $username = trim((string)($emailConfig['username'] ?? ''));
        $password = trim((string)($emailConfig['password'] ?? ''));
        
        if (empty($host) || empty($username) || empty($password)) {
            $logMessage = date('Y-m-d H:i:s') . " - FAILED: Parametri SMTP incompleti in config.json\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');
            return ['success' => false, 'error' => 'Configurazione SMTP incompleta', 'to' => $to, 'queue' => true];
        }
        
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = (bool)($emailConfig['auth'] ?? true);
        $mail->Username = $username;
        $mail->Password = $password;
        $secure = trim((string)($emailConfig['secure'] ?? 'tls'));
        $mail->SMTPSecure = ($secure === 'ssl') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $port;
        $mail->Timeout = (int)($emailConfig['timeout'] ?? 10);
        
        // Mittente
        $senderEmail = trim((string)($emailConfig['fromEmail'] ?? 'noreply@beachmaster.local'));
        $senderName = trim((string)($emailConfig['fromName'] ?? 'BeachMaster'));
        $mail->setFrom($senderEmail, $senderName);
        
        if (!empty($from) && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($from);
        }
        
        // Destinatario
        $mail->addAddress($to);
        
        // Contenuto
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        
        // Invia
        if ($mail->send()) {
            $logMessage = date('Y-m-d H:i:s') . " - SUCCESS (PHPMailer): Email inviata a $to (Subject: $subject)\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');
            return ['success' => true, 'message' => 'Email inviata con successo', 'to' => $to, 'method' => 'PHPMailer'];
        } else {
            $logMessage = date('Y-m-d H:i:s') . " - FAILED (PHPMailer): " . $mail->ErrorInfo . "\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');
            // Salva nella coda
            saveEmailToQueue($to, $subject, $body, $from);
            return ['success' => false, 'error' => 'Email in coda - verifica tra poco', 'to' => $to, 'queue' => true];
        }
        
    } catch (\Exception $e) {
        $logMessage = date('Y-m-d H:i:s') . " - EXCEPTION (PHPMailer): " . $e->getMessage() . "\n";
        @error_log($logMessage, 3, __DIR__ . '/data/email.log');
        return null; // Prova fallback
    }
}

/**
 * Fallback: usa mail() nativa PHP
 */
function sendEmailFallback(string $to, string $subject, string $body, string $from = ''): array {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    
    if (!empty($from) && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $headers .= "From: $from\r\n";
    } else {
        $headers .= "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'beachmaster.local') . "\r\n";
    }
    
    // Invia email - usa mail() nativa PHP
    $result = @mail($to, $subject, $body, $headers);
    
    // Log del risultato
    if ($result) {
        $logMessage = date('Y-m-d H:i:s') . " - SUCCESS (mail()): Email inviata a $to (Subject: $subject)\n";
        @error_log($logMessage, 3, __DIR__ . '/data/email.log');
        return ['success' => true, 'message' => 'Email inviata con successo', 'to' => $to, 'method' => 'mail()'];
    } else {
        // mail() ha fallito - salva nella coda
        saveEmailToQueue($to, $subject, $body, $from);
        $logMessage = date('Y-m-d H:i:s') . " - QUEUED (mail() failed): Email messa in coda per $to (Subject: $subject)\n";
        @error_log($logMessage, 3, __DIR__ . '/data/email.log');
        return ['success' => false, 'error' => 'Email in coda - verifica tra poco', 'to' => $to, 'queue' => true];
    }
}

/**
 * Salva email nella coda per invio successivo
 */
function saveEmailToQueue(string $to, string $subject, string $body, string $from = ''): void {
    $queueDir = __DIR__ . '/data/email-queue';
    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0777, true);
    }
    
    $queueFile = $queueDir . '/' . time() . '-' . md5($to . $subject) . '.json';
    $queueData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'to' => $to,
        'subject' => $subject,
        'body' => $body,
        'from' => $from,
        'attempts' => 0,
        'status' => 'pending'
    ];
    
    file_put_contents($queueFile, json_encode($queueData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function defaultConfig(): array {
    return [
        'tournament' => [
            'name' => '',
            'maxTeams' => 0,
            'maxPlayersPerTeam' => 3,
            'maxPlayersOnCourt' => 2,
            'maxSubstitutions' => 0,
            'numGroups' => 0,
            'numSets' => 2,
            'winScore' => 21,
            'maxScore' => 25,
            'timePerSetMinutes' => 35,
            'setupTimeMinutes' => 5,
            'maxTimeoutsPerSet' => 2
        ],
        'schedule' => [
            'courts' => []
        ],
        'phases' => [],
        'contact' => [
            'managerEmail' => ''
        ],
        'display' => [
            'theme' => 'chiringuito'
        ],
        'sponsors' => [],
        'payment' => [
            'enabled' => false,
            'costPerTeam' => 0,
            'currency' => 'EUR'
        ],
        'notes' => [],
        'news' => [],
        'autosave' => [
            'enabled' => false,
            'intervalSeconds' => 30,
            'maxSteps' => 10
        ],
        'email' => [
            'enabled' => false,
            'service' => 'gmail',
            'host' => '',
            'port' => 587,
            'secure' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'fromEmail' => 'noreply@beachmaster.local',
            'fromName' => 'BeachMaster',
            'timeout' => 10
        ],
        'security' => [
            'encryptionEnabled' => false,
            'encryptionPassword' => ''
        ]
    ];
}

function mergeConfig(array $existingConfig, array $defaultConfig): array {
    // Funzione per merge ricorsivo intelligente
    $merged = $defaultConfig;
    
    // Preserva i dati critici dell'utente dal config esistente
    if (isset($existingConfig['tournament'])) {
        $merged['tournament'] = array_merge(
            $defaultConfig['tournament'] ?? [],
            $existingConfig['tournament']
        );
    }
    
    if (isset($existingConfig['schedule'])) {
        $merged['schedule'] = $existingConfig['schedule'];
    }
    
    if (isset($existingConfig['phases'])) {
        $merged['phases'] = $existingConfig['phases'];
    }
    
    // Preserva i temi personalizzati
    if (isset($existingConfig['display']['customThemes'])) {
        if (!isset($merged['display'])) {
            $merged['display'] = [];
        }
        $merged['display']['customThemes'] = $existingConfig['display']['customThemes'];
    }
    
    // Preserva il tema corrente se è personalizzato
    if (isset($existingConfig['display']['theme'])) {
        if (!isset($merged['display'])) {
            $merged['display'] = [];
        }
        $merged['display']['theme'] = $existingConfig['display']['theme'];
    }
    
    // Preserva logo e background
    if (isset($existingConfig['display']['logoFile'])) {
        $merged['display']['logoFile'] = $existingConfig['display']['logoFile'];
    }
    if (isset($existingConfig['display']['backgroundFile'])) {
        $merged['display']['backgroundFile'] = $existingConfig['display']['backgroundFile'];
    }
    
    // Preserva la sezione contact (email del gestore, etc.)
    if (isset($existingConfig['contact'])) {
        $merged['contact'] = array_merge(
            $defaultConfig['contact'] ?? [],
            $existingConfig['contact']
        );
    }
    
    // Preserva sponsor list
    if (isset($existingConfig['sponsors'])) {
        $merged['sponsors'] = $existingConfig['sponsors'];
    }
    
    // Preserva payment settings
    if (isset($existingConfig['payment'])) {
        $merged['payment'] = array_merge(
            $defaultConfig['payment'] ?? [],
            $existingConfig['payment']
        );
    }
    
    // Preserva notes
    if (isset($existingConfig['notes'])) {
        $merged['notes'] = $existingConfig['notes'];
    }
    
    // Preserva news
    if (isset($existingConfig['news'])) {
        $merged['news'] = $existingConfig['news'];
    }
    
    // Preserva autosave settings
    if (isset($existingConfig['autosave'])) {
        $merged['autosave'] = array_merge(
            $defaultConfig['autosave'] ?? [],
            $existingConfig['autosave']
        );
    }
    
    // Preserva email settings
    if (isset($existingConfig['email'])) {
        $merged['email'] = array_merge(
            $defaultConfig['email'] ?? [],
            $existingConfig['email']
        );
    }
    
    // Preserva security settings
    if (isset($existingConfig['security'])) {
        $merged['security'] = array_merge(
            $defaultConfig['security'] ?? [],
            $existingConfig['security']
        );
    }
    
    return $merged;
}

function readConfig(): array {
    $default = defaultConfig();
    
    if (!file_exists(CONFIG_FILE)) {
        // Se il file non esiste, crea il default
        writeJsonFile(CONFIG_FILE, $default);
        return $default;
    }
    
    $existing = readJsonFile(CONFIG_FILE, $default);
    
    // Decrittografa se encryption è abilitata
    if (isset($existing['security']['encryptionEnabled']) && $existing['security']['encryptionEnabled'] && isset($existing['security']['encryptionPassword'])) {
        try {
            $key = getEncryptionKey($existing['security']['encryptionPassword']);
            $existing = decryptSensitiveFields($existing, $key);
        } catch (Exception $e) {
            // Se decriptazione fallisce, continua senza decriptare
            @error_log('Decryption error: ' . $e->getMessage());
        }
    }
    
    // Se il config è veramente vuoto (tournament name vuoto), non fare merge - ritorna come-è
    if (isset($existing['tournament']) && $existing['tournament']['name'] === '') {
        // Torneo veramente vuoto, ritorna il config salvato come-è (senza merge con defaults)
        return $existing;
    }
    
    // Se il config esiste e non è vuoto, fai un merge intelligente per upgrade compatibility
    return mergeConfig($existing, $default);
}

function writeConfig(array $config): void {
    // Crittografa se encryption è abilitata
    if (isset($config['security']['encryptionEnabled']) && $config['security']['encryptionEnabled'] && isset($config['security']['encryptionPassword'])) {
        try {
            $key = getEncryptionKey($config['security']['encryptionPassword']);
            $config = encryptSensitiveFields($config, $key);
        } catch (Exception $e) {
            // Se crittografia fallisce, salva comunque (senza crittografia)
            @error_log('Encryption error: ' . $e->getMessage());
        }
    }
    
    writeJsonFile(CONFIG_FILE, $config);
}

function saveToHistory(string $description = 'Modifica'): void {
    $config = readConfig();
    
    // Controlla se autosave è disabilitato
    if (!($config['autosave']['enabled'] ?? false)) {
        return;
    }
    
    $history = readJsonFile(HISTORY_FILE, [
        'snapshots' => [],
        'lastSaved' => null
    ]);
    
    $maxSteps = max(1, min(100, (int)($config['autosave']['maxSteps'] ?? 10)));
    
    // Crea snapshot
    $snapshot = [
        'timestamp' => date('Y-m-d H:i:s'),
        'description' => $description,
        'config' => $config,
        'state' => readJsonFile(DATA_FILE, initialState())
    ];
    
    // Aggiungi snapshot (max maxSteps items)
    array_unshift($history['snapshots'], $snapshot);
    if (count($history['snapshots']) > $maxSteps) {
        array_pop($history['snapshots']);
    }
    
    $history['lastSaved'] = date('Y-m-d H:i:s');
    writeJsonFile(HISTORY_FILE, $history);
}

function getHistory(): array {
    return readJsonFile(HISTORY_FILE, [
        'snapshots' => [],
        'lastSaved' => null
    ]);
}

function withStateTransaction(callable $callback): array {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile aprire il file dati']);
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile bloccare il file dati']);
    }

    $raw = stream_get_contents($fp);
    $loadedState = json_decode($raw ?: '', true);
    
    if (is_array($loadedState)) {
        // Lo state esiste - fai un merge intelligente per upgrade compatibility
        $newState = initialState();
        $state = mergeState($loadedState, $newState);
    } else {
        // Lo state non esiste o non è valido - crea uno nuovo
        $state = initialState();
    }

    $result = $callback($state);
    $state['meta']['lastUpdated'] = gmdate('c');

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return is_array($result) ? $result : [];
}

function initialState(): array {
    $config = readConfig();
    return [
        'settings' => [
            'maxTeams' => $config['tournament']['maxTeams'] ?? 16,
            'tournamentName' => $config['tournament']['name'] ?? ''  // Vuoto se non configurato
        ],
        'teams' => [],
        'groups' => [],
        'groupMatches' => [],
        'playoff' => [
            'quarterFinals' => [],
            'semiFinals' => [],
            'thirdPlace' => null,
            'final' => null
        ],
        'finalRanking' => [],
        'meta' => [
            'lastUpdated' => null
        ]
    ];
}

function mergeState(array $existingState, array $newState): array {
    // Preserva i dati critici del torneo dal state esistente
    $merged = $newState;
    
    // Preserva squadre, gironi, partite e risultati
    if (isset($existingState['teams']) && is_array($existingState['teams'])) {
        $merged['teams'] = $existingState['teams'];
    }
    
    if (isset($existingState['groups']) && is_array($existingState['groups'])) {
        $merged['groups'] = $existingState['groups'];
    }
    
    if (isset($existingState['groupMatches']) && is_array($existingState['groupMatches'])) {
        $merged['groupMatches'] = $existingState['groupMatches'];
    }
    
    if (isset($existingState['playoff']) && is_array($existingState['playoff'])) {
        $merged['playoff'] = $existingState['playoff'];
    }
    
    if (isset($existingState['finalRanking']) && is_array($existingState['finalRanking'])) {
        $merged['finalRanking'] = $existingState['finalRanking'];
    }
    
    // Preserva i metadata
    if (isset($existingState['meta']) && is_array($existingState['meta'])) {
        $merged['meta'] = array_merge($merged['meta'] ?? [], $existingState['meta']);
    }
    
    return $merged;
}

function bodyJson(): array {
    $raw = file_get_contents('php://input');
    if (($raw === false || trim($raw) === '') && PHP_SAPI === 'cli') {
        $stdin = stream_get_contents(STDIN);
        if ($stdin !== false) {
            $raw = $stdin;
        }
    }
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse(400, ['ok' => false, 'error' => 'JSON non valido']);
    }
    return $decoded;
}

function uid(): string {
    return bin2hex(random_bytes(8));
}

function randomInt(int $min, int $max): int {
    return random_int($min, $max);
}

// ===========================
// PHASE MANAGEMENT FUNCTIONS
// ===========================

/**
 * Ottiene o crea l'array phases nello state
 */
function ensurePhases(array &$state): void {
    if (!isset($state['phases']) || !is_array($state['phases'])) {
        // Migra da vecchia struttura se necessario
        if (!empty($state['groups']) || !empty($state['groupMatches'])) {
            $state['phases'] = [
                [
                    'id' => 'phase-1-groups',
                    'phaseIdx' => 1,
                    'name' => 'Fase 1 - Gironi',
                    'type' => 'groups',
                    'status' => !empty($state['groups']) ? 'active' : 'pending',
                    'groups' => $state['groups'] ?? [],
                    'matches' => $state['groupMatches'] ?? [],
                    'standings' => $state['standings'] ?? [],
                    'createdAt' => $state['meta']['groupsCreatedAt'] ?? gmdate('c')
                ],
                [
                    'id' => 'phase-2-knockout',
                    'phaseIdx' => 2,
                    'name' => 'Fase 2 - Playoff',
                    'type' => 'knockout',
                    'status' => 'pending',
                    'matches' => $state['playoff'] ?? ['quarterFinals' => [], 'semiFinals' => [], 'final' => null],
                    'standings' => [],
                    'createdAt' => null
                ]
            ];
            $state['currentPhaseIdx'] = 1;
        } else {
            $state['phases'] = [];
            $state['currentPhaseIdx'] = 0;
        }
    }
    
    if (!isset($state['currentPhaseIdx'])) {
        $state['currentPhaseIdx'] = 1;
    }
}

/**
 * Ottiene una fase per indice
 */
function getPhase(array &$state, int $phaseIdx): ?array {
    ensurePhases($state);
    foreach ($state['phases'] as &$phase) {
        if ($phase['phaseIdx'] === $phaseIdx) {
            return $phase;
        }
    }
    return null;
}

/**
 * Inizializza una nuova fase
 */
function initializePhase(array &$state, int $phaseIdx, string $name, string $type, array $config = []): array {
    ensurePhases($state);
    
    $phase = [
        'id' => 'phase-' . $phaseIdx . '-' . $type,
        'phaseIdx' => $phaseIdx,
        'name' => $name,
        'type' => $type,
        'status' => 'pending',
        'groups' => $config['groups'] ?? [],
        'matches' => $config['matches'] ?? [],
        'standings' => $config['standings'] ?? [],
        'createdAt' => gmdate('c'),
        'metadata' => $config['metadata'] ?? []
    ];
    
    // Rimuovi fasi precedenti con lo stesso phaseIdx se esiste
    $state['phases'] = array_filter($state['phases'], function($p) use ($phaseIdx) {
        return $p['phaseIdx'] !== $phaseIdx;
    });
    
    $state['phases'][] = $phase;
    $state['currentPhaseIdx'] = $phaseIdx;
    
    return $phase;
}

/**
 * Ottiene le partite di una fase
 */
function getPhaseMatches(array &$state, int $phaseIdx): array {
    $phase = getPhase($state, $phaseIdx);
    if (!$phase) return [];
    
    if ($phase['type'] === 'groups') {
        return $phase['matches'] ?? [];
    } elseif ($phase['type'] === 'knockout') {
        return $phase['matches'] ?? [];
    }
    
    return [];
}

/**
 * Imposta le partite di una fase
 */
function setPhaseMatches(array &$state, int $phaseIdx, array $matches): void {
    ensurePhases($state);
    foreach ($state['phases'] as &$phase) {
        if ($phase['phaseIdx'] === $phaseIdx) {
            $phase['matches'] = $matches;
            break;
        }
    }
}

/**
 * Ottiene i gironi di una fase
 */
function getPhaseGroups(array &$state, int $phaseIdx): array {
    $phase = getPhase($state, $phaseIdx);
    if (!$phase) return [];
    return $phase['groups'] ?? [];
}

/**
 * Imposta i gironi di una fase
 */
function setPhaseGroups(array &$state, int $phaseIdx, array $groups): void {
    ensurePhases($state);
    foreach ($state['phases'] as &$phase) {
        if ($phase['phaseIdx'] === $phaseIdx) {
            $phase['groups'] = $groups;
            break;
        }
    }
}

/**
 * Aggiorna lo status di una fase
 */
function setPhaseStatus(array &$state, int $phaseIdx, string $status): void {
    ensurePhases($state);
    foreach ($state['phases'] as &$phase) {
        if ($phase['phaseIdx'] === $phaseIdx) {
            $phase['status'] = $status; // 'pending', 'active', 'completed'
            break;
        }
    }
}

function getVersionInfo(): array {
    return readJsonFile(VERSION_FILE, [
        'version' => '1.0.0',
        'releaseDate' => date('Y-m-d'),
        'features' => [],
        'minPhpVersion' => '8.0.0'
    ]);
}

function getReleasesInfo(): array {
    return readJsonFile(RELEASES_FILE, [
        'latestVersion' => '1.0.0',
        'latestReleaseDate' => date('Y-m-d'),
        'downloadUrl' => '',
        'releaseNotes' => 'No updates available',
        'breaking' => false,
        'migrations' => []
    ]);
}

function compareVersions(string $v1, string $v2): int {
    // Ritorna: -1 se v1 < v2, 0 se uguali, 1 se v1 > v2
    $p1 = array_map('intval', explode('.', $v1));
    $p2 = array_map('intval', explode('.', $v2));
    
    $len = max(count($p1), count($p2));
    for ($i = 0; $i < $len; $i++) {
        $a = $p1[$i] ?? 0;
        $b = $p2[$i] ?? 0;
        if ($a < $b) return -1;
        if ($a > $b) return 1;
    }
    return 0;
}

function createProgramZip(): ?string {
    if (!is_dir(UPDATES_DIR)) {
        mkdir(UPDATES_DIR, 0777, true);
    }
    
    $zipPath = UPDATES_DIR . '/chiringuitobeachvolley-' . time() . '.zip';
    $rootDir = __DIR__;
    $tmpList = UPDATES_DIR . '/.ziplist';
    
    // Crea lista di file da includere nel ZIP
    $command = "cd " . escapeshellarg($rootDir) . " && find . -type f";
    $output = [];
    exec($command, $output, $returnCode);
    
    $files = [];
    foreach ($output as $file) {
        $file = substr($file, 2); // Rimuove il "./" iniziale
        
        // Ignora questi path
        if (preg_match('~^\.git|^\.gitignore|^node_modules|^data/updates|^\.~', $file)) {
            continue;
        }
        
        $files[] = $file;
    }
    
    if (empty($files)) {
        return null;
    }
    
    // Crea ZIP con i file trovati
    $fileList = implode(' ', array_map('escapeshellarg', $files));
    $command = "cd " . escapeshellarg($rootDir) . " && zip -q -r " . escapeshellarg($zipPath) . " " . $fileList;
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0 || !file_exists($zipPath)) {
        return null;
    }
    
    return $zipPath;
}

function extractUpdateZip(string $zipPath, bool $backup = true): array {
    // Crea backup se richiesto
    if ($backup) {
        $backupPath = UPDATES_DIR . '/backup-pre-update-' . time() . '.zip';
        $rootDir = __DIR__;
        $command = "cd " . escapeshellarg($rootDir) . " && zip -q -r " . escapeshellarg($backupPath) . " " .
                   escapeshellarg('data/config.json') . " " . escapeshellarg('data/tournament.json');
        exec($command, $output, $returnCode);
    }
    
    // Verifica che il file ZIP esista e sia valido
    if (!file_exists($zipPath)) {
        return ['ok' => false, 'error' => 'ZIP file not found'];
    }
    
    // Estrae lo ZIP nella root del progetto
    $extractPath = __DIR__;
    $command = "cd " . escapeshellarg($extractPath) . " && unzip -q -o " . escapeshellarg($zipPath);
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        return ['ok' => false, 'error' => 'Failed to extract files. Return code: ' . $returnCode];
    }
    
    return ['ok' => true, 'message' => 'Update installed successfully'];
}

function uploadViaFtp(string $localFilePath, string $ftpHost, int $ftpPort, string $ftpUsername, string $ftpPassword, string $ftpRemotePath): array {
    if (!function_exists('ftp_connect')) {
        return ['ok' => false, 'error' => 'FTP extension not available'];
    }
    
    if (!file_exists($localFilePath)) {
        return ['ok' => false, 'error' => 'Local file not found: ' . $localFilePath];
    }
    
    // Connessione FTP
    $ftpConn = @ftp_connect($ftpHost, $ftpPort, 30);
    if (!$ftpConn) {
        return ['ok' => false, 'error' => 'Cannot connect to FTP server: ' . $ftpHost . ':' . $ftpPort];
    }
    
    // Login
    if (!@ftp_login($ftpConn, $ftpUsername, $ftpPassword)) {
        ftp_close($ftpConn);
        return ['ok' => false, 'error' => 'FTP login failed. Invalid credentials or permissions'];
    }
    
    // Abilita passive mode
    if (!@ftp_pasv($ftpConn, true)) {
        ftp_close($ftpConn);
        return ['ok' => false, 'error' => 'Cannot enable passive mode'];
    }
    
    // Crea directory remota se non esiste
    $remotePath = rtrim($ftpRemotePath, '/');
    $fileName = basename($localFilePath);
    $remoteFilePath = $remotePath . '/' . $fileName;
    
    // Upload file
    if (!@ftp_put($ftpConn, $remoteFilePath, $localFilePath, FTP_BINARY)) {
        ftp_close($ftpConn);
        return ['ok' => false, 'error' => 'Upload failed. Check remote path permissions'];
    }
    
    ftp_close($ftpConn);
    return ['ok' => true, 'message' => 'File uploaded successfully via FTP to ' . $remoteFilePath, 'remoteFile' => $remoteFilePath];
}

function uploadViaSftp(string $localFilePath, string $sftpHost, int $sftpPort, string $sftpUsername, string $sftpPassword, string $sftpRemotePath): array {
    // Controlla se SSH2 è disponibile
    if (!function_exists('ssh2_connect')) {
        return ['ok' => false, 'error' => 'SSH2 extension not available. Installare php-ssh2 o usare FTP'];
    }
    
    if (!file_exists($localFilePath)) {
        return ['ok' => false, 'error' => 'Local file not found: ' . $localFilePath];
    }
    
    // Connessione SSH2
    $sshConn = @ssh2_connect($sftpHost, $sftpPort);
    if (!$sshConn) {
        return ['ok' => false, 'error' => 'Cannot connect to SFTP server: ' . $sftpHost . ':' . $sftpPort];
    }
    
    // Autenticazione con password
    if (!@ssh2_auth_password($sshConn, $sftpUsername, $sftpPassword)) {
        // Prova con chiave pubblica se disponibile
        return ['ok' => false, 'error' => 'SFTP authentication failed. Check credentials'];
    }
    
    // Sftp subsystem
    $sftpConn = @ssh2_sftp($sshConn);
    if (!$sftpConn) {
        return ['ok' => false, 'error' => 'Cannot initialize SFTP'];
    }
    
    // Upload file
    $remotePath = rtrim($sftpRemotePath, '/');
    $fileName = basename($localFilePath);
    $remoteFilePath = 'ssh2.sftp://' . intval($sftpConn) . $remotePath . '/' . $fileName;
    
    if (!@copy($localFilePath, $remoteFilePath)) {
        return ['ok' => false, 'error' => 'SFTP upload failed. Check remote path permissions'];
    }
    
    return ['ok' => true, 'message' => 'File uploaded successfully via SFTP to ' . $remotePath . '/' . $fileName, 'remoteFile' => $remotePath . '/' . $fileName];
}

function randomScore(): array {
    $a = randomInt(10, 21);
    $b = randomInt(10, 21);
    while ($a === $b) {
        $b = randomInt(10, 21);
    }
    return [$a, $b];
}

function shuffleArray(array $arr): array {
    $copy = $arr;
    shuffle($copy);
    return $copy;
}

/**
 * Calcola il numero di squadre qualificate e eliminate da una fase
 * @param array $phase - Configurazione della fase
 * @param int $teamsIn - Numero di squadre in ingresso in questa fase
 * @return array ['qualified' => int, 'eliminated' => int]
 */
function calculatePhaseTeams(array $phase, int $teamsIn): array {
    if ($phase['type'] === 'groups') {
        $numGroups = $phase['numGroups'] ?? 4;
        $teamsAdvance = $phase['teamsAdvance'] ?? 2;
        
        // Squadre qualificate = numGroups * teamsAdvance
        $qualified = $numGroups * $teamsAdvance;
        
        // Se teamsIn non è divisibile per numGroups, aggiustiamo
        $teamsPerGroup = (int)ceil($teamsIn / $numGroups);
        $totalTeamsInGroups = $numGroups * $teamsPerGroup;
        
        // Le eliminate sono il resto
        $eliminated = max(0, $totalTeamsInGroups - $qualified);
        
        return [
            'qualified' => min($qualified, $teamsIn),
            'eliminated' => min($eliminated, $teamsIn)
        ];
    } elseif ($phase['type'] === 'knockout') {
        $numTeams = $phase['numTeams'] ?? 8;
        // Nel knockout, i vincitori passano alla fase successiva
        $qualified = (int)ceil($numTeams / 2);
        $eliminated = (int)floor($numTeams / 2);
        
        return [
            'qualified' => $qualified,
            'eliminated' => $eliminated
        ];
    }
    
    return ['qualified' => $teamsIn, 'eliminated' => 0];
}

/**
 * Restituisce il numero di squadre che dovrebbe avere la fase successiva
 * basandosi sulla fase precedente e sul branch (qualified o eliminated)
 * @param array $phases - Array di tutte le fasi
 * @param int $currentPhaseIdx - Indice della fase corrente
 * @param string $branch - 'qualified' o 'eliminated'
 * @param int $teamsIn - Numero di squadre nella fase corrente
 * @return int numero di squadre per la fase successiva
 */
function getTeamsForNextPhase(array $phases, int $currentPhaseIdx, string $branch = 'qualified', int $teamsIn = 0): int {
    if ($currentPhaseIdx >= count($phases) - 1 || $teamsIn === 0) {
        return 0;
    }
    
    $currentPhase = $phases[$currentPhaseIdx];
    $teams = calculatePhaseTeams($currentPhase, $teamsIn);
    
    // Restituisci il numero di squadre del branch richiesto
    if ($branch === 'qualified') {
        return $teams['qualified'];
    } else {
        return $teams['eliminated'];
    }
}

/**
 * Calcola il numero di squadre totali per la prima fase
 * @param array $config - Configurazione del torneo
 * @param array $state - Stato del torneo
 * @return int numero di squadre approvate
 */
function getInitialTeamsCount(array $config, array $state): int {
    $approvedTeams = array_filter($state['teams'] ?? [], fn($t) => $t['approved'] ?? false);
    $approvedCount = count($approvedTeams);
    
    // Se non ci sono squadre approvate, usa il maxTeams configurato
    if ($approvedCount === 0) {
        return $config['tournament']['maxTeams'] ?? 16;
    }
    
    return $approvedCount;
}

function getTeamMap(array $state): array {
    $map = [];
    foreach ($state['teams'] as $team) {
        $map[$team['id']] = $team;
    }
    return $map;
}

function approvedTeams(array $state): array {
    return array_values(array_filter($state['teams'], fn($t) => !empty($t['approved'])));
}

function generateDummyTeamName(int $index): string {
    $names = [
        'Sand Force', 'Wave Warriors', 'Sunset Strikers', 'Beach Kings',
        'Ace Smash', 'Spike Force', 'Net Ninjas', 'Block Party',
        'Dig Deep', 'Serve Smash', 'Jump Set Go', 'Power Volley',
        'Sand Sharks', 'Top Spin', 'Last Stand', 'Blue Fire'
    ];
    return $names[$index % count($names)] . (intdiv($index, count($names)) > 0 ? ' ' . (intdiv($index, count($names))) : '');
}

/**
 * Calcola il peso di una squadra come media dei livelli dei giocatori
 * Livelli: 0=Amatore, 1=Intermedio, 2=Avanzato, 3=Professionista, 4=Top
 * Default per vecchio formato: 2 (Avanzato)
 */
function getTeamWeight(array $team): float {
    $players = $team['players'] ?? [];
    if (empty($players)) {
        return 2.0; // Default per squadre senza giocatori
    }
    
    $totalLevel = 0;
    $playerCount = 0;
    
    foreach ($players as $player) {
        if (is_array($player) && !empty($player['name'])) {
            $level = (int)($player['level'] ?? 2);
            $totalLevel += $level;
            $playerCount++;
        } elseif (is_string($player) && !empty($player)) {
            // Vecchio formato: string player
            $totalLevel += 2; // Default level
            $playerCount++;
        }
    }
    
    return $playerCount > 0 ? (float)($totalLevel / $playerCount) : 2.0;
}

/**
 * Distribuisce le squadre nei gironi usando snake-draft bilanciato
 * Garantisce che ogni girone abbia peso simile
 */
function balancedGroupDistribution(array $teams, int $groupCount): array {
    // Ordina squadre per peso (decrescente)
    $sortedTeams = $teams;
    usort($sortedTeams, function($a, $b) {
        return getTeamWeight($b) <=> getTeamWeight($a);
    });
    
    // Inizializza i gironi
    $groups = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $groups[] = [
            'name' => chr(65 + $i),
            'teamIds' => [],
            'totalWeight' => 0.0
        ];
    }
    
    // Snake-draft: alternare direzione per bilanciare i pesi
    $forward = true;
    foreach ($sortedTeams as $team) {
        $weight = getTeamWeight($team);
        
        if ($forward) {
            // Dall'inizio alla fine: assegna al girone con peso minore
            usort($groups, fn($a, $b) => $a['totalWeight'] <=> $b['totalWeight']);
        } else {
            // Dalla fine all'inizio: assegna ancora al girone con peso minore
            usort($groups, fn($a, $b) => $a['totalWeight'] <=> $b['totalWeight']);
        }
        
        $groups[0]['teamIds'][] = $team['id'];
        $groups[0]['totalWeight'] += $weight;
        $forward = !$forward; // Alterna direzione
    }
    
    // Rimuovi il campo temporaneo totalWeight dal risultato
    foreach ($groups as &$group) {
        unset($group['totalWeight']);
    }
    unset($group);
    
    return $groups;
}

/**
 * Crea seeding bilanciato per knockout
 * Le squadre più forti vengono posizionate negli incroci opposti (top 8)
 * per garantire partite competitive
 */
function balancedKnockoutSeeding(array $teams): array {
    // Ordina squadre per peso (decrescente)
    $sortedTeams = $teams;
    usort($sortedTeams, function($a, $b) {
        return getTeamWeight($b) <=> getTeamWeight($a);
    });
    
    // Genera seeding bilanciato: 1vs8, 4vs5, 2vs7, 3vs6
    // Questo garantisce che le squadre più forti affrontino quelle più deboli
    $seeding = [];
    $count = count($sortedTeams);
    
    if ($count >= 2) {
        // QF1: 1° vs ultimo
        $seeding[] = [
            'team1' => $sortedTeams[0]['id'],
            'team2' => $sortedTeams[$count - 1]['id']
        ];
    }
    
    if ($count >= 4) {
        // QF2: 3° vs penultimo
        $seeding[] = [
            'team1' => $sortedTeams[2]['id'],
            'team2' => $sortedTeams[$count - 2]['id']
        ];
    }
    
    if ($count >= 6) {
        // QF3: 2° vs terzultimo
        $seeding[] = [
            'team1' => $sortedTeams[1]['id'],
            'team2' => $sortedTeams[$count - 3]['id']
        ];
    }
    
    if ($count >= 8) {
        // QF4: 4° vs quartultimo
        $seeding[] = [
            'team1' => $sortedTeams[3]['id'],
            'team2' => $sortedTeams[$count - 4]['id']
        ];
    }
    
    return $seeding;
}

function tournamentStarted(array $state): bool {
    if (count($state['groups']) > 0 || count($state['groupMatches']) > 0) {
        return true;
    }

    if (
        count($state['playoff']['quarterFinals']) > 0 ||
        count($state['playoff']['semiFinals']) > 0 ||
        $state['playoff']['thirdPlace'] !== null ||
        $state['playoff']['final'] !== null
    ) {
        return true;
    }

    return count($state['finalRanking']) > 0;
}

function publicState(array $state): array {
    $teamMap = getTeamMap($state);
    $config = readConfig();
    
    // Assicurati che settings esista, altrimenti crea da config
    if (!isset($state['settings'])) {
        $state['settings'] = [
            'maxTeams' => $config['tournament']['maxTeams'] ?? 16,
            'tournamentName' => $config['tournament']['name'] ?? ''
        ];
    }
    
    return [
        'settings' => $state['settings'],
        'teams' => array_values(array_map(function ($t) {
            return [
                'id' => $t['id'],
                'name' => $t['name'],
                'players' => $t['players'],
                'category' => 'Misto',
                'paid' => (bool)($t['paid'] ?? false),
                'approved' => (bool)($t['approved'] ?? false)
            ];
        }, array_filter($state['teams'], fn($t) => !empty($t['approved'])))),
        'pendingCount' => count(array_filter($state['teams'], fn($t) => empty($t['approved']))),
        'groups' => array_values(array_map(function ($g) use ($teamMap) {
            return [
                'name' => $g['name'],
                'teams' => array_values(array_map(function ($id) use ($teamMap) {
                    return [
                        'id' => $id,
                        'name' => $teamMap[$id]['name'] ?? 'N/D',
                        'players' => $teamMap[$id]['players'] ?? []
                    ];
                }, $g['teamIds']))
            ];
        }, $state['groups'])),
        'groupMatches' => array_values(array_map(function ($m) use ($teamMap) {
            return [
                'matchId' => $m['id'],
                'id' => $m['id'],
                'group' => $m['group'],
                'team1Id' => $m['team1Id'],
                'team2Id' => $m['team2Id'],
                'team1Name' => $teamMap[$m['team1Id']]['name'] ?? 'N/D',
                'team2Name' => $teamMap[$m['team2Id']]['name'] ?? 'N/D',
                'score1' => $m['score1'],
                'score2' => $m['score2'],
                'date' => $m['date'] ?? null,
                'dayDate' => $m['date'] ?? null,  // Alias per compatibilità frontend
                'courtId' => $m['courtId'] ?? null,
                'courtIdx' => $m['courtIdx'] ?? null,  // Indice della corte
                'courtName' => $m['courtName'] ?? null,
                'startTime' => $m['startTime'] ?? null,
                'endTime' => $m['endTime'] ?? null,
                'time' => !empty($m['startTime']) && !empty($m['endTime']) ? ($m['startTime'] . ' - ' . $m['endTime']) : '',  // Formato leggibile
                'dateIdx' => $m['dateIdx'] ?? null,  // Indice della data
                'slotIdx' => $m['slotIdx'] ?? null,  // Indice dello slot
                'duration' => $m['duration'] ?? null
            ];
        }, $state['groupMatches'])),
        'standings' => computeStandings($state),
        'playoff' => playoffView($state),
        'finalRanking' => $state['finalRanking'],
        'meta' => $state['meta']
    ];
}

function playoffView(array $state): array {
    $teamMap = getTeamMap($state);
    $mapFn = function ($m) use ($teamMap) {
        return [
            'id' => $m['id'],
            'label' => $m['label'],
            'team1Id' => $m['team1Id'],
            'team2Id' => $m['team2Id'],
            'team1Name' => $teamMap[$m['team1Id']]['name'] ?? '-',
            'team2Name' => $teamMap[$m['team2Id']]['name'] ?? '-',
            'score1' => $m['score1'],
            'score2' => $m['score2']
        ];
    };

    return [
        'quarterFinals' => array_values(array_map($mapFn, $state['playoff']['quarterFinals'])),
        'semiFinals' => array_values(array_map($mapFn, $state['playoff']['semiFinals'])),
        'thirdPlace' => $state['playoff']['thirdPlace'] ? $mapFn($state['playoff']['thirdPlace']) : null,
        'final' => $state['playoff']['final'] ? $mapFn($state['playoff']['final']) : null
    ];
}

function computeStandings(array $state): array {
    $teamMap = getTeamMap($state);
    $out = [];

    foreach ($state['groups'] as $group) {
        $rows = [];
        foreach ($group['teamIds'] as $teamId) {
            $rows[$teamId] = [
                'teamId' => $teamId,
                'name' => $teamMap[$teamId]['name'] ?? 'N/D',
                'played' => 0,
                'won' => 0,
                'lost' => 0,
                'points' => 0,
                'scored' => 0,
                'conceded' => 0,
                'diff' => 0
            ];
        }

        foreach ($state['groupMatches'] as $match) {
            if ($match['group'] !== $group['name']) {
                continue;
            }
            if ($match['score1'] === null || $match['score2'] === null) {
                continue;
            }
            $t1 = $match['team1Id'];
            $t2 = $match['team2Id'];
            if (!isset($rows[$t1], $rows[$t2])) {
                continue;
            }

            $rows[$t1]['played']++;
            $rows[$t2]['played']++;
            $rows[$t1]['scored'] += $match['score1'];
            $rows[$t1]['conceded'] += $match['score2'];
            $rows[$t2]['scored'] += $match['score2'];
            $rows[$t2]['conceded'] += $match['score1'];

            if ($match['score1'] > $match['score2']) {
                $rows[$t1]['won']++;
                $rows[$t2]['lost']++;
                $rows[$t1]['points'] += 2;
            } elseif ($match['score2'] > $match['score1']) {
                $rows[$t2]['won']++;
                $rows[$t1]['lost']++;
                $rows[$t2]['points'] += 2;
            }
        }

        foreach ($rows as &$r) {
            $r['diff'] = $r['scored'] - $r['conceded'];
        }
        unset($r);

        $rows = array_values($rows);
        usort($rows, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['diff'] !== $b['diff']) return $b['diff'] <=> $a['diff'];
            if ($a['scored'] !== $b['scored']) return $b['scored'] <=> $a['scored'];
            return strcmp($a['name'], $b['name']);
        });

        $out[] = [
            'group' => $group['name'],
            'rows' => $rows
        ];
    }

    return $out;
}

function validateScheduleForTournament(array $state): array {
    $config = readConfig();
    $courts = $config['schedule']['courts'] ?? [];

    if (empty($courts)) {
        return ['valid' => false, 'message' => 'Nessun giorno schedulato nel torneo'];
    }

    // Calcola numero totale di partite nei gironi
    $totalMatches = 0;
    foreach ($state['groups'] as $group) {
        $teamCount = count($group['teamIds']);
        if ($teamCount >= 2) {
            $totalMatches += intdiv($teamCount * ($teamCount - 1), 2);
        }
    }

    // Leggi durata dell'incontro dalla configurazione del torneo
    $tournament = $config['tournament'] ?? [];
    $setupTimeMinutes = (int)($tournament['setupTimeMinutes'] ?? 5);
    $timePerSetMinutes = (int)($tournament['timePerSetMinutes'] ?? 15);
    $numSets = (int)($tournament['numSets'] ?? 2);
    $matchDurationMinutes = $setupTimeMinutes + ($timePerSetMinutes * $numSets);
    
    // ✅ CORRETTA: Calcola il numero TOTALE di match che possono stare negli slot
    // Per ogni timeSlot, calcola quanti match ci stanno (fill capacity)
    // Poi somma per tutti i timeSlot
    $totalSlots = 0;
    $slotDetails = []; // Per debugging
    
    foreach ($courts as $courtIdx => $court) {
        foreach ($court['availability'] ?? [] as $dateAvail) {
            $date = $dateAvail['date'] ?? '';
            foreach ($dateAvail['timeSlots'] ?? [] as $timeSlot) {
                $startTime = $timeSlot['startTime'] ?? '';
                $endTime = $timeSlot['endTime'] ?? '';
                
                // Calcola durata di questo timeSlot
                if ($startTime && $endTime) {
                    $startMinutes = parseTimeToMinutes($startTime);
                    $endMinutes = parseTimeToMinutes($endTime);
                    $slotDurationMinutes = $endMinutes - $startMinutes;
                    
                    // Calcola quanti incontri ci stanno in questo timeSlot
                    if ($slotDurationMinutes > 0 && $matchDurationMinutes > 0) {
                        $matchesInSlot = (int)floor($slotDurationMinutes / $matchDurationMinutes);
                        $matchesInSlot = max(1, $matchesInSlot); // Almeno 1 incontro per slot
                        $totalSlots += $matchesInSlot;
                        
                        $slotDetails[] = [
                            'date' => $date,
                            'startTime' => $startTime,
                            'endTime' => $endTime,
                            'slotDuration' => $slotDurationMinutes,
                            'matchDuration' => $matchDurationMinutes,
                            'matchesInSlot' => $matchesInSlot,
                            'courtIdx' => $courtIdx,
                            'courtName' => $court['courtName'] ?? 'Campo'
                        ];
                    }
                }
            }
        }
    }

    if ($totalMatches === 0) {
        return ['valid' => false, 'message' => 'Nessuna partita da programmare'];
    }

    // ✅ Validazione: totalSlots rappresenta il numero di match che possono essere programmati
    if ($totalMatches > $totalSlots) {
        return [
            'valid' => false, 
            'message' => "Slot insufficienti! Partite necessarie: {$totalMatches} | Slot effettivi disponibili: {$totalSlots}. Aggiungi più campi, giorni o fasce orarie.",
            'totalMatches' => $totalMatches,
            'totalSlots' => $totalSlots,
            'matchDurationMinutes' => $matchDurationMinutes,
            'setupTime' => $setupTimeMinutes,
            'timePerSet' => $timePerSetMinutes,
            'numSets' => $numSets,
            'slotDetails' => $slotDetails
        ];
    }

    return [
        'valid' => true, 
        'totalMatches' => $totalMatches, 
        'totalSlots' => $totalSlots,
        'matchDurationMinutes' => $matchDurationMinutes,
        'setupTime' => $setupTimeMinutes,
        'timePerSet' => $timePerSetMinutes,
        'numSets' => $numSets
    ];
}

function parseTimeToMinutes(string $timeStr): int {
    $parts = explode(':', $timeStr);
    if (count($parts) !== 2) return 0;
    $hours = (int)$parts[0];
    $minutes = (int)$parts[1];
    return $hours * 60 + $minutes;
}

function buildGroupMatches(array &$state): void {
    $matches = [];
    $day = 1;
    $slot = 0;

    foreach ($state['groups'] as $group) {
        $ids = $group['teamIds'];
        for ($i = 0; $i < count($ids) - 1; $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $hour = 19 + intdiv($slot, 2);
                $mins = ($slot % 2 === 0) ? '30' : '55';
                $matches[] = [
                    'id' => uid(),
                    'group' => $group['name'],
                    'team1Id' => $ids[$i],
                    'team2Id' => $ids[$j],
                    'score1' => null,
                    'score2' => null,
                    'day' => $day,
                    'time' => $hour . ':' . $mins
                ];
                $slot++;
                if ($slot > 5) {
                    $slot = 0;
                    $day++;
                }
            }
        }
    }

    $state['groupMatches'] = $matches;
}

function winnerLoser(?array $match): array {
    if (!$match || $match['score1'] === null || $match['score2'] === null) {
        return ['winner' => null, 'loser' => null];
    }
    if ($match['score1'] > $match['score2']) {
        return ['winner' => $match['team1Id'], 'loser' => $match['team2Id']];
    }
    if ($match['score2'] > $match['score1']) {
        return ['winner' => $match['team2Id'], 'loser' => $match['team1Id']];
    }
    return ['winner' => null, 'loser' => null];
}

function createPlayoff(array &$state): bool {
    $standings = computeStandings($state);
    $first = [];
    $second = [];

    foreach ($standings as $g) {
        if (!empty($g['rows'][0])) $first[] = $g['rows'][0]['teamId'];
        if (!empty($g['rows'][1])) $second[] = $g['rows'][1]['teamId'];
    }

    $qualified = array_slice(array_merge($first, $second), 0, 8);
    if (count($qualified) < 8) {
        return false;
    }

    // Recupera gli oggetti squadra dalle loro ID per calcolare il peso
    $teamsById = [];
    foreach ($state['teams'] as $team) {
        $teamsById[$team['id']] = $team;
    }
    
    // Crea array di squadre qualificate con i loro dati completi per il seeding bilanciato
    $qualifiedTeams = [];
    foreach ($qualified as $teamId) {
        if (isset($teamsById[$teamId])) {
            $qualifiedTeams[] = $teamsById[$teamId];
        }
    }
    
    // Genera seeding bilanciato per peso
    $seeding = balancedKnockoutSeeding($qualifiedTeams);
    
    // Crea i quarter-finals usando il seeding bilanciato
    $state['playoff']['quarterFinals'] = [];
    foreach ($seeding as $idx => $match) {
        $state['playoff']['quarterFinals'][] = [
            'id' => uid(),
            'label' => 'QF' . ($idx + 1),
            'team1Id' => $match['team1'],
            'team2Id' => $match['team2'],
            'score1' => null,
            'score2' => null
        ];
    }

    $state['playoff']['semiFinals'] = [
        ['id' => uid(), 'label' => 'SF1', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null],
        ['id' => uid(), 'label' => 'SF2', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null]
    ];
    $state['playoff']['thirdPlace'] = ['id' => uid(), 'label' => '3P', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null];
    $state['playoff']['final'] = ['id' => uid(), 'label' => 'F', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null];

    return true;
}

function updatePlayoffTree(array &$state): void {
    $qf = $state['playoff']['quarterFinals'];
    $sf = &$state['playoff']['semiFinals'];
    $final = &$state['playoff']['final'];
    $third = &$state['playoff']['thirdPlace'];

    if (count($qf) !== 4 || count($sf) !== 2 || !$final || !$third) {
        return;
    }

    $qw = array_map(fn($m) => winnerLoser($m)['winner'], $qf);
    $sf[0]['team1Id'] = $qw[0];
    $sf[0]['team2Id'] = $qw[1];
    $sf[1]['team1Id'] = $qw[2];
    $sf[1]['team2Id'] = $qw[3];

    $sf1 = winnerLoser($sf[0]);
    $sf2 = winnerLoser($sf[1]);

    $final['team1Id'] = $sf1['winner'];
    $final['team2Id'] = $sf2['winner'];
    $third['team1Id'] = $sf1['loser'];
    $third['team2Id'] = $sf2['loser'];
}

function computeFinalRanking(array &$state): void {
    $teamMap = getTeamMap($state);
    $standings = computeStandings($state);
    $rankingIds = [];

    $finalWL = winnerLoser($state['playoff']['final']);
    $thirdWL = winnerLoser($state['playoff']['thirdPlace']);

    if ($finalWL['winner']) $rankingIds[] = $finalWL['winner'];
    if ($finalWL['loser']) $rankingIds[] = $finalWL['loser'];
    if ($thirdWL['winner']) $rankingIds[] = $thirdWL['winner'];
    if ($thirdWL['loser']) $rankingIds[] = $thirdWL['loser'];

    foreach ($state['playoff']['semiFinals'] as $sf) {
        $wl = winnerLoser($sf);
        if ($wl['loser']) $rankingIds[] = $wl['loser'];
    }

    foreach ($standings as $g) {
        foreach ($g['rows'] as $r) {
            $rankingIds[] = $r['teamId'];
        }
    }

    $rankingIds = array_values(array_unique($rankingIds));
    $state['finalRanking'] = [];
    foreach ($rankingIds as $idx => $teamId) {
        $state['finalRanking'][] = [
            'position' => $idx + 1,
            'teamId' => $teamId,
            'name' => $teamMap[$teamId]['name'] ?? 'N/D'
        ];
    }
}

function calculateMatchDuration(array $state, ?int $score1, ?int $score2): int {
    $config = readConfig();
    $tournament = $config['tournament'];
    
    if ($score1 === null || $score2 === null) {
        return $tournament['setupTimeMinutes'] + ($tournament['numSets'] * $tournament['timePerSetMinutes']);
    }
    
    $setsPlayed = 1;
    $s1Win = ($score1 >= $tournament['winScore'] && $score1 - $score2 >= 2);
    $s2Win = ($score2 >= $tournament['winScore'] && $score2 - $score1 >= 2);
    
    if ($s1Win || $s2Win) {
        $setsPlayed = 1;
    } elseif (($score1 >= $tournament['winScore'] && $score2 >= $tournament['winScore'] - 1) ||
              ($score2 >= $tournament['winScore'] && $score1 >= $tournament['winScore'] - 1)) {
        $setsPlayed = 2;
    }
    
    return $tournament['setupTimeMinutes'] + ($setsPlayed * $tournament['timePerSetMinutes']);
}

function parseTime(string $timeStr): int {
    [$h, $m] = explode(':', $timeStr);
    return (int)$h * 60 + (int)$m;
}

function addMinutes(string $timeStr, int $minutes): string {
    $totalMin = parseTime($timeStr) + $minutes;
    $h = intdiv($totalMin, 60);
    $m = $totalMin % 60;
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

function buildGroupMatchesWithSchedule(array &$state): void {
    $config = readConfig();
    $courts = $config['schedule']['courts'] ?? [];
    
    // DEBUG: Log config structure
    error_log('DEBUG buildGroupMatchesWithSchedule: Reading config');
    error_log('  config keys: ' . implode(', ', array_keys($config)));
    error_log('  has schedule: ' . (isset($config['schedule']) ? 'YES' : 'NO'));
    error_log('  courts count: ' . count($courts));
    error_log('  RAW CONFIG SCHEDULE: ' . json_encode($config['schedule'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    if (empty($courts)) {
        error_log('DEBUG buildGroupMatchesWithSchedule: No courts found, using buildGroupMatches as fallback');
        buildGroupMatches($state);
        return;
    }
    
    // Verifica che i courts abbiano la struttura corretta
    $validCourts = 0;
    $totalSlots = 0;
    foreach ($courts as $idx => $court) {
        $availCount = count($court['availability'] ?? []);
        $slotCount = 0;
        foreach ($court['availability'] ?? [] as $av) {
            $slotCount += count($av['timeSlots'] ?? []);
        }
        error_log("  Court[$idx]: name=" . ($court['courtName'] ?? 'N/A') . ", availability=$availCount, timeSlots=$slotCount");
        error_log("    Court[$idx] full data: " . json_encode($court, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($availCount > 0) {
            $validCourts++;
            $totalSlots += $slotCount;
        }
    }
    
    if ($validCourts === 0 || $totalSlots === 0) {
        error_log('DEBUG buildGroupMatchesWithSchedule: No valid courts or slots, using buildGroupMatches as fallback');
        error_log('  validCourts=' . $validCourts . ', totalSlots=' . $totalSlots);
        buildGroupMatches($state);
        return;
    }
    
    // Costruisci lista di slot disponibili CONSIDERANDO il fill capacity
    // Se una fascia oraria può contenere N match, crea N "slot virtuali"
    $availableSlots = [];
    
    $tournament = $config['tournament'] ?? [];
    $setupTimeMinutes = (int)($tournament['setupTimeMinutes'] ?? 5);
    $timePerSetMinutes = (int)($tournament['timePerSetMinutes'] ?? 15);
    $numSets = (int)($tournament['numSets'] ?? 2);
    $matchDurationMinutes = $setupTimeMinutes + ($timePerSetMinutes * $numSets);
    
    foreach ($courts as $courtIdx => $court) {
        foreach ($court['availability'] ?? [] as $dateIdx => $dateAvail) {
            $date = $dateAvail['date'];
            foreach ($dateAvail['timeSlots'] ?? [] as $slotIdx => $timeSlot) {
                // Calcola quanti match ci stanno in questo timeSlot
                $startMinutes = parseTimeToMinutes($timeSlot['startTime']);
                $endMinutes = parseTimeToMinutes($timeSlot['endTime']);
                $slotDurationMinutes = $endMinutes - $startMinutes;
                $matchesInSlot = max(1, (int)floor($slotDurationMinutes / $matchDurationMinutes));
                
                // Crea N sub-slot virtuali, uno per ogni match che può starci
                for ($matchNum = 0; $matchNum < $matchesInSlot; $matchNum++) {
                    // Calcola il tempo di inizio per questo specifico match all'interno dello slot
                    $matchStartOffset = $matchNum * $matchDurationMinutes;
                    $matchStart = $startMinutes + $matchStartOffset;
                    $matchEnd = $matchStart + $matchDurationMinutes;
                    
                    // Converte i minuti in orario HH:MM
                    $matchStartHour = (int)($matchStart / 60);
                    $matchStartMin = $matchStart % 60;
                    $matchEndHour = (int)($matchEnd / 60);
                    $matchEndMin = $matchEnd % 60;
                    $matchStartTime = sprintf('%02d:%02d', $matchStartHour, $matchStartMin);
                    $matchEndTime = sprintf('%02d:%02d', $matchEndHour, $matchEndMin);
                    
                    $availableSlots[] = [
                        'courtIdx' => $courtIdx,
                        'dateIdx' => $dateIdx,
                        'slotIdx' => $slotIdx,
                        'matchNum' => $matchNum,  // Numero del match dentro lo slot (0-5 se ce ne stanno 6)
                        'courtId' => $court['courtId'],
                        'courtName' => $court['courtName'],
                        'date' => $date,
                        'startTime' => $matchStartTime,   // Orario calcolato per questo match
                        'endTime' => $matchEndTime        // Orario calcolato per questo match
                    ];
                }
            }
        }
    }
    
    error_log('DEBUG buildGroupMatchesWithSchedule: totalAvailableSlots=' . count($availableSlots));
    
    if (empty($availableSlots)) {
        error_log('DEBUG buildGroupMatchesWithSchedule: No available slots found, using buildGroupMatches as fallback');
        buildGroupMatches($state);
        return;
    }
    
    // Raccogli tutte le partite dei gironi
    $matches = [];
    foreach ($state['groups'] as $group) {
        $teamIds = $group['teamIds'];
        for ($i = 0; $i < count($teamIds) - 1; $i++) {
            for ($j = $i + 1; $j < count($teamIds); $j++) {
                $matches[] = [
                    'id' => uid(),
                    'group' => $group['name'],
                    'team1Id' => $teamIds[$i],
                    'team2Id' => $teamIds[$j],
                    'score1' => null,
                    'score2' => null,
                    'date' => null,
                    'courtId' => null,
                    'courtName' => null,
                    'startTime' => null,
                    'endTime' => null,
                    'courtIdx' => null,
                    'dateIdx' => null,
                    'slotIdx' => null
                ];
            }
        }
    }
    
    error_log('DEBUG buildGroupMatchesWithSchedule: totalMatches=' . count($matches));
    
    // SMART SCHEDULER: Assegna slot considerando il TEMPO effettivo, non solo posizioni
    error_log('🎯 SMART SCHEDULER: Starting time-aware intelligent distribution...');
    
    // Calcola le differenze di tempo tra gli slot
    $slotTimeDiffs = [];
    for ($i = 0; $i < count($availableSlots) - 1; $i++) {
        $current = $availableSlots[$i];
        $next = $availableSlots[$i+1];
        
        // Se cambiano data o campo, è un grande gap (ignora)
        if ($current['date'] !== $next['date'] || $current['courtIdx'] !== $next['courtIdx']) {
            $slotTimeDiffs[$i] = 9999; // Grande gap
        } else {
            // Calcola minuti tra fine di i e inizio di i+1
            $endTime = strtotime($current['endTime']);
            $startTime = strtotime($next['startTime']);
            $diffMinutes = ($startTime - $endTime) / 60;
            $slotTimeDiffs[$i] = $diffMinutes;
        }
    }
    
    error_log('  Slot time gaps (minutes): ' . json_encode($slotTimeDiffs));
    
    // Algoritmo greedy: assegna partite cercando di distribuire equamente nel TEMPO
    $slotUsed = array_fill(0, count($availableSlots), false);
    $teamLastSlot = [];
    $teamLastTime = []; // Traccia il tempo dell'ultimo match
    $matchesWithSlots = [];
    $slotsByDate = []; // Traccia quale giorno è stato usato per ogni girone
    
    // Per ogni squadra, calcola quante partite ha
    $teamMatchCount = [];
    foreach ($matches as $match) {
        $team1 = $match['team1Id'];
        $team2 = $match['team2Id'];
        $teamMatchCount[$team1] = ($teamMatchCount[$team1] ?? 0) + 1;
        $teamMatchCount[$team2] = ($teamMatchCount[$team2] ?? 0) + 1;
    }
    
    // Itera per numero di partite (round-robin nei match)
    $matchesSorted = [];
    $unassignedMatches = $matches;
    
    for ($round = 0; $round < max($teamMatchCount); $round++) {
        // In ogni round, seleziona una partita da ogni squadra che ancora deve giocare
        $assignedThisRound = false;
        
        foreach ($unassignedMatches as $idx => $match) {
            if (isset($matchesWithSlots[$idx])) {
                continue; // Già assegnata
            }
            
            $team1 = $match['team1Id'];
            $team2 = $match['team2Id'];
            $group = $match['group'];
            
            // Trova il miglior slot per questa partita considerando il tempo E il giorno del girone
            $bestSlotIdx = -1;
            $bestScore = PHP_INT_MIN;
            $bestSlotTime = null;
            
            // Ottieni il giorno preferito per questo girone (se già usato)
            $preferredDate = $slotsByDate[$group] ?? null;
            
            foreach ($availableSlots as $slotIdx => $slot) {
                if ($slotUsed[$slotIdx]) {
                    continue; // Slot già usato
                }
                
                $slotTime = strtotime($slot['startTime']);
                
                // Calcola tempo dall'ultimo match di ogni squadra
                $team1LastTime = $teamLastTime[$team1] ?? PHP_INT_MIN;
                $team2LastTime = $teamLastTime[$team2] ?? PHP_INT_MIN;
                
                // Gap minimo tra partite: 30 minuti
                $team1Gap = ($slotTime - $team1LastTime) / 60; // In minuti
                $team2Gap = ($slotTime - $team2LastTime) / 60;
                $minGap = min($team1Gap, $team2Gap);
                
                // Score base basato sul gap temporale
                if ($minGap < 30) {
                    $score = PHP_INT_MIN + (int)$minGap;
                } else {
                    // Score basato su quanto tempo è passato (preferisci squadre che non hanno giocato da tempo)
                    $score = (int)$minGap;
                }
                
                // BONUS: Se lo slot è dello stesso giorno del girone, aggiungi bonus
                if ($preferredDate && $slot['date'] === $preferredDate) {
                    $score += 1000; // Bonus elevato per preferenza giorno girone
                    error_log("    💡 Group $group: bonus for same-day slot {$slot['date']} (slot $slotIdx)");
                }
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestSlotIdx = $slotIdx;
                    $bestSlotTime = $slotTime;
                }
            }
            
            if ($bestSlotIdx !== -1) {
                // Assegna questo slot
                $slot = $availableSlots[$bestSlotIdx];
                $match['date'] = $slot['date'];
                $match['courtId'] = $slot['courtId'];
                $match['courtName'] = $slot['courtName'];
                $match['startTime'] = $slot['startTime'];
                $match['endTime'] = $slot['endTime'];
                $match['courtIdx'] = $slot['courtIdx'];
                $match['dateIdx'] = $slot['dateIdx'];
                $match['slotIdx'] = $slot['slotIdx'];
                
                // Registra il giorno usato per questo girone
                if (!isset($slotsByDate[$group])) {
                    $slotsByDate[$group] = $slot['date'];
                    error_log("  📅 Group $group assigned to date {$slot['date']}");
                }
                
                $matchesWithSlots[$idx] = $match;
                $slotUsed[$bestSlotIdx] = true;
                $teamLastSlot[$match['team1Id']] = $bestSlotIdx;
                $teamLastSlot[$match['team2Id']] = $bestSlotIdx;
                $teamLastTime[$match['team1Id']] = $bestSlotTime;
                $teamLastTime[$match['team2Id']] = $bestSlotTime;
                
                error_log("  ✅ Assigned: {$match['team1Id']} vs {$match['team2Id']} (Group $group) → Slot $bestSlotIdx ({$slot['date']} {$slot['startTime']})");
                
                $assignedThisRound = true;
            }
        }
        
        if (!$assignedThisRound && count($matchesWithSlots) < count($matches)) {
            // Se non siamo riusciti ad assegnare niente in questo round, rilassa il vincolo
            error_log('  ⚠️  Round ' . $round . ' found no assignments, relaxing constraints...');
            
            // Forza assegnazione delle partite rimanenti al primo slot disponibile
            foreach ($unassignedMatches as $idx => $match) {
                if (isset($matchesWithSlots[$idx])) {
                    continue;
                }
                
                for ($slotIdx = 0; $slotIdx < count($availableSlots); $slotIdx++) {
                    if (!$slotUsed[$slotIdx]) {
                        $slot = $availableSlots[$slotIdx];
                        $match['date'] = $slot['date'];
                        $match['courtId'] = $slot['courtId'];
                        $match['courtName'] = $slot['courtName'];
                        $match['startTime'] = $slot['startTime'];
                        $match['endTime'] = $slot['endTime'];
                        $match['courtIdx'] = $slot['courtIdx'];
                        $match['dateIdx'] = $slot['dateIdx'];
                        $match['slotIdx'] = $slot['slotIdx'];
                        
                        $matchesWithSlots[$idx] = $match;
                        $slotUsed[$slotIdx] = true;
                        
                        error_log("  ✅ Forced: {$match['team1Id']} vs {$match['team2Id']} → Slot $slotIdx (relaxed)");
                        break;
                    }
                }
            }
            break;
        }
    }
    
    if (count($matchesWithSlots) < count($matches)) {
        error_log('DEBUG buildGroupMatchesWithSchedule: Could not assign all matches! Assigned: ' . count($matchesWithSlots) . '/' . count($matches));
        error_log('  → Fallback: resetting matches without scheduling');
        $state['groupMatches'] = [];
        buildGroupMatches($state);
        return;
    }
    
    // Ricostruisci matches array mantenendo l'ordine originale
    $finalMatches = [];
    foreach ($matches as $idx => $match) {
        if (isset($matchesWithSlots[$idx])) {
            $finalMatches[] = $matchesWithSlots[$idx];
        }
    }
    
    error_log('DEBUG buildGroupMatchesWithSchedule: ✅ Successfully assigned all ' . count($finalMatches) . ' matches with time-aware distribution');
    $state['groupMatches'] = $finalMatches;
}

function simulateAll(array &$state): bool {
    if (count($state['groups']) === 0) {
        $approved = shuffleArray(approvedTeams($state));
        $approved = array_slice($approved, 0, (int)$state['settings']['maxTeams']);
        if (count($approved) < 4) {
            return false;
        }

        $groupCount = min(4, max(1, (int)ceil(count($approved) / 4)));
        $groups = [];
        for ($i = 0; $i < $groupCount; $i++) {
            $groups[] = ['name' => chr(65 + $i), 'teamIds' => []];
        }
        foreach ($approved as $i => $team) {
            $groups[$i % $groupCount]['teamIds'][] = $team['id'];
        }

        $state['groups'] = $groups;
        buildGroupMatches($state);
    }

    foreach ($state['groupMatches'] as &$match) {
        [$a, $b] = randomScore();
        $match['score1'] = $a;
        $match['score2'] = $b;
    }
    unset($match);

    if (count($state['playoff']['quarterFinals']) === 0) {
        if (!createPlayoff($state)) {
            return false;
        }
    }

    foreach ($state['playoff']['quarterFinals'] as &$qf) {
        [$a, $b] = randomScore();
        $qf['score1'] = $a;
        $qf['score2'] = $b;
    }
    unset($qf);

    updatePlayoffTree($state);

    foreach ($state['playoff']['semiFinals'] as &$sf) {
        if (!$sf['team1Id'] || !$sf['team2Id']) continue;
        [$a, $b] = randomScore();
        $sf['score1'] = $a;
        $sf['score2'] = $b;
    }
    unset($sf);

    updatePlayoffTree($state);

    if ($state['playoff']['thirdPlace']['team1Id'] && $state['playoff']['thirdPlace']['team2Id']) {
        [$a, $b] = randomScore();
        $state['playoff']['thirdPlace']['score1'] = $a;
        $state['playoff']['thirdPlace']['score2'] = $b;
    }
    if ($state['playoff']['final']['team1Id'] && $state['playoff']['final']['team2Id']) {
        [$a, $b] = randomScore();
        $state['playoff']['final']['score1'] = $a;
        $state['playoff']['final']['score2'] = $b;
    }

    computeFinalRanking($state);
    return true;
}

function authToken(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (strpos($auth, 'Bearer ') !== 0) return null;
    return substr($auth, 7);
}

function validSession(string $token = ''): bool {
    // Se il token non è fornito, leggi dall'Authorization header
    if (empty($token)) {
        $token = authToken() ?? '';
        if (empty($token)) {
            return false;
        }
    }
    
    $sessions = readJsonFile(SESSION_FILE, ['tokens' => []]);
    
    // Supporta entrambi i formati: legacy (array di stringhe) e nuovo (array di oggetti)
    foreach ($sessions['tokens'] as $item) {
        if (is_string($item) && $item === $token) {
            return true; // Formato legacy
        }
        if (is_array($item) && ($item['token'] ?? '') === $token) {
            return true; // Formato nuovo
        }
    }
    
    return false;
}

function requireAdmin(): void {
    $token = authToken();
    if (!$token || !validSession($token)) {
        jsonResponse(401, ['ok' => false, 'error' => 'Non autorizzato']);
    }
}

$stateInit = readJsonFile(DATA_FILE, initialState());
if ($stateInit === []) {
    writeJsonFile(DATA_FILE, initialState());
}
readJsonFile(SESSION_FILE, ['tokens' => []]);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'get_public' && $method === 'GET') {
    $state = readJsonFile(DATA_FILE, initialState());
    jsonResponse(200, ['ok' => true, 'data' => publicState($state)]);
}

if ($action === 'register_team' && $method === 'POST') {
    $body = bodyJson();
    $name = trim((string)($body['name'] ?? ''));
    $category = 'Misto';
    $phone = trim((string)($body['phone'] ?? ''));

    // Supporta sia nuovo formato (players array) che vecchio formato (player1, player2, player3)
    $playersData = [];
    
    if (!empty($body['players']) && is_array($body['players'])) {
        // Nuovo formato con array di giocatori
        $playersData = $body['players'];
    } else {
        // Vecchio formato per compatibilità
        $p1 = trim((string)($body['player1'] ?? ''));
        $p2 = trim((string)($body['player2'] ?? ''));
        $p3 = trim((string)($body['player3'] ?? ''));
        
        if (!empty($p1)) {
            $playersData[] = ['name' => $p1, 'isCaptain' => true, 'level' => null, 'image' => null];
        }
        if (!empty($p2)) {
            $playersData[] = ['name' => $p2, 'isCaptain' => false, 'level' => null, 'image' => null];
        }
        if (!empty($p3)) {
            $playersData[] = ['name' => $p3, 'isCaptain' => false, 'level' => null, 'image' => null];
        }
    }

    if ($name === '' || count($playersData) < 2) {
        jsonResponse(422, ['ok' => false, 'error' => 'Compila nome squadra e almeno 2 giocatori']);
    }

    // Valida nomi giocatori
    foreach ($playersData as $pd) {
        if (empty($pd['name'])) {
            jsonResponse(422, ['ok' => false, 'error' => 'Tutti i giocatori devono avere un nome']);
        }
    }

    // Leggi configurazione per email del gestore
    $config = readConfig();
    $managerEmail = $config['contact']['managerEmail'] ?? '';

    $emailResult = null;

    withStateTransaction(function (&$state) use ($name, $playersData, $category, $phone, $managerEmail, &$emailResult) {
        foreach ($state['teams'] as $team) {
            if (strtolower($team['name']) === strtolower($name)) {
                jsonResponse(409, ['ok' => false, 'error' => 'Nome squadra gia presente']);
            }
        }

        if (count($state['teams']) >= (int)$state['settings']['maxTeams']) {
            jsonResponse(422, ['ok' => false, 'error' => 'Torneo pieno']);
        }

        $teamId = uid();
        
        // Normalizza giocatori nel formato interno
        $players = [];
        foreach ($playersData as $pd) {
            $player = [
                'name' => trim((string)($pd['name'] ?? '')),
                'isCaptain' => (bool)($pd['isCaptain'] ?? false),
            ];
            
            // Aggiungi livello se presente
            if (!empty($pd['level']) && is_string($pd['level'])) {
                $player['level'] = $pd['level'];
            }
            
            // Aggiungi immagine se presente (base64 o URL)
            if (!empty($pd['image']) && is_string($pd['image'])) {
                $player['image'] = $pd['image'];
            }
            
            $players[] = $player;
        }

        $state['teams'][] = [
            'id' => $teamId,
            'name' => $name,
            'category' => $category,
            'players' => $players,
            'phone' => $phone,
            'paid' => false,
            'approved' => false,
            'createdAt' => gmdate('c')
        ];

        // Invia email al gestore se configurato
        $emailResult = ['success' => false, 'message' => 'Email del gestore non configurata'];
        
        if (!empty($managerEmail)) {
            $subject = '📋 Nuova iscrizione squadra al torneo';
            
            // Costruisci lista giocatori
            $playersList = '';
            foreach ($players as $p) {
                $playersList .= '<div>• ' . htmlspecialchars($p['name']) . 
                    ($p['isCaptain'] ? ' <span style="color: #e45a0a; font-weight: bold;">👑 Capitano</span>' : '') . 
                    (isset($p['level']) && $p['level'] ? ' [' . htmlspecialchars($p['level']) . ']' : '') .
                    '</div>';
            }
            
            $body = <<<HTML
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 8px; }
        .header { background: #e45a0a; color: white; padding: 15px; border-radius: 8px 8px 0 0; }
        .content { background: white; padding: 20px; border-radius: 0 0 8px 8px; }
        .team-info { background: #f0f0f0; padding: 15px; border-left: 4px solid #e45a0a; margin: 10px 0; }
        .label { font-weight: bold; color: #2f6a23; }
        .footer { font-size: 12px; color: #999; margin-top: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>⚽ Nuova Iscrizione Squadra</h2>
        </div>
        <div class="content">
            <p>Una nuova squadra si è iscritta al torneo.</p>
            
            <div class="team-info">
                <div class="label">Nome Squadra:</div>
                <div>{$name}</div>
            </div>
            
            <div class="team-info">
                <div class="label">Giocatori:</div>
                {$playersList}
            </div>
            
            <div class="team-info">
                <div class="label">Contatto:</div>
                <div>{$phone}</div>
            </div>
            
            <p style="color: #e45a0a; font-weight: bold;">⏳ In attesa di approvazione</p>
            <p>Accedi al pannello amministrativo per approvare o rifiutare l'iscrizione.</p>
            
            <div class="footer">
                <p>Questo è un messaggio automatico dal sistema di gestione del torneo.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
            $emailResult = sendEmail($managerEmail, $subject, $body);
        }

        return ['message' => 'Squadra registrata con successo'];
    });

    $response = [
        'ok' => true,
        'message' => '✅ Squadra registrata con successo'
    ];
    
    // Aggiungi dettagli email se presente
    if ($emailResult) {
        if ($emailResult['success']) {
            $response['emailStatus'] = 'success';
            $response['emailMessage'] = '📧 Conferma inviata al gestore';
        } else {
            $response['emailStatus'] = 'warning';
            $response['emailMessage'] = '⚠️ Squadra registrata, ma email al gestore non disponibile';
        }
    }

    jsonResponse(200, $response);
}

if ($action === 'admin_login' && $method === 'POST') {
    $body = bodyJson();
    $password = (string)($body['password'] ?? '');
    $email = (string)($body['email'] ?? '');

    // Verifica se siamo in un torneo multi-tenant
    $tournamentConfigFile = __DIR__ . '/.tournament-config.json';
    $isMultiTenant = file_exists($tournamentConfigFile);
    
    if ($isMultiTenant) {
        // Login multi-tenant: email + password
        $config = readJsonFile($tournamentConfigFile, []);
        
        if (empty($email) || $config['managerEmail'] !== $email) {
            jsonResponse(401, ['ok' => false, 'error' => 'Email non corretta']);
        }
        
        if (!password_verify($password, $config['managerPassword'])) {
            jsonResponse(401, ['ok' => false, 'error' => 'Password non corretta']);
        }
        
        $tournamentCode = $config['tournamentCode'] ?? 'unknown';
    } else {
        // Login single-tenant: password globale
        if ($password !== ADMIN_PASSWORD) {
            jsonResponse(401, ['ok' => false, 'error' => 'Password amministratore non valida']);
        }
        
        $tournamentCode = 'local';
    }

    $token = bin2hex(random_bytes(20));
    $sessions = readJsonFile(SESSION_FILE, ['tokens' => []]);
    $sessions['tokens'][] = [
        'token' => $token,
        'tournamentCode' => $tournamentCode,
        'email' => $email ?: 'admin',
        'createdAt' => date('Y-m-d H:i:s')
    ];
    writeJsonFile(SESSION_FILE, $sessions);

    jsonResponse(200, [
        'ok' => true,
        'token' => $token,
        'tournamentCode' => $tournamentCode,
        'isMultiTenant' => $isMultiTenant
    ]);
}

if (str_starts_with($action, 'admin_')) {
    requireAdmin();
}

if ($action === 'admin_state' && $method === 'GET') {
    $state = readJsonFile(DATA_FILE, initialState());
    jsonResponse(200, ['ok' => true, 'data' => array_merge(publicState($state), ['teamsAll' => $state['teams']])]);
}

if ($action === 'admin_update_team' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    if ($id === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'ID squadra obbligatorio']);
    }

    withStateTransaction(function (&$state) use ($body, $id) {
        $found = false;
        foreach ($state['teams'] as &$team) {
            if ($team['id'] !== $id) continue;
            $found = true;
            if (isset($body['name'])) {
                $name = mb_substr(trim((string)$body['name']), 0, 50);
                if ($name !== '') {
                    $team['name'] = $name;
                }
            }
            if (isset($body['paid'])) {
                $team['paid'] = (bool)$body['paid'];
            }
            if (isset($body['approved'])) {
                $team['approved'] = (bool)$body['approved'];
            }
            if (isset($body['kitDelivered'])) {
                $team['kitDelivered'] = (bool)$body['kitDelivered'];
            }
            if (isset($body['players']) && is_array($body['players'])) {
                // Normalizza giocatori: supporta sia string che {name, isCaptain, level}
                $config = readConfig();
                $maxPlayers = $config['tournament']['maxPlayersPerTeam'] ?? 3;
                $normalizedPlayers = [];
                foreach (array_slice($body['players'], 0, $maxPlayers) as $player) {
                    if (is_array($player) && isset($player['name'])) {
                        // Formato {name, isCaptain, level}
                        $name = mb_substr(trim((string)$player['name']), 0, 50);
                        if ($name !== '') {
                            $normalizedPlayers[] = [
                                'name' => $name,
                                'isCaptain' => (bool)($player['isCaptain'] ?? false),
                                'level' => (int)($player['level'] ?? 2) // Valori 0-4, default 2
                            ];
                        }
                    } elseif (is_string($player)) {
                        // Compatibilità con vecchio formato string
                        $name = mb_substr(trim($player), 0, 50);
                        if ($name !== '') {
                            $normalizedPlayers[] = [
                                'name' => $name,
                                'isCaptain' => false,
                                'level' => 2 // Default level per vecchio formato
                            ];
                        }
                    }
                }
                $team['players'] = $normalizedPlayers;
            }
            if (isset($body['phone'])) {
                $team['phone'] = mb_substr(trim((string)$body['phone']), 0, 20);
            }
            $team['category'] = 'Misto';
            break;
        }
        unset($team);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_delete_team' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    if ($id === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'ID squadra obbligatorio']);
    }

    withStateTransaction(function (&$state) use ($id) {
        if (tournamentStarted($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Non puoi cancellare squadre: il torneo e gia iniziato']);
        }

        $before = count($state['teams']);
        $state['teams'] = array_values(array_filter($state['teams'], fn($t) => $t['id'] !== $id));

        if (count($state['teams']) === $before) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_add_test_teams' && $method === 'POST') {
    $body = bodyJson();
    $count = (int)($body['count'] ?? 0);

    if ($count <= 0 || $count > 50) {
        jsonResponse(422, ['ok' => false, 'error' => 'Numero di squadre non valido (1-50)']);
    }

    $result = withStateTransaction(function (&$state) use ($count) {
        if (tournamentStarted($state)) {
            return ['ok' => false, 'error' => 'Non puoi aggiungere squadre: il torneo e gia iniziato'];
        }

        $addedCount = 0;
        $existingDummyCount = count(array_filter($state['teams'], fn($t) => ($t['dummy'] ?? false) === true));
        
        for ($i = 0; $i < $count; $i++) {
            $dummyTeam = [
                'id' => uid(),
                'name' => 'Test Team ' . ($existingDummyCount + $i + 1),
                'category' => 'Misto',
                'players' => [
                    ['name' => 'Bot 1', 'isCaptain' => false],
                    ['name' => 'Bot 2', 'isCaptain' => false],
                    ['name' => 'Bot 3', 'isCaptain' => false]
                ],
                'phone' => '',
                'paid' => true,
                'approved' => true,
                'kitDelivered' => false,
                'dummy' => true,
                'createdAt' => gmdate('c')
            ];
            $state['teams'][] = $dummyTeam;
            $addedCount++;
        }

        return [
            'ok' => true,
            'addedCount' => $addedCount,
            'totalTeams' => count($state['teams'])
        ];
    });

    if (!($result['ok'] ?? false)) {
        jsonResponse(422, $result);
    }
    jsonResponse(200, $result);
}

if ($action === 'admin_remove_test_teams' && $method === 'POST') {
    $result = withStateTransaction(function (&$state) {
        if (tournamentStarted($state)) {
            return ['ok' => false, 'error' => 'Non puoi rimuovere squadre: il torneo e gia iniziato'];
        }

        $testTeams = array_filter($state['teams'], fn($t) => ($t['dummy'] ?? false) === true);
        $removedCount = count($testTeams);

        $state['teams'] = array_values(array_filter($state['teams'], fn($t) => ($t['dummy'] ?? false) !== true));

        return [
            'ok' => true,
            'removedCount' => $removedCount,
            'totalTeams' => count($state['teams'])
        ];
    });

    if (!($result['ok'] ?? false)) {
        jsonResponse(422, $result);
    }
    jsonResponse(200, $result);
}

if ($action === 'admin_approve_all_teams' && $method === 'POST') {
    $result = withStateTransaction(function (&$state) {
        if (tournamentStarted($state)) {
            return ['ok' => false, 'error' => 'Non puoi approvare squadre: il torneo è già iniziato'];
        }

        $approvedCount = 0;
        foreach ($state['teams'] as &$team) {
            // Approva tutte le squadre che non sono già approvate (dummy o non-dummy)
            if (!($team['approved'] ?? false)) {
                $team['approved'] = true;
                $approvedCount++;
            }
        }
        unset($team);

        return [
            'ok' => true,
            'approvedCount' => $approvedCount,
            'totalTeams' => count($state['teams']),
            'message' => 'Approvate ' . $approvedCount . ' squadre'
        ];
    });

    if (!($result['ok'] ?? false)) {
        jsonResponse(422, $result);
    }
    jsonResponse(200, $result);
}

if ($action === 'admin_pay_all_teams' && $method === 'POST') {
    $result = withStateTransaction(function (&$state) {
        if (tournamentStarted($state)) {
            return ['ok' => false, 'error' => 'Non puoi registrare pagamenti: il torneo è già iniziato'];
        }

        $paidCount = 0;
        foreach ($state['teams'] as &$team) {
            if (!($team['dummy'] ?? false) && ($team['approved'] ?? false) && !($team['paid'] ?? false)) {
                $team['paid'] = true;
                $paidCount++;
            }
        }
        unset($team);

        return [
            'ok' => true,
            'paidCount' => $paidCount,
            'totalTeams' => count($state['teams'])
        ];
    });

    if (!($result['ok'] ?? false)) {
        jsonResponse(422, $result);
    }
    jsonResponse(200, $result);
}

if ($action === 'admin_generate_groups' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        $approved = approvedTeams($state);
        $total = count($state['teams'] ?? []);
        $maxTeams = (int)$state['settings']['maxTeams'];
        
        // DEBUG: Log all teams status
        error_log('DEBUG admin_generate_groups: Total teams=' . $total . ', Approved=' . count($approved) . ', MaxTeams=' . $maxTeams);
        foreach (array_slice($state['teams'] ?? [], 0, 5) as $t) {
            error_log('  Team: ' . $t['name'] . ' | approved=' . ($t['approved'] ? 'true' : 'false') . ' | dummy=' . ($t['dummy'] ? 'true' : 'false'));
        }
        
        // Aggiungi squadre fittive SE NON RAGGIUNGE IL MINIMO DI 4
        $minTeams = 4;
        while (count($approved) < $minTeams && count($approved) < $maxTeams) {
            $dummyTeam = [
                'id' => uid(),
                'name' => generateDummyTeamName(count($approved)),
                'category' => 'Misto',
                'players' => [
                    ['name' => 'Bot 1', 'isCaptain' => false],
                    ['name' => 'Bot 2', 'isCaptain' => false],
                    ['name' => 'Bot 3', 'isCaptain' => false]
                ],
                'phone' => '',
                'paid' => true,
                'approved' => true,
                'dummy' => true,
                'createdAt' => gmdate('c')
            ];
            $state['teams'][] = $dummyTeam;
            $approved[] = $dummyTeam;
            error_log('DEBUG: Aggiunta squadra fittizia ' . $dummyTeam['name'] . ', ora approved=' . count($approved));
        }
        
        // ORA fai il check (dopo aver aggiunto le dummy)
        if (count($approved) < $minTeams) {
            jsonResponse(422, [
                'ok' => false,
                'error' => 'Errore: non è stato possibile raggiungere ' . $minTeams . ' squadre. Approved=' . count($approved),
                'details' => [
                    'total_teams' => count($state['teams']),
                    'approved_count' => count($approved),
                    'needed' => $minTeams
                ]
            ]);
        }

        // Limita al massimo
        $approved = array_slice(shuffleArray($approved), 0, $maxTeams);

        $groupCount = min(4, max(1, (int)ceil(count($approved) / 4)));
        
        // Usa distribuzione bilanciata per peso squadra
        $groups = balancedGroupDistribution($approved, $groupCount);

        $state['groups'] = $groups;
        
        // Valida lo schedule prima di generare le partite
        $validation = validateScheduleForTournament($state);
        if (!$validation['valid']) {
            jsonResponse(422, [
                'ok' => false,
                'error' => $validation['message'],
                'details' => [
                    'groups_created' => count($groups),
                    'teams_in_groups' => count($approved)
                ]
            ]);
        }
        
        buildGroupMatchesWithSchedule($state);
        $state['playoff'] = [
            'quarterFinals' => [],
            'semiFinals' => [],
            'thirdPlace' => null,
            'final' => null
        ];
        $state['finalRanking'] = [];
        
        // ===== INTEGRAZIONE NUOVO SISTEMA DI FASI =====
        // Inizializza il sistema di fasi se necessario
        ensurePhases($state);
        
        // Crea/Aggiorna Fase 1 - Gironi
        initializePhase($state, 1, 'Fase 1 - Gironi', 'groups', [
            'groups' => $state['groups'],
            'matches' => $state['groupMatches'] ?? [],
            'metadata' => [
                'teamCount' => count($approved),
                'groupCount' => $groupCount
            ]
        ]);
        
        // Imposta status a 'active'
        setPhaseStatus($state, 1, 'active');
        
        // Iniz ializza Fase 2 - Playoff (vuota, da compilare dopo)
        initializePhase($state, 2, 'Fase 2 - Playoff', 'knockout', [
            'matches' => $state['playoff'],
            'metadata' => []
        ]);
        
        error_log('✅ Fasi create: Phase 1 (Gironi) - ' . count($groups) . ' groups, Phase 2 (Playoff) - pending');

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_move_team_group' && $method === 'POST') {
    $body = bodyJson();
    $teamId = (string)($body['teamId'] ?? '');
    $newGroup = (string)($body['newGroup'] ?? '');

    withStateTransaction(function (&$state) use ($teamId, $newGroup) {
        // Trova il girone attuale
        $currentGroup = null;
        $currentGroupIdx = null;
        foreach ($state['groups'] as $idx => &$group) {
            $pos = array_search($teamId, $group['teamIds'], true);
            if ($pos !== false) {
                $currentGroup = $group['name'];
                $currentGroupIdx = $idx;
                unset($group['teamIds'][$pos]);
                $group['teamIds'] = array_values($group['teamIds']); // Re-index array
                break;
            }
        }

        if ($currentGroup === null) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata in nessun girone']);
        }

        if ($currentGroup === $newGroup) {
            jsonResponse(400, ['ok' => false, 'error' => 'La squadra è già in questo girone']);
        }

        // Aggiunge squadra al nuovo girone
        $newGroupFound = false;
        foreach ($state['groups'] as &$group) {
            if ($group['name'] === $newGroup) {
                $group['teamIds'][] = $teamId;
                $newGroupFound = true;
                break;
            }
        }

        if (!$newGroupFound) {
            jsonResponse(404, ['ok' => false, 'error' => 'Girone di destinazione non trovato']);
        }

        // Rigenerare le partite
        buildGroupMatches($state);

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_update_group_match' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    withStateTransaction(function (&$state) use ($body, $id) {
        $found = false;
        foreach ($state['groupMatches'] as &$m) {
            if ($m['id'] !== $id) continue;
            $found = true;
            if (array_key_exists('score1', $body)) {
                $m['score1'] = is_null($body['score1']) ? null : (int)$body['score1'];
            }
            if (array_key_exists('score2', $body)) {
                $m['score2'] = is_null($body['score2']) ? null : (int)$body['score2'];
            }
            if (isset($body['time'])) {
                $m['time'] = trim((string)$body['time']);
            }
            if (isset($body['day'])) {
                $m['day'] = (int)$body['day'];
            }
            break;
        }
        unset($m);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Partita non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_create_playoff' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (!createPlayoff($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Playoff non generabile: servono almeno 8 squadre classificate']);
        }
        
        // ===== INTEGRAZIONE NUOVO SISTEMA DI FASI =====
        ensurePhases($state);
        
        // Aggiorna Fase 2 - Playoff con i dati generati
        initializePhase($state, 2, 'Fase 2 - Playoff', 'knockout', [
            'matches' => $state['playoff'] ?? ['quarterFinals' => [], 'semiFinals' => [], 'final' => null],
            'metadata' => []
        ]);
        
        // Imposta status a 'active'
        setPhaseStatus($state, 2, 'active');
        
        error_log('✅ Fase 2 (Playoff) creata e attivata');
        
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_get_phase_details' && $method === 'GET') {
    validSession();
    
    $phaseIdx = (int)($_GET['phaseIdx'] ?? 1);
    
    $state = readJsonFile(DATA_FILE, []);
    ensurePhases($state);
    
    $phase = getPhase($state, $phaseIdx);
    if (!$phase) {
        jsonResponse(404, ['ok' => false, 'error' => 'Fase non trovata']);
    }
    
    jsonResponse(200, [
        'ok' => true,
        'phase' => $phase,
        'phaseCount' => count($state['phases']),
        'currentPhaseIdx' => $state['currentPhaseIdx'] ?? 1
    ]);
}

if ($action === 'admin_get_phases_list' && $method === 'GET') {
    validSession();
    
    $state = readJsonFile(DATA_FILE, []);
    ensurePhases($state);
    
    // Ritorna lista semplificata delle fasi per dropdown
    $phasesList = [];
    foreach ($state['phases'] as $phase) {
        $phasesList[] = [
            'phaseIdx' => $phase['phaseIdx'],
            'name' => $phase['name'],
            'type' => $phase['type'],
            'status' => $phase['status'],
            'matchCount' => count($phase['matches'] ?? []),
            'groupCount' => count($phase['groups'] ?? [])
        ];
    }
    
    jsonResponse(200, [
        'ok' => true,
        'phases' => $phasesList,
        'currentPhaseIdx' => $state['currentPhaseIdx'] ?? 1
    ]);
}

if ($action === 'admin_update_playoff_match' && $method === 'POST') {
    $body = bodyJson();
    $phase = (string)($body['phase'] ?? '');
    $id = (string)($body['id'] ?? '');

    withStateTransaction(function (&$state) use ($body, $phase, $id) {
        $found = false;

        if (in_array($phase, ['quarterFinals', 'semiFinals'], true)) {
            foreach ($state['playoff'][$phase] as &$m) {
                if ($m['id'] !== $id) continue;
                $found = true;
                $m['score1'] = array_key_exists('score1', $body) ? (is_null($body['score1']) ? null : (int)$body['score1']) : $m['score1'];
                $m['score2'] = array_key_exists('score2', $body) ? (is_null($body['score2']) ? null : (int)$body['score2']) : $m['score2'];
                break;
            }
            unset($m);
        } elseif (in_array($phase, ['thirdPlace', 'final'], true)) {
            if ($state['playoff'][$phase] && $state['playoff'][$phase]['id'] === $id) {
                $found = true;
                $state['playoff'][$phase]['score1'] = array_key_exists('score1', $body) ? (is_null($body['score1']) ? null : (int)$body['score1']) : $state['playoff'][$phase]['score1'];
                $state['playoff'][$phase]['score2'] = array_key_exists('score2', $body) ? (is_null($body['score2']) ? null : (int)$body['score2']) : $state['playoff'][$phase]['score2'];
            }
        }

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Match playoff non trovato']);
        }

        updatePlayoffTree($state);
        computeFinalRanking($state);
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_simulate_all' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (!simulateAll($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Simulazione non possibile: approva almeno 4 squadre']);
        }
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_seed_demo' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        $demo = [
            'Sunset Duo','Sand Storm','Wave Riders','Beach Kings','Ace Smash','Spike Force','Net Ninjas','Block Party',
            'Dig Deep','Serve Smash','Jump Set Go','Power Volley','Sand Sharks','Top Spin','Last Stand','Blue Fire'
        ];

        $state['teams'] = [];
        foreach ($demo as $idx => $name) {
            $state['teams'][] = [
                'id' => uid(),
                'name' => $name,
                'category' => 'Misto',
                'players' => ['Giocatore ' . ($idx + 1), 'Giocatrice ' . ($idx + 1), ''],
                'phone' => '',
                'paid' => (bool)($idx % 2),
                'approved' => true,
                'createdAt' => gmdate('c')
            ];
        }

        $state['groups'] = [];
        $state['groupMatches'] = [];
        $state['playoff'] = ['quarterFinals' => [], 'semiFinals' => [], 'thirdPlace' => null, 'final' => null];
        $state['finalRanking'] = [];

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_reset' && $method === 'POST') {
    // Reset PARZIALE: cancella gironi, playoff, partite ma CONSERVA squadre e config
    withStateTransaction(function (&$state) {
        // Crea nuovo state ma preserva squadre e altre info
        $newState = [
            'settings' => [
                'maxTeams' => $state['settings']['maxTeams'] ?? 16,
                'tournamentName' => $state['settings']['tournamentName'] ?? ''
            ],
            'teams' => $state['teams'] ?? [],  // ✅ CONSERVA squadre
            'groups' => [],                      // ❌ Cancella gironi
            'groupMatches' => [],                // ❌ Cancella partite
            'playoff' => [                       // ❌ Cancella playoff
                'quarterFinals' => [],
                'semiFinals' => [],
                'thirdPlace' => null,
                'final' => null
            ],
            'standings' => [],                   // ❌ Cancella classifiche
            'finalRanking' => [],                // ❌ Cancella ranking
            'meta' => [
                'lastUpdated' => null
            ]
        ];
        $state = $newState;
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true, 'message' => 'Gironi, playoff e partite cancellate. Squadre conservate.']);
}

if ($action === 'admin_upload_logo' && $method === 'POST') {
    if (!isset($_FILES['logo'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $file = $_FILES['logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(CONFIG_FILE) . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'tournament-logo.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    $config['display']['logoFile'] = 'data/uploads/' . $filename;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'logoFile' => $config['display']['logoFile']]);
}

if ($action === 'admin_upload_background' && $method === 'POST') {
    if (!isset($_FILES['background'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $file = $_FILES['background'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(CONFIG_FILE) . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'tournament-background.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    $config['display']['backgroundFile'] = 'data/uploads/' . $filename;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'backgroundFile' => $config['display']['backgroundFile']]);
}

if ($action === 'admin_upload_kit_image' && $method === 'POST') {
    if (!isset($_FILES['kitImage'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $file = $_FILES['kitImage'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(CONFIG_FILE) . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'tournament-kit.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    $config = readConfig();
    if (!isset($config['kit'])) {
        $config['kit'] = [];
    }
    $config['kit']['imageFile'] = 'data/uploads/' . $filename;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'imageFile' => $config['kit']['imageFile']]);
}

if ($action === 'admin_upload_player_photo' && $method === 'POST') {
    if (!isset($_FILES['playerPhoto'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $teamId = $_POST['teamId'] ?? '';
    $playerIndex = (int)($_POST['playerIndex'] ?? -1);

    if (!$teamId || $playerIndex < 0) {
        jsonResponse(400, ['ok' => false, 'error' => 'Team ID e Player Index obbligatori']);
    }

    $file = $_FILES['playerPhoto'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(DATA_FILE) . '/uploads/players';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    // Generate unique filename based on teamId and playerIndex
    $filename = 'player-' . sanitizeFilename($teamId) . '-' . $playerIndex . '.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    // Delete old file if exists
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    withStateTransaction(function (&$state) use ($teamId, $playerIndex) {
        foreach ($state['teams'] as &$team) {
            if ($team['id'] !== $teamId) continue;
            if (!isset($team['players'][$playerIndex])) break;
            
            if (!is_array($team['players'][$playerIndex])) {
                $team['players'][$playerIndex] = ['name' => $team['players'][$playerIndex], 'isCaptain' => false];
            }
            $team['players'][$playerIndex]['photoFile'] = 'data/uploads/players/' . $filename;
            break;
        }
        unset($team);
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true, 'photoFile' => 'data/uploads/players/' . $filename]);
}

if ($action === 'admin_remove_player_photo' && $method === 'POST') {
    $body = bodyJson();
    $teamId = (string)($body['teamId'] ?? '');
    $playerIndex = (int)($body['playerIndex'] ?? -1);

    if (!$teamId || $playerIndex < 0) {
        jsonResponse(400, ['ok' => false, 'error' => 'Team ID e Player Index obbligatori']);
    }

    withStateTransaction(function (&$state) use ($teamId, $playerIndex) {
        foreach ($state['teams'] as &$team) {
            if ($team['id'] !== $teamId) continue;
            if (!isset($team['players'][$playerIndex])) break;
            
            if (!is_array($team['players'][$playerIndex])) {
                $team['players'][$playerIndex] = ['name' => $team['players'][$playerIndex], 'isCaptain' => false];
            }

            // Delete physical file
            $photoFile = $team['players'][$playerIndex]['photoFile'] ?? '';
            if ($photoFile && file_exists($photoFile)) {
                unlink($photoFile);
            }

            unset($team['players'][$playerIndex]['photoFile']);
            break;
        }
        unset($team);
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_export_backup' && $method === 'GET') {
    $config = readConfig();
    $state = readJsonFile(DATA_FILE, initialState());
    
    $backup = [
        'exportDate' => gmdate('c'),
        'version' => '1.0',
        'config' => $config,
        'state' => $state
    ];
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="beachmaster-backup-' . date('Y-m-d-His') . '.json"');
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'admin_reset_tournament' && $method === 'POST') {
    requireAdmin();
    try {
        // Scrivi file di configurazione con defaults completi
        $emptyConfig = [
            'tournament' => [
                'name' => '',
                'maxTeams' => 0,
                'maxPlayersPerTeam' => 3,
                'maxPlayersOnCourt' => 2,
                'maxSubstitutions' => 0,
                'numGroups' => 0,
                'numSets' => 2,
                'winScore' => 21,
                'maxScore' => 25,
                'timePerSetMinutes' => 35,
                'setupTimeMinutes' => 5,
                'maxTimeoutsPerSet' => 2
            ],
            'schedule' => [
                'courts' => []
            ],
            'phases' => [],
            'contact' => [
                'managerEmail' => ''
            ],
            'display' => [
                'theme' => 'chiringuito',
                'logoFile' => '',
                'backgroundFile' => '',
                'customThemes' => []
            ],
            'sponsors' => [],
            'payment' => [
                'enabled' => false,
                'costPerTeam' => 0,
                'currency' => 'EUR'
            ],
            'notes' => [],
            'news' => [],
            'autosave' => [
                'enabled' => false,
                'intervalSeconds' => 30,
                'maxSteps' => 10
            ]
        ];
        writeJsonFile(CONFIG_FILE, $emptyConfig);
        
        // Scrivi file di stato del torneo completamente vuoto
        $emptyState = [
            'settings' => [
                'maxTeams' => 0,
                'tournamentName' => ''
            ],
            'teams' => [],
            'groups' => [],
            'groupMatches' => [],
            'playoff' => [
                'quarterFinals' => [],
                'semiFinals' => [],
                'thirdPlace' => null,
                'final' => null
            ],
            'standings' => [],
            'finalRanking' => [],
            'meta' => [
                'lastUpdated' => null
            ]
        ];
        writeJsonFile(DATA_FILE, $emptyState);
        
        // Elimina cronologia autosave
        if (file_exists(HISTORY_FILE)) {
            @unlink(HISTORY_FILE);
        }
        // Ricrea history.json vuoto
        writeJsonFile(HISTORY_FILE, ['snapshots' => [], 'lastSaved' => null]);
        
        // Elimina i file di upload (logo, background, sponsor logo, etc.)
        $uploadsDir = UPLOADS_DIR;
        if (is_dir($uploadsDir)) {
            $files = glob($uploadsDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        
        jsonResponse(200, ['ok' => true, 'message' => 'Sistema completamente ripristinato. Pronto per una nuova configurazione.']);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore durante il reset: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_download_program' && $method === 'GET') {
    $zipPath = createProgramZip();
    if (!$zipPath || !file_exists($zipPath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Failed to create ZIP file']);
    }
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="chiringuitobeachvolley-' . time() . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit;
}

if ($action === 'admin_upload_and_install_update' && $method === 'POST') {
    $files = $_FILES ?? [];
    if (empty($files['updateFile'])) {
        jsonResponse(422, ['ok' => false, 'error' => 'Carica un file ZIP di aggiornamento']);
    }
    
    $uploadedFile = $files['updateFile'];
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(422, ['ok' => false, 'error' => 'Upload fallito']);
    }
    
    $mimeType = $uploadedFile['type'];
    if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'], true)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Solo file ZIP sono accettati']);
    }
    
    // Crea una directory temporanea per il file caricato
    if (!is_dir(UPDATES_DIR)) {
        mkdir(UPDATES_DIR, 0777, true);
    }
    
    $tmpPath = UPDATES_DIR . '/' . time() . '-update.zip';
    if (!move_uploaded_file($uploadedFile['tmp_name'], $tmpPath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile salvare il file']);
    }
    
    // Estrae e installa l'aggiornamento
    $result = extractUpdateZip($tmpPath, true);
    
    if ($result['ok']) {
        // Aggiorna il file version.json se presente nello ZIP
        // Questo viene fatto automaticamente dall'extract
        jsonResponse(200, ['ok' => true, 'message' => 'Aggiornamento installato con successo. Pagina in ricaricamento...']);
    } else {
        jsonResponse(500, $result);
    }
}

if ($action === 'admin_test_ftp_connection' && $method === 'POST') {
    $body = bodyJson();
    $ftpHost = trim((string)($body['ftpHost'] ?? ''));
    $ftpPort = (int)($body['ftpPort'] ?? 21);
    $ftpUsername = (string)($body['ftpUsername'] ?? '');
    $ftpPassword = (string)($body['ftpPassword'] ?? '');
    
    if (empty($ftpHost) || empty($ftpUsername) || empty($ftpPassword)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Host, username e password sono obbligatori']);
    }
    
    if (!function_exists('ftp_connect')) {
        jsonResponse(500, ['ok' => false, 'error' => 'FTP extension non disponibile']);
    }
    
    $ftpConn = @ftp_connect($ftpHost, $ftpPort, 30);
    if (!$ftpConn) {
        jsonResponse(200, ['ok' => false, 'error' => 'Impossibile connettersi a ' . $ftpHost . ':' . $ftpPort]);
    }
    
    if (!@ftp_login($ftpConn, $ftpUsername, $ftpPassword)) {
        ftp_close($ftpConn);
        jsonResponse(200, ['ok' => false, 'error' => 'FTP login fallito. Credenziali non valide']);
    }
    
    ftp_close($ftpConn);
    jsonResponse(200, ['ok' => true, 'message' => 'Connessione FTP riuscita']);
}

if ($action === 'admin_test_sftp_connection' && $method === 'POST') {
    $body = bodyJson();
    $sftpHost = trim((string)($body['sftpHost'] ?? ''));
    $sftpPort = (int)($body['sftpPort'] ?? 22);
    $sftpUsername = (string)($body['sftpUsername'] ?? '');
    $sftpPassword = (string)($body['sftpPassword'] ?? '');
    
    if (empty($sftpHost) || empty($sftpUsername) || empty($sftpPassword)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Host, username e password sono obbligatori']);
    }
    
    if (!function_exists('ssh2_connect')) {
        jsonResponse(200, ['ok' => false, 'error' => 'SSH2 extension non disponibile. Installare php-ssh2 o usare FTP']);
    }
    
    $sshConn = @ssh2_connect($sftpHost, $sftpPort);
    if (!$sshConn) {
        jsonResponse(200, ['ok' => false, 'error' => 'Impossibile connettersi a ' . $sftpHost . ':' . $sftpPort]);
    }
    
    if (!@ssh2_auth_password($sshConn, $sftpUsername, $sftpPassword)) {
        jsonResponse(200, ['ok' => false, 'error' => 'SFTP login fallito. Credenziali non valide']);
    }
    
    jsonResponse(200, ['ok' => true, 'message' => 'Connessione SFTP riuscita']);
}

if ($action === 'admin_upload_program_to_ftp' && $method === 'POST') {
    $body = bodyJson();
    $ftpHost = trim((string)($body['ftpHost'] ?? ''));
    $ftpPort = (int)($body['ftpPort'] ?? 21);
    $ftpUsername = (string)($body['ftpUsername'] ?? '');
    $ftpPassword = (string)($body['ftpPassword'] ?? '');
    $ftpRemotePath = trim((string)($body['ftpRemotePath'] ?? '/'));
    
    if (empty($ftpHost) || empty($ftpUsername) || empty($ftpPassword)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Host, username e password sono obbligatori']);
    }
    
    // Crea il programma ZIP
    $zipPath = createProgramZip();
    if (!$zipPath || !file_exists($zipPath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile creare il file ZIP']);
    }
    
    // Upload via FTP
    $result = uploadViaFtp($zipPath, $ftpHost, $ftpPort, $ftpUsername, $ftpPassword, $ftpRemotePath);
    jsonResponse($result['ok'] ? 200 : 500, $result);
}

if ($action === 'admin_upload_program_to_sftp' && $method === 'POST') {
    $body = bodyJson();
    $sftpHost = trim((string)($body['sftpHost'] ?? ''));
    $sftpPort = (int)($body['sftpPort'] ?? 22);
    $sftpUsername = (string)($body['sftpUsername'] ?? '');
    $sftpPassword = (string)($body['sftpPassword'] ?? '');
    $sftpRemotePath = trim((string)($body['sftpRemotePath'] ?? '/'));
    
    if (empty($sftpHost) || empty($sftpUsername) || empty($sftpPassword)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Host, username e password sono obbligatori']);
    }
    
    // Crea il programma ZIP
    $zipPath = createProgramZip();
    if (!$zipPath || !file_exists($zipPath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile creare il file ZIP']);
    }
    
    // Upload via SFTP
    $result = uploadViaSftp($zipPath, $sftpHost, $sftpPort, $sftpUsername, $sftpPassword, $sftpRemotePath);
    jsonResponse($result['ok'] ? 200 : 500, $result);
}

if ($action === 'admin_save_custom_theme' && $method === 'POST') {
    $body = bodyJson();
    $themeName = trim((string)($body['name'] ?? 'Tema Personalizzato'));
    $headingFont = trim((string)($body['headingFont'] ?? 'Poppins'));
    $bodyFont = trim((string)($body['bodyFont'] ?? 'Roboto'));
    $primaryColor = trim((string)($body['primaryColor'] ?? '#667eea'));
    $secondaryColor = trim((string)($body['secondaryColor'] ?? '#764ba2'));
    $headingColor = trim((string)($body['headingColor'] ?? '#000000'));

    if (empty($themeName)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nome tema richiesto']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    if (!isset($config['display']['customThemes'])) {
        $config['display']['customThemes'] = [];
    }

    $customTheme = [
        'id' => 'custom_' . time(),
        'name' => $themeName,
        'headingFont' => $headingFont,
        'bodyFont' => $bodyFont,
        'primaryColor' => $primaryColor,
        'secondaryColor' => $secondaryColor,
        'headingColor' => $headingColor,
        'createdAt' => gmdate('c')
    ];

    $config['display']['customThemes'][] = $customTheme;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'theme' => $customTheme]);
}

if ($action === 'admin_get_custom_themes' && $method === 'GET') {
    $config = readConfig();
    $customThemes = $config['display']['customThemes'] ?? [];
    jsonResponse(200, ['ok' => true, 'customThemes' => $customThemes]);
}

if ($action === 'admin_delete_custom_theme' && $method === 'POST') {
    $body = bodyJson();
    $themeId = trim((string)($body['themeId'] ?? ''));

    if (empty($themeId)) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID tema richiesto']);
    }

    $config = readConfig();
    if (!isset($config['display']['customThemes'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tema non trovato']);
    }

    $config['display']['customThemes'] = array_filter(
        $config['display']['customThemes'],
        fn($t) => $t['id'] !== $themeId
    );

    if (isset($config['display']['theme']) && $config['display']['theme'] === $themeId) {
        $config['display']['theme'] = 'chiringuito';
    }

    writeConfig($config);
    jsonResponse(200, ['ok' => true, 'message' => 'Tema eliminato']);
}

if ($action === 'admin_validate_config' && $method === 'GET') {
    $config = readConfig();
    $errors = [];
    $details = [];
    
    // Valida tournament
    if (empty($config['tournament']['name'])) $errors[] = 'Nome torneo mancante';
    if (($config['tournament']['maxTeams'] ?? 0) < 2) $errors[] = 'Numero massimo squadre insufficiente';
    
    // Valida schedule
    $courts = $config['schedule']['courts'] ?? [];
    if (empty($courts)) {
        $errors[] = 'Nessun campo configurato';
    } else {
        $details[] = 'Campi configurati: ' . count($courts);
        $totalSlots = 0;
        foreach ($courts as $court) {
            $courtSlots = 0;
            foreach ($court['availability'] ?? [] as $avail) {
                $courtSlots += count($avail['timeSlots'] ?? []);
            }
            $totalSlots += $courtSlots;
        }
        $details[] = 'Slot temporali totali: ' . $totalSlots;
    }
    
    // Valida phases
    $phases = $config['phases'] ?? [];
    if (empty($phases)) {
        $errors[] = 'Nessuna fase configurata';
    } else {
        $details[] = 'Fasi configurate: ' . count($phases);
        foreach ($phases as $p) {
            if ($p['type'] === 'knockout' && !in_array($p['numTeams'], [2,4,8,16,32,64,128])) {
                $errors[] = 'Fase knockout deve avere un numero di squadre potenza di 2';
            }
        }
    }
    
    $valid = empty($errors);
    jsonResponse(200, ['ok' => true, 'valid' => $valid, 'errors' => $errors, 'details' => $details]);
}

if ($action === 'admin_load_tournament_template' && $method === 'POST') {
    try {
        $body = bodyJson();
        $templateId = $body['templateId'] ?? '';
        
        jsonResponse(200, ['ok' => true, 'message' => 'Template caricato', 'templateId' => $templateId]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'admin_get_config' && $method === 'GET') {
    $config = readConfig();
    
    // Aggiungi i file di logo e background (con fallback ai default)
    if (empty($config['display']['logoFile'])) {
        $config['display']['logoFile'] = getLogoFile();
    }
    if (empty($config['display']['backgroundFile'])) {
        $config['display']['backgroundFile'] = getBackgroundFile();
    }
    
    jsonResponse(200, ['ok' => true, 'config' => $config]);
}

if ($action === 'get_favicon' && $method === 'GET') {
    // Endpoint per ottenere il favicon dal logo caricato
    $logoPath = getLogoFile();
    
    // Verifica direttamente se il file esiste
    if (file_exists($logoPath) && is_file($logoPath)) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mimeType = match($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png'
        };
        
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($logoPath));
        readfile($logoPath);
        exit;
    }
    
    // Fallback a favicon.ico di default
    if (file_exists('favicon.ico')) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=86400');
        readfile('favicon.ico');
        exit;
    }
    
    // Se niente esiste, ritorna 404
    http_response_code(404);
    exit;
}

if ($action === 'get_config' && $method === 'GET') {
    // Endpoint pubblico per ottenere la configurazione display e informazioni torneo
    $config = readConfig();
    $display = $config['display'] ?? [];
    
    // Aggiungi i file di logo e background (con fallback ai default)
    if (empty($display['logoFile'])) {
        $display['logoFile'] = getLogoFile();
    }
    if (empty($display['backgroundFile'])) {
        $display['backgroundFile'] = getBackgroundFile();
    }
    
    // Informazioni pubbliche del torneo
    $tournament = $config['tournament'] ?? [];
    
    $publicConfig = [
        'display' => $display,
        'tournament' => [
            'name' => $tournament['name'] ?? '',
            'maxTeams' => $tournament['maxTeams'] ?? 16,
            'maxPlayersPerTeam' => $tournament['maxPlayersPerTeam'] ?? 3,
            'maxPlayersOnCourt' => $tournament['maxPlayersOnCourt'] ?? 2
        ],
        'schedule' => $config['schedule'] ?? []
    ];
    jsonResponse(200, ['ok' => true, 'config' => $publicConfig]);
}

if ($action === 'get_themes' && $method === 'GET') {
    $themes = [
        ['id' => 'chiringuito', 'name' => '🏖️ Chiringuito', 'description' => 'Tema spiaggia con colori caldi'],
        ['id' => 'moderno', 'name' => '🎨 Moderno', 'description' => 'Tema moderno con colori vibranti'],
        ['id' => 'scuro', 'name' => '🌙 Scuro', 'description' => 'Tema dark mode'],
        ['id' => 'minimalista', 'name' => '✨ Minimalista', 'description' => 'Tema pulito e minimalista']
    ];
    jsonResponse(200, ['ok' => true, 'themes' => $themes]);
}

if ($action === 'get_version' && $method === 'GET') {
    $version = getVersionInfo();
    jsonResponse(200, ['ok' => true, 'version' => $version['version'], 'releaseDate' => $version['releaseDate']]);
}

if ($action === 'check_update' && $method === 'GET') {
    $current = getVersionInfo();
    $releases = getReleasesInfo();
    $hasUpdate = compareVersions($current['version'], $releases['latestVersion']) < 0;
    
    jsonResponse(200, [
        'ok' => true,
        'currentVersion' => $current['version'],
        'latestVersion' => $releases['latestVersion'],
        'hasUpdate' => $hasUpdate,
        'releaseNotes' => $releases['releaseNotes'],
        'breaking' => $releases['breaking'] ?? false,
        'downloadUrl' => $releases['downloadUrl'] ?? ''
    ]);
}

if ($action === 'get_sponsors' && $method === 'GET') {
    // Endpoint pubblico per ottenere la lista di sponsor da mostrare nel ticker
    $config = readConfig();
    $sponsors = $config['sponsors'] ?? [];
    
    // Costruisci array di sponsor con path logo
    $sponsorList = [];
    foreach ($sponsors as $sponsor) {
        $sponsorData = [
            'id' => $sponsor['id'] ?? bin2hex(random_bytes(4)),
            'name' => $sponsor['name'] ?? 'Sponsor',
            'repetitions' => (int)($sponsor['repetitions'] ?? 1),
            'logoFile' => $sponsor['logoFile'] ?? null
        ];
        $sponsorList[] = $sponsorData;
    }
    
    jsonResponse(200, ['ok' => true, 'sponsors' => $sponsorList]);
}

if ($action === 'admin_update_config' && $method === 'POST') {
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['tournament'])) {
        $t = $body['tournament'];
        if (isset($t['name'])) $config['tournament']['name'] = mb_substr(trim((string)$t['name']), 0, 100);
        if (isset($t['maxTeams'])) $config['tournament']['maxTeams'] = max(2, min(100, (int)$t['maxTeams']));
        if (isset($t['maxPlayersPerTeam'])) $config['tournament']['maxPlayersPerTeam'] = max(1, min(12, (int)$t['maxPlayersPerTeam']));
        if (isset($t['maxPlayersOnCourt'])) $config['tournament']['maxPlayersOnCourt'] = max(1, min(6, (int)$t['maxPlayersOnCourt']));
        if (isset($t['maxSubstitutions'])) $config['tournament']['maxSubstitutions'] = ((int)$t['maxSubstitutions'] < 0 ? 0 : (int)$t['maxSubstitutions']);
        if (isset($t['numGroups'])) $config['tournament']['numGroups'] = max(1, min(8, (int)$t['numGroups']));
        if (isset($t['numSets'])) $config['tournament']['numSets'] = ((int)$t['numSets'] === 1 ? 1 : 2);
        if (isset($t['winScore'])) $config['tournament']['winScore'] = max(15, min(30, (int)$t['winScore']));
        if (isset($t['timePerSetMinutes'])) $config['tournament']['timePerSetMinutes'] = max(20, min(60, (int)$t['timePerSetMinutes']));
        if (isset($t['setupTimeMinutes'])) $config['tournament']['setupTimeMinutes'] = max(1, min(15, (int)$t['setupTimeMinutes']));
        if (isset($t['maxTimeoutsPerSet'])) $config['tournament']['maxTimeoutsPerSet'] = max(0, min(5, (int)$t['maxTimeoutsPerSet']));
    }
    
    if (isset($body['schedule']) && is_array($body['schedule']['courts'] ?? null)) {
        error_log('DEBUG admin_update_config SCHEDULE: Ricevuti ' . count($body['schedule']['courts']) . ' courts');
        error_log('  RAW BODY SCHEDULE: ' . json_encode($body['schedule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        $config['schedule']['courts'] = [];
        foreach ($body['schedule']['courts'] as $courtData) {
            $courtId = trim((string)($courtData['courtId'] ?? ''));
            $courtName = trim((string)($courtData['courtName'] ?? 'Campo'));
            
            error_log("  Processing court: $courtName, received courtId: '$courtId'");
            error_log("    Court data: " . json_encode($courtData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            
            $availability = [];
            foreach ($courtData['availability'] ?? [] as $dateAvail) {
                $date = trim((string)($dateAvail['date'] ?? date('Y-m-d')));
                // Sanitizza la data: se è "undefined" (stringa letterale), usa oggi
                if ($date === 'undefined' || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $date = date('Y-m-d');
                }
                $timeSlots = [];
                foreach ($dateAvail['timeSlots'] ?? [] as $slot) {
                    $timeSlots[] = [
                        'startTime' => trim((string)($slot['startTime'] ?? '19:30')),
                        'endTime' => trim((string)($slot['endTime'] ?? '20:30'))
                    ];
                }
                if (count($timeSlots) > 0) {
                    $availability[] = [
                        'date' => $date,
                        'timeSlots' => $timeSlots
                    ];
                    error_log("      Added date $date with " . count($timeSlots) . " timeSlots");
                }
            }
            
            if (count($availability) > 0) {
                // CRITICAL: Genera courtId stabile se non fornito
                // Usa un hash del courtName per consistenza tra i salvataggi
                if (empty($courtId)) {
                    $courtId = 'court-' . substr(md5($courtName), 0, 8);
                    error_log("    No courtId provided, generated stable ID: $courtId");
                }
                
                $config['schedule']['courts'][] = [
                    'courtId' => $courtId,
                    'courtName' => $courtName ?: 'Campo',
                    'availability' => $availability
                ];
                error_log("    ✅ Court $courtName saved with " . count($availability) . " dates and courtId=$courtId");
            } else {
                error_log("    ⚠️ Court $courtName has no availability, skipping");
            }
        }
        error_log('  FINAL: Saved ' . count($config['schedule']['courts']) . ' courts to config');
        error_log('  FINAL CONFIG SCHEDULE (BEFORE writeConfig): ' . json_encode($config['schedule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    // DEBUG: Log config prima di salvare
    error_log('DEBUG admin_update_config: Writing config with ' . count($config['schedule']['courts'] ?? []) . ' courts');
    foreach ($config['schedule']['courts'] ?? [] as $idx => $court) {
        error_log("  Pre-save Court[$idx]: name={$court['courtName']}, courtId={$court['courtId']}, hasAvailability=" . (count($court['availability'] ?? []) > 0 ? 'YES' : 'NO'));
    }
    
    if (isset($body['phases']) && is_array($body['phases'])) {
        $phases = [];
        
        // Prima passata: normalizza tutti i dati
        foreach ($body['phases'] as $idx => $phase) {
            $phaseData = [
                'phaseNumber' => $idx + 1,
                'name' => trim((string)($phase['name'] ?? "Fase $idx")),
                'type' => in_array($phase['type'] ?? '', ['groups', 'knockout']) ? $phase['type'] : 'groups',
                'branch' => in_array($phase['branch'] ?? 'root', ['root', 'qualified', 'eliminated']) ? $phase['branch'] : 'root',
                'qualifiedGoTo' => trim((string)($phase['qualifiedGoTo'] ?? '')),
                'eliminatedGoTo' => trim((string)($phase['eliminatedGoTo'] ?? ''))
            ];
            
            if ($phaseData['type'] === 'groups') {
                $phaseData['numGroups'] = max(1, min(16, (int)($phase['numGroups'] ?? 4)));
                $phaseData['teamsAdvance'] = max(1, min(8, (int)($phase['teamsAdvance'] ?? 2)));
                $phaseData['hasRepescage'] = (bool)($phase['hasRepescage'] ?? false);
            } elseif ($phaseData['type'] === 'knockout') {
                $numTeams = (int)($phase['numTeams'] ?? 4);
                $validPowers = [2, 4, 8, 16, 32, 64, 128];
                $phaseData['numTeams'] = in_array($numTeams, $validPowers) ? $numTeams : 4;
                $phaseData['hasLosersPath'] = (bool)($phase['hasLosersPath'] ?? false);
                $phaseData['wildcardTeams'] = 0; // Inizializza a 0, sarà calcolato nella seconda passata
            }
            
            $phases[] = $phaseData;
        }
        
        // Seconda passata: calcola wildcard per knockout in base alla fase precedente
        $totalTeams = max(2, (int)($config['tournament']['maxTeams'] ?? 16));
        $teamsInPlay = $totalTeams;
        
        foreach ($phases as $idx => &$phase) {
            if ($phase['type'] === 'groups') {
                $qualified = ($phase['numGroups'] ?? 4) * ($phase['teamsAdvance'] ?? 2);
                $eliminated = max(0, $teamsInPlay - $qualified);
                $teamsInPlay = $qualified + ($phase['hasRepescage'] ? $eliminated : 0);
            } elseif ($phase['type'] === 'knockout') {
                $knockoutTeams = $phase['numTeams'] ?? 4;
                // Se abbiamo meno squadre di quelle richieste, aggiungi wildcard
                $wildcards = max(0, $knockoutTeams - $teamsInPlay);
                $phase['wildcardTeams'] = $wildcards;
                // Le squadre in gioco sono tutte quelle che entrano nel knockout
                $teamsInPlay = $knockoutTeams;
            }
        }
        unset($phase);
        
        // Salva sempre, anche se l'array è vuoto (permette cancellazione completa)
        $config['phases'] = $phases;
    }
    
    if (isset($body['contact']) && is_array($body['contact'])) {
        $managerEmail = mb_substr(trim((string)($body['contact']['managerEmail'] ?? '')), 0, 255);
        if ($managerEmail !== '') {
            if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(422, ['ok' => false, 'error' => 'Email del gestore non valida']);
            }
        }
        $config['contact']['managerEmail'] = $managerEmail;
    }
    
    if (isset($body['display']) && is_array($body['display'])) {
        $theme = trim((string)($body['display']['theme'] ?? 'chiringuito'));
        $validThemes = ['chiringuito', 'moderno', 'scuro', 'minimalista', 'metro'];
        
        // Consenti temi built-in o temi custom
        if (in_array($theme, $validThemes)) {
            $config['display']['theme'] = $theme;
        } elseif (strpos($theme, 'custom_') === 0) {
            // Verifica che il tema custom esista
            $customThemes = $config['display']['customThemes'] ?? [];
            if (array_filter($customThemes, fn($t) => $t['id'] === $theme)) {
                $config['display']['theme'] = $theme;
            }
        }
    }
    
    // Gestione configurazione email
    if (isset($body['email']) && is_array($body['email'])) {
        $emailCfg = $body['email'];
        $config['email']['enabled'] = (bool)($emailCfg['enabled'] ?? false);
        $config['email']['service'] = trim((string)($emailCfg['service'] ?? 'gmail'));
        $config['email']['host'] = mb_substr(trim((string)($emailCfg['host'] ?? '')), 0, 255);
        $config['email']['port'] = (int)($emailCfg['port'] ?? 587);
        $config['email']['secure'] = in_array($emailCfg['secure'] ?? 'tls', ['tls', 'ssl']) ? $emailCfg['secure'] : 'tls';
        $config['email']['auth'] = (bool)($emailCfg['auth'] ?? true);
        $config['email']['username'] = mb_substr(trim((string)($emailCfg['username'] ?? '')), 0, 255);
        $config['email']['password'] = mb_substr(trim((string)($emailCfg['password'] ?? '')), 0, 100);
        $config['email']['fromEmail'] = mb_substr(trim((string)($emailCfg['fromEmail'] ?? 'noreply@beachmaster.local')), 0, 255);
        $config['email']['fromName'] = mb_substr(trim((string)($emailCfg['fromName'] ?? 'BeachMaster')), 0, 100);
        $config['email']['timeout'] = (int)($emailCfg['timeout'] ?? 10);
    }
    
    writeConfig($config);
    
    // DEBUG: Verifica il file salvato
    $savedConfig = readConfig();
    error_log('DEBUG admin_update_config POST-SAVE: Verified ' . count($savedConfig['schedule']['courts'] ?? []) . ' courts');
    foreach ($savedConfig['schedule']['courts'] ?? [] as $idx => $court) {
        error_log("  Post-save Court[$idx]: name={$court['courtName']}, courtId={$court['courtId']}, availability=" . count($court['availability'] ?? []));
    }
    
    // Aggiorna anche lo state con le nuove impostazioni da config
    $state = readJsonFile(DATA_FILE, initialState());
    $state['settings']['tournamentName'] = $config['tournament']['name'] ?? '';
    $state['settings']['maxTeams'] = $config['tournament']['maxTeams'] ?? 0;
    writeJsonFile(DATA_FILE, $state);
    
    // Salva snapshot nella history se autosave è abilitato
    saveToHistory('Aggiornamento configurazione');
    
    jsonResponse(200, ['ok' => true, 'config' => $config]);
}

if ($action === 'create_subtournament' && $method === 'POST') {
    try {
        $body = bodyJson();
        $parentPhaseNumber = (int)($body['parentPhaseNumber'] ?? 0);
        $subtournamentData = $body['subtournamentData'] ?? [];
        
        if ($parentPhaseNumber <= 0 || empty($subtournamentData)) {
            jsonResponse(400, ['ok' => false, 'error' => 'Dati sotto-torneo non validi']);
            exit;
        }
        
        // Crea il timestamp
        $timestamp = date('Y-m-d_His');
        $filename = "sub_tournament_{$parentPhaseNumber}_{$timestamp}.json";
        $filepath = __DIR__ . '/data/subtournaments/' . $filename;
        
        // Crea la directory se non esiste
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        // Prepara i dati del sotto-torneo
        $subTournament = [
            'id' => bin2hex(random_bytes(16)),
            'mainTournamentPhase' => $parentPhaseNumber,
            'name' => mb_substr(trim((string)($subtournamentData['name'] ?? 'Sotto-Torneo')), 0, 100),
            'selectedTeams' => array_map(fn($t) => [
                'id' => $t['id'] ?? null,
                'name' => $t['name'] ?? '',
                'captain' => $t['captain'] ?? '',
                'players' => $t['players'] ?? []
            ], $subtournamentData['selectedTeams'] ?? []),
            'createdAt' => $subtournamentData['createdAt'] ?? date('c'),
            'tournament' => [
                'name' => mb_substr(trim((string)($subtournamentData['tournament']['name'] ?? 'Sotto-Torneo')), 0, 100),
                'maxTeams' => (int)($subtournamentData['tournament']['maxTeams'] ?? count($subtournamentData['selectedTeams'] ?? []))
            ],
            'phases' => $subtournamentData['phases'] ?? [],
            'schedule' => [
                'courts' => $subtournamentData['schedule']['courts'] ?? []
            ],
            'groups' => $subtournamentData['groups'] ?? [],
            'knockouts' => $subtournamentData['knockouts'] ?? []
        ];
        
        // Scrivi il file del sotto-torneo
        writeJsonFile($filepath, $subTournament);
        
        // Aggiorna il config.json con il riferimento al sotto-torneo
        $config = readConfig();
        if (!isset($config['subtournaments'])) {
            $config['subtournaments'] = [];
        }
        
        $config['subtournaments'][] = [
            'parentPhase' => $parentPhaseNumber,
            'filename' => $filename,
            'createdAt' => date('c')
        ];
        
        writeConfig($config);
        
        jsonResponse(200, [
            'ok' => true,
            'filename' => $filename,
            'subtournament' => $subTournament
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'admin_calculate_next_phase_teams' && $method === 'POST') {
    try {
        $body = bodyJson();
        $config = readConfig();
        $state = readJsonFile(DATA_FILE, initialState());
        
        $phases = $config['phases'] ?? [];
        $currentPhaseIdx = (int)($body['currentPhaseIdx'] ?? 0);
        $branch = $body['branch'] ?? 'qualified'; // 'qualified' o 'eliminated'
        
        if ($currentPhaseIdx >= count($phases)) {
            jsonResponse(400, ['ok' => false, 'error' => 'Fase non trovata']);
            exit;
        }
        
        // Se è la prima fase, calcola le squadre approvate
        if ($currentPhaseIdx === 0) {
            $teamsIn = getInitialTeamsCount($config, $state);
        } else {
            // Calcola le squadre dalla fase precedente
            $prevPhase = $phases[$currentPhaseIdx - 1];
            $prevTeamsIn = 0;
            
            if ($currentPhaseIdx === 1) {
                $prevTeamsIn = getInitialTeamsCount($config, $state);
            } else {
                // Se è la terza fase, calcola dalla prima fase
                $firstPhase = $phases[0];
                $prevTeamsIn = getInitialTeamsCount($config, $state);
                $prevTeamsOut = calculatePhaseTeams($firstPhase, $prevTeamsIn);
                
                if ($branch === 'qualified') {
                    $prevTeamsIn = $prevTeamsOut['qualified'];
                } else {
                    $prevTeamsIn = $prevTeamsOut['eliminated'];
                }
            }
            
            $teamsIn = $prevTeamsIn;
        }
        
        // Calcola i team per il ramo richiesto
        $currentPhase = $phases[$currentPhaseIdx];
        $teamStats = calculatePhaseTeams($currentPhase, $teamsIn);
        
        jsonResponse(200, [
            'ok' => true,
            'teamsIn' => $teamsIn,
            'qualified' => $teamStats['qualified'],
            'eliminated' => $teamStats['eliminated'],
            'branch' => $branch,
            'phaseIdx' => $currentPhaseIdx,
            'phaseName' => $currentPhase['name'] ?? "Fase " . ($currentPhaseIdx + 1),
            'message' => "Da questa fase: {$teamStats['qualified']} qualificate, {$teamStats['eliminated']} eliminate/ripescate"
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'admin_update_schedule' && $method === 'POST') {
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['courts']) && is_array($body['courts'])) {
        $config['schedule']['courts'] = [];
        foreach ($body['courts'] as $courtData) {
            $courtId = trim((string)($courtData['courtId'] ?? ''));
            $courtName = trim((string)($courtData['courtName'] ?? 'Campo'));
            
            $availability = [];
            foreach ($courtData['availability'] ?? [] as $dateAvail) {
                $date = trim((string)($dateAvail['date'] ?? date('Y-m-d')));
                $timeSlots = [];
                foreach ($dateAvail['timeSlots'] ?? [] as $slot) {
                    $timeSlots[] = [
                        'startTime' => trim((string)($slot['startTime'] ?? '19:30')),
                        'endTime' => trim((string)($slot['endTime'] ?? '20:30'))
                    ];
                }
                $availability[] = [
                    'date' => $date,
                    'timeSlots' => $timeSlots
                ];
            }
            
            $config['schedule']['courts'][] = [
                'courtId' => $courtId ?: bin2hex(random_bytes(4)),
                'courtName' => $courtName ?: 'Campo',
                'availability' => $availability
            ];
        }
    }
    
    writeConfig($config);
    
    // Salva snapshot nella history
    saveToHistory('Aggiornamento schedule e campi');
    
    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_reschedule_matches' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (empty($state['groups'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nessun girone generato']);
        }
        
        $config = readConfig();
        if (empty($config['schedule']['courts'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nessun giorno di schedule configurato']);
        }
        
        // Valida lo schedule prima di rigenerare le partite
        $validation = validateScheduleForTournament($state);
        if (!$validation['valid']) {
            jsonResponse(422, ['ok' => false, 'error' => $validation['message']]);
        }
        
        buildGroupMatchesWithSchedule($state);
        return ['ok' => true];
    });
    
    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_update_email_config' && $method === 'POST') {
    validSession();
    
    $body = bodyJson();
    $config = readConfig();
    
    // Valida host
    if (empty($body['host'])) {
        jsonResponse(422, ['ok' => false, 'error' => 'Host SMTP è obbligatorio']);
    }
    
    // Valida email mittente
    if (!filter_var($body['fromEmail'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Email mittente non valida']);
    }
    
    // Valida port
    $port = (int)($body['port'] ?? 587);
    if ($port < 1 || $port > 65535) {
        jsonResponse(422, ['ok' => false, 'error' => 'Porta SMTP non valida']);
    }
    
    // Valida secure
    $secure = $body['secure'] ?? 'tls';
    if (!in_array($secure, ['tls', 'ssl'])) {
        $secure = 'tls';
    }
    
    // Aggiorna configurazione email
    $config['email'] = [
        'enabled' => (bool)($body['enabled'] ?? false),
        'service' => trim((string)($body['service'] ?? 'gmail')),
        'host' => trim((string)($body['host'] ?? '')),
        'port' => $port,
        'secure' => $secure,
        'auth' => (bool)($body['auth'] ?? true),
        'username' => trim((string)($body['username'] ?? '')),
        'password' => trim((string)($body['password'] ?? '')),
        'fromEmail' => trim((string)($body['fromEmail'] ?? 'noreply@beachmaster.local')),
        'fromName' => trim((string)($body['fromName'] ?? 'BeachMaster')),
        'timeout' => (int)($body['timeout'] ?? 10)
    ];
    
    writeConfig($config);
    
    // Salva snapshot nella history
    saveToHistory('Aggiornamento configurazione email');
    
    jsonResponse(200, ['ok' => true, 'message' => 'Configurazione email salvata con successo']);
}

if ($action === 'admin_test_email_config' && $method === 'POST') {
    validSession();
    
    $body = bodyJson();
    $testEmail = trim((string)($body['testEmail'] ?? ''));
    
    // Valida email
    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Email di test non valida']);
    }
    
    // Invia email di test
    $subject = '[BeachMaster] Test Email Configuration';
    $body = '<h2>Email Configuration Test</h2><p>Se ricevi questo messaggio, la configurazione email è corretta!</p><p>Timestamp: ' . date('Y-m-d H:i:s') . '</p>';
    
    $result = sendEmail($testEmail, $subject, $body);
    
    if ($result['success']) {
        jsonResponse(200, ['ok' => true, 'message' => 'Email di test inviata con successo!']);
    } else {
        jsonResponse(422, ['ok' => false, 'error' => 'Errore durante l\'invio: ' . ($result['error'] ?? 'Errore sconosciuto'), 'details' => $result]);
    }
}

if ($action === 'admin_set_encryption_password' && $method === 'POST') {
    requireAdmin();
    
    $body = bodyJson();
    $enabled = (bool)($body['enabled'] ?? false);
    $password = trim((string)($body['password'] ?? ''));
    
    // Validazione
    if ($enabled && empty($password)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Password di crittografia è obbligatoria']);
    }
    
    if ($enabled && strlen($password) < 8) {
        jsonResponse(422, ['ok' => false, 'error' => 'Password di crittografia deve essere almeno 8 caratteri']);
    }
    
    $config = readConfig();
    
    // Aggiorna settings di crittografia
    $config['security']['encryptionEnabled'] = $enabled;
    if ($enabled) {
        $config['security']['encryptionPassword'] = $password;
    } else {
        // Se disabilita crittografia, decrittografa i dati salvati
        if (isset($config['email']['password']) && strpos($config['email']['password'], 'enc:') === 0) {
            try {
                $oldKey = getEncryptionKey($config['security']['encryptionPassword']);
                $config['email']['password'] = decryptField($config['email']['password'], $oldKey);
            } catch (Exception $e) {
                // Ignora errori di decrittografia
            }
        }
        if (isset($config['email']['username']) && strpos($config['email']['username'], 'enc:') === 0) {
            try {
                $oldKey = getEncryptionKey($config['security']['encryptionPassword']);
                $config['email']['username'] = decryptField($config['email']['username'], $oldKey);
            } catch (Exception $e) {
                // Ignora errori di decrittografia
            }
        }
    }
    
    writeConfig($config);
    
    // Salva snapshot nella history
    saveToHistory('Aggiornamento impostazioni crittografia');
    
    $message = $enabled ? 'Crittografia abilitata con successo' : 'Crittografia disabilitata';
    jsonResponse(200, ['ok' => true, 'message' => $message]);
}

if ($action === 'admin_generate_regolamento' && $method === 'POST') {
    try {
        $config = readConfig();
        $tournament = $config['tournament'] ?? [];
        
        $tournamentName = htmlspecialchars($tournament['name'] ?? 'Torneo Beach Volley', ENT_QUOTES, 'UTF-8');
        $maxTeams = $tournament['maxTeams'] ?? 16;
        $numGroups = $tournament['numGroups'] ?? 4;
        $numSets = $tournament['numSets'] ?? 2;
        $winScore = $tournament['winScore'] ?? 21;
        $maxScore = $tournament['maxScore'] ?? 25;
        $maxTimeouts = $tournament['maxTimeoutsPerSet'] ?? 2;
        $timePerSet = $tournament['timePerSetMinutes'] ?? 35;
        $phases = $config['phases'] ?? [];
        $notes = $config['notes'] ?? [];
        
        $phasesText = '';
        if (!empty($phases)) {
            $phasesText = '<h3>Fasi del Torneo</h3><ul>';
            foreach ($phases as $phase) {
                $phaseType = htmlspecialchars($phase['type'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
                $phaseName = htmlspecialchars($phase['name'] ?? 'Fase', ENT_QUOTES, 'UTF-8');
                $phasesText .= "<li><strong>$phaseName</strong> ($phaseType)</li>";
            }
            $phasesText .= '</ul>';
        }
        
        $notesText = '';
        if (!empty($notes)) {
            $notesText = '<div class="section"><h2>7. Note del Torneo</h2><ul>';
            foreach ($notes as $note) {
                $desc = htmlspecialchars($note['description'] ?? '', ENT_QUOTES, 'UTF-8');
                $points = (int)($note['points'] ?? 0);
                $pointsDisplay = $points >= 0 ? "+$points" : "$points";
                $notesText .= "<li><strong>$desc:</strong> $pointsDisplay punti</li>";
            }
            $notesText .= '</ul></div>';
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regolamento - $tournamentName</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }
        h1 { color: #e45a0a; text-align: center; border-bottom: 3px solid #e45a0a; padding-bottom: 10px; }
        h2 { color: #2f6a23; margin-top: 30px; }
        h3 { color: #555; }
        .section { margin: 20px 0; }
        ul { padding-left: 20px; }
        li { margin: 8px 0; }
        .generated { text-align: center; color: #888; font-size: 12px; margin-top: 40px; }
    </style>
</head>
<body>
    <h1>⚽ Regolamento Torneo: $tournamentName</h1>
    
    <div class="section">
        <h2>1. Informazioni Generali</h2>
        <ul>
            <li><strong>Nome Torneo:</strong> $tournamentName</li>
            <li><strong>Numero massimo squadre:</strong> $maxTeams</li>
            <li><strong>Formato squadre:</strong> Beach Volley (2 vs 2)</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>2. Regole di Gioco</h2>
        <ul>
            <li><strong>Numero set da giocare:</strong> Miglior di $numSets set</li>
            <li><strong>Punti per vincere un set:</strong> $winScore punti (con minimo 2 di differenza)</li>
            <li><strong>Massimo punti nel set:</strong> $maxScore punti</li>
            <li><strong>Timeout per set:</strong> Massimo $maxTimeouts timeout per set</li>
            <li><strong>Durata set:</strong> Circa $timePerSet minuti a set</li>
        </ul>
    </div>
    
    $phasesText
    
    <div class="section">
        <h2>3. Gironi</h2>
        <ul>
            <li><strong>Numero gironi:</strong> $numGroups</li>
            <li><strong>Criteri di classifica:</strong> Punti totali > Differenza set > Differenza punti</li>
            <li>Le squadre si affrontano in tutti gli incontri con ogni altra squadra dello stesso girone</li>
            <li>Vittoria = 2 punti; Sconfitta = 0 punti (non è previsto il pareggio)</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>4. Competizione Leale</h2>
        <ul>
            <li>Tutti i giocatori devono comportarsi correttamente e rispettare gli avversari</li>
            <li>Proteste e ricorsi devono essere fatti agli arbitri entro 5 minuti dalla fine della partita</li>
            <li>Le decisioni degli arbitri sono definitive</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>5. Sanzioni</h2>
        <ul>
            <li>Comportamento violento o ingiurioso: squalifica immediata</li>
            <li>Assenza alla partita programmata senza giustificazione: penalità di 2 set (0-21, 0-21)</li>
            <li>Ritardo superiore a 15 minuti dall'inizio della partita: perdita della partita</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>6. Modifiche al Regolamento</h2>
        <p>Lo staff organizzativo si riserva il diritto di modificare il regolamento prima dell'inizio della competizione con comunicazione ufficiale a tutti i partecipanti.</p>
    </div>
    
    $notesText
    
    <div class="generated">
        <p>📄 Regolamento generato automaticamente il <strong>$(date('d/m/Y H:i'))</strong></p>
        <p><em>Questo regolamento è valido solo per il torneo <strong>$tournamentName</strong></em></p>
    </div>
</body>
</html>
HTML;
        
        $filename = __DIR__ . '/regolamento.html';
        file_put_contents($filename, $html);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Regolamento generato con successo',
            'filename' => 'regolamento.html'
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore generazione regolamento: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_generate_policy' && $method === 'POST') {
    try {
        $config = readConfig();
        $tournament = $config['tournament'] ?? [];
        $tournamentName = htmlspecialchars($tournament['name'] ?? 'Torneo Beach Volley', ENT_QUOTES, 'UTF-8');
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - $tournamentName</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }
        h1 { color: #e45a0a; text-align: center; border-bottom: 3px solid #e45a0a; padding-bottom: 10px; }
        h2 { color: #2f6a23; margin-top: 30px; }
        h3 { color: #555; }
        .section { margin: 20px 0; }
        ul { padding-left: 20px; }
        li { margin: 8px 0; }
        .generated { text-align: center; color: #888; font-size: 12px; margin-top: 40px; }
    </style>
</head>
<body>
    <h1>🔒 Privacy Policy - $tournamentName</h1>
    
    <div class="section">
        <h2>1. Introduzione</h2>
        <p>Questa Privacy Policy descrive come utilizziamo i dati personali raccolti attraverso la piattaforma di gestione del torneo di Beach Volley.</p>
    </div>
    
    <div class="section">
        <h2>2. Dati Raccolti</h2>
        <p>Raccogliamo i seguenti dati dei partecipanti:</p>
        <ul>
            <li>Nome completo</li>
            <li>Numero di telefono</li>
            <li>Indirizzo email</li>
            <li>Informazioni relative alla partecipazione al torneo</li>
            <li>Risultati delle partite e classifiche</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>3. Scopo del Trattamento</h2>
        <p>I dati personali sono trattati esclusivamente per:</p>
        <ul>
            <li>Gestione e organizzazione del torneo</li>
            <li>Comunicazioni relative al torneo (orari, risultati, aggiornamenti)</li>
            <li>Compilation di classifiche e statistiche ufficiali</li>
            <li>Conformità a obblighi legali e normativi</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>4. Diritti dell'Interessato</h2>
        <p>In conformità al GDPR (Regolamento UE 2016/679), hai i seguenti diritti:</p>
        <ul>
            <li>Diritto di accesso ai tuoi dati personali</li>
            <li>Diritto alla rettificazione dei dati inesatti</li>
            <li>Diritto alla cancellazione ("diritto all'oblio")</li>
            <li>Diritto di limitare il trattamento</li>
            <li>Diritto di portabilità dei dati</li>
            <li>Diritto di opposizione al trattamento</li>
        </ul>
    </div>
    
    <div class="section">
        <h2>5. Conservazione dei Dati</h2>
        <p>I dati personali saranno conservati per la durata del torneo e per il periodo necessario per gestire eventuali ricorsi o rivendicazioni legali, e non più di 1 anno dopo la conclusione dell'evento.</p>
    </div>
    
    <div class="section">
        <h2>6. Contatti</h2>
        <p>Per qualsiasi domanda riguardante questa Privacy Policy o l'utilizzo dei tuoi dati personali, contatta l'organizzatore del torneo.</p>
    </div>
    
    <div class="generated">
        <p>🔒 Privacy Policy generata automaticamente il <strong>$(date('d/m/Y H:i'))</strong></p>
        <p><em>Valida per il torneo <strong>$tournamentName</strong></em></p>
    </div>
</body>
</html>
HTML;
        
        $filename = __DIR__ . '/policy.html';
        file_put_contents($filename, $html);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Privacy Policy generata con successo',
            'filename' => 'policy.html'
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore generazione policy: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_generate_cookie_policy' && $method === 'POST') {
    try {
        $config = readConfig();
        $tournament = $config['tournament'] ?? [];
        $tournamentName = htmlspecialchars($tournament['name'] ?? 'Torneo Beach Volley', ENT_QUOTES, 'UTF-8');
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookie Policy - $tournamentName</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }
        h1 { color: #e45a0a; text-align: center; border-bottom: 3px solid #e45a0a; padding-bottom: 10px; }
        h2 { color: #2f6a23; margin-top: 30px; }
        h3 { color: #555; }
        .section { margin: 20px 0; }
        ul { padding-left: 20px; }
        li { margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .generated { text-align: center; color: #888; font-size: 12px; margin-top: 40px; }
    </style>
</head>
<body>
    <h1>🍪 Cookie Policy - $tournamentName</h1>
    
    <div class="section">
        <h2>1. Che cosa sono i Cookie?</h2>
        <p>I cookie sono piccoli file di testo che vengono memorizzati sul tuo dispositivo (computer, tablet o smartphone) quando visiti il nostro sito. Vengono utilizzati per migliorare l'esperienza dell'utente e per ricordare le tue preferenze.</p>
    </div>
    
    <div class="section">
        <h2>2. Tipologie di Cookie Utilizzati</h2>
        
        <h3>Cookie Tecnici (Essenziali)</h3>
        <p>Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati. Includono:</p>
        <ul>
            <li><strong>sessionStorage:</strong> Memorizziamo il token di autenticazione per la sessione amministrativa</li>
            <li><strong>localStorage:</strong> Salviamo le preferenze dell'utente (tema, lingua)</li>
        </ul>
        
        <h3>Cookie di Analisi</h3>
        <p>Utilizziamo cookie per analizzare il traffico del sito e migliorare le nostre performance. Questi cookie non identificano direttamente l'utente.</p>
    </div>
    
    <div class="section">
        <h2>3. Tabella dei Cookie</h2>
        <table>
            <thead>
                <tr>
                    <th>Nome Cookie</th>
                    <th>Scopo</th>
                    <th>Tipo</th>
                    <th>Durata</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>adminToken</td>
                    <td>Autenticazione amministratore</td>
                    <td>Tecnico</td>
                    <td>Sessione</td>
                </tr>
                <tr>
                    <td>themePreference</td>
                    <td>Preferenza tema visual</td>
                    <td>Preferenze</td>
                    <td>Permanente</td>
                </tr>
                <tr>
                    <td>languagePreference</td>
                    <td>Preferenza lingua</td>
                    <td>Preferenze</td>
                    <td>Permanente</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="section">
        <h2>4. Come Gestire i Cookie</h2>
        <p>Puoi controllare e gestire i cookie nel tuo browser:</p>
        <ul>
            <li><strong>Chrome:</strong> Impostazioni → Privacy e sicurezza → Cookie e altri dati dei siti</li>
            <li><strong>Firefox:</strong> Preferenze → Privacy e sicurezza → Cookie e dati dei siti</li>
            <li><strong>Safari:</strong> Preferenze → Privacy → Gestisci dati dei siti web</li>
            <li><strong>Edge:</strong> Impostazioni → Privacy, ricerca e servizi → Cancella dati di esplorazione</li>
        </ul>
        <p><strong>Nota:</strong> La disabilitazione dei cookie tecnici potrebbe impedire il corretto funzionamento della piattaforma.</p>
    </div>
    
    <div class="section">
        <h2>5. Consenso ai Cookie</h2>
        <p>Continuando a utilizzare questo sito, accetti l'utilizzo dei cookie come descritto in questa Cookie Policy. Se non desideri che vengano utilizzati i cookie, ti consigliamo di disabilitarli dal tuo browser.</p>
    </div>
    
    <div class="section">
        <h2>6. Modifiche a questa Policy</h2>
        <p>Potremmo aggiornare questa Cookie Policy in qualsiasi momento. Ti consigliamo di controllarla periodicamente per rimanere aggiornato.</p>
    </div>
    
    <div class="generated">
        <p>🍪 Cookie Policy generata automaticamente il <strong>$(date('d/m/Y H:i'))</strong></p>
        <p><em>Valida per il torneo <strong>$tournamentName</strong></em></p>
    </div>
</body>
</html>
HTML;
        
        $filename = __DIR__ . '/cookie.html';
        file_put_contents($filename, $html);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Cookie Policy generata con successo',
            'filename' => 'cookie.html'
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore generazione cookie policy: ' . $e->getMessage()]);
    }
}

// ==================== SPONSOR MANAGEMENT ====================

if ($action === 'admin_update_sponsors' && $method === 'POST') {
    if (!validSession()) jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['sponsors']) && is_array($body['sponsors'])) {
        $sponsors = [];
        foreach ($body['sponsors'] as $sponsor) {
            $sponsorData = [
                'id' => trim((string)($sponsor['id'] ?? bin2hex(random_bytes(4)))),
                'name' => trim((string)($sponsor['name'] ?? 'Sponsor')),
                'repetitions' => max(1, min(10, (int)($sponsor['repetitions'] ?? 1))),
                'logoFile' => $sponsor['logoFile'] ?? null
            ];
            if (!empty($sponsorData['name'])) {
                $sponsors[] = $sponsorData;
            }
        }
        $config['sponsors'] = $sponsors;
    }
    
    writeConfig($config);
    
    // Salva snapshot nella history
    saveToHistory('Aggiornamento sponsor');
    
    jsonResponse(200, ['ok' => true, 'config' => ['sponsors' => $config['sponsors']], 'sponsors' => $config['sponsors']]);
}

if ($action === 'admin_upload_sponsor_logo' && $method === 'POST') {
    if (!isset($_FILES['logoFile'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }
    
    $file = $_FILES['logoFile'];
    $sponsorId = $_POST['sponsorId'] ?? null;
    
    if (!$sponsorId) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID sponsor mancante']);
    }
    
    // Validazione file
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Tipo file non supportato']);
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(422, ['ok' => false, 'error' => 'File troppo grande (max 5MB)']);
    }
    
    // Salva il file
    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg'
    };
    
    $filename = "sponsor-logo-$sponsorId.$ext";
    $filepath = __DIR__ . "/data/uploads/$filename";
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }
    
    // Aggiorna config con il path
    $config = readConfig();
    foreach ($config['sponsors'] as &$sponsor) {
        if ($sponsor['id'] === $sponsorId) {
            // Cancella il vecchio logo se esiste
            if (!empty($sponsor['logoFile']) && file_exists(__DIR__ . '/data/uploads/' . basename($sponsor['logoFile']))) {
                @unlink(__DIR__ . '/data/uploads/' . basename($sponsor['logoFile']));
            }
            $sponsor['logoFile'] = "data/uploads/$filename";
            break;
        }
    }
    writeConfig($config);
    
    jsonResponse(200, [
        'ok' => true,
        'logoFile' => "data/uploads/$filename",
        'sponsors' => $config['sponsors']
    ]);
}

if ($action === 'admin_remove_sponsor' && $method === 'POST') {
    $body = bodyJson();
    $sponsorId = $body['sponsorId'] ?? null;
    
    if (!$sponsorId) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID sponsor mancante']);
    }
    
    $config = readConfig();
    
    // Trova e rimuovi sponsor
    $found = false;
    foreach ($config['sponsors'] as $idx => $sponsor) {
        if ($sponsor['id'] === $sponsorId) {
            // Cancella il logo se esiste
            if (!empty($sponsor['logoFile']) && file_exists(__DIR__ . '/data/uploads/' . basename($sponsor['logoFile']))) {
                @unlink(__DIR__ . '/data/uploads/' . basename($sponsor['logoFile']));
            }
            unset($config['sponsors'][$idx]);
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        jsonResponse(404, ['ok' => false, 'error' => 'Sponsor non trovato']);
    }
    
    // Reindicizza array
    $config['sponsors'] = array_values($config['sponsors']);
    writeConfig($config);
    
    jsonResponse(200, ['ok' => true, 'sponsors' => $config['sponsors']]);
}

// ==================== PLAYER IMAGES ====================

if ($action === 'admin_upload_player_image' && $method === 'POST') {
    requireAdmin();
    
    if (!isset($_FILES['playerImage'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }
    
    $file = $_FILES['playerImage'];
    $teamId = $_POST['teamId'] ?? null;
    $playerIndex = $_POST['playerIndex'] ?? null;
    
    if (!$teamId || $playerIndex === null || $playerIndex === '') {
        jsonResponse(400, ['ok' => false, 'error' => 'teamId e playerIndex obbligatori']);
    }
    
    $playerIndex = (int)$playerIndex;
    
    // Validazione file
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Tipo file non supportato. Usa JPG, PNG, GIF o WebP']);
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(422, ['ok' => false, 'error' => 'File troppo grande (max 5MB)']);
    }
    
    // Determina estensione
    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg'
    };
    
    // Salva il file
    $filename = "player-${teamId}-${playerIndex}.${ext}";
    $uploadsDir = __DIR__ . '/data/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }
    
    // Aggiorna il team nello state
    withStateTransaction(function (&$state) use ($teamId, $playerIndex, $filename) {
        $found = false;
        foreach ($state['teams'] as &$team) {
            if ($team['id'] !== $teamId) continue;
            $found = true;
            
            if (!isset($team['players']) || !is_array($team['players'])) {
                $team['players'] = [];
            }
            
            // Controlla che l'indice del giocatore sia valido
            if ($playerIndex < 0 || $playerIndex >= count($team['players'])) {
                jsonResponse(422, ['ok' => false, 'error' => 'Indice giocatore non valido']);
            }
            
            // Se c'è un'immagine precedente, cancellala
            if (!empty($team['players'][$playerIndex]['imageFile'])) {
                $oldFile = __DIR__ . '/' . $team['players'][$playerIndex]['imageFile'];
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            
            // Assegna il nuovo path dell'immagine
            $team['players'][$playerIndex]['imageFile'] = "data/uploads/${filename}";
            break;
        }
        unset($team);
        
        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata']);
        }
        
        return ['ok' => true];
    });
    
    jsonResponse(200, [
        'ok' => true,
        'imageFile' => "data/uploads/${filename}"
    ]);
}

// ==================== PAYMENT E NOTES ====================

if ($action === 'admin_update_payment_config' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['payment']) && is_array($body['payment'])) {
        $payment = $body['payment'];
        $config['payment']['enabled'] = (bool)($payment['enabled'] ?? false);
        $config['payment']['costPerTeam'] = max(0, (float)($payment['costPerTeam'] ?? 0));
        $config['payment']['currency'] = in_array($payment['currency'] ?? 'EUR', ['EUR', 'USD', 'GBP', 'CHF']) ? $payment['currency'] : 'EUR';
    }
    
    writeConfig($config);
    saveToHistory('Aggiornamento configurazione pagamenti');
    
    jsonResponse(200, ['ok' => true, 'payment' => $config['payment']]);
}

if ($action === 'admin_update_notes' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['notes']) && is_array($body['notes'])) {
        $notes = [];
        foreach ($body['notes'] as $note) {
            $noteData = [
                'id' => trim((string)($note['id'] ?? bin2hex(random_bytes(4)))),
                'description' => trim((string)($note['description'] ?? '')),
                'points' => (int)($note['points'] ?? 0)
            ];
            if (!empty($noteData['description'])) {
                $notes[] = $noteData;
            }
        }
        $config['notes'] = $notes;
    }
    
    writeConfig($config);
    saveToHistory('Aggiornamento note torneo');
    
    jsonResponse(200, ['ok' => true, 'notes' => $config['notes']]);
}

if ($action === 'admin_get_payment_config' && $method === 'GET') {
    requireAdmin();
    $config = readConfig();
    jsonResponse(200, ['ok' => true, 'payment' => $config['payment'], 'notes' => $config['notes']]);
}

// ==================== NEWS MANAGEMENT ====================

if ($action === 'admin_create_news' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    
    if (!isset($config['news'])) {
        $config['news'] = [];
    }
    
    $newsItem = [
        'id' => bin2hex(random_bytes(8)),
        'title' => trim((string)($body['title'] ?? '')),
        'content' => trim((string)($body['content'] ?? '')),
        'imageFile' => trim((string)($body['imageFile'] ?? '')),
        'published' => (bool)($body['published'] ?? false),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'publishedAt' => (bool)($body['published'] ?? false) ? date('c') : null
    ];
    
    if (empty($newsItem['title']) || empty($newsItem['content'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Titolo e contenuto sono obbligatori']);
        return;
    }
    
    $config['news'][] = $newsItem;
    writeConfig($config);
    saveToHistory('Creazione nuova notizia: ' . $newsItem['title']);
    
    jsonResponse(200, ['ok' => true, 'news' => $newsItem]);
}

if ($action === 'admin_update_news' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    $newsId = (string)($body['id'] ?? '');
    
    if (empty($newsId)) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID notizia mancante']);
        return;
    }
    
    $found = false;
    if (isset($config['news']) && is_array($config['news'])) {
        foreach ($config['news'] as &$item) {
            if ($item['id'] === $newsId) {
                $oldPublished = $item['published'] ?? false;
                $newPublished = (bool)($body['published'] ?? false);
                
                $item['title'] = trim((string)($body['title'] ?? $item['title']));
                $item['content'] = trim((string)($body['content'] ?? $item['content']));
                if (isset($body['imageFile'])) {
                    $item['imageFile'] = trim((string)($body['imageFile']));
                }
                $item['published'] = $newPublished;
                $item['updatedAt'] = date('c');
                
                // Imposta publishedAt solo quando si passa da non-pubblicato a pubblicato
                if (!$oldPublished && $newPublished) {
                    $item['publishedAt'] = date('c');
                }
                
                $found = true;
                break;
            }
        }
    }
    
    if (!$found) {
        jsonResponse(404, ['ok' => false, 'error' => 'Notizia non trovata']);
        return;
    }
    
    writeConfig($config);
    saveToHistory('Aggiornamento notizia');
    jsonResponse(200, ['ok' => true, 'news' => $config['news']]);
}

if ($action === 'admin_delete_news' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    $newsId = (string)($body['id'] ?? '');
    
    if (empty($newsId)) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID notizia mancante']);
        return;
    }
    
    if (isset($config['news']) && is_array($config['news'])) {
        $config['news'] = array_filter($config['news'], function($item) use ($newsId) {
            return ($item['id'] ?? '') !== $newsId;
        });
    }
    
    writeConfig($config);
    saveToHistory('Eliminazione notizia');
    jsonResponse(200, ['ok' => true, 'news' => array_values($config['news'] ?? [])]);
}

if ($action === 'admin_get_news' && $method === 'GET') {
    requireAdmin();
    $config = readConfig();
    jsonResponse(200, ['ok' => true, 'news' => $config['news'] ?? []]);
}

if ($action === 'public_get_news' && $method === 'GET') {
    $config = readConfig();
    $published = array_filter($config['news'] ?? [], function($item) {
        return ($item['published'] ?? false) === true;
    });
    
    // Ordina per data di pubblicazione (più recente prima)
    usort($published, function($a, $b) {
        $dateA = strtotime($a['publishedAt'] ?? $a['createdAt']);
        $dateB = strtotime($b['publishedAt'] ?? $b['createdAt']);
        return $dateB - $dateA;
    });
    
    jsonResponse(200, ['ok' => true, 'news' => array_values($published)]);
}

if ($action === 'admin_upload_news_image' && $method === 'POST') {
    requireAdmin();
    
    if (!isset($_FILES['image'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
        return;
    }
    
    $file = $_FILES['image'];
    
    // Validazione file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore upload file']);
        return;
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Formato non supportato. Usa JPEG, PNG, GIF o WebP']);
        return;
    }
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        jsonResponse(400, ['ok' => false, 'error' => 'File troppo grande (max 5MB)']);
        return;
    }
    
    $uploadsDir = UPLOADS_DIR;
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFilename = 'news-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $newFilePath = $uploadsDir . '/' . $newFilename;
    
    if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore salvataggio file']);
        return;
    }
    
    jsonResponse(200, ['ok' => true, 'imageFile' => 'data/uploads/' . $newFilename]);
}

// ==================== BACKUP E RIPRISTINO ====================

if ($action === 'admin_export_backup' && $method === 'GET') {
    requireAdmin();
    
    try {
        $config = readConfig();
        $teams = readJsonFile(__DIR__ . '/data/teams.json') ?? [];
        $groups = readJsonFile(__DIR__ . '/data/groups.json') ?? [];
        $matches = readJsonFile(__DIR__ . '/data/matches.json') ?? [];
        $history = readJsonFile(__DIR__ . '/data/history.json') ?? ['snapshots' => [], 'lastSaved' => null];
        
        $backup = [
            'version' => '1.0',
            'timestamp' => date('c'),
            'config' => $config,
            'teams' => $teams,
            'groups' => $groups,
            'matches' => $matches,
            'history' => $history
        ];
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="backup_' . date('Ymd_His') . '.json"');
        echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore export: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_import_backup' && $method === 'POST') {
    requireAdmin();
    
    if (!isset($_FILES['backup'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file di backup caricato']);
        return;
    }
    
    $file = $_FILES['backup'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore upload file']);
        return;
    }
    
    try {
        $content = file_get_contents($file['tmp_name']);
        $backup = json_decode($content, true);
        
        if (!is_array($backup) || !isset($backup['config'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'File di backup non valido']);
            return;
        }
        
        // Ripristina tutti i dati
        writeJsonFile(CONFIG_FILE, $backup['config']);
        writeJsonFile(__DIR__ . '/data/teams.json', $backup['teams'] ?? []);
        writeJsonFile(__DIR__ . '/data/groups.json', $backup['groups'] ?? []);
        writeJsonFile(__DIR__ . '/data/matches.json', $backup['matches'] ?? []);
        writeJsonFile(__DIR__ . '/data/history.json', $backup['history'] ?? ['snapshots' => [], 'lastSaved' => null]);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Backup ripristinato con successo',
            'timestamp' => $backup['timestamp'],
            'itemsRestored' => [
                'config' => 1,
                'teams' => count($backup['teams'] ?? []),
                'groups' => count($backup['groups'] ?? []),
                'matches' => count($backup['matches'] ?? [])
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore import: ' . $e->getMessage()]);
    }
}

// ==================== BACKUP SERVER MANAGEMENT ====================

if ($action === 'admin_save_backup_server' && $method === 'POST') {
    requireAdmin();
    
    try {
        // Crea cartella backups se non esiste
        $backupsDir = __DIR__ . '/data/backups';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, 0755, true);
        }
        
        $config = readConfig();
        $state = readJsonFile(DATA_FILE, initialState());
        
        $backup = [
            'version' => '1.0',
            'timestamp' => date('c'),
            'config' => $config,
            'teams' => $state['teams'] ?? [],
            'groups' => $state['groups'] ?? [],
            'groupMatches' => $state['groupMatches'] ?? [],
            'playoff' => $state['playoff'] ?? [],
            'standings' => $state['standings'] ?? [],
            'finalRanking' => $state['finalRanking'] ?? []
        ];
        
        $filename = 'backup-' . date('Y-m-d_His') . '.json';
        $filepath = $backupsDir . '/' . $filename;
        
        file_put_contents($filepath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Backup salvato sul server',
            'filename' => $filename,
            'timestamp' => date('c')
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore salvataggio backup: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_list_backups' && $method === 'GET') {
    requireAdmin();
    
    try {
        $backupsDir = __DIR__ . '/data/backups';
        $backups = [];
        
        if (is_dir($backupsDir)) {
            $files = glob($backupsDir . '/backup-*.json');
            rsort($files); // Ordina dal più recente al più vecchio
            
            foreach ($files as $file) {
                $filename = basename($file);
                $size = filesize($file);
                $modified = filemtime($file);
                
                // Leggi il timestamp dal file
                $content = json_decode(file_get_contents($file), true);
                $timestamp = $content['timestamp'] ?? date('c', $modified);
                
                $backups[] = [
                    'filename' => $filename,
                    'timestamp' => $timestamp,
                    'size' => $size,
                    'sizeHuman' => formatBytes($size),
                    'modified' => $modified,
                    'modifiedHuman' => date('d/m/Y H:i:s', $modified)
                ];
            }
        }
        
        jsonResponse(200, [
            'ok' => true,
            'backups' => $backups,
            'count' => count($backups)
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore lettura backup: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_delete_backup' && $method === 'POST') {
    requireAdmin();
    
    try {
        $body = bodyJson();
        $filename = (string)($body['filename'] ?? '');
        
        if (!$filename || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nome file non valido']);
            return;
        }
        
        $backupPath = __DIR__ . '/data/backups/' . $filename;
        
        if (!file_exists($backupPath)) {
            jsonResponse(404, ['ok' => false, 'error' => 'Backup non trovato']);
            return;
        }
        
        unlink($backupPath);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Backup eliminato',
            'filename' => $filename
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore eliminazione backup: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_restore_backup' && $method === 'POST') {
    requireAdmin();
    
    try {
        $body = bodyJson();
        $filename = (string)($body['filename'] ?? '');
        
        if (!$filename || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nome file non valido']);
            return;
        }
        
        $backupPath = __DIR__ . '/data/backups/' . $filename;
        
        if (!file_exists($backupPath)) {
            jsonResponse(404, ['ok' => false, 'error' => 'Backup non trovato']);
            return;
        }
        
        $content = file_get_contents($backupPath);
        $backup = json_decode($content, true);
        
        if (!is_array($backup) || !isset($backup['config'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'File di backup non valido']);
            return;
        }
        
        // Ripristina tutti i dati
        writeJsonFile(CONFIG_FILE, $backup['config']);
        writeJsonFile(DATA_FILE, [
            'teams' => $backup['teams'] ?? [],
            'groups' => $backup['groups'] ?? [],
            'groupMatches' => $backup['groupMatches'] ?? [],
            'playoff' => $backup['playoff'] ?? [],
            'standings' => $backup['standings'] ?? [],
            'finalRanking' => $backup['finalRanking'] ?? [],
            'meta' => ['lastUpdated' => date('c')]
        ]);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Backup ripristinato con successo',
            'timestamp' => $backup['timestamp'],
            'itemsRestored' => [
                'teams' => count($backup['teams'] ?? []),
                'groups' => count($backup['groups'] ?? [])
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore ripristino backup: ' . $e->getMessage()]);
    }
}

// ==================== AUTOSAVE E UNDO ====================

if ($action === 'admin_toggle_autosave' && $method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $config = readConfig();
    
    $enabled = (bool)($body['enabled'] ?? false);
    $intervalSeconds = max(5, min(300, (int)($body['intervalSeconds'] ?? 30)));
    $maxSteps = max(1, min(100, (int)($body['maxSteps'] ?? 10)));
    
    $config['autosave'] = [
        'enabled' => $enabled,
        'intervalSeconds' => $intervalSeconds,
        'maxSteps' => $maxSteps
    ];
    
    writeConfig($config);
    
    jsonResponse(200, [
        'ok' => true,
        'autosave' => $config['autosave'],
        'message' => $enabled ? 'Autosave abilitato' : 'Autosave disabilitato'
    ]);
}

if ($action === 'admin_get_history' && $method === 'GET') {
    $history = getHistory();
    
    // Formatta la cronologia per il frontend
    $formatted = [];
    foreach ($history['snapshots'] as $idx => $snapshot) {
        $formatted[] = [
            'index' => $idx,
            'timestamp' => $snapshot['timestamp'],
            'description' => $snapshot['description'],
            'canRestore' => $idx > 0 // Non ripristinare lo snapshot corrente (idx 0)
        ];
    }
    
    jsonResponse(200, [
        'ok' => true,
        'lastSaved' => $history['lastSaved'],
        'snapshots' => $formatted,
        'totalSnapshots' => count($formatted)
    ]);
}

if ($action === 'admin_undo_step' && $method === 'POST') {
    $body = bodyJson();
    $steps = max(1, min(100, (int)($body['steps'] ?? 1)));
    
    $history = getHistory();
    
    if (empty($history['snapshots']) || count($history['snapshots']) <= $steps) {
        jsonResponse(400, ['ok' => false, 'error' => 'Non ci sono abbastanza passi da annullare']);
    }
    
    // Prendi lo snapshot al passo richiesto
    $targetSnapshot = $history['snapshots'][$steps];
    
    // Ripristina config e state
    writeJsonFile(CONFIG_FILE, $targetSnapshot['config']);
    writeJsonFile(DATA_FILE, $targetSnapshot['state']);
    
    // Salva questa azione nella history per nuovi step futuri
    $newHistory = [
        'snapshots' => [],
        'lastSaved' => date('Y-m-d H:i:s')
    ];
    
    // Mantieni solo gli snapshot dopo quello ripristinato (es: se undo 1 step, mantieni tutto from index 2+)
    for ($i = $steps; $i < count($history['snapshots']); $i++) {
        $newHistory['snapshots'][] = $history['snapshots'][$i];
    }
    
    // Aggiungi uno snapshot del ripristino
    $newHistory['snapshots'][] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'description' => "Undo di $steps passo/i - Ripristinato: " . $targetSnapshot['description'],
        'config' => $targetSnapshot['config'],
        'state' => $targetSnapshot['state']
    ];
    
    writeJsonFile(HISTORY_FILE, $newHistory);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => "Annullato $steps passo/i",
        'config' => $targetSnapshot['config'],
        'state' => $targetSnapshot['state']
    ]);
}

// Rileva il tipo di autenticazione (single-tenant vs multi-tenant)
if ($action === 'get_auth_mode' && $method === 'GET') {
    $tournamentConfigFile = __DIR__ . '/.tournament-config.json';
    $isMultiTenant = file_exists($tournamentConfigFile);
    
    if ($isMultiTenant) {
        $config = readJsonFile($tournamentConfigFile, []);
        jsonResponse(200, [
            'ok' => true,
            'mode' => 'multi-tenant',
            'tournamentCode' => $config['tournamentCode'] ?? '',
            'tournamentName' => $config['tournamentName'] ?? ''
        ]);
    } else {
        jsonResponse(200, [
            'ok' => true,
            'mode' => 'single-tenant',
            'tournamentCode' => 'local'
        ]);
    }
}

// ==================== MULTI-TENANT ENDPOINTS ====================

// Genera un GUID univoco
function generateGUID(): string {
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
}

// Leggi la lista dei tornei
function getTournamentsRegistry(): array {
    $registryFile = __DIR__ . '/data/tournaments.json';
    return readJsonFile($registryFile, ['tournaments' => []]);
}

// Scrivi la lista dei tornei
function writeTournamentsRegistry(array $data): void {
    writeJsonFile(__DIR__ . '/data/tournaments.json', $data);
}

// Copia ricorsivamente una directory
function copyDirectory(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    
    // Usa PHP puro per la copia (più affidabile)
    $dir = @opendir($src);
    if (!$dir) return false;
    
    $count = 0;
    while (($file = readdir($dir)) !== false) {
        // Esclusioni
        if ($file === '.' || $file === '..' || $file === '.git' || $file === 'node_modules' || $file === 'beachmaster') {
            continue;
        }
        
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        
        try {
            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
                $count++;
            }
        } catch (Throwable $e) {
            error_log("copyDirectory error copying $srcPath: " . $e->getMessage());
            // Continua con gli altri file
            continue;
        }
    }
    closedir($dir);
    
    error_log("✅ copyDirectory: copied $count files from $src to $dst");
    return true;
}

// TEST EMAIL - verifica se il sistema di email è funzionante
if ($action === 'test_email' && $method === 'POST') {
    try {
        $body = bodyJson();
        $testEmail = trim((string)($body['testEmail'] ?? ''));
        
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(400, ['ok' => false, 'error' => 'Email non valida']);
        }
        
        $subject = '🧪 Test Email - BeachMaster System';
        $htmlBody = "<html><body style='font-family: Arial, sans-serif; background: #f3ead8; padding: 20px'><div style='background: white; border-radius: 8px; padding: 30px; max-width: 500px; margin: 0 auto'><h2 style='color: #201c14; text-align: center'>✅ Email Test Successful</h2><p style='color: #6a5737; line-height: 1.6'>Questo è un email di test dal sistema BeachMaster.</p><p style='color: #6a5737; line-height: 1.6'><strong>⏰ Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p><p style='color: #6a5737; line-height: 1.6'>Se ricevi questo messaggio, il sistema di email è configurato correttamente.</p><hr style='border: none; border-top: 1px solid #d9bf8c; margin: 20px 0'><p style='color: #6a5737; font-size: 12px; text-align: center'>BeachMaster • Gestione Tornei di Beach Volley</p></div></body></html>";
        
        $emailResult = sendEmail($testEmail, $subject, $htmlBody);
        
        jsonResponse(200, [
            'ok' => true,
            'success' => $emailResult['success'],
            'message' => $emailResult['success'] ? '✅ Email inviata con successo - controlla la casella di posta' : '❌ Impossibile inviare email',
            'to' => $testEmail,
            'details' => $emailResult
        ]);
    } catch (Throwable $e) {
        error_log('test_email error: ' . $e->getMessage());
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore test email: ' . $e->getMessage()
        ]);
    }
}

// Crea torneo MULTI-TENANT
if ($action === 'create_tournament' && $method === 'POST') {
    error_log('📌 create_tournament: START');
    $body = bodyJson();
    $managerEmail = trim((string)($body['managerEmail'] ?? ''));
    $managerPassword = trim((string)($body['managerPassword'] ?? ''));
    $tournamentName = trim((string)($body['tournamentName'] ?? 'Nuovo Torneo'));
    
    error_log("📌 create_tournament: email=$managerEmail, name=$tournamentName");
    
    if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("📌 create_tournament: invalid email");
        jsonResponse(422, ['ok' => false, 'error' => 'Email non valida']);
    }
    
    if (strlen($managerPassword) < 8) {
        error_log("📌 create_tournament: password too short");
        jsonResponse(422, ['ok' => false, 'error' => 'Password deve avere almeno 8 caratteri']);
    }
    
    $tournamentCode = generateGUID();
    $beachmasterDir = __DIR__ . '/beachmaster';
    $tournamentDir = $beachmasterDir . '/' . $tournamentCode;
    
    error_log("📌 create_tournament: tournamentCode=$tournamentCode, dir=$tournamentDir");
    
    // Crea la directory beachmaster se non esiste
    if (!is_dir($beachmasterDir)) {
        mkdir($beachmasterDir, 0755, true);
    }
    
    // Copia il progetto nella nuova directory
    error_log("📌 create_tournament: starting copyDirectory...");
    if (!copyDirectory(__DIR__, $tournamentDir)) {
        error_log("📌 create_tournament: copyDirectory FAILED");
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nella creazione del torneo']);
    }
    error_log("📌 create_tournament: copyDirectory completed");
    
    // Pulisci: rimuovi la cartella beachmaster se è stata copiata per errore
    $beachmasterInTournament = $tournamentDir . '/beachmaster';
    if (is_dir($beachmasterInTournament)) {
        error_log("⚠️  ALERT: Trovata cartella beachmaster nel torneo! Rimuovo...");
        shell_exec("rm -rf " . escapeshellarg($beachmasterInTournament) . " 2>/dev/null");
    }
    
    // Nel torneo copiato: gestisci i file HTML
    // Elimina index.html (landing page del root che non serve nel torneo)
    // Rinomina scoreboard.html a index.html (homepage pubblica del torneo)
    $indexFile = $tournamentDir . '/index.html';
    $scoreboardFile = $tournamentDir . '/scoreboard.html';
    
    if (file_exists($indexFile)) {
        unlink($indexFile); // Elimina la landing page copiata
    }
    
    if (file_exists($scoreboardFile)) {
        rename($scoreboardFile, $indexFile); // Rinomina scoreboard.html a index.html
    }
    
    // Aggiorna admin.html nel torneo per puntare a index.html
    $adminFile = $tournamentDir . '/admin.html';
    if (file_exists($adminFile)) {
        $adminContent = file_get_contents($adminFile);
        // Sostituisci il link da scoreboard.html a index.html
        $adminContent = str_replace('href="scoreboard.html"', 'href="index.html"', $adminContent);
        file_put_contents($adminFile, $adminContent);
    }
    
    // Crea directory data/uploads se non esiste
    $uploadsDir = $tournamentDir . '/data/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }
    
    // Scrivi un file .env o config di tenant nel nuovo torneo
    $envFile = $tournamentDir . '/.tournament-config.json';
    $envConfig = [
        'tournamentCode' => $tournamentCode,
        'managerEmail' => $managerEmail,
        'managerPassword' => password_hash($managerPassword, PASSWORD_BCRYPT),
        'tournamentName' => $tournamentName,
        'createdAt' => date('Y-m-d H:i:s'),
        'updatedAt' => date('Y-m-d H:i:s')
    ];
    writeJsonFile($envFile, $envConfig);
    
    // Registra il torneo nella lista globale
    $registry = getTournamentsRegistry();
    $registry['tournaments'][] = [
        'code' => $tournamentCode,
        'email' => $managerEmail,
        'name' => $tournamentName,
        'path' => 'beachmaster/' . $tournamentCode,
        'createdAt' => date('Y-m-d H:i:s')
    ];
    writeTournamentsRegistry($registry);
    
    // Genera un token temporaneo per il primo accesso
    $token = bin2hex(random_bytes(32));
    
    error_log("📌 create_tournament: SUCCESS - code=$tournamentCode");
    
    jsonResponse(201, [
        'ok' => true,
        'message' => 'Torneo creato con successo',
        'tournamentCode' => $tournamentCode,
        'token' => $token,
        'redirectUrl' => 'beachmaster/' . $tournamentCode . '/admin.html'
    ]);
}

// Login per torneo multi-tenant
if ($action === 'tournament_login' && $method === 'POST') {
    $body = bodyJson();
    $tournamentCode = trim((string)($body['tournamentCode'] ?? ''));
    $managerEmail = trim((string)($body['managerEmail'] ?? ''));
    $managerPassword = trim((string)($body['managerPassword'] ?? ''));
    
    if (empty($tournamentCode) || !preg_match('/^[A-Z0-9]{12}$/', $tournamentCode)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Codice torneo non valido']);
    }
    
    $tournamentDir = __DIR__ . '/beachmaster/' . $tournamentCode;
    $configFile = $tournamentDir . '/.tournament-config.json';
    
    if (!file_exists($configFile)) {
        jsonResponse(404, ['ok' => false, 'error' => 'Torneo non trovato']);
    }
    
    $config = readJsonFile($configFile, []);
    
    // Verifica email e password
    if ($config['managerEmail'] !== $managerEmail) {
        jsonResponse(401, ['ok' => false, 'error' => 'Email non corretta']);
    }
    
    if (!password_verify($managerPassword, $config['managerPassword'])) {
        jsonResponse(401, ['ok' => false, 'error' => 'Password non corretta']);
    }
    
    // Genera token
    $token = bin2hex(random_bytes(32));
    
    // Salva il token nella sessione del torneo
    $sessionsFile = $tournamentDir . '/data/sessions.json';
    $sessions = readJsonFile($sessionsFile, ['sessions' => []]);
    
    $sessions['sessions'][] = [
        'token' => $token,
        'email' => $managerEmail,
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => date('Y-m-d H:i:s', time() + 86400 * 7) // 7 giorni
    ];
    
    writeJsonFile($sessionsFile, $sessions);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Login effettuato',
        'token' => $token,
        'tournamentCode' => $tournamentCode
    ]);
}

// ==================== CONTROL PANEL ENDPOINTS ====================

// Richiedi accesso al pannello di controllo (invia codice via mail)
if ($action === 'request_control_panel_access' && $method === 'POST') {
    try {
        $body = bodyJson();
        $managerEmail = trim((string)($body['managerEmail'] ?? ''));
        
        if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(400, ['ok' => false, 'error' => 'Email non valida']);
        }
        
        // Genera un codice segreto a 6 cifre
        $secretCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Salva il codice nella registry (con scadenza 1 ora)
        $registry = getTournamentsRegistry();
        if (!isset($registry['controlPanelCodes'])) {
            $registry['controlPanelCodes'] = [];
        }
        $registry['controlPanelCodes'][] = [
            'email' => $managerEmail,
            'code' => $secretCode,
            'createdAt' => date('Y-m-d H:i:s'),
            'expiresAt' => date('Y-m-d H:i:s', time() + 3600), // 1 ora
            'used' => false
        ];
        writeTournamentsRegistry($registry);
        
        // Invia email con il codice
        $subject = 'Codice di Accesso - Pannello di Controllo BeachMaster';
        $htmlBody = "<html><body style='font-family: Arial, sans-serif; background: #f3ead8; padding: 20px'><div style='background: white; border-radius: 8px; padding: 30px; max-width: 500px; margin: 0 auto'><h2 style='color: #201c14; text-align: center'>🔐 Codice di Accesso</h2><p style='color: #6a5737; line-height: 1.6'>Ciao,</p><p style='color: #6a5737; line-height: 1.6'>Hai richiesto l'accesso al pannello di controllo di BeachMaster. Usa il codice seguente per accedere:</p><div style='background: #f0f0f0; border: 2px solid #ea5b0c; border-radius: 6px; padding: 15px; text-align: center; margin: 20px 0'><h1 style='color: #ea5b0c; margin: 0; font-size: 2.5rem; letter-spacing: 2px'>$secretCode</h1></div><p style='color: #6a5737; line-height: 1.6'><strong>⏰ Questo codice scade in 1 ora.</strong></p><p style='color: #6a5737; line-height: 1.6'>Se non hai richiesto questo codice, ignora questo messaggio.</p><hr style='border: none; border-top: 1px solid #d9bf8c; margin: 20px 0'><p style='color: #6a5737; font-size: 12px; text-align: center'>BeachMaster • Gestione Tornei di Beach Volley</p></div></body></html>";
        
        $emailResult = sendEmail($managerEmail, $subject, $htmlBody);
        
        // Restituisci feedback con dettagli email
        $response = ['ok' => true];
        
        if ($emailResult['success']) {
            $response['message'] = '✅ Codice inviato via email';
            $response['emailStatus'] = 'success';
        } else {
            $response['message'] = '✅ Codice generato (verifica email in entrata)';
            $response['emailStatus'] = 'warning';
            $response['emailError'] = $emailResult['error'] ?? 'Email non disponibile';
        }
        
        jsonResponse(200, $response);
    } catch (Throwable $e) {
        error_log('request_control_panel_access error: ' . $e->getMessage());
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore interno: ' . $e->getMessage()
        ]);
    }
}

// Verifica il codice e ottieni i tornei dell'utente
if ($action === 'verify_control_panel_code' && $method === 'POST') {
    $body = bodyJson();
    $managerEmail = trim((string)($body['managerEmail'] ?? ''));
    $secretCode = trim((string)($body['secretCode'] ?? ''));
    
    if (!filter_var($managerEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Email non valida']);
    }
    
    if (empty($secretCode)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Codice non inserito']);
    }
    
    // Verifica il codice
    $registry = getTournamentsRegistry();
    $validCode = false;
    $codeIndex = -1;
    
    foreach ($registry['controlPanelCodes'] ?? [] as $idx => $codeEntry) {
        if ($codeEntry['email'] === $managerEmail && 
            $codeEntry['code'] === $secretCode && 
            !$codeEntry['used'] &&
            strtotime($codeEntry['expiresAt']) > time()) {
            $validCode = true;
            $codeIndex = $idx;
            break;
        }
    }
    
    if (!$validCode) {
        jsonResponse(401, ['ok' => false, 'error' => 'Codice non valido o scaduto']);
    }
    
    // Marca il codice come usato
    $registry['controlPanelCodes'][$codeIndex]['used'] = true;
    writeTournamentsRegistry($registry);
    
    // Ottieni i tornei dell'utente
    $userTournaments = [];
    foreach ($registry['tournaments'] ?? [] as $tournament) {
        if ($tournament['email'] === $managerEmail) {
            $userTournaments[] = $tournament;
        }
    }
    
    // Genera un token di sessione per il pannello di controllo
    $panelToken = bin2hex(random_bytes(32));
    $registry['panelSessions'][] = [
        'token' => $panelToken,
        'email' => $managerEmail,
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => date('Y-m-d H:i:s', time() + 86400 * 30) // 30 giorni
    ];
    writeTournamentsRegistry($registry);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Accesso verificato',
        'panelToken' => $panelToken,
        'tournaments' => $userTournaments,
        'count' => count($userTournaments)
    ]);
}

// Ottieni i tornei dell'utente
if ($action === 'get_user_tournaments' && $method === 'POST') {
    $body = bodyJson();
    $panelToken = trim((string)($body['panelToken'] ?? ''));
    $managerEmail = trim((string)($body['managerEmail'] ?? ''));
    
    if (empty($panelToken) || empty($managerEmail)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti']);
    }
    
    // Valida il token
    $registry = getTournamentsRegistry();
    $validSession = false;
    foreach ($registry['panelSessions'] ?? [] as $session) {
        if ($session['token'] === $panelToken && 
            $session['email'] === $managerEmail &&
            strtotime($session['expiresAt']) > time()) {
            $validSession = true;
            break;
        }
    }
    
    if (!$validSession) {
        jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    }
    
    // Ottieni i tornei dell'utente
    $userTournaments = [];
    foreach ($registry['tournaments'] ?? [] as $tournament) {
        if ($tournament['email'] === $managerEmail) {
            $userTournaments[] = $tournament;
        }
    }
    
    jsonResponse(200, [
        'ok' => true,
        'tournaments' => $userTournaments,
        'count' => count($userTournaments)
    ]);
}

// Ottieni TUTTI i tornei (per admin root)
if ($action === 'multitenant_get_all_tournaments' && $method === 'POST') {
    $registry = getTournamentsRegistry();
    
    jsonResponse(200, [
        'ok' => true,
        'tournaments' => $registry['tournaments'] ?? [],
        'count' => count($registry['tournaments'] ?? [])
    ]);
}

// Disabilita un torneo
if ($action === 'disable_tournament' && $method === 'POST') {
    $body = bodyJson();
    $tournamentCode = trim((string)($body['tournamentCode'] ?? ''));
    $panelToken = trim((string)($body['panelToken'] ?? ''));
    
    if (empty($tournamentCode) || empty($panelToken)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti']);
    }
    
    // Valida il token (accetta "admin" dal root oppure sessioni normali)
    $registry = getTournamentsRegistry();
    $validSession = false;
    
    // Controlla se è admin dal root
    if ($panelToken === 'admin') {
        $validSession = true;
    } else {
        // Altrimenti controlla le sessioni normali
        foreach ($registry['panelSessions'] ?? [] as $session) {
            if ($session['token'] === $panelToken && strtotime($session['expiresAt']) > time()) {
                $validSession = true;
                break;
            }
        }
    }
    
    if (!$validSession) {
        jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    }
    
    // Trova e disabilita il torneo
    $tournamentFound = false;
    for ($i = 0; $i < count($registry['tournaments']); $i++) {
        if ($registry['tournaments'][$i]['code'] === $tournamentCode) {
            $registry['tournaments'][$i]['disabled'] = true;
            $registry['tournaments'][$i]['disabledAt'] = date('Y-m-d H:i:s');
            $tournamentFound = true;
            break;
        }
    }
    
    if (!$tournamentFound) {
        jsonResponse(404, ['ok' => false, 'error' => 'Torneo non trovato']);
    }
    
    writeTournamentsRegistry($registry);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Torneo disabilitato'
    ]);
}

// Abilita un torneo
if ($action === 'enable_tournament' && $method === 'POST') {
    $body = bodyJson();
    $tournamentCode = trim((string)($body['tournamentCode'] ?? ''));
    $panelToken = trim((string)($body['panelToken'] ?? ''));
    
    if (empty($tournamentCode) || empty($panelToken)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti']);
    }
    
    // Valida il token (accetta "admin" dal root oppure sessioni normali)
    $registry = getTournamentsRegistry();
    $validSession = false;
    
    // Controlla se è admin dal root
    if ($panelToken === 'admin') {
        $validSession = true;
    } else {
        // Altrimenti controlla le sessioni normali
        foreach ($registry['panelSessions'] ?? [] as $session) {
            if ($session['token'] === $panelToken && strtotime($session['expiresAt']) > time()) {
                $validSession = true;
                break;
            }
        }
    }
    
    if (!$validSession) {
        jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    }
    
    // Trova e abilita il torneo
    $tournamentFound = false;
    for ($i = 0; $i < count($registry['tournaments']); $i++) {
        if ($registry['tournaments'][$i]['code'] === $tournamentCode) {
            $registry['tournaments'][$i]['disabled'] = false;
            $tournamentFound = true;
            break;
        }
    }
    
    if (!$tournamentFound) {
        jsonResponse(404, ['ok' => false, 'error' => 'Torneo non trovato']);
    }
    
    writeTournamentsRegistry($registry);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Torneo abilitato'
    ]);
}

// Cancella un torneo
if ($action === 'delete_tournament' && $method === 'POST') {
    $body = bodyJson();
    $tournamentCode = trim((string)($body['tournamentCode'] ?? ''));
    $panelToken = trim((string)($body['panelToken'] ?? ''));
    
    if (empty($tournamentCode) || empty($panelToken)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti']);
    }
    
    // Valida il token (accetta "admin" dal root oppure sessioni normali)
    $registry = getTournamentsRegistry();
    $validSession = false;
    
    // Controlla se è admin dal root
    if ($panelToken === 'admin') {
        $validSession = true;
    } else {
        // Altrimenti controlla le sessioni normali
        foreach ($registry['panelSessions'] ?? [] as $session) {
            if ($session['token'] === $panelToken && strtotime($session['expiresAt']) > time()) {
                $validSession = true;
                break;
            }
        }
    }
    
    if (!$validSession) {
        jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    }
    
    // Trova e cancella il torneo
    $tournamentIndex = -1;
    $tournamentPath = '';
    foreach ($registry['tournaments'] ?? [] as $idx => $tournament) {
        if ($tournament['code'] === $tournamentCode) {
            $tournamentIndex = $idx;
            $tournamentPath = $tournament['path'];
            break;
        }
    }
    
    if ($tournamentIndex === -1) {
        jsonResponse(404, ['ok' => false, 'error' => 'Torneo non trovato']);
    }
    
    // Cancella la directory del torneo
    $fullPath = __DIR__ . '/' . $tournamentPath;
    if (is_dir($fullPath)) {
        $result = shell_exec("rm -rf " . escapeshellarg($fullPath) . " 2>&1");
    }
    
    // Rimuovi dal registry
    array_splice($registry['tournaments'], $tournamentIndex, 1);
    writeTournamentsRegistry($registry);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Torneo cancellato'
    ]);
}

// DEBUG: Endpoint per diagnosticare lo schedule
if ($action === 'debug_schedule' && $method === 'GET') {
    try {
        $config = readConfig();
        $state = readJsonFile(DATA_FILE, initialState());
        
        $courts = $config['schedule']['courts'] ?? [];
        
        $totalSlots = 0;
        $slotsList = [];
        foreach ($courts as $court) {
            foreach ($court['availability'] ?? [] as $dateAvail) {
                $slotsList[] = [
                    'court' => $court['courtName'],
                    'date' => $dateAvail['date'],
                    'slots' => count($dateAvail['timeSlots'] ?? [])
                ];
                $totalSlots += count($dateAvail['timeSlots'] ?? []);
            }
        }
        
        $groupMatches = $state['groupMatches'] ?? [];
        $totalMatches = count($groupMatches);
        
        jsonResponse(200, [
            'ok' => true,
            'debug' => [
                'courts_count' => count($courts),
                'total_slots' => $totalSlots,
                'total_matches' => $totalMatches,
                'slots_list' => $slotsList,
                'groups_count' => count($state['groups'] ?? []),
                'teams_count' => count($state['teams'] ?? []),
                'approved_teams_count' => count(array_filter($state['teams'] ?? [], fn($t) => $t['approved'] ?? false)),
                'config_schedule_courts' => $courts
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Debug error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}

if ($action === 'admin_debug_teams' && $method === 'GET') {
    try {
        $state = readJsonFile(DATA_FILE, initialState());
        $teams = $state['teams'] ?? [];
        
        $approved = approvedTeams($state);
        
        $teamsList = array_map(function($t) {
            return [
                'id' => $t['id'],
                'name' => $t['name'],
                'approved' => $t['approved'] ?? false,
                'paid' => $t['paid'] ?? false,
                'dummy' => $t['dummy'] ?? false,
                'kitDelivered' => $t['kitDelivered'] ?? false
            ];
        }, $teams);
        
        jsonResponse(200, [
            'ok' => true,
            'total_teams' => count($teams),
            'approved_count' => count($approved),
            'teams_list' => $teamsList
        ]);
    } catch (Exception $e) {
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Debug error: ' . $e->getMessage()
        ]);
    }
}

// Tournament Flow Editor Endpoints
const FLOW_FILE = __DIR__ . '/data/tournament-flow.json';

if ($action === 'admin_save_tournament_flow' && $method === 'POST') {
    if (!validSession()) jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    
    if (empty($body['blocks']) || !is_array($body['blocks'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Flusso non valido']);
    }
    
    $flow = [
        'version' => '1.0',
        'blocks' => $body['blocks'],
        'connections' => $body['connections'] ?? [],
        'savedAt' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    writeJsonFile(FLOW_FILE, $flow);
    jsonResponse(200, ['ok' => true, 'message' => 'Flusso salvato con successo']);
}

if ($action === 'admin_load_tournament_flow' && $method === 'GET') {
    if (!validSession()) jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    
    $flow = readJsonFile(FLOW_FILE, ['version' => '1.0', 'blocks' => [], 'connections' => []]);
    jsonResponse(200, ['ok' => true, 'flow' => $flow]);
}

if ($action === 'admin_generate_from_flow' && $method === 'POST') {
    if (!validSession()) jsonResponse(401, ['ok' => false, 'error' => 'Sessione non valida']);
    
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $flow = $body['flow'] ?? [];
        
        if (empty($flow['blocks'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'Flusso vuoto']);
        }
        
        // Leggi config e state correnti
        $config = readConfig();
        $state = readJsonFile(DATA_FILE, initialState());
        
        // Processa ogni blocco del flusso
        $groupsPhase = null;
        $knockoutPhases = [];
        
        foreach ($flow['blocks'] as $block) {
            if ($block['type'] === 'groups') {
                $groupsPhase = [
                    'type' => 'groups',
                    'numGroups' => $block['config']['numGroups'] ?? 3,
                    'teamsAdvance' => $block['config']['teamsAdvance'] ?? 2,
                    'hasRepescage' => false
                ];
            } elseif ($block['type'] === 'knockout') {
                $knockoutPhases[] = [
                    'type' => 'knockout',
                    'numTeams' => $block['config']['numTeams'] ?? 8,
                    'label' => $block['label'] ?? 'Playoff'
                ];
            } elseif ($block['type'] === 'repescage') {
                if ($groupsPhase) {
                    $groupsPhase['hasRepescage'] = true;
                }
            }
        }
        
        // Costruisci array fasi
        $phases = [];
        if ($groupsPhase) {
            $phases[] = $groupsPhase;
        }
        $phases = array_merge($phases, $knockoutPhases);
        
        // Salva la configurazione delle fasi
        $config['phases'] = $phases;
        writeJsonFile(CONFIG_FILE, $config);
        
        // Salva anche il flusso come reference
        $flow['processedAt'] = date('Y-m-d H:i:s');
        writeJsonFile(FLOW_FILE, $flow);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Flusso elaborato e configurazione aggiornata',
            'phasesCreated' => count($phases),
            'phases' => $phases
        ]);
    } catch (Exception $e) {
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore durante elaborazione flusso: ' . $e->getMessage()
        ]);
    }
}

// Salva la durata di una partita
if ($action === 'save_match_duration' && $method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $matchId = $data['matchId'] ?? null;
        $duration = intval($data['duration'] ?? 0);
        
        if (!$matchId) {
            jsonResponse(400, ['ok' => false, 'error' => 'matchId non fornito']);
        }
        
        // Leggi lo stato
        $state = readJsonFile(DATA_FILE, []);
        $found = false;
        
        // Cerca la partita nei groupMatches
        foreach ($state['groupMatches'] ?? [] as &$match) {
            if (($match['id'] ?? '') === $matchId) {
                $match['duration'] = $duration;
                $found = true;
                break;
            }
        }
        
        // Se non trovato nei groupMatches, cerca nei playoff matches
        if (!$found) {
            $playoff = $state['playoff'] ?? [];
            
            // Cerca in quarterFinals
            foreach ($playoff['quarterFinals'] ?? [] as &$match) {
                if (($match['id'] ?? '') === $matchId) {
                    $match['duration'] = $duration;
                    $found = true;
                    break;
                }
            }
            
            // Cerca in semiFinals
            if (!$found) {
                foreach ($playoff['semiFinals'] ?? [] as &$match) {
                    if (($match['id'] ?? '') === $matchId) {
                        $match['duration'] = $duration;
                        $found = true;
                        break;
                    }
                }
            }
            
            // Cerca in thirdPlace
            if (!$found && isset($playoff['thirdPlace'])) {
                if (($playoff['thirdPlace']['id'] ?? '') === $matchId) {
                    $playoff['thirdPlace']['duration'] = $duration;
                    $found = true;
                }
            }
            
            // Cerca in final
            if (!$found && isset($playoff['final'])) {
                if (($playoff['final']['id'] ?? '') === $matchId) {
                    $playoff['final']['duration'] = $duration;
                    $found = true;
                }
            }
            
            if ($found) {
                $state['playoff'] = $playoff;
            }
        }
        
        if ($found) {
            writeJsonFile(DATA_FILE, $state);
            
            // Formatta il tempo
            $hours = intdiv($duration, 3600);
            $minutes = intdiv($duration % 3600, 60);
            $seconds = $duration % 60;
            $timeStr = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            
            jsonResponse(200, [
                'ok' => true,
                'message' => "Durata partita salvata: $timeStr",
                'duration' => $duration,
                'formatted' => $timeStr
            ]);
        } else {
            jsonResponse(404, [
                'ok' => false,
                'error' => 'Partita non trovata'
            ]);
        }
    } catch (Exception $e) {
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore nel salvataggio della durata: ' . $e->getMessage()
        ]);
    }
}

// ========== JSON EDITOR ENDPOINTS ==========

if ($action === 'admin_get_json' && $method === 'GET') {
    // Legge i file JSON disponibili sul server
    try {
        $file = $_GET['file'] ?? null;
        if (!$file) {
            jsonResponse(400, ['ok' => false, 'error' => 'File non specificato']);
            exit;
        }
        
        // Whitelist dei file accessibili
        $allowedFiles = ['tournament', 'config', 'sessions', 'releases', 'version'];
        if (!in_array($file, $allowedFiles)) {
            jsonResponse(400, ['ok' => false, 'error' => 'File non consentito']);
            exit;
        }
        
        // Costruisci il percorso del file
        $filePath = "data/{$file}.json";
        if (!file_exists($filePath)) {
            jsonResponse(404, ['ok' => false, 'error' => "File non trovato: {$file}.json"]);
            exit;
        }
        
        // Leggi il file
        $content = file_get_contents($filePath);
        if ($content === false) {
            jsonResponse(500, ['ok' => false, 'error' => 'Errore lettura file']);
            exit;
        }
        
        jsonResponse(200, [
            'ok' => true,
            'file' => $file,
            'content' => $content,
            'size' => filesize($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath))
        ]);
        
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_save_json' && $method === 'POST') {
    // Salva i file JSON dopo le modifiche
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $file = $input['file'] ?? null;
        $content = $input['content'] ?? null;
        
        if (!$file || !$content) {
            jsonResponse(400, ['ok' => false, 'error' => 'File o content non specificati']);
            exit;
        }
        
        // Whitelist dei file salvabili
        $allowedFiles = ['tournament', 'config', 'sessions', 'releases', 'version'];
        if (!in_array($file, $allowedFiles)) {
            jsonResponse(400, ['ok' => false, 'error' => 'File non consentito']);
            exit;
        }
        
        // Valida JSON
        if (json_decode($content, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(400, [
                'ok' => false,
                'error' => 'JSON non valido: ' . json_last_error_msg()
            ]);
            exit;
        }
        
        // Crea backup prima di salvare
        $filePath = "data/{$file}.json";
        $backupDir = 'data/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        if (file_exists($filePath)) {
            $timestamp = date('Y-m-d_His');
            $backupPath = "{$backupDir}/backup-{$file}-{$timestamp}.json";
            if (!copy($filePath, $backupPath)) {
                jsonResponse(500, ['ok' => false, 'error' => 'Errore creazione backup']);
                exit;
            }
        }
        
        // Salva il file con flock per evitare race conditions
        $fp = fopen($filePath, 'w');
        if (!$fp) {
            jsonResponse(500, ['ok' => false, 'error' => 'Errore apertura file']);
            exit;
        }
        
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            jsonResponse(500, ['ok' => false, 'error' => 'Errore lock file']);
            exit;
        }
        
        // Formatta il JSON prima di salvare (indentato per leggibilità)
        $formatted = json_encode(json_decode($content, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (fwrite($fp, $formatted) === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            jsonResponse(500, ['ok' => false, 'error' => 'Errore scrittura file']);
            exit;
        }
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        // Leggi il file salvato per conferma
        $savedContent = file_get_contents($filePath);
        
        jsonResponse(200, [
            'ok' => true,
            'message' => "File {$file}.json salvato con successo",
            'file' => $file,
            'size' => filesize($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'content' => $savedContent
        ]);
        
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore: ' . $e->getMessage()]);
    }
}

jsonResponse(404, ['ok' => false, 'error' => 'Endpoint non trovato']);
