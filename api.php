<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// 🔧 GLOBAL ERROR HANDLER: cattura tutti gli errori PHP non gestiti e li restituisce come JSON
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("❌ PHP Error [$errno]: $errstr in $errfile:$errline");
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Errore interno del server: ' . $errstr
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_exception_handler(function ($e) {
    error_log("❌ Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log("❌ Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Errore interno del server: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Handler per errori fatali PHP
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("❌ FATAL ERROR: " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Errore fatale del server: ' . $error['message']
        ], JSON_UNESCAPED_UNICODE);
    }
});

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

function readJsonFile(string $file, array $default = []): array {
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

    try {

        // ============================================
        // Caricamento PHPMailer senza Composer
        // Percorso: plugins/phpmailer/src/
        // ============================================
        $phpMailerPath = __DIR__ . '/plugins/phpmailer/src/';

        if (!file_exists($phpMailerPath . 'PHPMailer.php')) {
            $logMessage = date('Y-m-d H:i:s') . " - FAILED: PHPMailer non trovato in $phpMailerPath\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');
            return null;
        }

        require_once $phpMailerPath . 'Exception.php';
        require_once $phpMailerPath . 'PHPMailer.php';
        require_once $phpMailerPath . 'SMTP.php';


        // Leggi la configurazione email da config.json
        $config = readConfig();
        $emailConfig = $config['email'] ?? [];


        // Se email è disabilitata, non inviare
        if (!($emailConfig['enabled'] ?? false)) {

            $logMessage = date('Y-m-d H:i:s') . " - SKIPPED: Email disabilitata nella configurazione\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');

            return [
                'success' => false,
                'error' => 'Email non configurata nel pannello admin',
                'to' => $to,
                'queue' => true
            ];
        }


        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);


        // Parametri SMTP
        $host = trim((string)($emailConfig['host'] ?? ''));
        $port = (int)($emailConfig['port'] ?? 587);
        $username = trim((string)($emailConfig['username'] ?? ''));
        $password = trim((string)($emailConfig['password'] ?? ''));


        if (empty($host) || empty($username) || empty($password)) {

            $logMessage = date('Y-m-d H:i:s') . " - FAILED: Parametri SMTP incompleti in config.json\n";
            @error_log($logMessage, 3, __DIR__ . '/data/email.log');

            return [
                'success' => false,
                'error' => 'Configurazione SMTP incompleta',
                'to' => $to,
                'queue' => true
            ];
        }


        // Config SMTP
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = (bool)($emailConfig['auth'] ?? true);
        $mail->Username = $username;
        $mail->Password = $password;


        $secure = strtolower(trim((string)($emailConfig['secure'] ?? 'tls')));

        if ($secure === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }


        $mail->Port = $port;
        $mail->Timeout = (int)($emailConfig['timeout'] ?? 10);


        // Mittente
        $senderEmail = trim((string)($emailConfig['fromEmail'] ?? $username));
        $senderName = trim((string)($emailConfig['fromName'] ?? 'BeachMaster'));

        $mail->setFrom($senderEmail, $senderName);


        // Reply-To
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


        // Invio
        if ($mail->send()) {

            $logMessage = date('Y-m-d H:i:s') .
                " - SUCCESS (PHPMailer): Email inviata a $to (Subject: $subject)\n";

            @error_log($logMessage, 3, __DIR__ . '/data/email.log');

            return [
                'success' => true,
                'message' => 'Email inviata con successo',
                'to' => $to,
                'method' => 'PHPMailer'
            ];

        } else {

            $errorInfo = $mail->ErrorInfo;

            $logMessage = date('Y-m-d H:i:s') .
                " - FAILED (PHPMailer): $errorInfo\n";

            @error_log($logMessage, 3, __DIR__ . '/data/email.log');


            saveEmailToQueue($to, $subject, $body, $from);


            return [
                'success' => false,
                'error' => 'Errore SMTP: ' . $errorInfo,
                'to' => $to,
                'queue' => true,
                'smtpError' => $errorInfo
            ];
        }


    } catch (\Exception $e) {


        $errorMsg = $e->getMessage();

        $logMessage = date('Y-m-d H:i:s') .
            " - EXCEPTION (PHPMailer): $errorMsg\n";

        @error_log($logMessage, 3, __DIR__ . '/data/email.log');


        saveEmailToQueue($to, $subject, $body, $from);


        return [
            'success' => false,
            'error' => 'Eccezione: ' . $errorMsg,
            'exception' => $errorMsg,
            'queue' => true
        ];
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
            'numSets' => 1,
            'winScore' => 21,
            'maxScore' => 25,
            'timePerSetMinutes' => 25,
            'setupTimeMinutes' => 5,
            'maxTimeoutsPerSet' => 1,
            'registrationsClosed' => false,
            'registrationDeadline' => ''  // Data di fine iscrizioni (formato YYYY-MM-DD)
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
        'gallery' => [],
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
    
    // 🔧 FIX: mancava la preservazione della gallery. Senza questo blocco,
    // ogni volta che readConfig() veniva chiamato (cioè quasi ad ogni richiesta),
    // il merge con i default sovrascriveva l'intera configurazione partendo da
    // $defaultConfig (che non contiene "gallery"), cancellando istantaneamente
    // le foto appena caricate.
    if (isset($existingConfig['gallery'])) {
        $merged['gallery'] = $existingConfig['gallery'];
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
        'phases' => [],
        'currentPhaseIdx' => 1,  // 🆕 Fase selezionata di default
        'meta' => [
            'lastUpdated' => null
        ]
    ];
}

function mergeState(array $existingState, array $newState): array {
    // Preserva i dati critici del torneo dal state esistente
    $merged = $newState;
    
    // Preserva squadre e fasi
    if (isset($existingState['teams']) && is_array($existingState['teams'])) {
        $merged['teams'] = $existingState['teams'];
    }
    
    // PRESERVA LE FASI CON TUTTI I MATCH E GRUPPI SALVATI!
    if (isset($existingState['phases']) && is_array($existingState['phases'])) {
        $merged['phases'] = $existingState['phases'];
    }
    
    // 🆕 Preserva il phase index selezionato dall'utente
    if (isset($existingState['currentPhaseIdx']) && is_numeric($existingState['currentPhaseIdx'])) {
        $merged['currentPhaseIdx'] = $existingState['currentPhaseIdx'];
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

/**
 * Normalizza i groups da "array di array" a "array di oggetti con label e teams"
 * INPUT: [ [team1, team2], [team3, team4] ]
 * OUTPUT: [ { "label": "A", "teams": [team1, team2] }, { "label": "B", "teams": [team3, team4] } ]
 */
function normalizeGroups(array $groups, array $allTeams = []): array {
    $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $normalized = [];
    
    // Crea mappa veloce ID -> Team per lookup
    $teamsById = [];
    foreach ($allTeams as $team) {
        if (isset($team['id'])) {
            $teamsById[$team['id']] = $team;
        }
    }
    
    foreach ($groups as $idx => $group) {
        // Se è già nel formato nuovo con sia 'teams' che 'label', usa direttamente
        if (isset($group['label']) && isset($group['teams'])) {
            if (!isset($group['teamIds']) && is_array($group['teams'])) {
                $group['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $group['teams']);
            }
            $normalized[] = $group;
            continue;
        }
        
        // Formato VECCHIO: solo 'teamIds' (da vecchi tournament.json)
        if (isset($group['teamIds']) && !isset($group['teams'])) {
            $label = $group['label'] ?? $group['name'] ?? $groupNames[$idx] ?? ('G' . ($idx + 1));
            // Ricostruisci i teams da teamIds usando la mappa
            $teams = [];
            foreach ($group['teamIds'] as $teamId) {
                if (isset($teamsById[$teamId])) {
                    $teams[] = $teamsById[$teamId];
                }
            }
            $normalized[] = [
                'label' => $label,
                'name' => $label,
                'teams' => $teams,
                'teamIds' => $group['teamIds']
            ];
            continue;
        }
        
        // Formato vecchio: array di squadre (pure array)
        if (is_array($group) && !isset($group['label']) && !isset($group['teamIds'])) {
            $label = $groupNames[$idx] ?? ('G' . ($idx + 1));
            $teamIds = array_map(fn($t) => $t['id'] ?? null, $group);
            $normalized[] = [
                'label' => $label,
                'name' => $label,
                'teams' => $group,
                'teamIds' => $teamIds
            ];
        }
    }
    
    return $normalized;
}

/**
 * Calcola teamIds da teams se non esiste
 */
function ensureGroupTeamIds(array &$group): array {
    if (!isset($group['teamIds'])) {
        if (isset($group['teams']) && is_array($group['teams'])) {
            $group['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $group['teams']);
        } else {
            $group['teamIds'] = [];
        }
    }
    return $group['teamIds'];
}

/**
 * De-normalizza i groups da "array di oggetti" a "array di array" per il salvataggio
 * INPUT: [ { "label": "A", "teams": [team1, team2] } ]
 * OUTPUT: [ [team1, team2] ]
 */
function denormalizeGroups(array $groups): array {
    $denormalized = [];
    
    foreach ($groups as $group) {
        if (isset($group['teams'])) {
            $denormalized[] = $group['teams'];
        } elseif (is_array($group)) {
            // Se è già array di array, lo teniamo
            $denormalized[] = $group;
        }
    }
    
    return $denormalized;
}

// ===========================
// PHASE MANAGEMENT FUNCTIONS
// ===========================

/**
 * Ottiene o crea l'array phases nello state
 */
function ensurePhases(array &$state): void {
    if (!isset($state['phases']) || !is_array($state['phases'])) {
        $state['phases'] = [];
    }
    
    // Sincronizza le fasi da config.json
    $config = readConfig();
    $configPhases = $config['phases'] ?? [];
    
    if (!empty($configPhases)) {
        // Converti le fasi da config (basate su phaseNumber) a state (basate su phaseIdx)
        foreach ($configPhases as $configPhase) {
            $phaseNumber = $configPhase['phaseNumber'] ?? 0;
            if ($phaseNumber <= 0) continue;
            
            // Verifica se esiste già nello state
            $existsInState = false;
            $existingPhaseStatus = null;
            foreach ($state['phases'] as &$statePhase) {
                if (($statePhase['phaseNumber'] ?? $statePhase['phaseIdx'] ?? 0) === $phaseNumber) {
                    $existsInState = true;
                    // 🔧 PRESERVA lo status dalla fase esistente nello state
                    $existingPhaseStatus = $statePhase['status'] ?? 'pending';
                    break;
                }
            }
            unset($statePhase);
            
            if (!$existsInState) {
                // 🔧 FIX: Preserva i match/groups dal state se già presenti (non sovrascrivere!)
                $existingPhaseInState = null;
                foreach ($state['phases'] as $p) {
                    if (($p['phaseNumber'] ?? $p['phaseIdx'] ?? 0) === $phaseNumber) {
                        $existingPhaseInState = $p;
                        break;
                    }
                }
                
                // Aggiungi la fase da config allo state
                $newPhase = [
                    'id' => 'phase-' . $phaseNumber . '-' . ($configPhase['type'] ?? 'groups'),
                    'phaseIdx' => $phaseNumber,
                    'phaseNumber' => $phaseNumber,
                    'name' => $configPhase['name'] ?? 'Fase ' . $phaseNumber,
                    'type' => $configPhase['type'] ?? 'groups',
                    // 🔧 IMPORTANTE: USA lo status salvato nello state, non da config!
                    'status' => $existingPhaseStatus ?? $configPhase['status'] ?? 'pending',
                    // 🔧 FIX: Preserva groups/matches dal state esistente, non da config
                    'groups' => $existingPhaseInState['groups'] ?? $configPhase['groups'] ?? [],
                    'matches' => $existingPhaseInState['matches'] ?? $configPhase['matches'] ?? [],
                    'standings' => $existingPhaseInState['standings'] ?? $configPhase['standings'] ?? [],
                    'createdAt' => $configPhase['createdAt'] ?? gmdate('c'),
                    'metadata' => $configPhase['metadata'] ?? []
                ];
                
                $state['phases'][] = $newPhase;
            }
        }
    }
    
    if (!isset($state['currentPhaseIdx'])) {
        $state['currentPhaseIdx'] = !empty($state['phases']) ? 1 : 0;
    }
}

/**
 * Ottiene una fase per indice
 */
function getPhase(array &$state, int $phaseIdx): ?array {
    ensurePhases($state);
    foreach ($state['phases'] as &$phase) {
        if (($phase['phaseIdx'] ?? $phase['phaseNumber'] ?? 0) === $phaseIdx) {
            return $phase;
        }
    }
    return null;
}

/**
 * Risolve le regole di gioco (set, durate, punteggio, timeout, cambi) per una
 * fase specifica. Ogni fase può opzionalmente sovrascrivere uno o più valori
 * tramite $configPhase['matchRules'][chiave]; le chiavi non presenti (o vuote)
 * ricadono sul default configurato in Impostazioni → Torneo.
 * Usata ovunque si calcoli la durata di una partita per la schedulazione, così
 * i calcoli seguono il criterio della fase e, se non configurati, i default.
 */
function resolvePhaseMatchRules(array $tournamentConfig, ?array $configPhase, ?int $round = null): array {
    $keys = ['numSets', 'timePerSetMinutes', 'setupTimeMinutes', 'winScore', 'maxScore', 'maxTimeoutsPerSet', 'maxSubstitutions', 'winPoints'];
    $defaults = [
        'numSets' => 1, 'timePerSetMinutes' => 25, 'setupTimeMinutes' => 5,
        'winScore' => 21, 'maxScore' => 25, 'maxTimeoutsPerSet' => 1, 'maxSubstitutions' => 0,
        'winPoints' => 2
    ];
    $rules = [];
    $overrides = $configPhase['matchRules'] ?? [];
    foreach ($keys as $k) {
        if (isset($overrides[$k]) && $overrides[$k] !== '' && $overrides[$k] !== null) {
            $rules[$k] = (int)$overrides[$k];
        } else {
            $rules[$k] = (int)($tournamentConfig[$k] ?? $defaults[$k]);
        }
    }

    // 🆕 Sequenza set per round nel knockout: es. setsPerRound = [1,1,2] significa
    // round 1 (quarti) = 1 set per vincere, round 2 (semifinali) = 1, round 3
    // (finale) = 2 (meglio dei 3). Se $round supera la lunghezza dell'array,
    // si applica l'ultimo valore definito. Sovrascrive 'numSets' solo se presente
    // e solo quando viene passato un $round (partite di fasi a gironi non lo usano).
    if ($round !== null && !empty($overrides['setsPerRound']) && is_array($overrides['setsPerRound'])) {
        $sequence = array_values($overrides['setsPerRound']);
        if (!empty($sequence)) {
            $idx = max(0, $round - 1);
            $idx = min($idx, count($sequence) - 1);
            $val = (int)$sequence[$idx];
            if ($val > 0) {
                $rules['numSets'] = $val;
            }
        }
    }

    return $rules;
}

/**
 * Trova la configurazione (config.json → phases[]) di una fase dato il suo
 * phaseNumber. Usata per risolvere matchRules/winPoints/setsPerRound quando si
 * calcolano classifiche o si determina il vincitore di una partita.
 */
function getConfigPhaseByNumber(array $config, int $phaseNumber): ?array {
    foreach (($config['phases'] ?? []) as $cp) {
        if ((int)($cp['phaseNumber'] ?? -1) === $phaseNumber) {
            return $cp;
        }
    }
    return null;
}

/**
 * Conta i set vinti da ciascuna squadra in una partita, indipendentemente dal
 * formato con cui è stata salvata:
 * - Se 'sets' è popolato (knockout / partite multi-set): ogni elemento è un set
 *   giocato; il punteggio maggiore in ciascun elemento vince quel set.
 * - Se 'sets' è vuoto (gironi a set singolo, legacy): il risultato diretto in
 *   score1/score2 vale come unico set.
 * Ritorna [setsWon1, setsWon2, hasResult] dove hasResult=false se la partita
 * non ha ancora un punteggio registrato.
 */
function getMatchSetsWon(array $match): array {
    $sets = $match['sets'] ?? [];
    if (empty($sets)) {
        $s1 = $match['score1'] ?? null;
        $s2 = $match['score2'] ?? null;
        if ($s1 === null || $s2 === null) {
            return [0, 0, false];
        }
        return [$s1 > $s2 ? 1 : 0, $s2 > $s1 ? 1 : 0, true];
    }

    $won1 = 0;
    $won2 = 0;
    $any = false;
    foreach ($sets as $s) {
        $t1 = $s['team1'] ?? null;
        $t2 = $s['team2'] ?? null;
        if ($t1 === null || $t2 === null) continue;
        $any = true;
        if ($t1 > $t2) $won1++;
        elseif ($t2 > $t1) $won2++;
    }
    return [$won1, $won2, $any];
}

/**
 * Somma i punti fatti/subiti su tutti i set giocati (per statistiche di
 * classifica come "punti fatti/subiti"). Fallback su score1/score2 per le
 * partite legacy senza array 'sets'.
 */
function getMatchPointsScored(array $match): array {
    $sets = $match['sets'] ?? [];
    if (empty($sets)) {
        return [(int)($match['score1'] ?? 0), (int)($match['score2'] ?? 0)];
    }
    $scored1 = 0; $scored2 = 0;
    foreach ($sets as $s) {
        $scored1 += (int)($s['team1'] ?? 0);
        $scored2 += (int)($s['team2'] ?? 0);
    }
    return [$scored1, $scored2];
}

/**
 * Risolve quanti punti di classifica assegnare alla squadra vincente di una
 * partita di una data fase: usa matchRules.winPoints della fase se configurato,
 * altrimenti ricade sul default centrale in config.json → tournament.winPoints.
 */
function getPhaseWinPoints(array $config, int $phaseNumber): int {
    $configPhase = getConfigPhaseByNumber($config, $phaseNumber);
    $rules = resolvePhaseMatchRules($config['tournament'] ?? [], $configPhase);
    return (int)($rules['winPoints'] ?? 2);
}

/**
 * Assegna automaticamente date e orari alle partite dai slot disponibili
 */
function scheduleMatches(array &$state, array $matches, ?array $configPhase = null): array {
    error_log('🔧 scheduleMatches(): INIZIO - Matches totali: ' . count($matches));
    
    $config = readConfig(); // Leggi da config.json
    $schedule = $config['schedule'] ?? [];
    $courts = $schedule['courts'] ?? [];
    
    error_log('🔧 scheduleMatches(): Courts: ' . count($courts));
    
    if (empty($courts)) {
        // Nessuna schedulazione disponibile, ritorna le partite senza date/orari
        error_log('❌ scheduleMatches(): Nessun court configurato, skip scheduling');
        return $matches;
    }
    
    // 🔧 Le regole della partita (set, durata, prep) vengono risolte per la fase
    // specifica: se la fase ha un override in matchRules lo usa, altrimenti
    // ricade sul default configurato per il torneo.
    $phaseRules = resolvePhaseMatchRules($config['tournament'] ?? [], $configPhase);
    $timePerSet = $phaseRules['timePerSetMinutes'];
    $setupTime = $phaseRules['setupTimeMinutes'];
    $numSets = $phaseRules['numSets'];
    $matchDuration = ($timePerSet * $numSets) + $setupTime; // Es: 25*1 + 5 = 30 minuti
    
    error_log('🔧 scheduleMatches(): Match duration = ' . $matchDuration . ' min (setup=' . $setupTime . ', sets=' . $numSets . 'x' . $timePerSet . ')');
    
    // Raggruppa gli slot per (data, ora inizio): tutti i campi disponibili in quel preciso
    // orario finiscono nello stesso "turno". Questo è ciò che permette di sapere, per ogni
    // turno, quali squadre sono già impegnate su un altro campo nello stesso momento.
    $slotsByTime = [];
    
    foreach ($courts as $courtIdx => $court) {
        $courtId = $court['courtId'] ?? ('court-' . ($courtIdx + 1));
        $courtName = $court['courtName'] ?? $courtId;
        $availability = $court['availability'] ?? [];
        
        error_log('🔧 scheduleMatches(): Court ' . $courtIdx . ' (' . $courtName . ') - dates: ' . count($availability));
        
        foreach ($availability as $dateIdx => $avail) {
            $date = $avail['date'] ?? '';
            $timeSlots = $avail['timeSlots'] ?? [];
            
            if (empty($date)) continue;
            
            $globalSlotIdx = 0; // contatore per slot unici sul singolo campo/giorno
            foreach ($timeSlots as $slot) {
                $startStr = $slot['startTime'] ?? '';
                $endStr = $slot['endTime'] ?? '';
                
                if (empty($startStr) || empty($endStr)) continue;
                
                $current = strtotime($startStr);
                $slotEnd = strtotime($endStr);
                
                while ($current + ($matchDuration * 60) <= $slotEnd) {
                    $startTime = date('H:i', $current);
                    $endTime = date('H:i', $current + ($matchDuration * 60));
                    $key = $date . '|' . $startTime;
                    
                    if (!isset($slotsByTime[$key])) {
                        $slotsByTime[$key] = [
                            'date' => $date,
                            'startTime' => $startTime,
                            'endTime' => $endTime,
                            'sortKey' => strtotime($date . ' ' . $startTime),
                            'courts' => []
                        ];
                    }
                    
                    $slotsByTime[$key]['courts'][] = [
                        'courtId' => $courtId,
                        'courtName' => $courtName,
                        'courtIdx' => $courtIdx,
                        'dateIdx' => $dateIdx,
                        'slotIdx' => $globalSlotIdx
                    ];
                    
                    $current += ($matchDuration * 60);
                    $globalSlotIdx++;
                }
            }
        }
    }
    
    if (empty($slotsByTime)) {
        error_log('❌ scheduleMatches(): No available slots, returning matches without schedule');
        return $matches;
    }
    
    // Ordina i turni in ordine cronologico (data + ora)
    $timeSlotsList = array_values($slotsByTime);
    usort($timeSlotsList, fn($a, $b) => $a['sortKey'] <=> $b['sortKey']);
    
    $totalCourtSlots = array_sum(array_map(fn($t) => count($t['courts']), $timeSlotsList));
    error_log('✅ scheduleMatches(): Turni cronologici: ' . count($timeSlotsList) . ', slot-campo totali: ' . $totalCourtSlots);
    
    // Suddividi i turni in "giorni": dato che ogni data è un giorno di calendario distinto,
    // ordinando cronologicamente i turni di una stessa data restano automaticamente contigui.
    // Questo ci permette di individuare i confini tra un giorno e l'altro e di appiattire
    // ogni giorno in una sequenza di celle (turno + campo) pronte da riempire in ordine.
    $dayCells = [];
    $currentDate = null;
    $slotPos = 0;
    
    foreach ($timeSlotsList as $turn) {
        if ($turn['date'] !== $currentDate) {
            $dayCells[] = [];
            $currentDate = $turn['date'];
        }
        $lastDayIdx = count($dayCells) - 1;
        
        foreach ($turn['courts'] as $courtSlot) {
            $dayCells[$lastDayIdx][] = [
                'slotPos' => $slotPos,
                'date' => $turn['date'],
                'startTime' => $turn['startTime'],
                'endTime' => $turn['endTime'],
                'courtId' => $courtSlot['courtId'],
                'courtName' => $courtSlot['courtName'],
                'courtIdx' => $courtSlot['courtIdx'],
                'dateIdx' => $courtSlot['dateIdx'],
                'slotIdx' => $courtSlot['slotIdx']
            ];
        }
        $slotPos++;
    }
    
    error_log('✅ scheduleMatches(): Giorni disponibili: ' . count($dayCells));
    
    // Raggruppa le partite per girone, mantenendo l'ordine con cui i gironi compaiono
    // nell'array (A, B, C, ...). Le partite senza girone (es. fase knockout) finiscono
    // in un unico gruppo "senza nome".
    $matchesByGroup = [];
    foreach ($matches as $m) {
        $g = $m['groupName'] ?? '__nogroup__';
        $matchesByGroup[$g][] = $m;
    }
    
    // Ordina i gironi per numero di partite decrescente: i gironi più "pesanti" (più squadre,
    // quindi più partite) vengono schedulati per primi, in modo da occupare gli slot dei primi
    // giorni. A parità di numero di partite si mantiene l'ordine originale (sort stabile di
    // PHP 8+), es. girone A (5 squadre, 10 partite), girone C (5 squadre, 10 partite), girone B
    // (4 squadre, 6 partite) -> ordine di schedulazione: A, C, B.
    ksort($matchesByGroup);
    
    error_log('🔧 scheduleMatches(): Ordine di schedulazione gironi: ' . implode(', ', array_map(
        fn($g, $ms) => "$g(" . count($ms) . ')',
        array_keys($matchesByGroup),
        $matchesByGroup
    )));
    
    // Algoritmo: ogni girone viene collocato per intero nel giorno corrente, usando gli slot
    // disponibili in ordine cronologico e con distanziamento massimo tra le partite di una
    // stessa squadra (nessuna doppia prenotazione nello stesso turno). Se le celle del giorno
    // finiscono prima che il girone sia completo, si passa al giorno successivo con gli slot
    // rimasti. Il girone successivo riparte da dove si è fermato il puntatore, quindi può
    // condividere lo stesso giorno se resta capienza, oppure iniziare già sul giorno dopo.
    $scheduled = [];
    $unplaced = [];
    $teamLastSlotPos = []; // teamId => posizione cronologica dell'ultima partita assegnata
    $busyTeamsBySlotPos = []; // slotPos => squadre già impegnate in quel turno
    $dayIdx = 0;
    $cellIdx = 0;
    $matchCount = 0;
    
    foreach ($matchesByGroup as $groupName => $groupMatches) {
        $remaining = $groupMatches;
        
        while (!empty($remaining)) {
            if ($dayIdx >= count($dayCells)) {
                // Slot esauriti per l'intero torneo
                break;
            }
            if ($cellIdx >= count($dayCells[$dayIdx])) {
                // Giorno esaurito: passa al successivo (il girone corrente prosegue lì)
                $dayIdx++;
                $cellIdx = 0;
                continue;
            }
            
            $cell = $dayCells[$dayIdx][$cellIdx];
            $slotPosHere = $cell['slotPos'];
            $busyHere = $busyTeamsBySlotPos[$slotPosHere] ?? [];
            
            $bestIdx = null;
            $bestScore = -INF;
            
            foreach ($remaining as $idx => $m) {
                $t1 = $m['team1'] ?? null;
                $t2 = $m['team2'] ?? null;
                
                // Una squadra non può giocare due partite nello stesso turno (campi diversi, stessa ora)
                if (in_array($t1, $busyHere, true) || in_array($t2, $busyHere, true)) {
                    continue;
                }
                
                $gap1 = isset($teamLastSlotPos[$t1]) ? ($slotPosHere - $teamLastSlotPos[$t1]) : PHP_INT_MAX;
                $gap2 = isset($teamLastSlotPos[$t2]) ? ($slotPosHere - $teamLastSlotPos[$t2]) : PHP_INT_MAX;
                $score = min($gap1, $gap2); // vince la coppia con il "riposo" minimo più grande
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $idx;
                }
            }
            
            if ($bestIdx === null) {
                // Nessuna partita di questo girone compatibile con questa cella (squadre già
                // impegnate in questo turno): la lasciamo vuota e proviamo la prossima cella,
                // restando nello stesso giorno finché possibile.
                $cellIdx++;
                continue;
            }
            
            $match = $remaining[$bestIdx];
            unset($remaining[$bestIdx]);
            $remaining = array_values($remaining);
            
            $match['date'] = $cell['date'];
            $match['startTime'] = $cell['startTime'];
            $match['endTime'] = $cell['endTime'];
            $match['courtId'] = $cell['courtId'];
            $match['courtName'] = $cell['courtName'];
            $match['courtIdx'] = $cell['courtIdx'];
            $match['dateIdx'] = $cell['dateIdx'];
            $match['slotIdx'] = $cell['slotIdx'];
            
            $scheduled[] = $match;
            
            $t1 = $match['team1'] ?? null;
            $t2 = $match['team2'] ?? null;
            $busyTeamsBySlotPos[$slotPosHere][] = $t1;
            $busyTeamsBySlotPos[$slotPosHere][] = $t2;
            $teamLastSlotPos[$t1] = $slotPosHere;
            $teamLastSlotPos[$t2] = $slotPosHere;
            
            if ($matchCount < 5) {
                error_log('🔧 scheduleMatches(): Match ' . $matchCount . " (girone $groupName) assigned to courtIdx=" . $match['courtIdx'] . ', date=' . $match['date'] . ', time=' . $match['startTime']);
            }
            $matchCount++;
            $cellIdx++;
        }
        
        if (!empty($remaining)) {
            error_log("⚠️ scheduleMatches(): girone $groupName - " . count($remaining) . ' partite non assegnate per esaurimento slot');
            foreach ($remaining as $m) {
                $unplaced[] = $m;
            }
        }
    }
    
    foreach ($unplaced as $m) {
        $scheduled[] = $m;
    }
    
    error_log('✅ scheduleMatches(): COMPLETE - ' . $matchCount . ' matches scheduled su ' . count($matches) . ' totali');
    
    return $scheduled;
}

/**
 * Inizializza una nuova fase
 */
function initializePhase(array &$state, int $phaseIdx, string $name, string $type, array $config = []): array {
    ensurePhases($state);
    
    $phase = [
        'id' => 'phase-' . $phaseIdx . '-' . $type,
        'phaseIdx' => $phaseIdx,
        'phaseNumber' => $phaseIdx,  // Alias per coerenza con frontend
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

function randomScore(int $numSets = 2): array {
    // Genera punteggi congrui al numero di set configurato
    // Ad esempio, se numSets=2: possibili risultati sono 2-0, 2-1, 1-2, 0-2
    $a = random_int(0, $numSets);
    $b = random_int(0, $numSets);
    
    // Assicura che almeno uno raggiunga il valore di vittoria (numSets)
    if ($a < $numSets && $b < $numSets) {
        if (random_int(0, 1) === 0) {
            $a = $numSets;
        } else {
            $b = $numSets;
        }
    }
    return [$a, $b];
}

function shuffleArray(array $arr): array {
    $copy = $arr;
    shuffle($copy);
    return $copy;
}

/**
 * ✅ NUOVO: Parsa il valore teamsAdvance in array per girone
 * Input: "2" → [2,2,2,2] (con 4 gironi)
 * Input: "2,2,3" → [2,2,3]
 * @param mixed $teamsAdvance - Numero o stringa con virgole
 * @param int $numGroups - Numero di gironi
 * @return array Numero di squadre che passano per ogni girone
 */
function parseTeamsAdvancePerGroup($teamsAdvance, int $numGroups): array {
    if (is_string($teamsAdvance) && strpos($teamsAdvance, ',') !== false) {
        // Valori separati da virgola: prima trim, poi intval
        $parts = array_map('trim', explode(',', $teamsAdvance));
        $values = array_map(function($v) { return (int)$v; }, $parts);
        
        // Completa con zeri se non ci sono abbastanza valori
        while (count($values) < $numGroups) {
            $values[] = 0;
        }
        return array_slice($values, 0, $numGroups);
    } else {
        // Singolo numero per tutti i gironi
        $num = (int)$teamsAdvance ?: 2;
        return array_fill(0, $numGroups, $num);
    }
}

/**
 * ✅ NUOVO: Estrae le squadre qualificate per ogni girone
 * @param array $phases - Array delle fasi
 * @param array $standings - Standings della fase gironi
 * @param array $teamsPerGroup - Array con numero squadre per ogni girone [2,2,3]
 * @return array Squadre qualificate ordinate per girone
 */
function extractQualifiedTeamsByGroup(array $phases, array $standings, array $teamsPerGroup): array {
    // Organizza gli standings per girone (label A, B, C, D)
    $groupedStandings = [];
    foreach ($standings as $standing) {
        $groupLabel = $standing['groupLabel'] ?? 'A';
        if (!isset($groupedStandings[$groupLabel])) {
            $groupedStandings[$groupLabel] = [];
        }
        $groupedStandings[$groupLabel][] = $standing;
    }
    
    $qualified = [];
    $groupLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    
    // Per ogni girone, estrai i top N
    foreach ($groupLabels as $idx => $label) {
        if (!isset($teamsPerGroup[$idx]) || $teamsPerGroup[$idx] === 0) {
            continue; // Skip questo girone
        }
        
        $groupTeams = $groupedStandings[$label] ?? [];
        $numToTake = (int)$teamsPerGroup[$idx];
        
        // Prendi i top N squadre da questo girone
        $topTeams = array_slice($groupTeams, 0, $numToTake);
        $qualified = array_merge($qualified, $topTeams);
    }
    
    return $qualified;
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
        
        // ✅ MIGLIORATO: Parsa teamsAdvance per supportare valori separati da virgola
        $perGroup = parseTeamsAdvancePerGroup($teamsAdvance, $numGroups);
        $qualified = array_sum($perGroup); // Somma i valori per ogni girone
        
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
    try {
        error_log("🔍 DEBUG getTeamMap: START teams count=" . count($state['teams'] ?? []));
        
        $map = [];
        foreach ($state['teams'] ?? [] as $team) {
            if (isset($team['id'])) {
                $map[$team['id']] = $team;
            }
        }
        
        error_log("🔍 DEBUG getTeamMap: END map size=" . count($map));
        return $map;
    } catch (Exception $e) {
        error_log("❌ getTeamMap error: " . $e->getMessage());
        return [];
    }
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
            'label' => chr(65 + $i),
            'name' => chr(65 + $i),
            'teams' => [],  // ✅ Solo questo campo contiene i dati completi
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
        
        $groups[0]['teams'][] = $team;  // ✅ SOLO questo: salva l'oggetto team completo
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
 * per garantire partite competitive: 1vs8, 2vs7, 3vs6, 4vs5
 */
function balancedKnockoutSeeding(array $teams): array {
    // Ordina squadre per peso (decrescente)
    $sortedTeams = $teams;
    usort($sortedTeams, function($a, $b) {
        return getTeamWeight($b) <=> getTeamWeight($a);
    });
    
    // Genera seeding bilanciato: 1vs8, 2vs7, 3vs6, 4vs5
    // Questo garantisce che le squadre più forti affrontino quelle più deboli
    $seeding = [];
    $count = count($sortedTeams);
    
    // Crea tutte le coppie seguendo la formula: i-esimo vs (count-i)-esimo
    for ($i = 0; $i < (int)($count / 2); $i++) {
        $seeding[] = [
            'team1' => $sortedTeams[$i]['id'],
            'team2' => $sortedTeams[$count - 1 - $i]['id']
        ];
    }
    
    return $seeding;
}

function tournamentStarted(array $state): bool {
    // Controlla se il torneo ha fasi con matches o gruppi
    if (!empty($state['phases']) && is_array($state['phases'])) {
        foreach ($state['phases'] as $phase) {
            if ((!empty($phase['matches']) && is_array($phase['matches']) && count($phase['matches']) > 0) ||
                (!empty($phase['groups']) && is_array($phase['groups']) && count($phase['groups']) > 0)) {
                return true;
            }
        }
    }
    return false;
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
    
    // Estrai groups e matches dalla prima fase (gironi)
    $groupsPhase = $state['phases'][0] ?? null;
    $groups = $groupsPhase ? ($groupsPhase['groups'] ?? []) : [];
    $allMatches = $groupsPhase ? ($groupsPhase['matches'] ?? []) : [];
    
    // Crea una mappa matchId -> phaseInfo per recuperare fase e nome
    $matchPhaseMap = [];
    foreach ($state['phases'] ?? [] as $phase) {
        foreach ($phase['matches'] ?? [] as $m) {
            $matchPhaseMap[$m['id']] = [
                'phaseId' => $phase['id'],
                'phaseIdx' => $phase['phaseIdx'],
                'phaseName' => $phase['name']
            ];
        }
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
        'groups' => array_values(array_map(function ($g, $idx) use ($teamMap) {
            // I gironi sono salvati come { name, teamIds: [...] } (solo ID), non come array
            // di team object: qui li risolviamo tramite la mappa id->team per esporre al
            // frontend i dati completi delle squadre (nome, giocatori, ecc.)
            $groupTeams = [];
            $teamIds = [];
            if (is_array($g)) {
                if (isset($g['teamIds']) && is_array($g['teamIds'])) {
                    $teamIds = $g['teamIds'];
                } elseif (isset($g['teams']) && is_array($g['teams'])) {
                    // Formato alternativo: già un array di team object
                    foreach ($g['teams'] as $team) {
                        if (is_array($team) && !empty($team['id'])) {
                            $teamIds[] = $team['id'];
                        }
                    }
                } else {
                    // Formato legacy: $g è direttamente un array di team object
                    foreach ($g as $team) {
                        if (is_array($team) && !empty($team['id'])) {
                            $teamIds[] = $team['id'];
                        }
                    }
                }
            }
            foreach ($teamIds as $teamId) {
                $team = $teamMap[$teamId] ?? null;
                if ($team) {
                    $groupTeams[] = [
                        'id' => $team['id'],
                        'name' => $team['name'] ?? 'N/D',
                        'players' => $team['players'] ?? []
                    ];
                }
            }
            $groupName = (is_array($g) ? ($g['name'] ?? $g['label'] ?? null) : null) ?? chr(65 + $idx);
            return [
                'name' => $groupName,
                'teams' => $groupTeams
            ];
        }, $groups, array_keys($groups))),
        'groupMatches' => array_values(array_map(function ($m) use ($teamMap, $matchPhaseMap) {
            $phaseInfo = $matchPhaseMap[$m['id']] ?? ['phaseId' => null, 'phaseIdx' => 1, 'phaseName' => 'Fase 1 - Gironi'];
            return [
                'matchId' => $m['id'],
                'id' => $m['id'],
                'group' => $m['groupName'] ?? $m['group'] ?? '',
                'team1Id' => $m['team1'] ?? $m['team1Id'] ?? null,
                'team2Id' => $m['team2'] ?? $m['team2Id'] ?? null,
                'team1Name' => ($teamMap[$m['team1'] ?? $m['team1Id'] ?? null]['name'] ?? null) ?? $m['team1Name'] ?? 'N/D',
                'team2Name' => ($teamMap[$m['team2'] ?? $m['team2Id'] ?? null]['name'] ?? null) ?? $m['team2Name'] ?? 'N/D',
                'score1' => $m['score1'],
                'score2' => $m['score2'],
                'date' => $m['date'] ?? null,
                'dayDate' => $m['date'] ?? null,
                'courtId' => $m['courtId'] ?? null,
                'courtIdx' => $m['courtIdx'] ?? null,
                'courtName' => $m['courtName'] ?? null,
                'startTime' => $m['startTime'] ?? null,
                'endTime' => $m['endTime'] ?? null,
                'time' => !empty($m['startTime']) && !empty($m['endTime']) ? ($m['startTime'] . ' - ' . $m['endTime']) : '',
                'dateIdx' => $m['dateIdx'] ?? null,
                'slotIdx' => $m['slotIdx'] ?? null,
                'duration' => $m['duration'] ?? null,
                'phaseId' => $phaseInfo['phaseId'],
                'phaseIdx' => $phaseInfo['phaseIdx'],
                'phaseName' => $phaseInfo['phaseName']
            ];
        }, $allMatches)),
        'standings' => computeStandings($state),
        'playoff' => playoffView($state),
        'finalRanking' => computeFinalRanking($state),
        'meta' => $state['meta'],
        // 🔧 FIX: senza questo campo lo scoreboard pubblico non può sapere quale
        // fase è stata marcata come "corrente" (⭐) in admin, e mostrava sempre
        // di default la prima fase in ordine di numero.
        'currentPhaseIdx' => $state['currentPhaseIdx'] ?? null,
        'phases' => array_map(function ($phase, $idx) use ($state) {
            // ✅ Aggiungi standings a TUTTE le fasi di tipo 'groups', non solo la prima
            if (($phase['type'] ?? '') === 'groups') {
                $phaseNumber = $phase['phaseNumber'] ?? ($idx + 1);
                $phase['standings'] = computeStandingsForPhase($state, $phaseNumber);
            }
            return $phase;
        }, $state['phases'] ?? [], array_keys($state['phases'] ?? []))
    ];
}

function playoffView(array $state): array {
    $teamMap = getTeamMap($state);
    $mapFn = function ($m) use ($teamMap) {
        $team1Id = $m['team1'] ?? $m['team1Id'] ?? null;
        $team2Id = $m['team2'] ?? $m['team2Id'] ?? null;
        return [
            'id' => $m['id'],
            'label' => $m['label'] ?? '',
            'team1Id' => $team1Id,
            'team2Id' => $team2Id,
            'team1Name' => $teamMap[$team1Id]['name'] ?? '-',
            'team2Name' => $teamMap[$team2Id]['name'] ?? '-',
            'score1' => $m['score1'],
            'score2' => $m['score2']
        ];
    };

    $playoff = [
        'quarterFinals' => [],
        'semiFinals' => [],
        'thirdPlace' => null,
        'final' => null
    ];

    // Estrai matches dalle fasi knockout
    // 🔧 FIX: prima si saltava "$phaseIdx === 0" assumendo che la fase gironi
    // fosse sempre in POSIZIONE 0 dell'array $state['phases']. Ma $phaseIdx
    // qui è solo la posizione nell'array (dal foreach), non l'identità della
    // fase (phaseNumber) — se una fase 'groups' non è in prima posizione (es.
    // un torneo con più fasi a gironi, come Gold/Silver), il controllo per
    // posizione non fa quello che deve. Il controllo giusto è già quello
    // interno su $phase['type'] === 'knockout', quindi lo rendiamo l'unico.
    foreach ($state['phases'] ?? [] as $phase) {
        if (($phase['type'] ?? null) !== 'knockout') continue;

        foreach ($phase['matches'] ?? [] as $match) {
            // Classifica in base al tipo di match (se presente)
            $matchType = $match['type'] ?? '';

            if ($matchType === 'quarterFinal' || strpos($match['label'] ?? '', 'Quarti') !== false) {
                $playoff['quarterFinals'][] = $mapFn($match);
            } elseif ($matchType === 'semiFinal' || strpos($match['label'] ?? '', 'Semi') !== false) {
                $playoff['semiFinals'][] = $mapFn($match);
            } elseif ($matchType === 'thirdPlace' || strpos($match['label'] ?? '', 'Terzo') !== false) {
                $playoff['thirdPlace'] = $mapFn($match);
            } elseif ($matchType === 'final' || strpos($match['label'] ?? '', 'Final') !== false) {
                $playoff['final'] = $mapFn($match);
            }
        }
    }

    return $playoff;
}

function computeStandings(array $state): array {
    $teamMap = getTeamMap($state);
    $out = [];

    // ✅ REFACTORED: Estrai groups e matches dalla prima fase (gironi)
    $groupsPhase = $state['phases'][0] ?? null;
    if (!$groupsPhase) {
        return $out;
    }
    
    $groups = $groupsPhase['groups'] ?? [];
    $allMatches = $groupsPhase['matches'] ?? [];
    $config = readConfig();
    $winPoints = getPhaseWinPoints($config, (int)($groupsPhase['phaseNumber'] ?? 1));

    // Itera su ogni gruppo: ogni $group è salvato come { name, teamIds: [...] } (solo ID),
    // quindi va risolto tramite $teamMap per ottenere i dati completi delle squadre.
    foreach ($groups as $groupIdx => $group) {
        $rows = [];
        $groupName = (is_array($group) ? ($group['name'] ?? $group['label'] ?? null) : null) ?? chr(65 + $groupIdx);
        
        $teamIds = [];
        if (is_array($group)) {
            if (isset($group['teamIds']) && is_array($group['teamIds'])) {
                $teamIds = $group['teamIds'];
            } elseif (isset($group['teams']) && is_array($group['teams'])) {
                // Formato alternativo: già un array di team object
                foreach ($group['teams'] as $t) {
                    if (is_array($t) && !empty($t['id'])) {
                        $teamIds[] = $t['id'];
                    }
                }
            } else {
                // Formato legacy: $group è direttamente un array di team object
                foreach ($group as $t) {
                    if (is_array($t) && !empty($t['id'])) {
                        $teamIds[] = $t['id'];
                    }
                }
            }
        }
        
        foreach ($teamIds as $teamId) {
            $team = $teamMap[$teamId] ?? null;
            if ($team) {
                $rows[$teamId] = [
                    'teamId' => $teamId,
                    'name' => $team['name'] ?? 'N/D',
                    'played' => 0,
                    'won' => 0,
                    'lost' => 0,
                    'points' => 0,
                    'scored' => 0,
                    'conceded' => 0,
                    'diff' => 0
                ];
            }
        }

        foreach ($allMatches as $match) {
            $matchGroupName = $match['groupName'] ?? $match['group'] ?? '';
            if ($matchGroupName !== $groupName && $matchGroupName !== ('Girone ' . $groupName)) {
                continue;
            }
            [$won1, $won2, $hasResult] = getMatchSetsWon($match);
            if (!$hasResult) {
                continue;
            }
            $t1 = $match['team1'] ?? $match['team1Id'] ?? null;
            $t2 = $match['team2'] ?? $match['team2Id'] ?? null;
            if (!isset($rows[$t1], $rows[$t2])) {
                continue;
            }
            [$scored1, $scored2] = getMatchPointsScored($match);

            $rows[$t1]['played']++;
            $rows[$t2]['played']++;
            $rows[$t1]['scored'] += $scored1;
            $rows[$t1]['conceded'] += $scored2;
            $rows[$t2]['scored'] += $scored2;
            $rows[$t2]['conceded'] += $scored1;

            if ($won1 > $won2) {
                $rows[$t1]['won']++;
                $rows[$t2]['lost']++;
                $rows[$t1]['points'] += $winPoints;
            } elseif ($won2 > $won1) {
                $rows[$t2]['won']++;
                $rows[$t1]['lost']++;
                $rows[$t2]['points'] += $winPoints;
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
            'group' => $groupName,
            'rows' => $rows
        ];
    }

    return $out;
}

// ==========================================================================
// 🆕 MOTORE GENERICO WORKFLOW FASI SUCCESSIVE
// Permette di collegare qualsiasi fase (gironi o castello) alla successiva,
// pescando le squadre REALI qualificate/eliminate dai risultati effettivi,
// invece di semplici "etichette" testuali come faceva il wizard finora.
// ==========================================================================

/**
 * Come computeStandings(), ma per una fase QUALSIASI (non solo phases[0]).
 * Restituisce la classifica di ogni girone della fase indicata.
 */
function computeStandingsForPhase(array $state, int $phaseNumber): array {
    error_log("🔍 DEBUG computeStandingsForPhase: START phaseNumber=$phaseNumber");
    
    $teamMap = getTeamMap($state);
    error_log("🔍 DEBUG computeStandingsForPhase: teamMap size=" . count($teamMap));
    
    $out = [];

    $phaseIdx = array_search($phaseNumber, array_column($state['phases'] ?? [], 'phaseNumber'), true);
    error_log("🔍 DEBUG computeStandingsForPhase: phaseIdx=$phaseIdx");
    
    if ($phaseIdx === false) {
        error_log("❌ computeStandingsForPhase: fase $phaseNumber non trovata");
        return $out;
    }

    $groupsPhase = $state['phases'][$phaseIdx];
    $groups = $groupsPhase['groups'] ?? [];
    $allMatches = $groupsPhase['matches'] ?? [];
    $config = readConfig();
    $winPoints = getPhaseWinPoints($config, $phaseNumber);
    
    error_log("🔍 DEBUG computeStandingsForPhase: groups count=" . count($groups) . ", matches count=" . count($allMatches));

    foreach ($groups as $groupIdx => $group) {
        error_log("🔍 DEBUG computeStandingsForPhase: processing group $groupIdx");
        
        $rows = [];
        $groupName = (is_array($group) ? ($group['name'] ?? $group['label'] ?? null) : null) ?? chr(65 + $groupIdx);

        error_log("🔍 DEBUG computeStandingsForPhase: groupName=$groupName, group type=" . gettype($group));
        
        $teamIds = [];
        if (is_array($group)) {
            if (isset($group['teamIds']) && is_array($group['teamIds'])) {
                $teamIds = $group['teamIds'];
                error_log("🔍 DEBUG computeStandingsForPhase: using teamIds field");
            } elseif (isset($group['teams']) && is_array($group['teams'])) {
                foreach ($group['teams'] as $t) {
                    if (is_array($t) && !empty($t['id'])) $teamIds[] = $t['id'];
                }
                error_log("🔍 DEBUG computeStandingsForPhase: using teams field");
            } else {
                foreach ($group as $t) {
                    if (is_array($t) && !empty($t['id'])) $teamIds[] = $t['id'];
                }
                error_log("🔍 DEBUG computeStandingsForPhase: using direct iteration");
            }
        }
        
        error_log("🔍 DEBUG computeStandingsForPhase: group $groupName teamIds count=" . count($teamIds));

        foreach ($teamIds as $teamId) {
            $team = $teamMap[$teamId] ?? null;
            if ($team) {
                $rows[$teamId] = [
                    'teamId' => $teamId, 'name' => $team['name'] ?? 'N/D',
                    'played' => 0, 'won' => 0, 'lost' => 0, 'points' => 0,
                    'scored' => 0, 'conceded' => 0, 'diff' => 0
                ];
            }
        }

        foreach ($allMatches as $match) {
            $matchGroupName = $match['groupName'] ?? $match['group'] ?? '';
            if ($matchGroupName !== $groupName && $matchGroupName !== ('Girone ' . $groupName)) continue;
            [$won1, $won2, $hasResult] = getMatchSetsWon($match);
            if (!$hasResult) continue;
            $t1 = $match['team1'] ?? $match['team1Id'] ?? null;
            $t2 = $match['team2'] ?? $match['team2Id'] ?? null;
            if (!isset($rows[$t1], $rows[$t2])) continue;
            [$scored1, $scored2] = getMatchPointsScored($match);

            $rows[$t1]['played']++; $rows[$t2]['played']++;
            $rows[$t1]['scored'] += $scored1; $rows[$t1]['conceded'] += $scored2;
            $rows[$t2]['scored'] += $scored2; $rows[$t2]['conceded'] += $scored1;

            if ($won1 > $won2) {
                $rows[$t1]['won']++; $rows[$t2]['lost']++; $rows[$t1]['points'] += $winPoints;
            } elseif ($won2 > $won1) {
                $rows[$t2]['won']++; $rows[$t1]['lost']++; $rows[$t2]['points'] += $winPoints;
            }
        }

        foreach ($rows as &$r) { $r['diff'] = $r['scored'] - $r['conceded']; }
        unset($r);

        $rows = array_values($rows);
        usort($rows, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['diff'] !== $b['diff']) return $b['diff'] <=> $a['diff'];
            if ($a['scored'] !== $b['scored']) return $b['scored'] <=> $a['scored'];
            return strcmp($a['name'], $b['name']);
        });

        error_log("🔍 DEBUG computeStandingsForPhase: group $groupName final rows count=" . count($rows));
        $out[] = ['group' => $groupName, 'rows' => $rows];
    }

    // 🔧 FIX: i gironi possono essere salvati in un ordine diverso da quello
    // alfabetico (la distribuzione bilanciata per peso squadra riordina gli
    // oggetti nell'array durante la generazione). Chi consuma questo risultato
    // (es. getTeamsFromPhaseBranch(), per applicare "2,3,3" squadre-che-passano
    // per girone) si aspetta che la posizione nell'array corrisponda alla
    // posizione alfabetica del girone (A=0, B=1, C=2...) — altrimenti un girone
    // riceve il numero di qualificati pensato per un girone diverso. Ordiniamo
    // qui, una volta sola, alla fonte.
    usort($out, fn($a, $b) => strcmp($a['group'], $b['group']));

    error_log("🔍 DEBUG computeStandingsForPhase: END out count=" . count($out));
    return $out;
}

/**
 * Determina, PER UNA FASE QUALSIASI, quali squadre sono "qualificate" e quali
 * "eliminate" in base ai risultati reali (non a un numero stimato).
 *
 * - Fase a gironi: qualificate = prime $teamsAdvance per girone (classifica reale);
 *   eliminate = tutte le altre.
 * - Fase a eliminazione diretta (castello): qualificate = vincitori dell'ultimo
 *   turno con risultati completi; eliminate = perdenti di tutti i match giocati
 *   della fase.
 *
 * Restituisce ['qualified' => [teamId,...], 'eliminated' => [teamId,...], 'complete' => bool]
 * 'complete' indica se la fase ha tutti i risultati necessari per considerare
 * definitivo l'elenco (utile per avvisare l'utente se prova ad avanzare troppo presto).
 */
/**
 * $teamsAdvancePerGroup può essere:
 *  - un int: stesso numero di qualificati per ogni girone (comportamento di default)
 *  - un array indicizzato per posizione del girone: es. [2,1,2,2] = il girone A
 *    qualifica 2 squadre, il B ne qualifica 1, C e D ne qualificano 2 ciascuno.
 *    Questo combacia con il campo "teamsAdvance" salvato in config.json (es. "2,1,2,2"),
 *    che permette gironi con un numero diverso di qualificati.
 */
function getTeamsFromPhaseBranch(array $state, int $sourcePhaseNumber, $teamsAdvancePerGroup = 2, int $sortCriterion = 1): array {
    error_log("🔍 DEBUG getTeamsFromPhaseBranch: START sourcePhaseNumber=$sourcePhaseNumber, teamsAdvancePerGroup=" . json_encode($teamsAdvancePerGroup) . ", sortCriterion=$sortCriterion");
    
    $phaseIdx = array_search($sourcePhaseNumber, array_column($state['phases'] ?? [], 'phaseNumber'), true);
    error_log("🔍 DEBUG getTeamsFromPhaseBranch: phaseIdx=$phaseIdx");
    
    if ($phaseIdx === false) {
        error_log("❌ getTeamsFromPhaseBranch: fase $sourcePhaseNumber non trovata");
        return ['qualified' => [], 'eliminated' => [], 'complete' => false, 'error' => "Fase {$sourcePhaseNumber} non trovata"];
    }

    $phase = $state['phases'][$phaseIdx];
    $type = $phase['type'] ?? 'groups';
    error_log("🔍 DEBUG getTeamsFromPhaseBranch: type=$type");

    if ($type === 'groups') {
        error_log("🔍 DEBUG getTeamsFromPhaseBranch: calcolando standings per fase groups");
        $standings = computeStandingsForPhase($state, $sourcePhaseNumber);
        error_log("🔍 DEBUG getTeamsFromPhaseBranch: standings computed, groups count=" . count($standings) . ", teamsAdvancePerGroup=" . json_encode($teamsAdvancePerGroup));
        
        // 🔧 FIX: Converti stringa "2,3,3" in array [2,3,3]
        if (is_string($teamsAdvancePerGroup) && strpos($teamsAdvancePerGroup, ',') !== false) {
            $teamsAdvancePerGroup = array_map(fn($x) => (int)trim($x), explode(',', $teamsAdvancePerGroup));
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: converted string to array: " . json_encode($teamsAdvancePerGroup));
        } elseif (is_string($teamsAdvancePerGroup)) {
            $teamsAdvancePerGroup = (int)$teamsAdvancePerGroup;
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: converted string to int: " . json_encode($teamsAdvancePerGroup));
        }
        
        $qualified = [];
        $eliminated = [];
        $complete = !empty($standings);

        $groupIdx = 0;
        foreach ($standings as $groupPosition => $g) {
            $rows = $g['rows'];
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: GROUP $groupPosition (groupIdx=$groupIdx) has " . count($rows) . " teams");
            
            // Numero di qualificati per QUESTO girone: se è un array, usa l'indice numerico
            // del girone (0=A, 1=B, 2=C...); se l'indice non è nell'array, usa l'ultimo valore noto.
            if (is_array($teamsAdvancePerGroup)) {
                $advanceCount = $teamsAdvancePerGroup[$groupIdx]
                    ?? end($teamsAdvancePerGroup)
                    ?: 2;
            } else {
                $advanceCount = $teamsAdvancePerGroup;
            }
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: GROUP $groupPosition advanceCount=$advanceCount");

            // Se non tutte le squadre del girone hanno giocato tutte le partite tra loro,
            // la classifica non è ancora definitiva.
            $expectedMatches = count($rows) > 1 ? intdiv(count($rows) * (count($rows) - 1), 2) : 0;
            $playedMatches = 0;
            foreach ($phase['matches'] ?? [] as $m) {
                $mg = $m['groupName'] ?? $m['group'] ?? '';
                if (($mg === $g['group'] || $mg === ('Girone ' . $g['group'])) && $m['score1'] !== null && $m['score2'] !== null) {
                    $playedMatches++;
                }
            }
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: GROUP {$g['group']} expectedMatches=$expectedMatches, playedMatches=$playedMatches");
            if ($playedMatches < $expectedMatches) $complete = false;

            foreach ($rows as $idx => $row) {
                if ($idx < $advanceCount) {
                    $qualified[] = $row['teamId'];
                } else {
                    $eliminated[] = $row['teamId'];
                }
            }
            $groupIdx++;
        }
        
        // 🆕 ORDINAMENTO: applica il criterio scelto (1=media, 2=%, 3=media+quoziente+diff)
        if (!empty($qualified)) {
            $qualifiedWithStats = [];
            foreach ($standings as $g) {
                foreach ($g['rows'] as $row) {
                    if (in_array($row['teamId'], $qualified, true)) {
                        $qualifiedWithStats[] = [
                            'teamId' => $row['teamId'],
                            'points' => $row['points'] ?? 0,
                            'diff' => $row['diff'] ?? 0,
                            'scored' => $row['scored'] ?? 0,
                            'conceded' => $row['conceded'] ?? 0,
                            'played' => $row['played'] ?? 0
                        ];
                    }
                }
            }
            
            // Ordina in base al criterio scelto
            usort($qualifiedWithStats, function($a, $b) use ($sortCriterion) {
                if ($sortCriterion === 2) {
                    // Opzione 2: % Vittorie (points/2 / played)
                    $winPercentA = ($a['played'] > 0) ? (($a['points'] / 2) / $a['played']) : 0;
                    $winPercentB = ($b['played'] > 0) ? (($b['points'] / 2) / $b['played']) : 0;
                    if ($winPercentA !== $winPercentB) return ($winPercentB <=> $winPercentA);
                    // Spareggio: diff
                    if ($a['diff'] !== $b['diff']) return ($b['diff'] <=> $a['diff']);
                    return ($b['points'] <=> $a['points']);
                } elseif ($sortCriterion === 3) {
                    // Opzione 3: Media + Quoziente + Diff
                    $mediaA = ($a['played'] > 0) ? ($a['points'] / $a['played']) : 0;
                    $mediaB = ($b['played'] > 0) ? ($b['points'] / $b['played']) : 0;
                    if ($mediaA !== $mediaB) return ($mediaB <=> $mediaA);
                    $quozienteA = ($a['conceded'] > 0) ? ($a['scored'] / $a['conceded']) : 0;
                    $quozienteB = ($b['conceded'] > 0) ? ($b['scored'] / $b['conceded']) : 0;
                    if ($quozienteA !== $quozienteB) return ($quozienteB <=> $quozienteA);
                    if ($a['diff'] !== $b['diff']) return ($b['diff'] <=> $a['diff']);
                    return ($b['points'] <=> $a['points']);
                } else {
                    // Opzione 1 (default): Media punti per partita
                    $mediaA = ($a['played'] > 0) ? ($a['points'] / $a['played']) : 0;
                    $mediaB = ($b['played'] > 0) ? ($b['points'] / $b['played']) : 0;
                    if ($mediaA !== $mediaB) return ($mediaB <=> $mediaA);
                    $diffPerPartitaA = ($a['played'] > 0) ? ($a['diff'] / $a['played']) : 0;
                    $diffPerPartitaB = ($b['played'] > 0) ? ($b['diff'] / $b['played']) : 0;
                    if ($diffPerPartitaA !== $diffPerPartitaB) return ($diffPerPartitaB <=> $diffPerPartitaA);
                    $pfPerPartitaA = ($a['played'] > 0) ? ($a['scored'] / $a['played']) : 0;
                    $pfPerPartitaB = ($b['played'] > 0) ? ($b['scored'] / $b['played']) : 0;
                    return ($pfPerPartitaB <=> $pfPerPartitaA);
                }
            });
            
            // Ritorna solo i teamId ordinati
            $qualified = array_map(fn($q) => $q['teamId'], $qualifiedWithStats);
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: Sorted qualified by criterion $sortCriterion");
        }
        
        error_log("🔍 DEBUG getTeamsFromPhaseBranch: FINAL qualified count=" . count($qualified) . ", eliminated count=" . count($eliminated) . ", complete=" . ($complete ? 'true' : 'false'));
        return ['qualified' => $qualified, 'eliminated' => $eliminated, 'complete' => $complete];
    }

    if ($type === 'knockout') {
        error_log("🔍 DEBUG getTeamsFromPhaseBranch: calcolando per knockout");
        $matches = $phase['matches'] ?? [];
        if (empty($matches)) {
            error_log("🔍 DEBUG getTeamsFromPhaseBranch: no matches in knockout phase");
            return ['qualified' => [], 'eliminated' => [], 'complete' => false];
        }

        // Individua l'ultimo turno presente (per label: F > 3P (escluso) > SF > QF > R16 > R32...)
        // Usiamo l'ordine di apparizione dei match: l'ultimo tipo diverso da 'thirdPlace' con match completi.
        $roundsOrder = [];
        foreach ($matches as $m) {
            $t = $m['type'] ?? '';
            if ($t !== '' && $t !== 'thirdPlace' && !in_array($t, $roundsOrder, true)) {
                $roundsOrder[] = $t;
            }
        }
        $lastRoundType = end($roundsOrder) ?: null;
        error_log("🔍 DEBUG getTeamsFromPhaseBranch: knockout roundsOrder=" . json_encode($roundsOrder) . ", lastRoundType=$lastRoundType");

        $qualified = [];
        $eliminated = [];
        $complete = true;
        $firstRoundType = $roundsOrder[0] ?? null;

        foreach ($matches as $m) {
            if (($m['type'] ?? '') === 'thirdPlace') continue; // non conta per l'avanzamento
            // 🔧 FIX: usa i set vinti (getMatchSetsWon), non il punteggio grezzo
            // dell'ultimo set — con partite multi-set score1/score2 sono solo
            // il set in corso/ultimo, non l'esito complessivo dell'incontro.
            [$won1, $won2, $hasScore] = getMatchSetsWon($m);

            // Eliminati: perdenti di QUALSIASI turno giocato (chi perde è fuori, indipendentemente dal turno)
            if ($hasScore) {
                $t1 = $m['team1Id'] ?? $m['team1'] ?? null;
                $t2 = $m['team2Id'] ?? $m['team2'] ?? null;
                if ($t1 && $t2) {
                    if ($won1 > $won2) $eliminated[] = $t2;
                    elseif ($won2 > $won1) $eliminated[] = $t1;
                }
            }

            // Qualificati: vincitori SOLO dell'ultimo turno (chi vince l'ultimo turno completa la fase)
            if (($m['type'] ?? '') === $lastRoundType) {
                if (!$hasScore) {
                    $complete = false;
                } else {
                    $t1 = $m['team1Id'] ?? $m['team1'] ?? null;
                    $t2 = $m['team2Id'] ?? $m['team2'] ?? null;
                    if ($t1 && $t2) {
                        if ($won1 > $won2) $qualified[] = $t1;
                        elseif ($won2 > $won1) $qualified[] = $t2;
                    }
                }
            }
        }

        error_log("🔍 DEBUG getTeamsFromPhaseBranch: knockout FINAL qualified=" . count($qualified) . ", eliminated=" . count($eliminated) . ", complete=" . ($complete ? 'true' : 'false'));
        return ['qualified' => array_values(array_unique($qualified)), 'eliminated' => array_values(array_unique($eliminated)), 'complete' => $complete];
    }

    error_log("❌ getTeamsFromPhaseBranch: tipo fase '{$type}' non gestito");
    return ['qualified' => [], 'eliminated' => [], 'complete' => false, 'error' => "Tipo fase '{$type}' non gestito"];
}

/**
 * Genera un bracket a eliminazione diretta per un numero QUALSIASI di squadre
 * (non più fisso a 8 come createPlayoff()). Se il numero di squadre non è una
 * potenza di 2, le squadre più forti ricevono un "bye" (turno di riposo) al
 * primo turno, così il tabellone resta bilanciato.
 *
 * Restituisce l'elenco completo dei match di TUTTI i turni: il primo turno ha
 * le squadre reali (seeding bilanciato per peso), i turni successivi sono
 * placeholder (team1/team2 = null) che verranno riempiti mano a mano che i
 * risultati dei turni precedenti vengono inseriti (vedi advanceKnockoutWinners()).
 */
/**
 * Seeding bilanciato per un bracket di QUALSIASI dimensione (potenza di 2).
 * balancedKnockoutSeeding() esistente è cablata per un massimo di 8 squadre;
 * questa generalizza lo stesso principio (forti contro deboli) per 4, 16, 32...
 * Le squadre "fantasma" oltre il numero reale (quando non è una potenza di 2)
 * diventano un "bye": chi le incontra passa automaticamente il turno.
 * 
 * Se $teamGroupMap è fornito (array team_id => group_id), evita di accoppiare
 * squadre dello stesso gruppo/girone nel primo turno.
 */
function genericBalancedSeeding(array $teams, int $bracketSize, ?array $teamGroupMap = null): array {
    // 🔧 FORMULA CORRETTA: 1 vs ultimo, 2 vs penultimo, 3 vs terzultimo, etc.
    // Le squadre arrivano già ordinate dalla classifica girone (1°, 2°, 3°...)
    // In caso di bye (squadre < bracketSize), le squadre più forti ricevono bye
    
    $n = count($teams);
    $pairs = [];
    
    // Se non ci sono squadre, return vuoto
    if ($n < 1) return $pairs;
    
    // Se non abbiamo mappatura di gruppi, usa seeding standard
    if ($teamGroupMap === null) {
        // Crea coppie con formula: i vs (n - 1 - i)
        for ($i = 0; $i < $bracketSize; $i += 2) {
            $idx1 = $i;
            $idx2 = $bracketSize - 1 - $i;
            
            $teamA = ($idx1 < $n) ? $teams[$idx1] : null;
            $teamB = ($idx2 < $n && $idx2 !== $idx1) ? $teams[$idx2] : null;
            
            // Se è un bye (una squadra non esiste), segna team2 come null
            $pairs[] = [
                'team1' => $teamA['id'] ?? null,
                'team2' => $teamB['id'] ?? null
            ];
        }
        return $pairs;
    }
    
    // 🆕 SEEDING INTELLIGENTE: evita rematch dello stesso girone
    // Estratto i team IDs ordinati
    $teamIds = array_map(fn($t) => $t['id'] ?? null, $teams);
    $teamIds = array_filter($teamIds);
    $teamIds = array_values($teamIds); // Reindicizza per avere chiavi consecutive
    
    // Dividi in forti (prima metà) e deboli (seconda metà)
    $midpoint = intdiv($n, 2);
    $forti = array_slice($teamIds, 0, $midpoint);
    $deboli = array_slice($teamIds, $midpoint);
    
    // Greedy matching: per ogni forte, trova il miglior debole di girone diverso
    $usedDebolIndices = [];
    $pairedDeboli = [];
    
    foreach ($forti as $forteId) {
        $forteGroup = $teamGroupMap[$forteId] ?? null;
        $bestDebolIndex = null;
        
        // Preferenza 1: debole di girone diverso, non ancora usato
        if ($forteGroup !== null) {
            foreach ($deboli as $debolIndex => $debolId) {
                if (isset($usedDebolIndices[$debolIndex])) continue;
                $debolGroup = $teamGroupMap[$debolId] ?? null;
                if ($debolGroup !== null && $debolGroup !== $forteGroup) {
                    $bestDebolIndex = $debolIndex;
                    break;
                }
            }
        }
        
        // Fallback: qualsiasi debole non ancora usato
        if ($bestDebolIndex === null) {
            foreach ($deboli as $debolIndex => $debolId) {
                if (!isset($usedDebolIndices[$debolIndex])) {
                    $bestDebolIndex = $debolIndex;
                    break;
                }
            }
        }
        
        // Se trovato, marca come usato e abbina
        if ($bestDebolIndex !== null) {
            $usedDebolIndices[$bestDebolIndex] = true;
            $pairedDeboli[] = $deboli[$bestDebolIndex];
        } else {
            $pairedDeboli[] = null; // Fallback a null (bye)
        }
    }
    
    error_log("🔧 avoidGroupRematches: Greedy pairing completato. Forti=" . count($forti) . ", Deboli=" . count($deboli));
    
    // Costruisci le coppie finali (forte vs debole)
    foreach ($forti as $idx => $forteId) {
        $pairs[] = [
            'team1' => $forteId,
            'team2' => $pairedDeboli[$idx] ?? null
        ];
    }
    
    return $pairs;
}


function generateGenericKnockoutMatches(array $teams, ?array $teamGroupMap = null): array {
    $n = count($teams);
    if ($n < 2) return [];

    // Prossima potenza di 2 (es. 5,6,7 squadre -> 8; 9..16 -> 16)
    $bracketSize = 1;
    while ($bracketSize < $n) $bracketSize *= 2;

    $seeding = genericBalancedSeeding($teams, $bracketSize, $teamGroupMap); // 🔧 generalizzato per qualsiasi dimensione, non solo 8

    // Etichette dei turni in base alla dimensione del bracket
    $roundLabelFor = function (int $teamsInRound) {
        return match (true) {
            $teamsInRound >= 32 => ['label' => 'R32', 'type' => 'round32'],
            $teamsInRound === 16 => ['label' => 'R16', 'type' => 'round16'],
            $teamsInRound === 8 => ['label' => 'QF', 'type' => 'quarterFinal'],
            $teamsInRound === 4 => ['label' => 'SF', 'type' => 'semiFinal'],
            $teamsInRound === 2 => ['label' => 'F', 'type' => 'final'],
            default => ['label' => 'R' . $teamsInRound, 'type' => 'round' . $teamsInRound],
        };
    };

    $matches = [];
    $firstRoundInfo = $roundLabelFor($bracketSize);

    // Primo turno: usa il seeding bilanciato; se il bracket è più grande del numero
    // di squadre reali, le prime coppie a partire dalla fine ricevono un bye.
    foreach ($seeding as $idx => $pair) {
        $isBye = empty($pair['team2']);
        $matches[] = [
            'id' => uid(),
            'label' => $firstRoundInfo['label'] . ($idx + 1),
            'type' => $firstRoundInfo['type'],
            'round' => 1,
            'matchIndexInRound' => $idx,
            'team1' => $pair['team1'], 'team1Id' => $pair['team1'],
            'team2' => $pair['team2'] ?? null, 'team2Id' => $pair['team2'] ?? null,
            'score1' => null, 'score2' => null,
            'bye' => $isBye, // 🆕 nessun avversario: la squadra passa il turno senza giocare
            'date' => null, 'startTime' => null, 'endTime' => null
        ];
    }

    // Turni successivi come placeholder, fino alla finale.
    // $teamsInRound = quante squadre ENTRANO in quel turno (es. 8 squadre -> turno QF).
    $teamsInRound = intdiv($bracketSize, 2);
    $roundNumber = 2;
    while ($teamsInRound >= 2) {
        $info = $roundLabelFor($teamsInRound);
        $numMatchesInRound = intdiv($teamsInRound, 2);

        for ($i = 0; $i < $numMatchesInRound; $i++) {
            $matches[] = [
                'id' => uid(),
                'label' => $info['label'] . ($numMatchesInRound > 1 ? ($i + 1) : ''),
                'type' => $info['type'],
                'round' => $roundNumber,
                'matchIndexInRound' => $i,
                'team1' => null, 'team1Id' => null,
                'team2' => null, 'team2Id' => null,
                'score1' => null, 'score2' => null,
                'bye' => false,
                'date' => null, 'startTime' => null, 'endTime' => null
            ];
        }

        if ($teamsInRound === 2) break; // la finale era l'ultimo turno appena creato
        $teamsInRound = intdiv($teamsInRound, 2);
        $roundNumber++;
    }

    // Finalina 3°/4° posto, solo se ha senso (bracket con semifinali, cioè almeno 4 squadre)
    if ($bracketSize >= 4) {
        $matches[] = [
            'id' => uid(), 'label' => '3P', 'type' => 'thirdPlace',
            'team1' => null, 'team1Id' => null, 'team2' => null, 'team2Id' => null,
            'score1' => null, 'score2' => null, 'date' => null, 'startTime' => null, 'endTime' => null
        ];
    }

    return $matches;
}

/**
 * Fa avanzare automaticamente vincitori (e, per le semifinali, perdenti verso la
 * finalina 3°/4° posto) da un turno all'altro di una fase 'knockout', usando i
 * campi 'round' e 'matchIndexInRound' assegnati da generateGenericKnockoutMatches().
 *
 * Va richiamata: (1) subito dopo aver creato una fase knockout (per risolvere
 * subito eventuali "bye"), e (2) ogni volta che si salva il risultato di una
 * partita di una fase knockout (per far avanzare i vincitori nei turni successivi).
 *
 * È idempotente: ricalcola l'intera catena dei turni ogni volta, quindi può
 * essere richiamata liberamente senza rischio di applicare avanzamenti doppi.
 */
function advanceKnockoutBracket(array &$phase): void {
    if (($phase['type'] ?? '') !== 'knockout' || empty($phase['matches'])) return;

    $matches = &$phase['matches'];

    $byRoundIndex = [];
    $thirdPlaceIdx = null;
    foreach ($matches as $i => $m) {
        if (isset($m['round'], $m['matchIndexInRound'])) {
            $byRoundIndex[$m['round']][$m['matchIndexInRound']] = $i;
        }
        if (($m['type'] ?? '') === 'thirdPlace') {
            $thirdPlaceIdx = $i;
        }
    }
    unset($m);

    if (empty($byRoundIndex)) return;
    $maxRound = max(array_keys($byRoundIndex));

    for ($r = 1; $r <= $maxRound; $r++) {
        if (!isset($byRoundIndex[$r])) continue;

        foreach ($byRoundIndex[$r] as $idx => $mIdx) {
            $m = $matches[$mIdx];
            $winnerId = null; $winnerName = null;
            $loserId = null; $loserName = null;

            if (!empty($m['bye'])) {
                // Nessun avversario: chi c'è passa automaticamente il turno
                $winnerId = $m['team1Id'] ?? null;
                $winnerName = $m['team1Name'] ?? null;
            } else {
                // 🔧 FIX: usa i set vinti (getMatchSetsWon), non score1/score2 grezzi
                // — con partite multi-set questi rappresentano solo il set in corso/
                // ultimo, non l'esito complessivo dell'incontro.
                [$won1, $won2, $hasResult] = getMatchSetsWon($m);
                if ($hasResult) {
                    if ($won1 > $won2) {
                        $winnerId = $m['team1Id']; $winnerName = $m['team1Name'] ?? null;
                        $loserId = $m['team2Id']; $loserName = $m['team2Name'] ?? null;
                    } elseif ($won2 > $won1) {
                        $winnerId = $m['team2Id']; $winnerName = $m['team2Name'] ?? null;
                        $loserId = $m['team1Id']; $loserName = $m['team1Name'] ?? null;
                    }
                }
            }

            if ($winnerId === null) continue;

            // Avanza il vincitore al turno successivo (posizione idx/2, slot pari=team1/dispari=team2)
            $nextRound = $r + 1;
            $nextIdx = intdiv($idx, 2);
            if (isset($byRoundIndex[$nextRound][$nextIdx])) {
                $nextMatchIdx = $byRoundIndex[$nextRound][$nextIdx];
                $slot = ($idx % 2 === 0) ? 1 : 2;
                $matches[$nextMatchIdx]['team' . $slot] = $winnerId;
                $matches[$nextMatchIdx]['team' . $slot . 'Id'] = $winnerId;
                $matches[$nextMatchIdx]['team' . $slot . 'Name'] = $winnerName;
            }

            // Se questo turno è una semifinale, il perdente va alla finalina 3°/4° posto
            if (($m['type'] ?? '') === 'semiFinal' && $thirdPlaceIdx !== null && $loserId !== null) {
                $slot = ($idx % 2 === 0) ? 1 : 2;
                $matches[$thirdPlaceIdx]['team' . $slot] = $loserId;
                $matches[$thirdPlaceIdx]['team' . $slot . 'Id'] = $loserId;
                $matches[$thirdPlaceIdx]['team' . $slot . 'Name'] = $loserName;
            }
        }
    }
}

function validateScheduleForTournament(array $state): array {
    $config = readConfig();
    $courts = $config['schedule']['courts'] ?? [];

    if (empty($courts)) {
        return ['valid' => false, 'message' => 'Nessun giorno schedulato nel torneo'];
    }

    // ✅ REFACTORED: Leggi i gruppi dalla prima fase
    $groups = $state['phases'][0]['groups'] ?? [];

    // Calcola numero totale di partite nei gironi
    $totalMatches = 0;
    foreach ($groups as $group) {
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

    // ✅ REFACTORED: Leggi i gruppi dalla prima fase
    $groups = $state['phases'][0]['groups'] ?? [];

    foreach ($groups as $group) {
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

    // ✅ REFACTORED: Salva i match nella prima fase
    if (!isset($state['phases'][0])) {
        $state['phases'][0] = [];
    }
    $state['phases'][0]['matches'] = $matches;
}

function winnerLoser(?array $match): array {
    if (!$match) {
        return ['winner' => null, 'loser' => null];
    }
    // 🔧 FIX: usa i set vinti (getMatchSetsWon), non score1/score2 grezzi —
    // con partite multi-set questi rappresentano solo il set in corso/ultimo.
    [$won1, $won2, $hasResult] = getMatchSetsWon($match);
    if (!$hasResult) {
        return ['winner' => null, 'loser' => null];
    }
    $team1Id = $match['team1Id'] ?? $match['team1'] ?? null;
    $team2Id = $match['team2Id'] ?? $match['team2'] ?? null;
    if ($won1 > $won2) {
        return ['winner' => $team1Id, 'loser' => $team2Id];
    }
    if ($won2 > $won1) {
        return ['winner' => $team2Id, 'loser' => $team1Id];
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
    
    // Crea i match delle fasi knockout nella nuova fase
    $knockoutMatches = [];
    
    // Quarter-finals
    foreach ($seeding as $idx => $match) {
        $knockoutMatches[] = [
            'id' => uid(),
            'label' => 'QF' . ($idx + 1),
            'type' => 'quarterFinal',
            'team1' => $match['team1'],
            'team2' => $match['team2'],
            'team1Id' => $match['team1'],
            'team2Id' => $match['team2'],
            'score1' => null,
            'score2' => null,
            'date' => null,
            'startTime' => null,
            'endTime' => null
        ];
    }

    // Semi-finals (placeholder, verranno riempiti dai quarti)
    for ($i = 0; $i < 2; $i++) {
        $knockoutMatches[] = [
            'id' => uid(),
            'label' => 'SF' . ($i + 1),
            'type' => 'semiFinal',
            'team1' => null,
            'team2' => null,
            'team1Id' => null,
            'team2Id' => null,
            'score1' => null,
            'score2' => null,
            'date' => null,
            'startTime' => null,
            'endTime' => null
        ];
    }

    // Third place match
    $knockoutMatches[] = [
        'id' => uid(),
        'label' => '3P',
        'type' => 'thirdPlace',
        'team1' => null,
        'team2' => null,
        'team1Id' => null,
        'team2Id' => null,
        'score1' => null,
        'score2' => null,
        'date' => null,
        'startTime' => null,
        'endTime' => null
    ];

    // Final match
    $knockoutMatches[] = [
        'id' => uid(),
        'label' => 'F',
        'type' => 'final',
        'team1' => null,
        'team2' => null,
        'team1Id' => null,
        'team2Id' => null,
        'score1' => null,
        'score2' => null,
        'date' => null,
        'startTime' => null,
        'endTime' => null
    ];

    // Aggiungi la fase knockout allo state
    $knockoutPhaseIdx = count($state['phases']);
    $state['phases'][] = [
        'id' => 'phase-' . ($knockoutPhaseIdx + 1) . '-knockout',
        'phaseIdx' => $knockoutPhaseIdx + 1,
        'phaseNumber' => $knockoutPhaseIdx + 1,
        'name' => 'Fase ' . ($knockoutPhaseIdx + 1) . ' - Playoff',
        'type' => 'knockout',
        'status' => 'pending',
        'matches' => $knockoutMatches,
        'groups' => [],
        'standings' => [],
        'createdAt' => gmdate('c')
    ];

    return true;
}

function updatePlayoffTree(array &$state): void {
    // Trova la fase knockout
    $knockoutPhaseIdx = null;
    $knockoutPhase = null;
    
    foreach ($state['phases'] ?? [] as $phaseIdx => $phase) {
        if ($phase['type'] === 'knockout') {
            $knockoutPhaseIdx = $phaseIdx;
            $knockoutPhase = &$state['phases'][$phaseIdx];
            break;
        }
    }
    
    if (!$knockoutPhase || !isset($knockoutPhase['matches'])) {
        return;
    }
    
    // Separa i match per tipo
    $quarterFinals = [];
    $semiFinals = [];
    $final = null;
    $thirdPlace = null;
    
    foreach ($knockoutPhase['matches'] as $idx => $match) {
        if ($match['type'] === 'quarterFinal') {
            $quarterFinals[] = ['idx' => $idx, 'match' => $match];
        } elseif ($match['type'] === 'semiFinal') {
            $semiFinals[] = ['idx' => $idx, 'match' => $match];
        } elseif ($match['type'] === 'final') {
            $final = ['idx' => $idx, 'match' => $match];
        } elseif ($match['type'] === 'thirdPlace') {
            $thirdPlace = ['idx' => $idx, 'match' => $match];
        }
    }
    
    if (count($quarterFinals) !== 4 || count($semiFinals) !== 2 || !$final || !$thirdPlace) {
        return;
    }
    
    // Calcola i vincitori dei quarti
    $qw = [];
    foreach ($quarterFinals as $qf) {
        $wl = winnerLoser($qf['match']);
        $qw[] = $wl['winner'];
    }
    
    // Aggiorna le semifinali con i vincitori dei quarti
    if (isset($semiFinals[0]['idx'])) {
        $knockoutPhase['matches'][$semiFinals[0]['idx']]['team1'] = $qw[0] ?? null;
        $knockoutPhase['matches'][$semiFinals[0]['idx']]['team1Id'] = $qw[0] ?? null;
        $knockoutPhase['matches'][$semiFinals[0]['idx']]['team2'] = $qw[1] ?? null;
        $knockoutPhase['matches'][$semiFinals[0]['idx']]['team2Id'] = $qw[1] ?? null;
    }
    if (isset($semiFinals[1]['idx'])) {
        $knockoutPhase['matches'][$semiFinals[1]['idx']]['team1'] = $qw[2] ?? null;
        $knockoutPhase['matches'][$semiFinals[1]['idx']]['team1Id'] = $qw[2] ?? null;
        $knockoutPhase['matches'][$semiFinals[1]['idx']]['team2'] = $qw[3] ?? null;
        $knockoutPhase['matches'][$semiFinals[1]['idx']]['team2Id'] = $qw[3] ?? null;
    }
    
    // Calcola vincitori e perdenti delle semifinali
    $sf1 = winnerLoser($knockoutPhase['matches'][$semiFinals[0]['idx']] ?? []);
    $sf2 = winnerLoser($knockoutPhase['matches'][$semiFinals[1]['idx']] ?? []);
    
    // Aggiorna la finale con i vincitori delle semifinali
    $knockoutPhase['matches'][$final['idx']]['team1'] = $sf1['winner'] ?? null;
    $knockoutPhase['matches'][$final['idx']]['team1Id'] = $sf1['winner'] ?? null;
    $knockoutPhase['matches'][$final['idx']]['team2'] = $sf2['winner'] ?? null;
    $knockoutPhase['matches'][$final['idx']]['team2Id'] = $sf2['winner'] ?? null;
    
    // Aggiorna la terza posizione con i perdenti delle semifinali
    $knockoutPhase['matches'][$thirdPlace['idx']]['team1'] = $sf1['loser'] ?? null;
    $knockoutPhase['matches'][$thirdPlace['idx']]['team1Id'] = $sf1['loser'] ?? null;
    $knockoutPhase['matches'][$thirdPlace['idx']]['team2'] = $sf2['loser'] ?? null;
    $knockoutPhase['matches'][$thirdPlace['idx']]['team2Id'] = $sf2['loser'] ?? null;
}

function computeFinalRanking(array $state): array {
    $teamMap = getTeamMap($state);
    $standings = computeStandings($state);
    $rankingIds = [];

    // Estrai matches dalle fasi knockout (a partire dalla fase 2)
    // 🔧 FIX: il controllo precedente saltava solo "$phaseIdx === 0" (la
    // POSIZIONE nell'array, non l'identità della fase), assumendo che ci
    // fosse un'unica fase a gironi e che fosse sempre la prima. Con più fasi
    // a gironi (es. Gold a knockout + Silver a gironi come Fase 3), le
    // partite della Fase 3 finivano incluse qui insieme a quelle knockout,
    // sballando la classifica finale. Ora filtriamo esplicitamente per tipo.
    foreach ($state['phases'] ?? [] as $phase) {
        if (($phase['type'] ?? null) !== 'knockout') continue;

        foreach ($phase['matches'] ?? [] as $match) {
            // Determina il vincitore e il perdente di ogni match
            $wl = winnerLoser($match);
            if ($wl['winner']) $rankingIds[] = $wl['winner'];
            if ($wl['loser']) $rankingIds[] = $wl['loser'];
        }
    }

    // Aggiungi le standings dei gironi
    foreach ($standings as $g) {
        foreach ($g['rows'] as $r) {
            $rankingIds[] = $r['teamId'];
        }
    }

    $rankingIds = array_values(array_unique($rankingIds));
    $finalRanking = [];
    foreach ($rankingIds as $idx => $teamId) {
        $finalRanking[] = [
            'position' => $idx + 1,
            'teamId' => $teamId,
            'name' => $teamMap[$teamId]['name'] ?? 'N/D'
        ];
    }
    
    return $finalRanking;
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
    
    // ✅ REFACTORED: Leggi i gruppi dalla prima fase
    $groups = $state['phases'][0]['groups'] ?? [];
    
    // Raccogli tutte le partite dei gironi
    $matches = [];
    foreach ($groups as $group) {
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
    
    // ✅ GROUP-DAY PRIORITY SCHEDULER con ROUND-BASED DISTRIBUTION
    error_log('🎯 GROUP-DAY PRIORITY SCHEDULER with ROUND-BASED DISTRIBUTION: Starting...');
    
    // 1. RACCOGLI le partite per girone
    $matchesByGroup = [];
    foreach ($matches as $idx => $match) {
        $group = $match['group'];
        if (!isset($matchesByGroup[$group])) {
            $matchesByGroup[$group] = [];
        }
        $matchesByGroup[$group][] = ['idx' => $idx, 'match' => $match];
    }
    
    error_log('  Groups found: ' . implode(', ', array_keys($matchesByGroup)));
    foreach ($matchesByGroup as $group => $groupMatches) {
        error_log("    - $group: " . count($groupMatches) . ' matches');
    }
    
    // 2. RAGGRUPPA gli slot per GIORNO (non per data+time)
    $slotsByDate = [];
    for ($i = 0; $i < count($availableSlots); $i++) {
        $slot = $availableSlots[$i];
        $date = $slot['date'];
        if (!isset($slotsByDate[$date])) {
            $slotsByDate[$date] = [];
        }
        $slotsByDate[$date][] = $i;
    }
    
    error_log("  Available dates: " . implode(', ', array_keys($slotsByDate)));
    foreach ($slotsByDate as $date => $slotIndices) {
        error_log("    - $date: " . count($slotIndices) . ' slots');
    }
    
    // 3. ASSEGNA un giorno per ogni girone
    $slotUsed = array_fill(0, count($availableSlots), false);
    $matchesWithSlots = [];
    $groupDateAssignment = [];
    
    $datesList = array_keys($slotsByDate);
    $groupsList = array_keys($matchesByGroup);
    
    foreach ($groupsList as $groupIdx => $group) {
        error_log("\n  ▶️  Processing GROUP: $group (match count: " . count($matchesByGroup[$group]) . ")");
        
        // Seleziona il GIORNO meno occupato per questo girone
        $bestDate = null;
        $maxAvailableSlots = -1;
        
        foreach ($datesList as $date) {
            $availableSlots_inDate = array_filter($slotsByDate[$date], fn($i) => !$slotUsed[$i]);
            $slotCount = count($availableSlots_inDate);
            
            if ($slotCount > $maxAvailableSlots) {
                $maxAvailableSlots = $slotCount;
                $bestDate = $date;
            }
        }
        
        if ($bestDate === null) {
            error_log("    ❌ No available date for group $group! Fallback to old algorithm.");
            buildGroupMatches($state);
            return;
        }
        
        error_log("    ✅ Assigned $group to date $bestDate (max $maxAvailableSlots slots available)");
        $groupDateAssignment[$group] = $bestDate;
        
        // 4. DISTRIBUISCI le partite del girone nei slot del giorno assegnato CON GREEDY MATCHING
        // Strategy: Ad ogni passo, scelgo la partita NON ANCORA ASSEGNATA che meno carica le squadre già cariche
        $groupMatches = $matchesByGroup[$group];
        $daySlots = array_filter($slotsByDate[$bestDate], fn($i) => !$slotUsed[$i]);
        $daySlots = array_values($daySlots); // Re-index
        
        if (count($daySlots) < count($groupMatches)) {
            error_log("    ⚠️  Day $bestDate has " . count($daySlots) . " slots but group needs " . count($groupMatches) . " - will overflow to next available slots");
        }
        
        // Traccia quali match sono già assegnati
        $assignedMatchIndices = [];
        $teamLoadIndex = [];
        
        // Inizializza i carichi
        foreach ($groupMatches as $matchData) {
            $team1 = $matchData['match']['team1Id'];
            $team2 = $matchData['match']['team2Id'];
            if (!isset($teamLoadIndex[$team1])) {
                $teamLoadIndex[$team1] = 0;
            }
            if (!isset($teamLoadIndex[$team2])) {
                $teamLoadIndex[$team2] = 0;
            }
        }
        
        $teamLastTime = [];
        
        // Greedy matching: finché ci sono partite non assegnate
        while (count($assignedMatchIndices) < count($groupMatches)) {
            $bestMatchIdx = -1;
            $bestScore = PHP_INT_MIN;
            $bestLoad = PHP_INT_MAX;
            
            // Trova la miglior partita NOT YET ASSIGNED
            foreach ($groupMatches as $idx => $matchData) {
                if (in_array($idx, $assignedMatchIndices)) {
                    continue; // Già assegnata
                }
                
                $team1 = $matchData['match']['team1Id'];
                $team2 = $matchData['match']['team2Id'];
                
                // Score: preferiamo partite dove le squadre sono meno cariche
                $load = max($teamLoadIndex[$team1], $teamLoadIndex[$team2]);
                $minLoad = min($teamLoadIndex[$team1], $teamLoadIndex[$team2]);
                
                // Prefer: squadre poco cariche, e se uno è poco carico e l'altro più carico, è OK
                $score = -($load * 100 + $minLoad);  // Negativo perché cerchiamo il minimo
                
                if ($score > $bestScore || ($score === $bestScore && $load < $bestLoad)) {
                    $bestScore = $score;
                    $bestMatchIdx = $idx;
                    $bestLoad = $load;
                }
            }
            
            if ($bestMatchIdx === -1) {
                error_log("    ❌ Could not find unassigned match in $group!");
                buildGroupMatches($state);
                return;
            }
            
            // Assegna questa partita al miglior slot disponibile nel giorno
            $matchData = $groupMatches[$bestMatchIdx];
            $idx = $matchData['idx'];
            $match = $matchData['match'];
            $team1 = $match['team1Id'];
            $team2 = $match['team2Id'];
            
            $bestSlotIdx = -1;
            $bestSlotScore = PHP_INT_MIN;
            $bestSlotTime = null;
            
            // Cerca lo slot migliore considerando i gap
            foreach ($daySlots as $slotIdx) {
                if ($slotUsed[$slotIdx]) continue;
                
                $slot = $availableSlots[$slotIdx];
                $slotTime = strtotime($slot['startTime']);
                
                $team1LastTime = $teamLastTime[$team1] ?? PHP_INT_MIN;
                $team2LastTime = $teamLastTime[$team2] ?? PHP_INT_MIN;
                
                $team1Gap = ($slotTime - $team1LastTime) / 60;
                $team2Gap = ($slotTime - $team2LastTime) / 60;
                $minGap = min($team1Gap, $team2Gap);
                
                // Score: preferiamo gap >= 75 minuti
                $slotScore = ($minGap >= 75 || $team1LastTime === PHP_INT_MIN || $team2LastTime === PHP_INT_MIN) 
                    ? (1000 + $minGap) 
                    : $minGap;
                
                if ($slotScore > $bestSlotScore) {
                    $bestSlotScore = $slotScore;
                    $bestSlotIdx = $slotIdx;
                    $bestSlotTime = $slotTime;
                }
            }
            
            if ($bestSlotIdx !== -1) {
                $slot = $availableSlots[$bestSlotIdx];
                $match['date'] = $slot['date'];
                $match['courtId'] = $slot['courtId'];
                $match['courtName'] = $slot['courtName'];
                $match['startTime'] = $slot['startTime'];
                $match['endTime'] = $slot['endTime'];
                $match['courtIdx'] = $slot['courtIdx'];
                $match['dateIdx'] = $slot['dateIdx'];
                $match['slotIdx'] = $slot['slotIdx'];
                
                $matchesWithSlots[$idx] = $match;
                $slotUsed[$bestSlotIdx] = true;
                $teamLastTime[$team1] = $bestSlotTime;
                $teamLastTime[$team2] = $bestSlotTime;
                $teamLoadIndex[$team1]++;
                $teamLoadIndex[$team2]++;
                $assignedMatchIndices[] = $bestMatchIdx;
                
                error_log("    ✅ [{count($assignedMatchIndices)}/{count($groupMatches)}] {$team1}[L:{$teamLoadIndex[$team1]}] vs {$team2}[L:{$teamLoadIndex[$team2]}] → {$slot['startTime']}");
            } else {
                error_log("    ❌ Could not find slot for group $group!");
                buildGroupMatches($state);
                return;
            }
        }
    }
    
    if (count($matchesWithSlots) < count($matches)) {
        error_log('DEBUG buildGroupMatchesWithSchedule: Could not assign all matches! Assigned: ' . count($matchesWithSlots) . '/' . count($matches));
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
    // ✅ REFACTORED: Salva i match nella prima fase
    if (!isset($state['phases'][0])) {
        $state['phases'][0] = [];
    }
    $state['phases'][0]['matches'] = $finalMatches;
}

function simulateAll(array &$state): bool {
    // ✅ REFACTORED: Leggi la configurazione per ottenere numSets
    $config = readConfig();
    $numSets = (int)($config['tournament']['numSets'] ?? 2);
    error_log("✅ simulateAll: Usando numSets=$numSets dalla configurazione");
    
    // ✅ REFACTORED: Controlla i gruppi nella prima fase
    $groupsPhase = &$state['phases'][0];
    $groupsInPhase = $groupsPhase['groups'] ?? [];
    
    if (count($groupsInPhase) === 0) {
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

        // ✅ REFACTORED: Salva i gruppi nella prima fase
        $groupsPhase['groups'] = $groups;
        buildGroupMatches($state);
    }

    // ✅ REFACTORED: Itera sui match della prima fase
    $groupsPhase = &$state['phases'][0];
    foreach ($groupsPhase['matches'] ?? [] as &$match) {
        [$a, $b] = randomScore($numSets);
        $match['score1'] = $a;
        $match['score2'] = $b;
    }
    unset($match);

    // ✅ REFACTORED: Verifica se ci sono fasi di playoff
    $playoffPhases = array_filter($state['phases'], fn($p) => ($p['type'] ?? null) === 'knockout');
    if (count($playoffPhases) === 0) {
        if (!createPlayoff($state)) {
            return false;
        }
        $playoffPhases = array_filter($state['phases'], fn($p) => ($p['type'] ?? null) === 'knockout');
    }

    // ✅ REFACTORED: Simula i quarti di finale
    foreach ($playoffPhases as &$phase) {
        if (($phase['type'] ?? null) !== 'knockout') continue;
        
        foreach ($phase['matches'] ?? [] as &$match) {
            if (($match['type'] ?? null) === 'quarterFinal') {
                [$a, $b] = randomScore($numSets);
                $match['score1'] = $a;
                $match['score2'] = $b;
            }
        }
        unset($match);
    }
    unset($phase);

    updatePlayoffTree($state);

    // ✅ REFACTORED: Simula le semifinali
    foreach ($playoffPhases as &$phase) {
        if (($phase['type'] ?? null) !== 'knockout') continue;
        
        foreach ($phase['matches'] ?? [] as &$match) {
            if (($match['type'] ?? null) === 'semiFinal' && ($match['team1Id'] ?? null) && ($match['team2Id'] ?? null)) {
                [$a, $b] = randomScore($numSets);
                $match['score1'] = $a;
                $match['score2'] = $b;
            }
        }
        unset($match);
    }
    unset($phase);

    updatePlayoffTree($state);

    // ✅ REFACTORED: Simula terzo posto e finale
    foreach ($playoffPhases as &$phase) {
        if (($phase['type'] ?? null) !== 'knockout') continue;
        
        foreach ($phase['matches'] ?? [] as &$match) {
            if (($match['type'] ?? null) === 'thirdPlace' && ($match['team1Id'] ?? null) && ($match['team2Id'] ?? null)) {
                [$a, $b] = randomScore($numSets);
                $match['score1'] = $a;
                $match['score2'] = $b;
            }
            if (($match['type'] ?? null) === 'final' && ($match['team1Id'] ?? null) && ($match['team2Id'] ?? null)) {
                [$a, $b] = randomScore($numSets);
                $match['score1'] = $a;
                $match['score2'] = $b;
            }
        }
        unset($match);
    }
    unset($phase);

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

// Ottiene il tournament code dal token della sessione
function getTournamentCodeFromToken(string $token = ''): ?string {
    if (empty($token)) {
        $token = authToken() ?? '';
        if (empty($token)) {
            return null;
        }
    }
    
    $sessions = readJsonFile(SESSION_FILE, ['tokens' => []]);
    
    foreach ($sessions['tokens'] as $item) {
        if (is_array($item) && ($item['token'] ?? '') === $token) {
            return $item['tournamentCode'] ?? null;
        }
    }
    
    return null;
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

    // Controlla se le iscrizioni sono chiuse
    if ($config['tournament']['registrationsClosed'] ?? false) {
        jsonResponse(403, [
            'ok' => false,
            'error' => '🚫 Le iscrizioni al torneo sono chiuse',
            'registrationsClosed' => true
        ]);
    }

    // Controlla se la data di scadenza iscrizioni è stata superata
    $deadline = $config['tournament']['registrationDeadline'] ?? '';
    if (!empty($deadline)) {
        $deadlineDate = strtotime($deadline);
        if ($deadlineDate !== false && time() > $deadlineDate) {
            jsonResponse(403, [
                'ok' => false,
                'error' => '🚫 La data di scadenza iscrizioni è stata superata',
                'deadlineExpired' => true
            ]);
        }
    }

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
    // Sincronizza le fasi da config.json se non esistono
    ensurePhases($state);
    
    // Normalizza i groups di ogni fase per il frontend
    if (isset($state['phases']) && is_array($state['phases'])) {
        foreach ($state['phases'] as &$phase) {
            if (isset($phase['groups'])) {
                $phase['groups'] = normalizeGroups($phase['groups'], $state['teams'] ?? []);
            }
        }
    }
    unset($phase);
    
    // 🔧 FIX: il refactoring precedente aveva rimosso il campo 'standings' dallo
    // stato salvato su disco, ma questo endpoint (usato dal pannello admin) non lo
    // ricalcolava più al volo come invece fa già publicState() (usato da
    // scoreboard.html) — per questo la classifica in admin risultava vuota/a zero
    // o mostrava uno snapshot vecchio, mentre quella pubblica era sempre corretta.
    // Lo calcoliamo qui fresco, sugli stessi dati reali appena normalizzati sopra.
    $state['standings'] = computeStandings($state);
    
    // ✅ AGGIUNTO: Calcola standings per OGNI fase di tipo 'groups'
    // (esattamente come fa publicState()) così admin.html può visualizzarle
    if (isset($state['phases']) && is_array($state['phases'])) {
        foreach ($state['phases'] as &$phase) {
            if (($phase['type'] ?? '') === 'groups') {
                $phaseNumber = $phase['phaseNumber'] ?? null;
                if ($phaseNumber !== null) {
                    $phase['standings'] = computeStandingsForPhase($state, $phaseNumber);
                }
            }
        }
        unset($phase);
    }
    
    // Per admin: ritorna LO STATO COMPLETO, non filtrato
    jsonResponse(200, ['ok' => true, 'data' => $state]);
}

if ($action === 'admin_set_current_phase' && $method === 'POST') {
    $body = bodyJson();
    $phaseIdx = (int)($body['phaseIdx'] ?? 0);
    
    if ($phaseIdx < 0) {
        jsonResponse(422, ['ok' => false, 'error' => 'Phase index invalido']);
    }
    
    $result = withStateTransaction(function (&$state) use ($phaseIdx) {
        $maxPhaseIdx = count($state['phases'] ?? []);
        
        // Validazione: la fase deve esistere
        if ($phaseIdx > $maxPhaseIdx) {
            return [
                'ok' => false,
                'error' => "Fase $phaseIdx non trovata (max: $maxPhaseIdx)"
            ];
        }
        
        $state['currentPhaseIdx'] = $phaseIdx;
        error_log("✅ admin_set_current_phase: impostato currentPhaseIdx = $phaseIdx");
        
        return ['ok' => true, 'currentPhaseIdx' => $phaseIdx];
    });
    
    jsonResponse(200, $result);
}

if ($action === 'admin_delete_phase' && $method === 'POST') {
    $body = bodyJson();
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);
    
    if ($phaseNumber <= 0) {
        jsonResponse(422, ['ok' => false, 'error' => 'Numero fase invalido']);
    }
    
    $result = withStateTransaction(function (&$state) use ($phaseNumber) {
        $phases = $state['phases'] ?? [];
        
        // Trova l'indice della fase da cancellare
        $deleteIdx = -1;
        foreach ($phases as $idx => $phase) {
            if (($phase['phaseNumber'] ?? 0) === $phaseNumber) {
                $deleteIdx = $idx;
                break;
            }
        }
        
        if ($deleteIdx === -1) {
            return [
                'ok' => false,
                'error' => "Fase $phaseNumber non trovata"
            ];
        }
        
        // Rimuovi la fase
        array_splice($phases, $deleteIdx, 1);
        $state['phases'] = $phases;
        
        // Se la fase rimossa era currentPhaseIdx, cambia a una fase valida
        if (($state['currentPhaseIdx'] ?? 0) === $phaseNumber) {
            if (count($phases) > 0) {
                $state['currentPhaseIdx'] = $phases[0]['phaseNumber'] ?? 1;
            } else {
                $state['currentPhaseIdx'] = 1;
            }
        }
        
        error_log("✅ admin_delete_phase: cancellata fase $phaseNumber. Rimangono " . count($phases) . " fasi. currentPhaseIdx ora = " . $state['currentPhaseIdx']);
        
        return ['ok' => true, 'state' => $state];
    });
    
    jsonResponse(200, $result);
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

if ($action === 'admin_add_team' && $method === 'POST') {
    $body = bodyJson();
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 50);
    $phone = mb_substr(trim((string)($body['phone'] ?? '')), 0, 20);
    $players = $body['players'] ?? [];
    
    if ($name === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'Nome squadra obbligatorio']);
    }

    $result = withStateTransaction(function (&$state) use ($name, $phone, $players) {
        if (tournamentStarted($state)) {
            return ['ok' => false, 'error' => 'Non puoi aggiungere squadre: il torneo è già iniziato'];
        }

        $config = readConfig();
        $maxTeams = $config['tournament']['maxTeams'] ?? 0;
        $currentTeams = count($state['teams'] ?? []);
        
        if ($maxTeams > 0 && $currentTeams >= $maxTeams) {
            return ['ok' => false, 'error' => "Hai raggiunto il numero massimo di squadre ($maxTeams)"];
        }

        // Normalizza giocatori
        $normalizedPlayers = [];
        $maxPlayers = $config['tournament']['maxPlayersPerTeam'] ?? 3;
        
        if (is_array($players)) {
            foreach (array_slice($players, 0, $maxPlayers) as $player) {
                if (is_array($player) && isset($player['name'])) {
                    $playerName = mb_substr(trim((string)$player['name']), 0, 50);
                    if ($playerName !== '') {
                        $normalizedPlayers[] = [
                            'name' => $playerName,
                            'isCaptain' => (bool)($player['isCaptain'] ?? false),
                            'level' => max(1, min(5, (int)($player['level'] ?? 3)))
                        ];
                    }
                }
            }
        }

        // Crea la nuova squadra
        $newTeam = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'category' => 'Misto',
            'players' => $normalizedPlayers,
            'phone' => $phone,
            'paid' => false,
            'approved' => false,
            'kitDelivered' => false,
            'dummy' => false,
            'createdAt' => gmdate('c'),
            'skillLevel' => 3  // Default skill level
        ];

        if (!isset($state['teams'])) {
            $state['teams'] = [];
        }
        
        $state['teams'][] = $newTeam;

        error_log("✅ admin_add_team: Aggiunta squadra " . $newTeam['id'] . " - " . $name);

        return [
            'ok' => true,
            'teamId' => $newTeam['id'],
            'totalTeams' => count($state['teams'])
        ];
    });

    jsonResponse(200, $result);
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
            // Assegna livelli diversi per creare squadre di forza variabile
            // Distribuisci livelli: 1-2 (deboli), 3-4 (medi), 5 (forti)
            $playerLevels = [
                rand(1, 2),  // Giocatore 1: 1-2 (debole)
                rand(3, 4),  // Giocatore 2: 3-4 (medio)
                rand(2, 5)   // Giocatore 3: 2-5 (vario)
            ];
            
            // Determina il livello della squadra basato sulla media
            $squadsLevel = (int)ceil(array_sum($playerLevels) / count($playerLevels));
            
            $dummyTeam = [
                'id' => uid(),
                'name' => 'Test Team ' . ($existingDummyCount + $i + 1),
                'category' => 'Misto',
                'players' => [
                    ['name' => 'Bot 1', 'isCaptain' => false, 'level' => $playerLevels[0]],
                    ['name' => 'Bot 2', 'isCaptain' => false, 'level' => $playerLevels[1]],
                    ['name' => 'Bot 3', 'isCaptain' => false, 'level' => $playerLevels[2]]
                ],
                'phone' => '',
                'paid' => true,
                'approved' => true,
                'kitDelivered' => false,
                'dummy' => true,
                'skillLevel' => $squadsLevel,  // Livello squadra (1-5)
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
        $dummyCounter = 0;
        while (count($approved) < $minTeams && count($approved) < $maxTeams) {
            // Assegna livelli diversi per creare squadre di forza variabile
            $playerLevels = [
                rand(1, 2),  // Giocatore 1: 1-2 (debole)
                rand(3, 4),  // Giocatore 2: 3-4 (medio)
                rand(2, 5)   // Giocatore 3: 2-5 (vario)
            ];
            $squadsLevel = (int)ceil(array_sum($playerLevels) / count($playerLevels));
            
            $dummyTeam = [
                'id' => uid(),
                'name' => generateDummyTeamName(count($approved)),
                'category' => 'Misto',
                'players' => [
                    ['name' => 'Bot 1', 'isCaptain' => false, 'level' => $playerLevels[0]],
                    ['name' => 'Bot 2', 'isCaptain' => false, 'level' => $playerLevels[1]],
                    ['name' => 'Bot 3', 'isCaptain' => false, 'level' => $playerLevels[2]]
                ],
                'phone' => '',
                'paid' => true,
                'approved' => true,
                'dummy' => true,
                'skillLevel' => $squadsLevel,
                'createdAt' => gmdate('c')
            ];
            $state['teams'][] = $dummyTeam;
            $approved[] = $dummyTeam;
            $dummyCounter++;
            error_log('DEBUG: Aggiunta squadra fittizia ' . $dummyTeam['name'] . ' (level=' . $squadsLevel . '), ora approved=' . count($approved));
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
        
        // ✅ Assicura che ogni group abbia teamIds calcolato da teams
        foreach ($groups as &$group) {
            if (!isset($group['teamIds']) && isset($group['teams'])) {
                $group['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $group['teams']);
            }
        }
        unset($group);

        // ✅ REFACTORED: Salva i gruppi nella prima fase
        if (!isset($state['phases'][0])) {
            $state['phases'][0] = [
                'id' => 'groups_' . uid(),
                'phaseIdx' => 0,
                'phaseNumber' => 1,
                'name' => 'Fase 1 - Gironi',
                'type' => 'groups',
                'status' => 'pending',
                'matches' => [],
                'groups' => [],
                'standings' => [],
                'createdAt' => gmdate('c')
            ];
        }
        $state['phases'][0]['groups'] = $groups;
        
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
        
        // ===== INTEGRAZIONE NUOVO SISTEMA DI FASI =====
        // Le fasi sono state create direttamente da buildGroupMatchesWithSchedule
        // che salva i match in phases[0]['matches']
        
        error_log('✅ Gruppi e partite create: ' . count($groups) . ' groups con ' . count($state['phases'][0]['matches'] ?? []) . ' matches');

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

/**
 * ✅ DEPRECATED: Questa funzione non è più necessaria poiché $state['groupMatches'] è stato rimosso
 * Tutti i match sono ora memorizzati in phases[0]['matches']
 */
if ($action === 'admin_sync_group_matches_slots' && $method === 'POST') {
    validSession($token);
    
    jsonResponse(200, [
        'ok' => true,
        'message' => 'Sincronizzazione non necessaria - tutti i match sono in phases[0].matches',
        'syncedCount' => 0,
        'totalMatches' => 0
    ]);
}

/**
 * Aggiorna i parametri di una fase configurata
 * POST body: { phaseNumber: 1, name: "Fase 1", type: "groups", numGroups: 4, teamsAdvance: 2, hasRepescage: false, notes: "..." }
 */
if ($action === 'admin_update_phase' && $method === 'POST') {
    requireAdmin();
    
    $body = bodyJson();
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);
    if ($phaseNumber <= 0) {
        jsonResponse(400, ['ok' => false, 'error' => 'Phase number richiesto']);
    }
    
    $config = readConfig();
    $phases = $config['phases'] ?? [];
    
    // Cerca la fase da aggiornare
    $phaseIdx = null;
    foreach ($phases as $idx => $p) {
        if ($p['phaseNumber'] === $phaseNumber) {
            $phaseIdx = $idx;
            break;
        }
    }
    
    if ($phaseIdx === null) {
        jsonResponse(404, ['ok' => false, 'error' => 'Fase non trovata']);
    }
    
    // Aggiorna i parametri
    $phase = &$phases[$phaseIdx];
    $phase['name'] = $body['name'] ?? $phase['name'];
    $phase['type'] = $body['type'] ?? $phase['type'];
    $phase['notes'] = $body['notes'] ?? '';
    $phase['qualifiedGoTo'] = $body['qualifiedGoTo'] ?? '';
    $phase['eliminatedGoTo'] = $body['eliminatedGoTo'] ?? '';
    
    // Parametri specifici per tipo
    if ($phase['type'] === 'groups') {
        $phase['numGroups'] = (int)($body['numGroups'] ?? $phase['numGroups'] ?? 4);
        $phase['teamsAdvance'] = (string)($body['teamsAdvance'] ?? $phase['teamsAdvance'] ?? '2');
        $phase['hasRepescage'] = (bool)($body['hasRepescage'] ?? false);
    } elseif ($phase['type'] === 'knockout') {
        $phase['numTeams'] = (int)($body['numTeams'] ?? $phase['numTeams'] ?? 8);
        $phase['hasLosersPath'] = (bool)($body['hasLosersPath'] ?? false);
    }

    // 🔧 Regole di gioco specifiche della fase: set, durata, preparazione,
    // punteggio, timeout, cambi. Ogni chiave è opzionale — se assente o vuota
    // in questa fase, si usa il default configurato per il torneo (vedi
    // resolvePhaseMatchRules()). Salviamo solo le chiavi effettivamente
    // valorizzate, così i campi lasciati vuoti tornano davvero al default.
    if (isset($body['matchRules']) && is_array($body['matchRules'])) {
        $allowedKeys = ['numSets', 'timePerSetMinutes', 'setupTimeMinutes', 'winScore', 'maxScore', 'maxTimeoutsPerSet', 'maxSubstitutions', 'winPoints'];
        $mr = [];
        foreach ($allowedKeys as $k) {
            if (isset($body['matchRules'][$k]) && $body['matchRules'][$k] !== '' && $body['matchRules'][$k] !== null) {
                $mr[$k] = (int)$body['matchRules'][$k];
            }
        }
        // 🆕 setsPerRound: sequenza di set-per-vincere per ogni round del knockout,
        // es. [1,1,2] = quarti 1 set, semifinali 1 set, finale 2 (meglio dei 3).
        // È un array, non un singolo intero, quindi va gestito a parte dalle
        // altre chiavi numeriche.
        if (isset($body['matchRules']['setsPerRound']) && is_array($body['matchRules']['setsPerRound'])) {
            $seq = array_values(array_map('intval', $body['matchRules']['setsPerRound']));
            $seq = array_filter($seq, fn($v) => $v > 0);
            if (!empty($seq)) {
                $mr['setsPerRound'] = array_values($seq);
            }
        }
        $phase['matchRules'] = $mr;
    }
    
    $config['phases'] = $phases;
    writeConfig($config);
    
    jsonResponse(200, ['ok' => true, 'phases' => $phases]);
}

/**
 * Genera una fase generica (non solo gironi)
 * POST body: { phaseIdx: 2, type: 'knockout' }
 */
if ($action === 'admin_generate_phase' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $phaseIdx = (int)($body['phaseIdx'] ?? 0);
    $type = $body['type'] ?? '';
    
    error_log("🚀 admin_generate_phase: phaseIdx=$phaseIdx, type=$type");
    
    if (!$phaseIdx || !$type) {
        jsonResponse(400, ['ok' => false, 'error' => 'phaseIdx e type sono obbligatori']);
    }
    
    withStateTransaction(function (&$state) use ($phaseIdx, $type) {
        error_log("📥 Dentro withStateTransaction callback");
        ensurePhases($state);
        
        // Solo per fasi successive alla prima, controlla che la fase precedente esista e sia completata
        if ($phaseIdx > 1) {
            $previousPhase = getPhase($state, $phaseIdx - 1);
            if (!$previousPhase) {
                jsonResponse(400, ['ok' => false, 'error' => 'La fase precedente non esiste']);
            }
            
            if ($previousPhase['status'] !== 'completed') {
                jsonResponse(400, ['ok' => false, 'error' => 'La fase precedente non è completata']);
            }
        }
        
        // Genera la fase in base al tipo
        if ($type === 'groups') {
            // Per la prima fase, usa squadre approvate
            $approvedTeams = $state['teams'] ?? [];
            $approvedTeams = array_filter($approvedTeams, function($t) { return !empty($t['approved']); });
            $approvedTeams = array_values($approvedTeams);
            
            if (count($approvedTeams) === 0) {
                jsonResponse(400, ['ok' => false, 'error' => 'Nessuna squadra approvata disponibile']);
            }
            
            // Leggi i parametri della fase da CONFIG usando phaseNumber (join key)
            $config = readConfig();
            $configPhases = $config['phases'] ?? [];
            $configPhase = null;
            foreach ($configPhases as $cp) {
                // Join su phaseNumber: il valore ricevuto ($phaseIdx) corrisponde a phaseNumber in config
                if (($cp['phaseNumber'] ?? null) === $phaseIdx) {
                    $configPhase = $cp;
                    break;
                }
            }
            
            if (!$configPhase) {
                jsonResponse(400, ['ok' => false, 'error' => "Fase $phaseIdx non trovata nella configurazione"]);
            }
            
            $numGroups = !empty($configPhase['numGroups']) ? (int)$configPhase['numGroups'] : 4;
            $teamsAdvance = !empty($configPhase['teamsAdvance']) ? (string)$configPhase['teamsAdvance'] : '2';
            
            // Distribuisci squadre nei gironi usando lo snake-draft bilanciato per peso
            // (getTeamWeight = livello medio giocatori), NON il semplice ordine di iscrizione.
            // Questo garantisce che ogni girone abbia un livello medio simile.
            $balancedGroups = balancedGroupDistribution($approvedTeams, $numGroups);
            
            // Genera le partite round-robin per ogni girone
            $groupMatches = [];
            
            foreach ($balancedGroups as $group) {
                $groupTeams = $group['teams'];
                if (empty($groupTeams)) continue;
                
                $groupName = $group['name'];
                
                // Round-robin: ogni squadra gioca con tutte le altre una volta
                for ($i = 0; $i < count($groupTeams); $i++) {
                    for ($j = $i + 1; $j < count($groupTeams); $j++) {
                        $groupMatches[] = [
                            'id' => uid(),
                            'groupName' => $groupName,
                            'team1' => $groupTeams[$i]['id'] ?? $groupTeams[$i]['teamId'] ?? null,
                            'team2' => $groupTeams[$j]['id'] ?? $groupTeams[$j]['teamId'] ?? null,
                            'team1Name' => $groupTeams[$i]['name'] ?? 'TBD',
                            'team2Name' => $groupTeams[$j]['name'] ?? 'TBD',
                            'score1' => null,
                            'score2' => null,
                            'status' => 'pending',
                            'sets' => []
                        ];
                    }
                }
            }
            
            // Assegna automaticamente date e orari alle partite, distribuendo bene ogni squadra
            // nell'arco della giornata (vedi scheduleMatches: algoritmo a "gap" cronologico)
            $groupMatches = scheduleMatches($state, $groupMatches, $configPhase);
            
            // Converte $balancedGroups nel formato corretto per lo stato [['name' => 'A', 'teamIds' => [...]]]
            $groupsFormatted = [];
            foreach ($balancedGroups as $group) {
                $teamIds = array_map(fn($t) => $t['id'] ?? $t['teamId'], $group['teams']);
                $groupsFormatted[] = [
                    'name' => $group['name'],
                    'teamIds' => $teamIds
                ];
            }
            
            $phaseName = 'Fase ' . $phaseIdx . ' - Gironi';
            initializePhase($state, $phaseIdx, $phaseName, 'groups', [
                'matches' => $groupMatches,
                'groups' => $groupsFormatted,
                'metadata' => ['teamCount' => count($approvedTeams), 'numGroups' => $numGroups]
            ]);
            
            setPhaseStatus($state, $phaseIdx, 'active');
            
            error_log("✅ Fase gironi creata: phaseIdx=$phaseIdx, matches=" . count($groupMatches) . ", groups=" . count($balancedGroups));
            
            return ['ok' => true, 'phaseName' => $phaseName, 'teamCount' => count($approvedTeams), 'matchCount' => count($groupMatches)];
        }
        
        if ($type === 'knockout') {
            // ✅ MIGLIORATO: Estrai i team qualificati dalla fase precedente
            // Considerando i valori separati da virgola in teamsAdvance
            $standings = computeStandings($state);
            
            // Leggi la configurazione della fase gironi (fase precedente)
            $groupsPhaseConfig = null;
            foreach ($configPhases as $cp) {
                if (($cp['type'] ?? null) === 'groups' && ($cp['phaseNumber'] ?? null) === ($phaseIdx - 1)) {
                    $groupsPhaseConfig = $cp;
                    break;
                }
            }
            
            if ($groupsPhaseConfig) {
                // Usa la nuova logica che rispetta i valori per girone
                $numGroups = $groupsPhaseConfig['numGroups'] ?? 4;
                $teamsAdvance = $groupsPhaseConfig['teamsAdvance'] ?? 2;
                $perGroup = parseTeamsAdvancePerGroup($teamsAdvance, $numGroups);
                $qualifiedTeams = extractQualifiedTeamsByGroup($state['phases'] ?? [], $standings, $perGroup);
                
                error_log("✅ Squadre qualificate estratte per girone: teamsAdvance=$teamsAdvance, perGroup=" . json_encode($perGroup) . ", total=" . count($qualifiedTeams));
            } else {
                // Fallback: usa la logica semplice (top metà)
                $qualifiedTeams = array_slice($standings, 0, (int)ceil(count($standings) / 2));
                error_log("⚠️ Config gironi non trovata, uso fallback: " . count($qualifiedTeams) . " squadre");
            }
            
            // Crea i playoff/knockout
            $knockoutMatches = [];
            $teamsAdvance = (int)ceil(count($qualifiedTeams) / 2);
            
            // Crea bracket per knockout
            for ($i = 0; $i < count($qualifiedTeams); $i += 2) {
                $team1 = $qualifiedTeams[$i] ?? null;
                $team2 = $qualifiedTeams[$i + 1] ?? null;
                
                if ($team1) {
                    $knockoutMatches[] = [
                        'id' => uid(),
                        'team1' => $team1['teamId'] ?? null,
                        'team2' => $team2['teamId'] ?? null,
                        'team1Name' => $team1['name'] ?? 'TBD',
                        'team2Name' => $team2['name'] ?? 'TBD',
                        'score1' => null,
                        'score2' => null,
                        'status' => 'pending'
                    ];
                }
            }
            
            $phaseName = 'Fase ' . $phaseIdx . ' - Knockout';
            if ($phaseIdx === 3) $phaseName = 'Semifinale';
            elseif ($phaseIdx === 4) $phaseName = 'Finale';
            
            initializePhase($state, $phaseIdx, $phaseName, 'knockout', [
                'matches' => $knockoutMatches,
                'metadata' => ['teamCount' => count($qualifiedTeams)]
            ]);
            
            setPhaseStatus($state, $phaseIdx, 'active');
            
            return ['ok' => true, 'phaseName' => $phaseName, 'matchCount' => count($knockoutMatches)];
        }
        
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo fase non supportato: ' . $type]);
    });
    
    error_log("✅ withStateTransaction completato, phases in state: " . count(readJsonFile(DATA_FILE, initialState())['phases'] ?? []));
    
    jsonResponse(200, ['ok' => true, 'message' => 'Fase generata con successo']);
}

/**
 * Sposta una squadra da un girone a un altro
 */
if ($action === 'admin_move_team_to_group' && $method === 'POST') {
    $body = bodyJson();
    $teamName = (string)($body['teamName'] ?? '');
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);
    $groupLabel = (string)($body['groupLabel'] ?? '');

    error_log("🎯 MOVE_TEAM_TO_GROUP - teamName: $teamName, phase: $phaseNumber, groupLabel: $groupLabel");

    if (!$teamName || $phaseNumber < 1 || !$groupLabel) {
        jsonResponse(422, ['ok' => false, 'error' => 'Parametri non validi']);
        return;
    }

    $result = withStateTransaction(function (&$state) use ($teamName, $phaseNumber, $groupLabel) {
        ensurePhases($state);
        
        // Cerca la fase
        $phaseIdx = array_search($phaseNumber, array_column($state['phases'], 'phaseNumber'), true);
        if ($phaseIdx === false) {
            return ['ok' => false, 'error' => "Fase {$phaseNumber} non trovata"];
        }

        $currentPhase = &$state['phases'][$phaseIdx];

        // Verifica che i gruppi esistano
        if (empty($currentPhase['groups']) || !is_array($currentPhase['groups'])) {
            return ['ok' => false, 'error' => 'Nessun girone trovato in questa fase'];
        }

        // 🔧 FIX: i gironi salvati su disco possono avere diversi formati storici
        // (array puro di squadre, solo teamIds, oppure già {label, teams, teamIds}).
        // normalizeGroups() li riporta tutti a un formato uniforme con 'teams'
        // (oggetti squadra completi) e 'teamIds' popolati, così la ricerca funziona
        // indipendentemente da come sono stati salvati.
        $groups = normalizeGroups($currentPhase['groups'], $state['teams'] ?? []);

        // Mappa label girone → indice  
        // label A = indice 0, B = indice 1, ecc
        $groupLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $destGroupIdx = array_search($groupLabel, $groupLabels);
        
        if ($destGroupIdx === false || !isset($groups[$destGroupIdx])) {
            return ['ok' => false, 'error' => "Girone $groupLabel non trovato"];
        }

        // Cerca la squadra (per nome) in tutti i gironi della fase
        $sourceGroupIdx = null;
        $foundTeam = null;

        foreach ($groups as $gIdx => &$group) {
            $teams = $group['teams'] ?? [];
            foreach ($teams as $tIdx => $team) {
                if (($team['name'] ?? '') === $teamName) {
                    $foundTeam = $team;
                    $sourceGroupIdx = $gIdx;

                    // Rimuovi dal girone attuale
                    unset($teams[$tIdx]);
                    $group['teams'] = array_values($teams);
                    $group['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $group['teams']);

                    error_log("✅ Squadra trovata nel girone " . $groupLabels[$gIdx]);
                    break 2;
                }
            }
        }
        unset($group);

        if ($foundTeam === null) {
            return ['ok' => false, 'error' => "Squadra '$teamName' non trovata in questa fase"];
        }

        if ($sourceGroupIdx === $destGroupIdx) {
            return ['ok' => false, 'error' => "Squadra già in questo girone"];
        }

        // Aggiungi al girone di destinazione
        $groups[$destGroupIdx]['teams'][] = $foundTeam;
        $groups[$destGroupIdx]['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $groups[$destGroupIdx]['teams']);

        $currentPhase['groups'] = $groups;

        error_log("✅ Squadra $teamName spostata da " . $groupLabels[$sourceGroupIdx] . " a $groupLabel");
        return ['ok' => true, 'message' => "Squadra spostata a Girone $groupLabel"];
    });

    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }

    jsonResponse(200, $result);
}

/**
 * Rigenera le partite per una fase dopo che i gironi sono stati modificati
 */
if ($action === 'admin_regenerate_phase_matches' && $method === 'POST') {
    $body = bodyJson();
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);

    if ($phaseNumber < 1) {
        jsonResponse(422, ['ok' => false, 'error' => 'Numero fase non valido']);
        return;
    }

    $result = withStateTransaction(function (&$state) use ($phaseNumber) {
        // Trova la fase
        $phase = null;
        $phaseIdx = null;
        foreach ($state['phases'] ?? [] as $idx => &$p) {
            if (($p['phaseNumber'] ?? null) === $phaseNumber) {
                $phase = &$p;
                $phaseIdx = $idx;
                break;
            }
        }

        if (!$phase) {
            return ['ok' => false, 'error' => "Fase $phaseNumber non trovata"];
        }

        if ($phase['type'] !== 'groups') {
            return ['ok' => false, 'error' => 'Ricalcolo disponibile solo per fasi a gironi'];
        }

        // Estrai i gironi attuali dalla fase
        $currentGroups = $phase['groups'] ?? [];
        
        if (empty($currentGroups)) {
            return ['ok' => false, 'error' => 'Nessun girone trovato nella fase'];
        }

        // Normalizza i groups al formato vecchio se necessario
        $groupsForMatching = [];
        foreach ($currentGroups as $group) {
            // Se è nel formato nuovo { label, teams }
            if (isset($group['teams']) && is_array($group['teams'])) {
                $groupsForMatching[] = $group['teams'];
            } 
            // Se è nel formato vecchio (array di squadre)
            elseif (is_array($group)) {
                $groupsForMatching[] = $group;
            }
        }

        // Se non c'è nulla da matchare, errore
        if (empty($groupsForMatching)) {
            return ['ok' => false, 'error' => 'Nessuna squadra trovata nei gironi'];
        }

        // Rigenera le partite round-robin per i gironi attuali
        $newGroupMatches = [];
        $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($groupsForMatching as $gIdx => $groupTeams) {
            if (empty($groupTeams)) continue;

            $groupName = $groupNames[$gIdx] ?? ('G' . ($gIdx + 1));

            // Round-robin: ogni squadra gioca con tutte le altre una volta
            for ($i = 0; $i < count($groupTeams); $i++) {
                for ($j = $i + 1; $j < count($groupTeams); $j++) {
                    $newGroupMatches[] = [
                        'id' => uid(),
                        'groupName' => $groupName,
                        'team1' => $groupTeams[$i]['id'] ?? $groupTeams[$i]['teamId'] ?? null,
                        'team2' => $groupTeams[$j]['id'] ?? $groupTeams[$j]['teamId'] ?? null,
                        'team1Name' => $groupTeams[$i]['name'] ?? 'TBD',
                        'team2Name' => $groupTeams[$j]['name'] ?? 'TBD',
                        'score1' => null,
                        'score2' => null,
                        'status' => 'pending',
                        'sets' => []
                    ];
                }
            }
        }

        // Assegna automaticamente date e orari alle nuove partite, rispettando
        // le eventuali regole di gioco specifiche di questa fase (matchRules)
        $configForRules = readConfig();
        $configPhaseForRules = null;
        foreach (($configForRules['phases'] ?? []) as $cp) {
            if (($cp['phaseNumber'] ?? null) === $phaseNumber) { $configPhaseForRules = $cp; break; }
        }
        $newGroupMatches = scheduleMatches($state, $newGroupMatches, $configPhaseForRules);

        // ✅ REFACTORED: Aggiorna solo la fase, non il campo obsoleto
        $state['phases'][$phaseIdx]['matches'] = $newGroupMatches;

        return ['ok' => true, 'message' => 'Partite ricalcolate con successo', 'matchCount' => count($newGroupMatches)];
    });

    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }

    jsonResponse(200, ['ok' => true, 'message' => 'Partite ricalcolate e salvate con successo']);
}
if ($action === 'admin_get_current_phase' && $method === 'GET') {
    $state = readState();
    ensurePhases($state);
    
    $currentPhaseIdx = $state['currentPhaseIdx'] ?? 1;
    $currentPhase = getPhase($state, $currentPhaseIdx);
    
    if (!$currentPhase) {
        jsonResponse(404, ['ok' => false, 'error' => 'Nessuna fase attiva trovata']);
        exit;
    }
    
    // Prepara info sulla prossima fase
    $nextPhaseIdx = $currentPhaseIdx + 1;
    $nextPhase = getPhase($state, $nextPhaseIdx);
    
    // Conta squadre avanzate dalla fase corrente
    $advancedTeamsCount = 0;
    if ($currentPhase['type'] === 'groups') {
        $standings = computeStandings($state);
        $advancedTeamsCount = count($standings);
    }
    
    jsonResponse(200, [
        'ok' => true,
        'currentPhase' => [
            'phaseIdx' => $currentPhase['phaseIdx'],
            'name' => $currentPhase['name'],
            'type' => $currentPhase['type'],
            'status' => $currentPhase['status'],
            'groupCount' => count($currentPhase['groups'] ?? []),
            'matchCount' => count($currentPhase['matches'] ?? []),
            'advancedTeamsCount' => $advancedTeamsCount,
            'createdAt' => $currentPhase['createdAt']
        ],
        'nextPhase' => $nextPhase ? [
            'phaseIdx' => $nextPhase['phaseIdx'],
            'name' => $nextPhase['name'],
            'type' => $nextPhase['type'],
            'status' => $nextPhase['status']
        ] : null
    ]);
    exit;
}

/**
 * Verifica lo stato del completamento di una fase
 */
if ($action === 'admin_check_phase_completion' && $method === 'POST') {
    $body = bodyJson();
    $phaseIdx = (int)($body['phaseIdx'] ?? 0);
    
    $state = readState();
    ensurePhases($state);
    
    $phase = getPhase($state, $phaseIdx);
    if (!$phase) {
        jsonResponse(404, ['ok' => false, 'error' => 'Fase non trovata']);
        exit;
    }
    
    $status = [
        'completed' => $phase['status'] === 'completed',
        'matchCount' => count($phase['matches'] ?? []),
        'matchesCompleted' => 0,
        'missing' => []
    ];
    
    // Conta partite con risultati
    $matchesWithScore = 0;
    foreach ($phase['matches'] ?? [] as $match) {
        if ($phase['type'] === 'groups') {
            if ($match['score1'] !== null && $match['score2'] !== null) {
                $matchesWithScore++;
            }
        } else if ($phase['type'] === 'knockout') {
            if (!empty($match['sets']) && count($match['sets']) > 0) {
                $hasAllSets = true;
                foreach ($match['sets'] as $set) {
                    if ($set['team1'] === null || $set['team2'] === null) {
                        $hasAllSets = false;
                        break;
                    }
                }
                if ($hasAllSets) $matchesWithScore++;
            }
        }
    }
    $status['matchesCompleted'] = $matchesWithScore;
    
    // Verifica cosa manca
    if ($phase['type'] === 'groups') {
        // Verifica gironi
        if (empty($phase['groups'])) {
            $status['missing'][] = 'Nessun girone configurato';
        }
        
        // Verifica partite
        if (empty($phase['matches'])) {
            $status['missing'][] = 'Nessuna partita programmata';
        } else if ($matchesWithScore < count($phase['matches'])) {
            $remaining = count($phase['matches']) - $matchesWithScore;
            $status['missing'][] = "{$remaining} partita/e senza risultati";
        }
        
        // Verifica classifiche
        $standings = computeStandings($state);
        if (empty($standings)) {
            $status['missing'][] = 'Classifiche non disponibili';
        }
        
        $status['teamsQualified'] = count($standings);
        $status['teamsExpected'] = 0;
        // Calcola squadre attese dalla configurazione
        $config = readConfig();
        $configPhase = null;
        foreach (($config['phases'] ?? []) as $cp) {
            if (($cp['phaseNumber'] ?? null) === $phase['phaseNumber']) {
                $configPhase = $cp;
                break;
            }
        }
        if ($configPhase) {
            $teamsAdvance = $configPhase['teamsAdvance'] ?? 2;
            if (is_string($teamsAdvance) && strpos($teamsAdvance, ',') !== false) {
                $teamsAdvance = array_sum(array_map('intval', explode(',', $teamsAdvance)));
            } else {
                $teamsAdvance = (int)$teamsAdvance;
            }
            $status['teamsExpected'] = (count($phase['groups'] ?? []) ?? 1) * $teamsAdvance;
        }
    } else if ($phase['type'] === 'knockout') {
        if (empty($phase['matches'])) {
            $status['missing'][] = 'Nessuna partita programmata';
        } else if ($matchesWithScore < count($phase['matches'])) {
            $remaining = count($phase['matches']) - $matchesWithScore;
            $status['missing'][] = "{$remaining} partita/e senza risultati";
        }
        
        $status['teamsQualified'] = ceil(count($phase['matches']) / 2);
        $status['teamsExpected'] = $phase['matchRules']['numSets'] ?? 1;
    }
    
    jsonResponse(200, ['ok' => true, 'status' => $status]);
    exit;
}

/**
 * Completa la fase corrente e fa avanzare a quella successiva
 */
if ($action === 'admin_complete_phase' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        ensurePhases($state);
        
        $currentPhaseIdx = $state['currentPhaseIdx'] ?? 1;
        $currentPhase = getPhase($state, $currentPhaseIdx);
        
        if (!$currentPhase) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nessuna fase attiva trovata']);
            exit;
        }
        
        if ($currentPhase['status'] === 'completed') {
            jsonResponse(400, ['ok' => false, 'error' => 'La fase è già stata completata']);
            exit;
        }
        
        // Marca la fase corrente come completata
        setPhaseStatus($state, $currentPhaseIdx, 'completed');
        error_log("✅ Fase {$currentPhaseIdx} ({$currentPhase['name']}) completata");
        
        // Prepara la prossima fase
        $nextPhaseIdx = $currentPhaseIdx + 1;
        $nextPhase = getPhase($state, $nextPhaseIdx);
        
        $result = [
            'ok' => true,
            'completedPhase' => [
                'phaseIdx' => $currentPhaseIdx,
                'name' => $currentPhase['name'],
                'status' => 'completed'
            ],
            'nextPhaseReady' => false,
            'nextPhase' => null
        ];
        
        // Se esiste la fase successiva, l'attiva
        if ($nextPhase && $nextPhase['status'] !== 'active') {
            setPhaseStatus($state, $nextPhaseIdx, 'active');
            $state['currentPhaseIdx'] = $nextPhaseIdx;
            
            $result['nextPhaseReady'] = true;
            $result['nextPhase'] = [
                'phaseIdx' => $nextPhaseIdx,
                'name' => $nextPhase['name'],
                'type' => $nextPhase['type'],
                'status' => 'active'
            ];
            
            error_log("✅ Fase {$nextPhaseIdx} ({$nextPhase['name']}) ora attiva");
        }
        
        return $result;
    });
    
    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_move_team_group' && $method === 'POST') {
    $body = bodyJson();
    $teamId = (string)($body['teamId'] ?? '');
    $phaseNumber = (int)($body['phaseNumber'] ?? 1);
    $newGroupLabel = (string)($body['newGroup'] ?? '');

    withStateTransaction(function (&$state) use ($teamId, $phaseNumber, $newGroupLabel) {
        // ✅ REFACTORED: Usa le fasi per spostare le squadre tra gironi
        $phaseIdx = null;
        $phase = null;
        
        foreach ($state['phases'] ?? [] as $idx => &$p) {
            if (($p['phaseNumber'] ?? null) === $phaseNumber) {
                $phaseIdx = $idx;
                $phase = &$p;
                break;
            }
        }

        if (!$phase) {
            jsonResponse(404, ['ok' => false, 'error' => "Fase $phaseNumber non trovata"]);
        }

        // Mappa label → indice
        $groupLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $newGroupIdx = array_search($newGroupLabel, $groupLabels);
        
        if ($newGroupIdx === false) {
            jsonResponse(400, ['ok' => false, 'error' => 'Etichetta girone non valida']);
        }

        // Cerca la squadra nei gironi attuali
        $foundTeam = null;
        $currentGroupIdx = null;

        $groups = &$phase['groups'];
        for ($gIdx = 0; $gIdx < count($groups); $gIdx++) {
            $group = &$groups[$gIdx];
            
            if (!isset($group['teamIds'])) continue;
            
            $pos = array_search($teamId, $group['teamIds'], true);
            if ($pos !== false) {
                $currentGroupIdx = $gIdx;
                $foundTeam = $group['teamIds'][$pos];
                
                // Rimuovi dal girone attuale
                unset($group['teamIds'][$pos]);
                $group['teamIds'] = array_values($group['teamIds']);
                break;
            }
        }

        if ($currentGroupIdx === null || !$foundTeam) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata in nessun girone']);
        }

        if ($currentGroupIdx === $newGroupIdx) {
            jsonResponse(400, ['ok' => false, 'error' => 'La squadra è già in questo girone']);
        }

        // Aggiungi al nuovo girone
        if (!isset($groups[$newGroupIdx])) {
            jsonResponse(404, ['ok' => false, 'error' => "Girone $newGroupLabel non trovato"]);
        }

        $groups[$newGroupIdx]['teamIds'][] = $teamId;

        // Rigenerare le partite della fase
        $newMatches = [];
        $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        
        foreach ($groups as $gIdx => $group) {
            $teamIds = $group['teamIds'] ?? [];
            if (count($teamIds) < 2) continue;
            
            $groupName = $groupNames[$gIdx] ?? 'G';
            for ($i = 0; $i < count($teamIds) - 1; $i++) {
                for ($j = $i + 1; $j < count($teamIds); $j++) {
                    $newMatches[] = [
                        'id' => uid(),
                        'group' => $groupName,
                        'team1Id' => $teamIds[$i],
                        'team2Id' => $teamIds[$j],
                        'score1' => null,
                        'score2' => null
                    ];
                }
            }
        }

        $phase['matches'] = $newMatches;

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_update_group_match' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);

    if( $phaseNumber < 1 || !$id ) {
        jsonResponse(422, ['ok' => false, 'error' => 'Parametri non validi di PhaseNumber o Match ID']);
        return;
    }

    withStateTransaction(function (&$state) use ($body, $id, $phaseNumber) {
        // 🔧 FIX: Accedi alla fase PER REFERENCE direttamente da $state['phases']
        ensurePhases($state);
        
        $phaseIdx = array_search(
            $phaseNumber,
            array_column($state['phases'], 'phaseNumber'),
            true
        );

        if ($phaseIdx === false) {
            return ['ok' => false, 'error' => "Fase {$phaseNumber} non trovata"];
        }

        $currentPhase = &$state['phases'][$phaseIdx];

        if (empty($currentPhase['matches'])) {
            return ['ok' => false, 'error' => "Nessuna partita trovata in fase {$phaseNumber}"];
        }
        
        // Ricerca e aggiorna il match per reference
        $matchFound = false;
        foreach ($currentPhase['matches'] as &$m) {
            if ($m['id'] !== $id) continue;
            
            $matchFound = true;
            
            // 🆕 Stessa tecnica real-time del knockout: se arriva un array 'sets',
            // l'ultimo elemento è il set in corso (score1/score2 live), i precedenti
            // sono i set conclusi. Sostituisce l'aggiornamento diretto di score1/score2
            // quando la partita è multi-set (matchRules.numSets > 1 anche nei gironi).
            if (array_key_exists('sets', $body) && is_array($body['sets'])) {
                $sets = $body['sets'];
                $m['sets'] = $sets;
                if (isset($body['team1Timeouts'])) $m['team1Timeouts'] = (int)$body['team1Timeouts'];
                if (isset($body['team2Timeouts'])) $m['team2Timeouts'] = (int)$body['team2Timeouts'];
                if (!empty($sets)) {
                    $lastSet = $sets[count($sets) - 1];
                    $m['score1'] = $lastSet['team1'] ?? 0;
                    $m['score2'] = $lastSet['team2'] ?? 0;
                } else {
                    $m['score1'] = null;
                    $m['score2'] = null;
                }
                $m['updatedAt'] = date('c');
            } else {
                // Aggiorna i campi della partita (formato legacy a set singolo)
                if (array_key_exists('score1', $body)) {
                    $m['score1'] = is_null($body['score1']) ? null : (int)$body['score1'];
                }
                if (array_key_exists('score2', $body)) {
                    $m['score2'] = is_null($body['score2']) ? null : (int)$body['score2'];
                }
            }
            if (isset($body['time'])) {
                $m['time'] = trim((string)$body['time']);
            }
            if (isset($body['day'])) {
                $m['day'] = (int)$body['day'];
            }
            // Nuovi campi per gestione slot
            if (isset($body['startTime'])) {
                $m['startTime'] = $body['startTime'] === null ? null : (string)$body['startTime'];
            }
            if (isset($body['endTime'])) {
                $m['endTime'] = $body['endTime'] === null ? null : (string)$body['endTime'];
            }
            if (isset($body['courtIdx'])) {
                $m['courtIdx'] = $body['courtIdx'] === null ? null : (int)$body['courtIdx'];
            }
            if (isset($body['dateIdx'])) {
                $m['dateIdx'] = $body['dateIdx'] === null ? null : (int)$body['dateIdx'];
            }
            if (isset($body['slotIdx'])) {
                $m['slotIdx'] = $body['slotIdx'] === null ? null : (int)$body['slotIdx'];
            }
            if (isset($body['date'])) {
                $m['date'] = $body['date'] === null ? null : (string)$body['date'];
            }
            if (isset($body['courtName'])) {
                $m['courtName'] = $body['courtName'] === null ? null : (string)$body['courtName'];
            }
            
            error_log("✅ Match {$id} aggiornato in fase {$phaseNumber}");
            break;
        }
        unset($m);

        if (!$matchFound) {
            return ['ok' => false, 'error' => "Match {$id} non trovato in fase {$phaseNumber}"];
        }

        // 🆕 Se questa fase è un castello (eliminazione diretta), fa avanzare
        // automaticamente il vincitore (e l'eventuale perdente di semifinale
        // verso la finalina 3°/4° posto) nei turni successivi.
        advanceKnockoutBracket($currentPhase);

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

// 🆕 Scambia lo slot (data/ora/campo) tra due partite della stessa fase.
// Usato dal drag&drop nel calendario partite in admin.html: la squadra/incontro
// resta la stessa, cambia solo quando/dove si gioca.
if ($action === 'admin_swap_match_slots' && $method === 'POST') {
    $body = bodyJson();
    $matchIdA = (string)($body['matchIdA'] ?? '');
    $matchIdB = (string)($body['matchIdB'] ?? '');
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);

    if (!$matchIdA || !$matchIdB || $phaseNumber < 1) {
        jsonResponse(422, ['ok' => false, 'error' => 'Parametri non validi']);
        return;
    }

    if ($matchIdA === $matchIdB) {
        jsonResponse(400, ['ok' => false, 'error' => 'Seleziona due partite diverse']);
        return;
    }

    $result = withStateTransaction(function (&$state) use ($matchIdA, $matchIdB, $phaseNumber) {
        ensurePhases($state);

        $phaseIdx = array_search($phaseNumber, array_column($state['phases'], 'phaseNumber'), true);
        if ($phaseIdx === false) {
            return ['ok' => false, 'error' => "Fase {$phaseNumber} non trovata"];
        }

        $currentPhase = &$state['phases'][$phaseIdx];

        if (empty($currentPhase['matches'])) {
            return ['ok' => false, 'error' => 'Nessuna partita trovata in questa fase'];
        }

        // Campi che identificano "quando/dove" si gioca una partita.
        // Il resto (squadre, punteggi, girone) resta invariato: si scambia solo lo slot.
        $slotFields = ['date', 'startTime', 'endTime', 'courtIdx', 'dateIdx', 'slotIdx', 'courtName', 'time'];

        $idxA = null;
        $idxB = null;
        foreach ($currentPhase['matches'] as $idx => $m) {
            if (($m['id'] ?? null) === $matchIdA) $idxA = $idx;
            if (($m['id'] ?? null) === $matchIdB) $idxB = $idx;
        }

        if ($idxA === null || $idxB === null) {
            return ['ok' => false, 'error' => 'Una delle due partite non è stata trovata in questa fase'];
        }

        $slotA = [];
        $slotB = [];
        foreach ($slotFields as $f) {
            $slotA[$f] = $currentPhase['matches'][$idxA][$f] ?? null;
            $slotB[$f] = $currentPhase['matches'][$idxB][$f] ?? null;
        }

        foreach ($slotFields as $f) {
            $currentPhase['matches'][$idxA][$f] = $slotB[$f];
            $currentPhase['matches'][$idxB][$f] = $slotA[$f];
        }

        return ['ok' => true];
    });

    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }

    jsonResponse(200, ['ok' => true]);
}

// 🆕 Assegna automaticamente data/ora/campo alle partite di una fase che non ne
// hanno ancora una, usando le fasce orarie configurate in Impostazioni → Calendario.
// Rispetta gli slot già occupati da QUALSIASI partita di QUALSIASI fase (non solo
// quella corrente), quindi non genera mai doppie prenotazioni. Le partite "bye"
// (nessun avversario) vengono saltate, dato che non si giocano davvero.
if ($action === 'admin_auto_schedule_phase' && $method === 'POST') {
    $body = bodyJson();
    $phaseNumber = (int)($body['phaseNumber'] ?? 0);
    $filterDates = $body['dates'] ?? [];      // Date selezionate dall'utente
    $filterSlots = $body['timeSlots'] ?? [];  // Fasce selezionate dall'utente

    if ($phaseNumber < 1) {
        jsonResponse(422, ['ok' => false, 'error' => 'phaseNumber richiesto']);
        return;
    }

    $result = withStateTransaction(function (&$state) use ($phaseNumber, $filterDates, $filterSlots) {
        ensurePhases($state);

        $config = readConfig();
        $courts = $config['schedule']['courts'] ?? [];

        if (empty($courts)) {
            return ['ok' => false, 'error' => 'Nessun campo/orario configurato. Vai in Impostazioni → Calendario e aggiungi disponibilità.'];
        }

        // Durata di una partita, per suddividere le fasce ampie (es. 19:30-23:00) in
        // più slot prenotabili distinti, invece di trattarle come UN unico slot.
        // 🔧 Usa le regole specifiche della fase (matchRules) se configurate,
        // altrimenti ricade sui default del torneo (vedi resolvePhaseMatchRules).
        $configPhaseForRules = null;
        foreach (($config['phases'] ?? []) as $cp) {
            if (($cp['phaseNumber'] ?? null) === $phaseNumber) { $configPhaseForRules = $cp; break; }
        }
        $phaseRules = resolvePhaseMatchRules($config['tournament'] ?? [], $configPhaseForRules);
        $setupMin = $phaseRules['setupTimeMinutes'];
        $perSetMin = $phaseRules['timePerSetMinutes'];
        $numSets = max(1, $phaseRules['numSets']);
        $matchDuration = max(5, $setupMin + ($perSetMin * $numSets));

        $timeToMinutes = function (?string $t): ?int {
            if (!$t || !str_contains($t, ':')) return null;
            [$h, $m] = array_map('intval', explode(':', $t));
            return $h * 60 + $m;
        };
        $minutesToTime = function (int $mins): string {
            $mins = ((int)$mins) % (24 * 60);
            return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
        };

        // 🔧 FIX: prima ogni fascia configurata (es. 19:30-23:00) veniva trattata come
        // UN SOLO slot prenotabile, quindi bastava una partita per "esaurirla" anche se
        // durava 3 ore e mezza. Qui la dividiamo in tanti slot quanti ce ne stanno in base
        // alla durata reale di una partita, ciascuno con il proprio orario di inizio/fine.
        $allSlots = [];
        foreach ($courts as $courtIdx => $court) {
            foreach (($court['availability'] ?? []) as $dateIdx => $avail) {
                foreach (($avail['timeSlots'] ?? []) as $rangeIdx => $ts) {
                    $startMin = $timeToMinutes($ts['startTime'] ?? null);
                    $endMin = $timeToMinutes($ts['endTime'] ?? null);
                    if ($startMin === null || $endMin === null || $endMin <= $startMin) continue;

                    $subCount = max(1, intdiv($endMin - $startMin, $matchDuration));
                    // 🔧 FIX: il frontend seleziona ("filterSlots") l'etichetta della fascia
                    // GREZZA così come configurata (es. "19:30-24:00"), non il singolo
                    // sotto-slot da {matchDuration} minuti generato qui sotto. Confrontare
                    // il filtro con il sotto-slot (es. "19:30-19:55") faceva scartare TUTTI
                    // gli slot di ogni fascia più lunga di una singola partita, azzerando
                    // silenziosamente lo scheduling per qualunque fascia "ampia".
                    $rangeLabel = ($ts['startTime'] ?? '') . '-' . ($ts['endTime'] ?? '');
                    for ($sub = 0; $sub < $subCount; $sub++) {
                        $subStart = $startMin + ($sub * $matchDuration);
                        $subEnd = $subStart + $matchDuration;
                        
                        // 🔧 FILTRO: se l'utente ha selezionato date/slot specifici, scarta gli altri
                        if (!empty($filterDates) && !in_array($avail['date'] ?? null, $filterDates, true)) continue;
                        if (!empty($filterSlots) && !in_array($rangeLabel, $filterSlots, true)) continue;
                        
                        $allSlots[] = [
                            'courtIdx' => $courtIdx, 'dateIdx' => $dateIdx, 'slotIdx' => $rangeIdx,
                            'date' => $avail['date'] ?? null,
                            'startTime' => $minutesToTime($subStart), 'endTime' => $minutesToTime($subEnd),
                            'courtName' => $court['courtName'] ?? ('Campo ' . ($courtIdx + 1))
                        ];
                    }
                }
            }
        }
        usort($allSlots, function ($a, $b) {
            $da = $a['date'] ?? ''; $db = $b['date'] ?? '';
            if ($da !== $db) return strcmp($da, $db);
            return strcmp($a['startTime'] ?? '', $b['startTime'] ?? '');
        });

        // Segna come occupati gli INTERVALLI orari già usati da qualsiasi
        // partita di qualsiasi ALTRA fase, per campo e data.
        // 🔧 IMPORTANTE: NON includiamo le partite della FASE CORRENTE, così
        // i Quarti e Semifinali della stessa fase possono competere per gli stessi
        // slot. Questo assicura che le partite vengono assegnate in ordine di
        // processing (primo turno prima), non per occupazione preesistente.
        $occupiedIntervals = []; // "courtIdx_date" => [[startMin, endMin], ...]
        foreach ($state['phases'] as $ph) {
            // Salta la fase corrente: le sue partite competono tra loro
            if (($ph['phaseNumber'] ?? null) === $phaseNumber) continue;
            
            foreach (($ph['matches'] ?? []) as $m) {
                if (isset($m['courtIdx']) && !empty($m['date']) && !empty($m['startTime']) && !empty($m['endTime'])) {
                    $s = $timeToMinutes($m['startTime']);
                    $e = $timeToMinutes($m['endTime']);
                    if ($s === null || $e === null) continue;
                    $ckey = $m['courtIdx'] . '_' . $m['date'];
                    $occupiedIntervals[$ckey][] = [$s, $e];
                }
            }
        }
        $slotOverlapsOccupied = function (string $ckey, int $startMin, int $endMin) use (&$occupiedIntervals): bool {
            foreach (($occupiedIntervals[$ckey] ?? []) as $interval) {
                [$s, $e] = $interval;
                if ($startMin < $e && $s < $endMin) return true; // si sovrappongono
            }
            return false;
        };

        $phaseIdx = array_search($phaseNumber, array_column($state['phases'], 'phaseNumber'), true);
        if ($phaseIdx === false) {
            return ['ok' => false, 'error' => "Fase {$phaseNumber} non trovata"];
        }
        $currentPhase = &$state['phases'][$phaseIdx];

        // 🔧 Riposo squadre: chi ha giocato l'ultima partita più tempo fa ha la
        // priorità sugli slot successivi, così nessuna squadra gioca tante
        // partite di fila senza pause — stesso criterio già usato per generare
        // automaticamente i gironi in scheduleMatches(), ora applicato anche
        // qui (fasi successive alla prima incluse). Le date/ore sono convertite
        // in minuti assoluti (con strtotime) così il confronto resta corretto
        // anche tra giorni diversi, non solo all'interno dello stesso giorno.
        $absMinutes = function (string $date, string $time): int {
            return intdiv(strtotime("$date $time"), 60);
        };
        $teamBusyIntervals = []; // teamId => [[startAbsMin, endAbsMin], ...]
        $teamLastEndAbs = [];    // teamId => orario abs di fine dell'ultima partita nota
        foreach ($state['phases'] as $ph) {
            foreach (($ph['matches'] ?? []) as $m) {
                if (empty($m['date']) || empty($m['startTime']) || empty($m['endTime'])) continue;
                $s = $absMinutes($m['date'], $m['startTime']);
                $e = $absMinutes($m['date'], $m['endTime']);
                foreach ([$m['team1'] ?? $m['team1Id'] ?? null, $m['team2'] ?? $m['team2Id'] ?? null] as $tid) {
                    if (!$tid) continue;
                    $teamBusyIntervals[$tid][] = [$s, $e];
                    if (!isset($teamLastEndAbs[$tid]) || $e > $teamLastEndAbs[$tid]) $teamLastEndAbs[$tid] = $e;
                }
            }
        }
        $teamOverlapsBusy = function ($teamId, int $startAbs, int $endAbs) use (&$teamBusyIntervals): bool {
            if (!$teamId) return false;
            foreach (($teamBusyIntervals[$teamId] ?? []) as [$s, $e]) {
                if ($startAbs < $e && $s < $endAbs) return true;
            }
            return false;
        };

        $scheduled = 0;
        $skippedByes = 0;

        // Partite ancora da schedulare in questa fase (bye e già-schedulate escluse)
        $pendingMatches = [];
        foreach ($currentPhase['matches'] as $idx => $m) {
            if (!empty($m['bye'])) { $skippedByes++; continue; }
            if (!empty($m['date'])) continue;
            $pendingMatches[] = ['idx' => $idx, 'match' => $m];
        }

        if (empty($pendingMatches)) {
            // Nessuna partita da schedulare
            return [
                'ok' => true,
                'scheduled' => 0,
                'skippedByes' => $skippedByes,
                'totalMatches' => count($currentPhase['matches'])
            ];
        }

        error_log("📅 admin_auto_schedule_phase: Fase {$phaseNumber} (tipo={$currentPhase['type']}), " . count($pendingMatches) . " partite da schedulare, " . count($allSlots) . " slot disponibili");

        // 🔧 LOGICA SEMPLIFICATA PER KNOCKOUT:
        // Assegna sequenzialmente: match[0]→slot[0], match[1]→slot[1], etc.
        if ($currentPhase['type'] === 'knockout') {
            error_log("⚡ KNOCKOUT: assegnazione sequenziale semplificata");
            
            $slotCount = count($allSlots);
            foreach ($pendingMatches as $matchPos => $item) {
                if ($matchPos >= $slotCount) {
                    error_log("⚠️ KNOCKOUT: non ci sono abbastanza slot ({$slotCount}) per le " . count($pendingMatches) . " partite rimaste");
                    break;
                }
                
                $slot = $allSlots[$matchPos];
                $idx = $item['idx'];
                
                $currentPhase['matches'][$idx]['date'] = $slot['date'];
                $currentPhase['matches'][$idx]['startTime'] = $slot['startTime'];
                $currentPhase['matches'][$idx]['endTime'] = $slot['endTime'];
                $currentPhase['matches'][$idx]['courtIdx'] = $slot['courtIdx'];
                $currentPhase['matches'][$idx]['dateIdx'] = $slot['dateIdx'];
                $currentPhase['matches'][$idx]['slotIdx'] = $slot['slotIdx'];
                $currentPhase['matches'][$idx]['courtName'] = $slot['courtName'];
                $currentPhase['matches'][$idx]['time'] = $slot['startTime'];
                
                $scheduled++;
                error_log("  ✅ {$item['match']['label']}: {$slot['date']} {$slot['startTime']}-{$slot['endTime']} @{$slot['courtName']}");
            }
        } else {
            // LOGICA PER GIRONI: rispetta vincoli di riposo squadre
            error_log("📚 GIRONI: assegnazione con vincoli di riposo squadre");
            
            $timeToMinutes = function (?string $t): ?int {
                if (!$t || !str_contains($t, ':')) return null;
                [$h, $m] = array_map('intval', explode(':', $t));
                return $h * 60 + $m;
            };
            $absMinutes = function (string $date, string $time): int {
                return intdiv(strtotime("$date $time"), 60);
            };
            
            $occupiedIntervals = [];
            foreach ($state['phases'] as $ph) {
                if (($ph['phaseNumber'] ?? null) === $phaseNumber) continue;
                foreach (($ph['matches'] ?? []) as $m) {
                    if (isset($m['courtIdx']) && !empty($m['date']) && !empty($m['startTime']) && !empty($m['endTime'])) {
                        $s = $timeToMinutes($m['startTime']);
                        $e = $timeToMinutes($m['endTime']);
                        if ($s === null || $e === null) continue;
                        $ckey = $m['courtIdx'] . '_' . $m['date'];
                        $occupiedIntervals[$ckey][] = [$s, $e];
                    }
                }
            }
            $slotOverlapsOccupied = function (string $ckey, int $startMin, int $endMin) use (&$occupiedIntervals): bool {
                foreach (($occupiedIntervals[$ckey] ?? []) as $interval) {
                    [$s, $e] = $interval;
                    if ($startMin < $e && $s < $endMin) return true;
                }
                return false;
            };

            $teamBusyIntervals = [];
            $teamLastEndAbs = [];
            foreach ($state['phases'] as $ph) {
                foreach (($ph['matches'] ?? []) as $m) {
                    if (empty($m['date']) || empty($m['startTime']) || empty($m['endTime'])) continue;
                    $s = $absMinutes($m['date'], $m['startTime']);
                    $e = $absMinutes($m['date'], $m['endTime']);
                    foreach ([$m['team1'] ?? $m['team1Id'] ?? null, $m['team2'] ?? $m['team2Id'] ?? null] as $tid) {
                        if (!$tid) continue;
                        $teamBusyIntervals[$tid][] = [$s, $e];
                        if (!isset($teamLastEndAbs[$tid]) || $e > $teamLastEndAbs[$tid]) $teamLastEndAbs[$tid] = $e;
                    }
                }
            }
            $teamOverlapsBusy = function ($teamId, int $startAbs, int $endAbs) use (&$teamBusyIntervals): bool {
                if (!$teamId) return false;
                foreach (($teamBusyIntervals[$teamId] ?? []) as [$s, $e]) {
                    if ($startAbs < $e && $s < $endAbs) return true;
                }
                return false;
            };

            $pendingIdx = array_column($pendingMatches, 'idx');
            
            foreach ($allSlots as $slot) {
                if (empty($pendingIdx)) break;

                $ckey = $slot['courtIdx'] . '_' . $slot['date'];
                $sMin = $timeToMinutes($slot['startTime']);
                $eMin = $timeToMinutes($slot['endTime']);
                if ($sMin === null || $eMin === null) continue;
                
                if ($slotOverlapsOccupied($ckey, $sMin, $eMin)) continue;

                $slotStartAbs = $absMinutes($slot['date'], $slot['startTime']);
                $slotEndAbs = $absMinutes($slot['date'], $slot['endTime']);

                $bestPos = null;
                $bestScore = -INF;
                foreach ($pendingIdx as $pos => $idx) {
                    $m = $currentPhase['matches'][$idx];
                    $t1 = $m['team1'] ?? $m['team1Id'] ?? null;
                    $t2 = $m['team2'] ?? $m['team2Id'] ?? null;

                    if ($teamOverlapsBusy($t1, $slotStartAbs, $slotEndAbs) || $teamOverlapsBusy($t2, $slotStartAbs, $slotEndAbs)) continue;

                    $gap1 = isset($teamLastEndAbs[$t1]) ? ($slotStartAbs - $teamLastEndAbs[$t1]) : PHP_INT_MAX;
                    $gap2 = isset($teamLastEndAbs[$t2]) ? ($slotStartAbs - $teamLastEndAbs[$t2]) : PHP_INT_MAX;
                    $score = min($gap1, $gap2);

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestPos = $pos;
                    }
                }

                if ($bestPos === null) continue;

                $idx = $pendingIdx[$bestPos];
                array_splice($pendingIdx, $bestPos, 1);

                $currentPhase['matches'][$idx]['date'] = $slot['date'];
                $currentPhase['matches'][$idx]['startTime'] = $slot['startTime'];
                $currentPhase['matches'][$idx]['endTime'] = $slot['endTime'];
                $currentPhase['matches'][$idx]['courtIdx'] = $slot['courtIdx'];
                $currentPhase['matches'][$idx]['dateIdx'] = $slot['dateIdx'];
                $currentPhase['matches'][$idx]['slotIdx'] = $slot['slotIdx'];
                $currentPhase['matches'][$idx]['courtName'] = $slot['courtName'];
                $currentPhase['matches'][$idx]['time'] = $slot['startTime'];

                $occupiedIntervals[$ckey][] = [$sMin, $eMin];
                $t1 = $currentPhase['matches'][$idx]['team1'] ?? $currentPhase['matches'][$idx]['team1Id'] ?? null;
                $t2 = $currentPhase['matches'][$idx]['team2'] ?? $currentPhase['matches'][$idx]['team2Id'] ?? null;
                foreach ([$t1, $t2] as $tid) {
                    if (!$tid) continue;
                    $teamBusyIntervals[$tid][] = [$slotStartAbs, $slotEndAbs];
                    $teamLastEndAbs[$tid] = $slotEndAbs;
                }

                $scheduled++;
            }
        }

        return [
            'ok' => true,
            'scheduled' => $scheduled,
            'skippedByes' => $skippedByes,
            'totalMatches' => count($currentPhase['matches'])
        ];
    });

    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }

    jsonResponse(200, $result);
}

if ($action === 'admin_create_playoff' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (!createPlayoff($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Playoff non generabile: servono almeno 8 squadre classificate']);
        }
        
        // ✅ REFACTORED: createPlayoff() ha già creato e inizializzato la fase di playoff
        error_log('✅ Fase di Playoff creata e attivata');
        
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

// 🆕 MOTORE GENERICO: crea una fase successiva (gironi o castello) con le
// squadre REALI qualificate/eliminate di una fase precedente, oppure ripescando
// tutte le squadre iscritte al torneo ("nuovo torneo").
//
// POST body:
//   targetPhaseNumber   int      numero della nuova fase (es. 2, 3...)
//   name                string   nome della fase (es. "Ottavi - Vincenti Girone")
//   type                string   'groups' | 'knockout'
//   teamSource          string   'phase' | 'registration'
//   sourcePhaseNumber   int      richiesto se teamSource='phase'
//   sourceBranch        string   'qualified' | 'eliminated', richiesto se teamSource='phase'
//   teamsAdvancePerGroup int     richiesto se la fase SORGENTE è di tipo 'groups' (default 2)
//   numGroups           int      richiesto se type='groups'
//   teamsAdvance        int      richiesto se type='groups' (per il PROSSIMO collegamento)
if ($action === 'admin_create_phase_from_source' && $method === 'POST') {
    $body = bodyJson();
    $targetPhaseNumber = (int)($body['targetPhaseNumber'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $type = (string)($body['type'] ?? '');
    $teamSource = (string)($body['teamSource'] ?? 'phase');
    $sourcePhaseNumber = (int)($body['sourcePhaseNumber'] ?? 0);
    $sourceBranch = (string)($body['sourceBranch'] ?? 'qualified');
    $sortCriterion = (int)($body['sortCriterion'] ?? 1); // 1=media, 2=%, 3=media+quoziente+diff
    // 🔧 Compatibilità con config.json: "teamsAdvance" può essere un numero singolo
    // (stesso numero di qualificati per ogni girone) oppure una stringa tipo "2,1,2,2"
    // (numero diverso per ogni girone, come già usato nella Fase 1 esistente).
    $teamsAdvanceRaw = $body['teamsAdvancePerGroup'] ?? 2;
    if (is_string($teamsAdvanceRaw) && str_contains($teamsAdvanceRaw, ',')) {
        $teamsAdvancePerGroup = array_map('intval', array_map('trim', explode(',', $teamsAdvanceRaw)));
    } else {
        $teamsAdvancePerGroup = (int)$teamsAdvanceRaw;
    }
    $numGroups = (int)($body['numGroups'] ?? 2);
    // 🔧 Flag per evitare rematch dello stesso girone nel knockout
    $avoidGroupRematches = !empty($body['avoidGroupRematches']);
    
    // 🆕 Flag per generare il match di 3°/4° posto durante avanzamento knockout
    $includeThirdPlace = !empty($body['includeThirdPlace']);
    
    // 🆕 Permette di "Ricalcolare" una fase già esistente (rigenerarla da zero con
    // gli stessi parametri, es. dopo aver corretto le squadre-che-avanzano-per-girone),
    // invece di dover cancellare tutto a mano.
    $overwrite = !empty($body['overwrite']);

    if ($targetPhaseNumber < 1 || !in_array($type, ['groups', 'knockout'], true)) {
        jsonResponse(422, ['ok' => false, 'error' => 'Parametri non validi (targetPhaseNumber/type)']);
        return;
    }
    if (!in_array($teamSource, ['phase', 'registration'], true)) {
        jsonResponse(422, ['ok' => false, 'error' => "teamSource non valido: usare 'phase' o 'registration'"]);
        return;
    }
    if ($teamSource === 'phase' && (!in_array($sourceBranch, ['qualified', 'eliminated'], true) || $sourcePhaseNumber < 1)) {
        jsonResponse(422, ['ok' => false, 'error' => 'sourcePhaseNumber/sourceBranch richiesti quando teamSource=phase']);
        return;
    }

    // 🔧 FIX: Leggi le matchRules PRIMA della closure, così sono disponibili
    // sia dentro (per la nuova fase nello state) che fuori (per il config.json)
    $preservedMatchRules = null;
    if ($overwrite) {
        $cfg = readConfig();
        $existingConfigPhase = null;
        foreach ($cfg['phases'] ?? [] as $cp) {
            if (($cp['phaseNumber'] ?? null) === $targetPhaseNumber && !empty($cp['matchRules'])) {
                $existingConfigPhase = $cp;
                break;
            }
        }
        if ($existingConfigPhase && !empty($existingConfigPhase['matchRules'])) {
            $preservedMatchRules = $existingConfigPhase['matchRules'];
            error_log("🔧 Preserving matchRules for phase $targetPhaseNumber: " . json_encode($preservedMatchRules));
        }
    }

    $result = withStateTransaction(function (&$state) use ($targetPhaseNumber, $name, $type, $teamSource, $sourcePhaseNumber, $sourceBranch, $sortCriterion, $teamsAdvancePerGroup, $numGroups, $avoidGroupRematches, $includeThirdPlace, $overwrite, $preservedMatchRules) {
        ensurePhases($state);

        $existingIdx = array_search($targetPhaseNumber, array_column($state['phases'], 'phaseNumber'), true);
        if ($existingIdx !== false) {
            if (!$overwrite) {
                return ['ok' => false, 'error' => "La fase {$targetPhaseNumber} esiste già"];
            }
            // 🆕 Ricalcolo: rimuove la fase esistente per rigenerarla da zero con i
            // parametri (eventualmente corretti) appena inviati.
            array_splice($state['phases'], $existingIdx, 1);
        }

        // 1) Risolvi l'elenco REALE di teamId per questa nuova fase
        $teamIds = [];
        if ($teamSource === 'registration') {
            // "Nuovo torneo": tutte le squadre iscritte (approvate), a prescindere da qualsiasi fase precedente
            $teamIds = array_values(array_map(fn($t) => $t['id'], approvedTeams($state)));
        } else {
            // 🔧 FIX: Quando estrai gli eliminati/qualificati DALLA FASE SORGENTE,
            // NON usare il valore "teamsAdvance" della NUOVA fase (che è destinato
            // alla prossima fase), ma il valore della FASE SORGENTE stessa.
            // Es: per gli eliminati della Fase 1 (2,3,3), usa "2,3,3" per calcolare,
            // non il "3" che l'utente ha messo per la Fase 3.
            $teamsAdvanceForCalculation = $teamsAdvancePerGroup;
            if ($sourceBranch === 'eliminated' || $sourceBranch === 'qualified') {
                // Leggi il valore della fase sorgente dal config
                $cfg = readConfig();
                $sourceConfigPhase = null;
                foreach ($cfg['phases'] ?? [] as $cp) {
                    if (($cp['phaseNumber'] ?? null) === $sourcePhaseNumber) {
                        $sourceConfigPhase = $cp;
                        break;
                    }
                }
                if ($sourceConfigPhase && !empty($sourceConfigPhase['teamsAdvance'])) {
                    $teamsAdvanceForCalculation = $sourceConfigPhase['teamsAdvance'];
                }
                error_log("🔍 DEBUG admin_create_phase: sourceBranch=$sourceBranch, teamsAdvancePerGroup=" . json_encode($teamsAdvancePerGroup) . ", teamsAdvanceForCalculation=" . json_encode($teamsAdvanceForCalculation));
            }
            
            $branchResult = getTeamsFromPhaseBranch($state, $sourcePhaseNumber, $teamsAdvanceForCalculation, $sortCriterion);
            error_log("🔍 DEBUG admin_create_phase: branchResult qualified=" . count($branchResult['qualified'] ?? []) . ", eliminated=" . count($branchResult['eliminated'] ?? []) . ", complete=" . ($branchResult['complete'] ? 'true' : 'false') . ", sortCriterion=$sortCriterion");
            if (!empty($branchResult['error'])) {
                return ['ok' => false, 'error' => $branchResult['error']];
            }
            // 🔧 FIX: NON richiedere che la fase sorgente sia completa quando si CREA
            // una nuova fase. L'utente può creare la Fase 3 anche prima che la Fase 1
            // abbia tutti i risultati. Se serve, farà un "Ricalcola" dopo.
            // Questo permette di strutturare il torneo in anticipo e poi progressivamente
            // inserire i risultati via via che le partite si concludono.
            // if (!$branchResult['complete']) {
            //     return ['ok' => false, 'error' => "La fase {$sourcePhaseNumber} non ha ancora tutti i risultati necessari per calcolare le squadre {$sourceBranch}. Completa prima tutte le partite."];
            // }
            $teamIds = $branchResult[$sourceBranch] ?? [];
            error_log("🔍 DEBUG admin_create_phase: Using branch=$sourceBranch, teamIds count=" . count($teamIds));
        }

        if (count($teamIds) < 2) {
            return ['ok' => false, 'error' => 'Servono almeno 2 squadre per creare la fase (trovate: ' . count($teamIds) . ')'];
        }

        $teamMap = getTeamMap($state);
        $teams = [];
        foreach ($teamIds as $tid) {
            if (isset($teamMap[$tid])) $teams[] = $teamMap[$tid];
        }

        // 2) Crea la struttura della fase in base al tipo scelto
        // 🔧 FIX: 'phaseIdx' deve essere un puro alias di 'phaseNumber' (come fa
        // initializePhase() e come lo trattano tutte le ricerche lato frontend,
        // es. `.find(p => p.phaseNumber === X || p.phaseIdx === X)`). Prima veniva
        // calcolato come count($state['phases']) — la posizione nell'array al
        // momento della creazione — che NON coincide con phaseNumber se le fasi
        // non vengono create in ordine stretto 1,2,3... (es. generando prima la
        // fase 3 e poi la fase 2, o rigenerando una fase). Quando divergeva, la
        // ricerca per fallback su phaseIdx poteva far trovare la fase sbagliata
        // (es. selezionando "Fase 2" si apriva il modale della Fase 3).
        $newPhase = [
            'id' => 'phase_' . uid(),
            'phaseIdx' => $targetPhaseNumber,
            'phaseNumber' => $targetPhaseNumber,
            'name' => $name ?: ("Fase {$targetPhaseNumber}"),
            'type' => $type,
            'status' => 'pending',
            'sourcePhaseNumber' => $teamSource === 'phase' ? $sourcePhaseNumber : null,
            'sourceBranch' => $teamSource === 'phase' ? $sourceBranch : null,
            'teamSource' => $teamSource,
            'matches' => [],
            'groups' => [],
            'standings' => [],
            'createdAt' => gmdate('c')
        ];
        
        // 🔧 FIX: Se stiamo ricalcolando una fase e abbiamo salvato le matchRules,
        // ripristinale nella nuova fase. Altrimenti anderebbero perse.
        if (!empty($preservedMatchRules)) {
            $newPhase['matchRules'] = $preservedMatchRules;
            error_log("✅ Restored matchRules for phase $targetPhaseNumber");
        }

        if ($type === 'groups') {
            $groupCount = max(1, $numGroups);
            $groups = balancedGroupDistribution($teams, $groupCount);

            // 🔧 EFFICIENZA: salviamo solo 'teamIds' (leggeri), NON l'intero oggetto
            // squadra duplicato (giocatori, telefono, ecc.) come faceva finora la
            // generazione della Fase 1. admin_state già ricostruisce 'teams' al volo
            // da 'teamIds' con normalizeGroups() quando serve al frontend, quindi
            // l'interfaccia non cambia comportamento — cambia solo cosa sta salvato
            // su disco. Ogni fase successiva NON duplica più tutti i dati squadra.
            foreach ($groups as &$group) {
                if (!isset($group['teamIds'])) {
                    $group['teamIds'] = array_map(fn($t) => $t['id'] ?? null, $group['teams'] ?? []);
                }
                unset($group['teams']);
            }
            unset($group);
            $newPhase['groups'] = $groups;

            // Genera le partite round-robin (senza slot orario: verranno assegnati
            // dall'admin con il pulsante "📅 Slot" già esistente in Partite e orari)
            $matches = [];
            foreach ($groups as $group) {
                $groupTeamIds = $group['teamIds'] ?? [];
                $count = count($groupTeamIds);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $matches[] = [
                            'id' => uid(),
                            'groupName' => $group['label'] ?? $group['name'] ?? '',
                            'team1' => $groupTeamIds[$i], 'team1Id' => $groupTeamIds[$i],
                            'team2' => $groupTeamIds[$j], 'team2Id' => $groupTeamIds[$j],
                            'team1Name' => $teamMap[$groupTeamIds[$i]]['name'] ?? '',
                            'team2Name' => $teamMap[$groupTeamIds[$j]]['name'] ?? '',
                            'score1' => null, 'score2' => null,
                            'date' => null, 'startTime' => null, 'endTime' => null
                        ];
                    }
                }
            }
            $newPhase['matches'] = $matches;
        } else { // knockout
            // 🔧 Se avoidGroupRematches è attivo e la sorgente è una fase di gruppi,
            // crea una mappatura team -> group per evitare accoppiamenti dello stesso girone
            $teamGroupMap = null;
            if ($avoidGroupRematches && $teamSource === 'phase') {
                $sourcePhase = array_values(array_filter($state['phases'], fn($p) => ($p['phaseNumber'] ?? null) === $sourcePhaseNumber))[0] ?? null;
                if ($sourcePhase && ($sourcePhase['type'] ?? null) === 'groups' && !empty($sourcePhase['groups'])) {
                    $teamGroupMap = [];
                    foreach ($sourcePhase['groups'] as $groupIdx => $group) {
                        $groupId = $group['id'] ?? $group['label'] ?? ('group_' . $groupIdx);
                        foreach ($group['teamIds'] ?? [] as $tid) {
                            $teamGroupMap[$tid] = $groupId;
                        }
                    }
                    error_log("🔧 avoidGroupRematches: mappatura creata per " . count($teamGroupMap) . " squadre");
                }
            }
            
            $matches = generateGenericKnockoutMatches($teams, $teamGroupMap);
            // Aggiungi team1Name/team2Name per comodità di visualizzazione
            foreach ($matches as &$m) {
                if (!empty($m['team1Id'])) $m['team1Name'] = $teamMap[$m['team1Id']]['name'] ?? '';
                if (!empty($m['team2Id'])) $m['team2Name'] = $teamMap[$m['team2Id']]['name'] ?? '';
            }
            unset($m);
            $newPhase['matches'] = $matches;
            
            // 🔧 Salva il flag avoidGroupRematches per eventuale ricalcolo futuro
            $newPhase['avoidGroupRematches'] = $avoidGroupRematches;
            
            // 🆕 Salva il criterio di ordinamento utilizzato per il seeding
            $newPhase['sortCriterion'] = $sortCriterion;
            
            // 🆕 Salva il flag includeThirdPlace per generare il 3°/4° posto
            $newPhase['includeThirdPlace'] = !empty($body['includeThirdPlace']);
            
            // 🆕 Popola gli standings con le squadre ordinate come nel seeding
            // (serve per mostrare il seeding nel scoreboard pubblico)
            if ($teamSource === 'phase') {
                $sourcePhase = array_values(array_filter($state['phases'], fn($p) => ($p['phaseNumber'] ?? null) === $sourcePhaseNumber))[0] ?? null;
                if ($sourcePhase && !empty($sourcePhase['standings'])) {
                    // Raccogli tutte le statistiche delle squadre ordinate
                    $standings = $sourcePhase['standings'];
                    $seededTeams = [];
                    
                    // Estrai le squadre da $standings, ordinate secondo il criterio
                    foreach ($standings as $g) {
                        foreach ($g['rows'] as $row) {
                            if (in_array($row['teamId'], $teamIds, true)) {
                                $seededTeams[] = $row;
                            }
                        }
                    }
                    
                    // Ordina secondo il criterio scelto
                    usort($seededTeams, function($a, $b) use ($sortCriterion) {
                        if ($sortCriterion === 2) {
                            // Opzione 2: % Vittorie
                            $winPercentA = ($a['played'] ?? 0) > 0 ? (($a['points'] ?? 0 / 2) / ($a['played'] ?? 0)) : 0;
                            $winPercentB = ($b['played'] ?? 0) > 0 ? (($b['points'] ?? 0 / 2) / ($b['played'] ?? 0)) : 0;
                            if ($winPercentA !== $winPercentB) return ($winPercentB <=> $winPercentA);
                            if (($b['diff'] ?? 0) !== ($a['diff'] ?? 0)) return (($b['diff'] ?? 0) <=> ($a['diff'] ?? 0));
                            return (($b['points'] ?? 0) <=> ($a['points'] ?? 0));
                        } elseif ($sortCriterion === 3) {
                            // Opzione 3: Media + Quoziente + Diff
                            $mediaA = ($a['played'] ?? 0) > 0 ? ($a['points'] ?? 0 / ($a['played'] ?? 0)) : 0;
                            $mediaB = ($b['played'] ?? 0) > 0 ? ($b['points'] ?? 0 / ($b['played'] ?? 0)) : 0;
                            if ($mediaA !== $mediaB) return ($mediaB <=> $mediaA);
                            $quozienteA = ($a['conceded'] ?? 0) > 0 ? ($a['scored'] ?? 0 / ($a['conceded'] ?? 0)) : 0;
                            $quozienteB = ($b['conceded'] ?? 0) > 0 ? ($b['scored'] ?? 0 / ($b['conceded'] ?? 0)) : 0;
                            if ($quozienteA !== $quozienteB) return ($quozienteB <=> $quozienteA);
                            if (($b['diff'] ?? 0) !== ($a['diff'] ?? 0)) return (($b['diff'] ?? 0) <=> ($a['diff'] ?? 0));
                            return (($b['points'] ?? 0) <=> ($a['points'] ?? 0));
                        } else {
                            // Opzione 1 (default): Media Punti
                            $mediaA = ($a['played'] ?? 0) > 0 ? ($a['points'] ?? 0 / ($a['played'] ?? 0)) : 0;
                            $mediaB = ($b['played'] ?? 0) > 0 ? ($b['points'] ?? 0 / ($b['played'] ?? 0)) : 0;
                            if ($mediaA !== $mediaB) return ($mediaB <=> $mediaA);
                            $diffPerPartitaA = ($a['played'] ?? 0) > 0 ? ($a['diff'] ?? 0 / ($a['played'] ?? 0)) : 0;
                            $diffPerPartitaB = ($b['played'] ?? 0) > 0 ? ($b['diff'] ?? 0 / ($b['played'] ?? 0)) : 0;
                            if ($diffPerPartitaA !== $diffPerPartitaB) return ($diffPerPartitaB <=> $diffPerPartitaA);
                            $pfPerPartitaA = ($a['played'] ?? 0) > 0 ? ($a['scored'] ?? 0 / ($a['played'] ?? 0)) : 0;
                            $pfPerPartitaB = ($b['played'] ?? 0) > 0 ? ($b['scored'] ?? 0 / ($b['played'] ?? 0)) : 0;
                            return ($pfPerPartitaB <=> $pfPerPartitaA);
                        }
                    });
                    
                    // Salva gli standings ordinati
                    $newPhase['standings'] = [[
                        'group' => 'Seeding',
                        'rows' => $seededTeams
                    ]];
                }
            }

            // 🆕 Risolve subito eventuali "bye" (chi non ha avversario passa il turno
            // automaticamente, senza dover aspettare l'inserimento di un punteggio)
            advanceKnockoutBracket($newPhase);
        }

        $state['phases'][] = $newPhase;

        error_log("✅ Fase {$targetPhaseNumber} ('{$name}') creata da sorgente=" . ($teamSource === 'phase' ? "fase {$sourcePhaseNumber}/{$sourceBranch}" : 'registrazione') . ' con ' . count($teams) . ' squadre');

        return ['ok' => true, 'phaseNumber' => $targetPhaseNumber, 'teamsCount' => count($teams)];
    });

    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }

    // 🔧 FIX COMPATIBILITÀ: l'interfaccia esistente (selettore fasi, pannello
    // contenuti) richiede che ogni fase esista ANCHE in config.phases (la lista
    // di "pianificazione" usata dal vecchio wizard), altrimenti mostra "Fase non
    // trovata in configurazione" anche se la fase è stata generata correttamente
    // in tournament.json. Aggiungiamo qui una voce minima corrispondente, senza
    // duplicare i dati reali (squadre/partite restano solo in state.phases).
    $cfg = readConfig();
    if (!isset($cfg['phases']) || !is_array($cfg['phases'])) {
        $cfg['phases'] = [];
    }
    $existingCfgIdx = null;
    foreach ($cfg['phases'] as $cpIdx => $cp) {
        if (($cp['phaseNumber'] ?? null) === $targetPhaseNumber) { $existingCfgIdx = $cpIdx; break; }
    }
    $newCfgEntry = [
        'phaseNumber' => $targetPhaseNumber,
        'name' => $name ?: ("Fase {$targetPhaseNumber}"),
        'type' => $type,
        'branch' => $teamSource === 'phase' ? $sourceBranch : 'root',
        'qualifiedGoTo' => '',
        'eliminatedGoTo' => '',
        'numGroups' => $type === 'groups' ? $numGroups : null,
        'teamsAdvance' => $body['teamsAdvancePerGroup'] ?? '2',
        'hasRepescage' => false,
        'notes' => 'Creata con il motore automatico (squadre reali)',
        'sortCriterion' => $type === 'knockout' ? $sortCriterion : null,
        'avoidGroupRematches' => $type === 'knockout' ? $avoidGroupRematches : null
    ];
    if ($existingCfgIdx === null) {
        $cfg['phases'][] = $newCfgEntry;
        writeConfig($cfg);
    } elseif ($overwrite) {
        // 🆕 Ricalcolo: aggiorna anche i metadati (es. il nuovo teamsAdvance corretto)
        // 🔧 FIX: Preserva le matchRules se erano state precedentemente salvate
        if (!empty($preservedMatchRules)) {
            $newCfgEntry['matchRules'] = $preservedMatchRules;
            error_log("✅ Preserved matchRules in config for phase $targetPhaseNumber: " . json_encode($preservedMatchRules));
        }
        $cfg['phases'][$existingCfgIdx] = $newCfgEntry;
        writeConfig($cfg);
    }

    jsonResponse(200, $result);
}

// 🆕 Anteprima: quante squadre risulterebbero qualificate/eliminate da una fase
// in base ai risultati REALI attuali (utile per la UI prima di creare la fase successiva)
if ($action === 'admin_preview_phase_branch' && $method === 'POST') {
    validSession();
    
    try {
        error_log("🔍 DEBUG admin_preview_phase_branch INIZIO");
        
        $body = bodyJson();
        error_log("🔍 DEBUG body ricevuto: " . json_encode($body));
        
        $sourcePhaseNumber = (int)($body['sourcePhaseNumber'] ?? 0);
        error_log("🔍 DEBUG sourcePhaseNumber: $sourcePhaseNumber");
        
        $teamsAdvanceRaw = $body['teamsAdvancePerGroup'] ?? 2;
        error_log("🔍 DEBUG teamsAdvanceRaw: " . json_encode($teamsAdvanceRaw));
        
        if (is_string($teamsAdvanceRaw) && str_contains($teamsAdvanceRaw, ',')) {
            $teamsAdvancePerGroup = array_map('intval', array_map('trim', explode(',', $teamsAdvanceRaw)));
        } else {
            $teamsAdvancePerGroup = (int)$teamsAdvanceRaw;
        }
        error_log("🔍 DEBUG teamsAdvancePerGroup dopo parsing: " . json_encode($teamsAdvancePerGroup));

        if ($sourcePhaseNumber < 1) {
            error_log("❌ sourcePhaseNumber non valido: $sourcePhaseNumber");
            jsonResponse(422, ['ok' => false, 'error' => 'sourcePhaseNumber richiesto']);
            return;
        }

        error_log("🔍 DEBUG: lettura state file");
        $state = readJsonFile(DATA_FILE, []);
        error_log("🔍 DEBUG: state caricato, fasi: " . count($state['phases'] ?? []));
        
        error_log("🔍 DEBUG: ensurePhases");
        ensurePhases($state);
        error_log("🔍 DEBUG: ensurePhases completato");
        
        error_log("🔍 DEBUG: getTeamsFromPhaseBranch per fase $sourcePhaseNumber");
        $branchResult = getTeamsFromPhaseBranch($state, $sourcePhaseNumber, $teamsAdvancePerGroup);
        error_log("🔍 DEBUG: branchResult: " . json_encode($branchResult));
        
        error_log("🔍 DEBUG: getTeamMap");
        $teamMap = getTeamMap($state);
        error_log("🔍 DEBUG: teamMap caricato, squadre: " . count($teamMap));

        $resolve = fn($ids) => array_values(array_map(fn($id) => ['id' => $id, 'name' => $teamMap[$id]['name'] ?? '?'], $ids));

        $response = [
            'ok' => true,
            'complete' => $branchResult['complete'],
            'qualified' => $resolve($branchResult['qualified'] ?? []),
            'eliminated' => $resolve($branchResult['eliminated'] ?? [])
        ];
        
        error_log("🔍 DEBUG: response preparato: " . json_encode($response));
        jsonResponse(200, $response);
        
    } catch (Exception $e) {
        error_log('❌ Exception in admin_preview_phase_branch: ' . $e->getMessage() . ' - ' . $e->getFile() . ':' . $e->getLine());
        error_log('❌ Stack trace: ' . $e->getTraceAsString());
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel calcolo dei rami: ' . $e->getMessage()]);
    }
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
    $matchId = (string)($body['id'] ?? '');
    $matchType = (string)($body['type'] ?? ''); // 'quarterFinal', 'semiFinal', 'thirdPlace', 'final'

    withStateTransaction(function (&$state) use ($body, $matchId, $matchType) {
        // ✅ REFACTORED: Trova la fase di playoff e il match
        $found = false;
        $playoffPhases = array_filter($state['phases'], fn($p) => ($p['type'] ?? null) === 'knockout');
        
        foreach ($playoffPhases as &$phase) {
            foreach ($phase['matches'] ?? [] as &$match) {
                if ($match['id'] === $matchId) {
                    $found = true;
                    $match['score1'] = array_key_exists('score1', $body) ? (is_null($body['score1']) ? null : (int)$body['score1']) : $match['score1'];
                    $match['score2'] = array_key_exists('score2', $body) ? (is_null($body['score2']) ? null : (int)$body['score2']) : $match['score2'];
                    break 2;
                }
            }
            unset($match);
        }
        unset($phase);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Match playoff non trovato']);
        }

        updatePlayoffTree($state);
        computeFinalRanking($state);
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_swap_knockout_teams' && $method === 'POST') {
    $body = bodyJson();
    $matchId = (string)($body['matchId'] ?? '');

    withStateTransaction(function (&$state) use ($matchId) {
        $found = false;
        $result = ['team1Name' => '', 'team2Name' => ''];

        // ✅ REFACTORED: Cerca nelle fasi di knockout
        $playoffPhases = array_filter($state['phases'], fn($p) => ($p['type'] ?? null) === 'knockout');
        
        foreach ($playoffPhases as &$phase) {
            foreach ($phase['matches'] ?? [] as &$match) {
                if ($match['id'] !== $matchId) continue;
                
                // Scambio squadre
                $tmp1 = $match['team1Id'];
                $match['team1Id'] = $match['team2Id'];
                $match['team2Id'] = $tmp1;
                
                $tmp2 = $match['team1Name'] ?? '';
                $match['team1Name'] = $match['team2Name'] ?? '';
                $match['team2Name'] = $tmp2;
                
                // Scambio anche gli score se non sono null
                $tmpScore = $match['score1'];
                $match['score1'] = $match['score2'];
                $match['score2'] = $tmpScore;
                
                $result['team1Name'] = $match['team1Name'];
                $result['team2Name'] = $match['team2Name'];
                $found = true;
                break 2;
            }
            unset($match);
        }
        unset($phase);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Match knockout non trovato']);
        }

        error_log("✅ Squadre scambiate per match $matchId: " . $result['team1Name'] . " ↔ " . $result['team2Name']);
        return array_merge(['ok' => true], $result);
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

        // ✅ REFACTORED: Inizializza solo le fasi, non i campi obsoleti
        $state['phases'] = [
            [
                'id' => 'groups_' . uid(),
                'phaseIdx' => 0,
                'phaseNumber' => 1,
                'name' => 'Fase 1 - Gironi',
                'type' => 'groups',
                'status' => 'pending',
                'matches' => [],
                'groups' => [],
                'standings' => [],
                'createdAt' => gmdate('c')
            ]
        ];
        $state['currentPhaseIdx'] = 0;

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_reset' && $method === 'POST') {
    // Reset PARZIALE: cancella gironi, playoff, partite ma CONSERVA squadre e config
    withStateTransaction(function (&$state) {
        // ✅ REFACTORED: Crea nuovo state con fasi resettate
        $newState = [
            'settings' => [
                'maxTeams' => $state['settings']['maxTeams'] ?? 16,
                'tournamentName' => $state['settings']['tournamentName'] ?? ''
            ],
            'teams' => $state['teams'] ?? [],  // ✅ CONSERVA squadre
            'phases' => [
                [
                    'id' => 'groups_' . uid(),
                    'phaseIdx' => 0,
                    'phaseNumber' => 1,
                    'name' => 'Fase 1 - Gironi',
                    'type' => 'groups',
                    'status' => 'pending',
                    'matches' => [],
                    'groups' => [],
                    'standings' => [],
                    'createdAt' => gmdate('c')
                ]
            ],
            'currentPhaseIdx' => 0,
            'meta' => [
                'lastUpdated' => null
            ]
        ];
        $state = $newState;
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true, 'message' => 'Torneo resettato. Squadre conservate.']);
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
    
    // Dati pubblici delle fasi: servono al frontend (scoreboard.html) per sapere quante
    // squadre passano per girone (teamsAdvance) e disegnare la soglia di qualificazione.
    // Esponiamo solo i campi necessari, non le note interne dell'admin.
    $publicPhases = array_map(function ($p) {
        return [
            'phaseNumber' => $p['phaseNumber'] ?? null,
            'name' => $p['name'] ?? '',
            'type' => $p['type'] ?? '',
            'numGroups' => $p['numGroups'] ?? null,
            'teamsAdvance' => $p['teamsAdvance'] ?? null
        ];
    }, $config['phases'] ?? []);
    
    $publicConfig = [
        'display' => $display,
        'tournament' => [
            'name' => $tournament['name'] ?? '',
            'maxTeams' => $tournament['maxTeams'] ?? 16,
            'maxPlayersPerTeam' => $tournament['maxPlayersPerTeam'] ?? 3,
            'maxPlayersOnCourt' => $tournament['maxPlayersOnCourt'] ?? 2,
			'registrationsClosed' => $tournament['registrationsClosed'] ?? false,
			'registrationDeadline' =>$tournament['registrationDeadline'] ?? ''
        ],
        'schedule' => $config['schedule'] ?? [],
        'phases' => $publicPhases
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

/**
 * Salva tutte le partite di una fase contemporaneamente (batch save)
 */
if ($action === 'admin_save_all_matches' && $method === 'POST') {
    try {
        $body = bodyJson();
        $phaseNumber = (int)($body['phaseNumber'] ?? 0);
        $phaseType = trim((string)($body['phaseType'] ?? 'groups'));
        $matches = $body['matches'] ?? [];
        
        if ($phaseNumber < 1 || empty($matches)) {
            jsonResponse(400, ['ok' => false, 'error' => 'Parametri non validi']);
            exit;
        }

        $savedCount = 0;
        $errors = [];

        // Salva tutte le partite nella transazione
        withStateTransaction(function (&$state) use ($phaseNumber, $phaseType, $matches, &$savedCount, &$errors) {
            ensurePhases($state);
            
            $phaseIdx = array_search(
                $phaseNumber,
                array_column($state['phases'], 'phaseNumber'),
                true
            );

            if ($phaseIdx === false) {
                $errors[] = "Fase {$phaseNumber} non trovata";
                return ['ok' => false, 'error' => "Fase {$phaseNumber} non trovata"];
            }

            $currentPhase = &$state['phases'][$phaseIdx];

            // Processa ogni partita nel batch
            foreach ($matches as $matchData) {
                $matchId = $matchData['matchId'] ?? null;
                
                if (!$matchId) {
                    $errors[] = "ID partita mancante";
                    continue;
                }

                // Trova la partita per reference
                $matchFound = false;
                foreach ($currentPhase['matches'] as &$m) {
                    if ($m['id'] !== $matchId) continue;
                    
                    $matchFound = true;

                    // 🔧 Stessa tecnica real-time per QUALSIASI tipo di fase: se
                    // arriva 'sets', l'ultimo elemento è il set in corso (score1/
                    // score2 live), i precedenti sono conclusi. Prima questo valeva
                    // solo per il knockout e comunque non derivava mai score1/score2
                    // dall'ultimo set; i gironi non accettavano affatto sets[].
                    if (isset($matchData['sets']) && is_array($matchData['sets'])) {
                        $sets = $matchData['sets'];
                        $m['sets'] = $sets;
                        if (!empty($sets)) {
                            $lastSet = $sets[count($sets) - 1];
                            $m['score1'] = $lastSet['team1'] ?? 0;
                            $m['score2'] = $lastSet['team2'] ?? 0;
                        } else {
                            $m['score1'] = null;
                            $m['score2'] = null;
                        }
                        $m['updatedAt'] = date('c');
                    } elseif (isset($matchData['score1']) || isset($matchData['score2'])) {
                        // Formato legacy a set singolo (es. simulazione punteggi)
                        if (isset($matchData['score1'])) {
                            $m['score1'] = is_null($matchData['score1']) ? null : (int)$matchData['score1'];
                        }
                        if (isset($matchData['score2'])) {
                            $m['score2'] = is_null($matchData['score2']) ? null : (int)$matchData['score2'];
                        }
                    }
                    if (isset($matchData['team1Timeouts'])) {
                        $m['team1Timeouts'] = (int)$matchData['team1Timeouts'];
                    }
                    if (isset($matchData['team2Timeouts'])) {
                        $m['team2Timeouts'] = (int)$matchData['team2Timeouts'];
                    }
                    if (isset($matchData['time'])) {
                        $m['time'] = trim((string)$matchData['time']);
                    }

                    $savedCount++;
                    break;
                }

                if (!$matchFound) {
                    $errors[] = "Partita {$matchId} non trovata";
                }
            }

            return ['ok' => true];
        });

        error_log("💾 admin_save_all_matches: Salvate {$savedCount} partite su " . count($matches) . ", errori: " . count($errors));
        
        if (count($errors) > 0) {
            error_log("⚠️ Errori durante il salvataggio: " . json_encode($errors));
        }

        jsonResponse(200, [
            'ok' => true,
            'savedCount' => $savedCount,
            'totalMatches' => count($matches),
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        error_log("❌ admin_save_all_matches ERRORE: " . $e->getMessage());
        jsonResponse(500, ['ok' => false, 'error' => 'Errore durante il salvataggio batch: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_update_config' && $method === 'POST') {
    try {
        error_log("📝 admin_update_config: INIZIO");
        $body = bodyJson();
        error_log("📝 admin_update_config: bodyJson() OK, keys=" . json_encode(array_keys($body)));
        
        $config = readConfig();
        error_log("📝 admin_update_config: readConfig() OK");
    
    if (isset($body['tournament'])) {
        $t = $body['tournament'];
        if (isset($t['name'])) $config['tournament']['name'] = mb_substr(trim((string)$t['name']), 0, 100);
        if (isset($t['slug'])) $config['tournament']['slug'] = mb_substr(preg_replace('/[^a-z0-9-]/', '', trim(strtolower((string)$t['slug']))), 0, 50);
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
        if (isset($t['registrationsClosed'])) $config['tournament']['registrationsClosed'] = (bool)$t['registrationsClosed'];
        if (isset($t['registrationDeadline'])) $config['tournament']['registrationDeadline'] = trim((string)$t['registrationDeadline']);
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
                $phaseData['teamsAdvance'] = (string)($phase['teamsAdvance'] ?? '2');
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
                // Parsa teamsAdvance (potrebbe essere "2" o "2,1,3") e somma il totale
                $numGroups = $phase['numGroups'] ?? 4;
                $teamsAdvancePerGroup = parseTeamsAdvancePerGroup($phase['teamsAdvance'] ?? '2', $numGroups);
                $qualified = array_sum($teamsAdvancePerGroup);
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
    
    error_log("📝 admin_update_config: SUCCESSO, inviando config");
    jsonResponse(200, ['ok' => true, 'config' => $config]);
    } catch (Exception $e) {
        error_log("❌ admin_update_config ERRORE: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        jsonResponse(500, ['ok' => false, 'error' => 'Errore configurazione: ' . $e->getMessage()]);
    }
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
        // ✅ REFACTORED: Verifica i gruppi nella fase
        $groups = $state['phases'][0]['groups'] ?? [];
        if (empty($groups)) {
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
    
    // Leggi config attuale per diagnostica
    $config = readConfig();
    $emailConfig = $config['email'] ?? [];
    
    $diagnostics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'enabled' => (bool)($emailConfig['enabled'] ?? false),
        'hasPHPMailer' => file_exists(__DIR__ . '/vendor/autoload.php'),
        'configComplete' => !empty($emailConfig['host']) && !empty($emailConfig['username']) && !empty($emailConfig['password']),
        'host' => $emailConfig['host'] ?? null,
        'port' => $emailConfig['port'] ?? null,
        'secure' => $emailConfig['secure'] ?? null
    ];
    
    // Validazione preliminare
    if (!$diagnostics['enabled']) {
        jsonResponse(422, [
            'ok' => false,
            'error' => 'Email non abilitata - abilita prima di testare',
            'diagnostics' => $diagnostics
        ]);
    }
    
    if (!$diagnostics['configComplete']) {
        jsonResponse(422, [
            'ok' => false,
            'error' => 'Configurazione SMTP incompleta - compila tutti i campi obbligatori',
            'diagnostics' => $diagnostics
        ]);
    }
    
    // Invia email di test
    $subject = '[BeachMaster] Test Email Configuration';
    $testBody = '<h2>🧪 Test Email Configuration</h2>';
    $testBody .= '<p>Se ricevi questo messaggio, la configurazione email è corretta!</p>';
    $testBody .= '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>';
    
    error_log("📧 admin_test_email_config: Tentativo invio a $testEmail");
    $result = sendEmail($testEmail, $subject, $testBody);
    error_log("📧 admin_test_email_config: Risultato - success=" . ($result['success'] ? 'true' : 'false') . ", error=" . ($result['error'] ?? 'none'));
    
    if ($result['success']) {
        $diagnostics['sendResult'] = 'SUCCESS';
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Email di test inviata con successo!',
            'diagnostics' => $diagnostics
        ]);
    } else {
        $diagnostics['sendResult'] = 'FAILED';
        $diagnostics['errorMessage'] = $result['error'] ?? 'Errore sconosciuto';
        
        // Aggiungi dettagli dell'eccezione se disponibili
        if (isset($result['exception'])) {
            $diagnostics['exceptionDetails'] = $result['exception'];
        }
        
        jsonResponse(422, [
            'ok' => false,
            'error' => 'Errore durante l\'invio: ' . ($result['error'] ?? 'Errore sconosciuto'),
            'details' => $result,
            'diagnostics' => $diagnostics
        ]);
    }
}

/**
 * ✅ Diagnostica completa configurazione email - per debugging
 */
if ($action === 'admin_email_diagnostics' && $method === 'GET') {
    validSession();
    
    $config = readConfig();
    $emailConfig = $config['email'] ?? [];
    
    $diagnostics = [
        'phpmailer' => [
            'installed' => file_exists(__DIR__ . '/vendor/autoload.php'),
            'path' => __DIR__ . '/vendor/autoload.php'
        ],
        'config' => [
            'enabled' => (bool)($emailConfig['enabled'] ?? false),
            'host' => $emailConfig['host'] ?? '',
            'port' => $emailConfig['port'] ?? 587,
            'username' => $emailConfig['username'] ?? '',
            'hasPassword' => !empty($emailConfig['password']),
            'fromEmail' => $emailConfig['fromEmail'] ?? '',
            'fromName' => $emailConfig['fromName'] ?? '',
            'secure' => $emailConfig['secure'] ?? 'tls',
            'timeout' => $emailConfig['timeout'] ?? 10
        ],
        'validation' => [
            'hostProvided' => !empty($emailConfig['host']),
            'usernameProvided' => !empty($emailConfig['username']),
            'passwordProvided' => !empty($emailConfig['password']),
            'allRequired' => !empty($emailConfig['host']) && !empty($emailConfig['username']) && !empty($emailConfig['password'])
        ],
        'logFile' => [
            'exists' => file_exists(__DIR__ . '/data/email.log'),
            'path' => __DIR__ . '/data/email.log'
        ]
    ];
    
    jsonResponse(200, ['ok' => true, 'diagnostics' => $diagnostics]);
}

/**
 * ✅ Leggi ultimi entry del log email
 */
if ($action === 'admin_email_log' && $method === 'GET') {
    validSession();
    
    $logFile = __DIR__ . '/data/email.log';
    $lines = [];
    
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $lines = array_filter(array_reverse(preg_split('/\n/', $content)));
        $lines = array_slice($lines, 0, 20); // Ultimi 20 entry
    }
    
    jsonResponse(200, ['ok' => true, 'logLines' => $lines, 'exists' => file_exists($logFile)]);
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
    $filename = "player-{$teamId}-{$playerIndex}.{$ext}";
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
            $team['players'][$playerIndex]['imageFile'] = "data/uploads/{$filename}";
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
        'imageFile' => "data/uploads/{$filename}"
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

if ($action === 'admin_upload_gallery_image' && $method === 'POST') {
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
    $newFilename = 'gallery-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $newFilePath = $uploadsDir . '/' . $newFilename;
    
    if (!move_uploaded_file($file['tmp_name'], $newFilePath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore salvataggio file']);
        return;
    }
    
    jsonResponse(200, ['ok' => true, 'imageFile' => 'data/uploads/' . $newFilename]);
}

// ==================== GALLERY MANAGEMENT ====================

if ($action === 'admin_get_gallery' && $method === 'GET') {
    requireAdmin();
    $config = readConfig();
    $gallery = $config['gallery'] ?? [];
    // Ordina per data di creazione (più recente prima)
    usort($gallery, function($a, $b) {
        $dateA = strtotime($a['publishedAt'] ?? $a['createdAt']);
        $dateB = strtotime($b['publishedAt'] ?? $b['createdAt']);
        return $dateB - $dateA;
    });
    jsonResponse(200, ['ok' => true, 'gallery' => array_values($gallery)]);
}

if ($action === 'public_get_gallery' && $method === 'GET') {
    $config = readConfig();
    $published = array_filter($config['gallery'] ?? [], function($item) {
        return ($item['published'] ?? false) === true;
    });
    
    // Ordina per data di pubblicazione (più recente prima)
    usort($published, function($a, $b) {
        $dateA = strtotime($a['publishedAt'] ?? $a['createdAt']);
        $dateB = strtotime($b['publishedAt'] ?? $b['createdAt']);
        return $dateB - $dateA;
    });
    jsonResponse(200, ['ok' => true, 'gallery' => array_values($published)]);
}

if ($action === 'admin_create_gallery' && $method === 'POST') {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title']) || empty($data['imageFile'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Titolo e foto sono obbligatori']);
        return;
    }
    
    $config = readConfig();
    if (!isset($config['gallery'])) {
        $config['gallery'] = [];
    }
    
    $item = [
        'id' => bin2hex(random_bytes(16)),
        'title' => $data['title'],
        'description' => $data['description'] ?? '',
        'imageFile' => $data['imageFile'],
        'published' => $data['published'] ?? false,
        'createdAt' => date('c'),
        'publishedAt' => ($data['published'] ?? false) ? date('c') : null
    ];
    
    $config['gallery'][] = $item;
    writeConfig($config);
    
    jsonResponse(200, ['ok' => true, 'gallery' => $item]);
}

if ($action === 'admin_update_gallery' && $method === 'POST') {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID obbligatorio']);
        return;
    }
    
    $config = readConfig();
    $gallery = $config['gallery'] ?? [];
    
    $found = false;
    foreach ($gallery as &$item) {
        if ($item['id'] === $data['id']) {
            $item['title'] = $data['title'] ?? $item['title'];
            $item['description'] = $data['description'] ?? $item['description'];
            $item['imageFile'] = $data['imageFile'] ?? $item['imageFile'];
            
            $wasPublished = $item['published'] ?? false;
            $isPublishing = ($data['published'] ?? false) === true && !$wasPublished;
            
            $item['published'] = $data['published'] ?? $item['published'];
            if ($isPublishing) {
                $item['publishedAt'] = date('c');
            }
            
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        jsonResponse(404, ['ok' => false, 'error' => 'Foto non trovata']);
        return;
    }
    
    $config['gallery'] = $gallery;
    writeConfig($config);
    
    jsonResponse(200, ['ok' => true, 'gallery' => array_values($gallery)]);
}

if ($action === 'admin_delete_gallery' && $method === 'POST') {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID obbligatorio']);
        return;
    }
    
    $config = readConfig();
    $gallery = $config['gallery'] ?? [];
    
    $gallery = array_filter($gallery, function($item) use ($data) {
        return $item['id'] !== $data['id'];
    });
    
    $config['gallery'] = array_values($gallery);
    writeConfig($config);
    
    jsonResponse(200, ['ok' => true, 'gallery' => $config['gallery']]);
}

// ==================== TABLESCORE / MATCH SCORING ====================

if ($action === 'admin_get_match' && $method === 'GET') {
    requireAdmin();
    $matchId = $_GET['matchId'] ?? null;
    $phase = $_GET['phase'] ?? null;
    
    if (!$matchId || !$phase) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti']);
        return;
    }
    
    $tournament = readJsonFile(__DIR__ . '/data/tournament.json') ?? [];
    $phases = $tournament['phases'] ?? [];
    $match = null;
    $phaseName = null;
    
    foreach ($phases as $p) {
        if (($p['phaseNumber'] ?? null) == $phase) {
            $phaseName = $p['name'] ?? null;
            $matches = $p['matches'] ?? [];
            foreach ($matches as $m) {
                if (($m['id'] ?? null) === $matchId) {
                    $match = $m;
                    break 2;
                }
            }
        }
    }
    
    if (!$match) {
        jsonResponse(404, ['ok' => false, 'error' => 'Partita non trovata']);
        return;
    }

    // 🆕 Risolve le regole effettive di questa partita (winScore, numSets per il
    // round specifico se la fase definisce setsPerRound, winPoints), cosi il
    // tabellone (tablescore.html) può giocare con i valori reali della fase
    // invece di valori fissi.
    $config = readConfig();
    $configPhase = getConfigPhaseByNumber($config, (int)$phase);
    $round = isset($match['round']) ? (int)$match['round'] : null;
    $matchRules = resolvePhaseMatchRules($config['tournament'] ?? [], $configPhase, $round);

    jsonResponse(200, ['ok' => true, 'match' => $match, 'phaseName' => $phaseName, 'matchRules' => $matchRules]);
}

if ($action === 'admin_update_match_score' && $method === 'POST') {
    // 🔧 DEBUG CRITICO: Traccia TUTTO
    $rawInput = file_get_contents('php://input');
    error_log("🔵 admin_update_match_score: Raw input = " . $rawInput);
    
    requireAdmin();
    $data = json_decode($rawInput, true);
    
    error_log("🔵 admin_update_match_score: Parsed data = " . json_encode($data));
    
    if (empty($data['matchId']) || empty($data['phase'])) {
        error_log("❌ admin_update_match_score: Parametri mancanti! matchId=" . ($data['matchId'] ?? 'NULL') . ", phase=" . ($data['phase'] ?? 'NULL'));
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti: matchId=' . ($data['matchId'] ?? 'NULL') . ', phase=' . ($data['phase'] ?? 'NULL')]);
        return;
    }
    
    $matchId = $data['matchId'];
    $phase = (int)$data['phase'];
    $sets = $data['sets'] ?? [];
    $team1Timeouts = $data['team1Timeouts'] ?? 0;
    $team2Timeouts = $data['team2Timeouts'] ?? 0;
    
    error_log("🔵 admin_update_match_score: Ricevuti i parametri");
    error_log("   matchId={$matchId}");
    error_log("   phase={$phase}");
    error_log("   team1Timeouts={$team1Timeouts}, team2Timeouts={$team2Timeouts}");
    error_log("   sets.count=" . count($sets));
    if (!empty($sets)) {
        foreach ($sets as $i => $s) {
            error_log("      sets[{$i}]: team1=" . ($s['team1'] ?? 'NULL') . ", team2=" . ($s['team2'] ?? 'NULL'));
        }
    } else {
        error_log("   ⚠️  ATTENZIONE: sets è VUOTO!");
    }
    
    // 🔧 FIX: Usa withStateTransaction() come tutti gli altri endpoint, altrimenti
    // il salvataggio può fallire silenziosamente a causa di race condition o
    // permessi file. Questo garantisce che le modifiche siano atomiche.
    $result = withStateTransaction(function (&$state) use ($matchId, $phase, $sets, $team1Timeouts, $team2Timeouts) {
        ensurePhases($state);
        
        error_log("🔵 admin_update_match_score: Cercando fase phaseNumber={$phase}");
        error_log("   Fasi disponibili: " . json_encode(array_column($state['phases'] ?? [], 'phaseNumber')));
        
        // Cerca la fase per phaseNumber
        $phaseIdx = array_search($phase, array_column($state['phases'], 'phaseNumber'), true);
        if ($phaseIdx === false) {
            error_log("❌ admin_update_match_score: Fase {$phase} non trovata!");
            return ['ok' => false, 'error' => "Fase {$phase} non trovata"];
        }
        
        error_log("🔵 admin_update_match_score: Fase trovata a indice {$phaseIdx}");
        
        $currentPhase = &$state['phases'][$phaseIdx];
        
        if (empty($currentPhase['matches'])) {
            error_log("❌ admin_update_match_score: Nessuna partita in fase {$phase}!");
            return ['ok' => false, 'error' => "Nessuna partita trovata in fase {$phase}"];
        }
        
        error_log("🔵 admin_update_match_score: Fase ha " . count($currentPhase['matches']) . " partite");
        error_log("   Cercando matchId={$matchId}");
        error_log("   MatchIds disponibili: " . json_encode(array_column($currentPhase['matches'], 'id')));
        
        // Cerca e aggiorna il match per reference
        $found = false;
        foreach ($currentPhase['matches'] as &$match) {
            if (($match['id'] ?? null) !== $matchId) continue;
            
            error_log("✅ admin_update_match_score: Partita {$matchId} trovata!");
            
            $found = true;
            $match['sets'] = $sets;
            $match['team1Timeouts'] = $team1Timeouts;
            $match['team2Timeouts'] = $team2Timeouts;
            $match['updatedAt'] = date('c');
            
            error_log("🔵 admin_update_match_score: Prima di elaborare i punteggi");
            error_log("   match['sets'] ora contiene: " . json_encode($sets));
            error_log("   scoreboardState.sets RICEVUTO (array): " . json_encode($sets));
            
            // 🔧 FIX CRITICO (KNOCKOUT): In knockout, score1/score2 sono i punteggi 
            // del SET CORRENTE, non il numero di set vinti!
            // Il tabellone invia sets[] = [set1, set2, ..., setInCorso]
            // Devo estrarre score1/score2 dall'ULTIMO elemento di sets (set in corso)
            // e contare i set vinti guardando solo gli elementi precedenti
            
            if (!empty($sets)) {
                $lastSet = $sets[count($sets) - 1];
                $match['score1'] = $lastSet['team1'] ?? 0;
                $match['score2'] = $lastSet['team2'] ?? 0;
                
                // Conta set vinti guardando solo i set COMPLETATI (non l'ultimo)
                $team1SetsWon = 0;
                $team2SetsWon = 0;
                for ($i = 0; $i < count($sets) - 1; $i++) {
                    $t1 = $sets[$i]['team1'] ?? 0;
                    $t2 = $sets[$i]['team2'] ?? 0;
                    if ($t1 > $t2) $team1SetsWon++;
                    elseif ($t2 > $t1) $team2SetsWon++;
                }
                
                error_log("✅ TABELLONE KNOCKOUT: Partita {$matchId} → sets=" . count($sets) . 
                    ", setsWon={$team1SetsWon}-{$team2SetsWon}, currentSet={$match['score1']}-{$match['score2']}");
            } else {
                $match['score1'] = null;
                $match['score2'] = null;
            }
            
            break;
        }
        unset($match);
        
        if (!$found) {
            return ['ok' => false, 'error' => "Partita {$matchId} non trovata"];
        }
        
        return ['ok' => true, 'message' => 'Punteggi salvati'];
    });
    
    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, ['ok' => false, 'error' => $result['error'] ?? 'Errore sconosciuto']);
        return;
    }
    
    jsonResponse(200, $result);
}

if ($action === 'admin_advance_knockout_round' && $method === 'POST') {
    // 🔧 Avanza le squadre vincitrici da un round al successivo
    // Accettati parametri:
    //   - phase: numero della fase knockout
    //   - fromRound: da quale round avanzare (1=quarti, 2=semifinali, ecc)
    // Determina automaticamente il toRound = fromRound + 1
    
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $phase = $data['phase'] ?? null;
    $fromRound = $data['fromRound'] ?? null;
    
    if (!$phase || !$fromRound) {
        jsonResponse(400, ['ok' => false, 'error' => 'Parametri mancanti: phase, fromRound']);
        return;
    }
    
    $result = withStateTransaction(function (&$state) use ($phase, $fromRound) {
        ensurePhases($state);
        
        $phaseIdx = array_search($phase, array_column($state['phases'], 'phaseNumber'), true);
        if ($phaseIdx === false) {
            return ['ok' => false, 'error' => "Fase {$phase} non trovata"];
        }
        
        $phaseData = &$state['phases'][$phaseIdx];
        if ($phaseData['type'] !== 'knockout') {
            return ['ok' => false, 'error' => 'La fase non è un knockout'];
        }
        
        $matches = &$phaseData['matches'];
        
        // 1️⃣ Estrai i vincitori dal round corrente
        $fromRoundMatches = array_filter($matches, fn($m) => ($m['round'] ?? 0) == $fromRound);
        $winners = [];
        
        foreach ($fromRoundMatches as $match) {
            // 🔧 FIX: il vincitore va determinato dai SET VINTI (getMatchSetsWon),
            // non dal punteggio grezzo score1/score2 — quest'ultimo per le partite
            // multi-set rappresenta solo il set in corso/ultimo, non l'esito
            // complessivo dell'incontro.
            [$won1, $won2, $hasResult] = getMatchSetsWon($match);
            if (!$hasResult) {
                // Match non giocato
                return ['ok' => false, 'error' => "Match " . ($match['label'] ?? 'sconosciuto') . " non ha ancora punteggi"];
            }

            $winner = null;
            if ($won1 > $won2) {
                $winner = $match['team1'];
            } elseif ($won2 > $won1) {
                $winner = $match['team2'];
            } else {
                return ['ok' => false, 'error' => "Match " . ($match['label'] ?? 'sconosciuto') . " ha punteggio in parità (" . $won1 . "-" . $won2 . " set)"];
            }

            $winners[] = [
                'winner' => $winner,
                'winnerName' => ($won1 > $won2) ? $match['team1Name'] : $match['team2Name'],
                'loser' => ($won1 > $won2) ? $match['team2'] : $match['team1'],
                'loserName' => ($won1 > $won2) ? $match['team2Name'] : $match['team1Name']
            ];
        }
        
        // 2️⃣ Identifica il round destinazione (escludi thirdPlace)
        $toRound = $fromRound + 1;
        $toRoundMatches = array_filter(
            $matches,
            fn($m) => ($m['round'] ?? 0) == $toRound && ($m['type'] ?? null) !== 'thirdPlace'
        );
        
        // ⚠️ Valida che i vincitori possono riempire i match (2 vincitori per match)
        $matchCount = count($toRoundMatches);
        $winnersCount = count($winners);
        $expectedWinners = $matchCount * 2;  // 2 vincitori per match
        
        if ($winnersCount !== $expectedWinners) {
            error_log("❌ Validazione fallita: Vincitori={$winnersCount}, Match={$matchCount}, ExpectedWinners={$expectedWinners}");
            error_log("   Winners: " . json_encode(array_map(fn($w) => $w['winnerName'], $winners)));
            error_log("   Matches: " . json_encode(array_map(fn($m) => $m['label'] ?? 'unknown', array_values($toRoundMatches))));
            return ['ok' => false, 'error' => "Vincitori ({$winnersCount}) non possono essere accoppiati in {$matchCount} match (servono {$expectedWinners} vincitori)"];
        }
        
        // 3️⃣ Crea mappatura per abbinare vincitori ai match del round successivo
        // L'ordine è importante: i vincitori mantengono l'abbinamento del bracket
        $winnersArray = array_values($winners);
        
        // Raccogli i match del round successivo (mantenendo gli indici) senza usare ARRAY_FILTER_PRESERVE_KEYS
        $toRoundMatchesArray = [];
        foreach ($matches as $idx => $m) {
            if (($m['round'] ?? 0) == $toRound && ($m['type'] ?? null) !== 'thirdPlace') {
                $toRoundMatchesArray[$idx] = $m;
            }
        }
        
        // 4️⃣ Aggiorna i match del round successivo con i vincitori
        // Abbina 2 vincitori per match (vincitore1 vs vincitore2)
        $matchIdx = 0;
        $losers = [];  // Raccogli i perdenti per il terzo posto
        
        foreach ($toRoundMatchesArray as $toMatchIdx => $toMatch) {
            if ($matchIdx >= count($winnersArray)) break;
            
            // Prendi due vincitori consecutivi
            $team1Data = $winnersArray[$matchIdx];
            $matchIdx++;
            
            if ($matchIdx < count($winnersArray)) {
                $team2Data = $winnersArray[$matchIdx];
                $matchIdx++;
            } else {
                // Dispari: il vincitore rimasto va contro bye
                error_log("⚠️ Numero dispari di vincitori");
                break;
            }
            
            // Abbina i vincitori nel match del round successivo
            $matches[$toMatchIdx]['team1'] = $team1Data['winner'];
            $matches[$toMatchIdx]['team1Id'] = $team1Data['winner'];
            $matches[$toMatchIdx]['team1Name'] = $team1Data['winnerName'];
            $matches[$toMatchIdx]['team2'] = $team2Data['winner'];
            $matches[$toMatchIdx]['team2Id'] = $team2Data['winner'];
            $matches[$toMatchIdx]['team2Name'] = $team2Data['winnerName'];
            $matches[$toMatchIdx]['score1'] = null;
            $matches[$toMatchIdx]['score2'] = null;
            $matches[$toMatchIdx]['sets'] = [];
            
            // Raccogli i perdenti per il terzo posto
            $losers[] = $team1Data;
            $losers[] = $team2Data;
        }
        
        // 5️⃣ Se esiste un match di terzo posto E il flag includeThirdPlace è true, abbina i perdenti
        $includeThirdPlace = $phaseData['includeThirdPlace'] ?? false;
        
        if ($includeThirdPlace) {
            $thirdPlaceMatches = array_filter(
                $matches,
                fn($m) => ($m['type'] ?? null) === 'thirdPlace' && ($m['round'] ?? 0) == $toRound
            );
            
            if (!empty($thirdPlaceMatches) && count($losers) >= 2) {
                // Il match di terzo posto ha i primi 2 perdenti
                foreach ($thirdPlaceMatches as $tpIdx => $tpMatch) {
                    $matches[$tpIdx]['team1'] = $losers[0]['loser'];
                    $matches[$tpIdx]['team1Id'] = $losers[0]['loser'];
                    $matches[$tpIdx]['team1Name'] = $losers[0]['loserName'];
                    $matches[$tpIdx]['team2'] = $losers[1]['loser'];
                    $matches[$tpIdx]['team2Id'] = $losers[1]['loser'];
                    $matches[$tpIdx]['team2Name'] = $losers[1]['loserName'];
                    $matches[$tpIdx]['score1'] = null;
                    $matches[$tpIdx]['score2'] = null;
                    $matches[$tpIdx]['sets'] = [];
                    
                    error_log("✅ Terzo posto: " . $losers[0]['loserName'] . " vs " . $losers[1]['loserName']);
                    break; // Solo un match di terzo posto
                }
            }
        } else {
            error_log("⊘ Terzo posto SKIPPED (includeThirdPlace=false)");
        }
        
        return ['ok' => true, 'message' => "Round avanzato: {$fromRound} → {$toRound}"];
    });
    
    if (!$result || !($result['ok'] ?? false)) {
        jsonResponse(400, $result);
        return;
    }
    
    jsonResponse(200, $result);
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
        
        // ✅ REFACTORED: Backup delle fasi invece dei campi obsoleti
        $backup = [
            'version' => '1.0',
            'timestamp' => date('c'),
            'config' => $config,
            'teams' => $state['teams'] ?? [],
            'phases' => $state['phases'] ?? [],
            'currentPhaseIdx' => $state['currentPhaseIdx'] ?? 0,
            'meta' => $state['meta'] ?? []
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
function copyDirectory(string $src, string $dst, array $extraExcludeDirs = []): bool {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    
    // ✅ Cartelle e file da escludere da TUTTE le copie
    $excludeDirs = ['.', '..', '.git', 'node_modules', 'beachmaster', '.github', 'vendor'];
    // Aggiungi le esclusioni extra (es: il codice del torneo che si sta creando)
    $excludeDirs = array_merge($excludeDirs, $extraExcludeDirs);
    $excludeFiles = ['.gitignore', '.gitattributes'];
    
    // Usa PHP puro per la copia (più affidabile)
    $dir = @opendir($src);
    if (!$dir) return false;
    
    $count = 0;
    while (($file = readdir($dir)) !== false) {
        // Esclusioni per directory e file
        if (in_array($file, $excludeDirs, true) || in_array($file, $excludeFiles, true)) {
            continue;
        }
        
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        
        try {
            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                copyDirectory($srcPath, $dstPath, $extraExcludeDirs);
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

/**
 * ✅ Rimuove ricorsivamente una directory e tutto il suo contenuto
 */
function removeDirectoryRecursive(string $path): bool {
    if (!is_dir($path)) {
        return false;
    }
    
    $items = @scandir($path);
    if ($items === false) {
        return false;
    }
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $itemPath = $path . '/' . $item;
        
        try {
            if (is_dir($itemPath)) {
                removeDirectoryRecursive($itemPath);
            } else {
                @unlink($itemPath);
            }
        } catch (Throwable $e) {
            error_log("removeDirectoryRecursive error: " . $e->getMessage());
        }
    }
    
    @rmdir($path);
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
    $tournamentDir = __DIR__ . '/' . $tournamentCode;
    
    error_log("📌 create_tournament: tournamentCode=$tournamentCode, dir=$tournamentDir");
    
    // ✅ NON copiare l'intera directory (che include dati di altri tornei)
    // Crea una struttura minimale con solo i file necessari
    error_log("📌 create_tournament: creating minimal structure...");
    
    // Crea directory principali
    $dirsToCreate = [
        $tournamentDir,
        $tournamentDir . '/data',
        $tournamentDir . '/data/backups',
        $tournamentDir . '/data/updates',
        $tournamentDir . '/data/uploads',
        $tournamentDir . '/config',
        $tournamentDir . '/images',
        $tournamentDir . '/images/default',
        $tournamentDir . '/images/favicon',
        $tournamentDir . '/plugins/phpmailer',
        $tournamentDir . '/plugins/phpmailer/src',
        $tournamentDir . '/plugins/phpmailer/language',
        $tournamentDir . '/scripts'
    ];
    
    foreach ($dirsToCreate as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("❌ create_tournament: failed to create dir $dir");
                jsonResponse(500, ['ok' => false, 'error' => "Errore nella creazione della directory $dir"]);
            }
        }
    }
    
    // Copia SOLO i file HTML e CSS necessari (non le directory di altri tornei)
    $filesToCopy = [
        'admin.html', 'scoreboard.html', 'index.html', 'policy.html', 'regolamento.html', 'cookie.html',
        'tournament-flow-editor.html', 'test_logo.html', 'test_sponsors.html', 'test_workflow_tree.html',
        'theme-chiringuito.css', 'theme-scuro.css', 'theme-minimalista.css', 'theme-moderno.css', 'theme-metro.css', 'theme-beachmaster.css',
        'api.php', 'seed-data.php', 'test_group_scheduling.php',
        'composer.json'
    ];
    
    foreach ($filesToCopy as $file) {
        $srcFile = __DIR__ . '/' . $file;
        $dstFile = $tournamentDir . '/' . $file;
        if (file_exists($srcFile) && !is_dir($srcFile)) {
            if (!copy($srcFile, $dstFile)) {
                error_log("❌ create_tournament: failed to copy $file");
                // Continua comunque, non è critico
            }
        }
    }
    
    // Copia le directory plugin (necessarie per phpmailer)
    copyDirectory(__DIR__ . '/plugins/phpmailer', $tournamentDir . '/plugins/phpmailer', []);
    
    // Nel torneo creato: gestisci i file HTML
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
    
    // ✅ RESET: Scrivi tournament.json vuoto (stato iniziale) con struttura completa
    $tournamentJsonFile = $tournamentDir . '/data/tournament.json';
    $emptyTournament = [
        'settings' => [
            'maxTeams' => 0,
            'tournamentName' => ''
        ],
        'teams' => [],
        'phases' => [
            [
                'id' => 'phase-1-groups',
                'phaseIdx' => 1,
                'phaseNumber' => 1,
                'name' => 'Fase 1 - Gironi',
                'type' => 'groups',
                'status' => 'pending',
                'groups' => [],
                'matches' => [],
                'standings' => [],
                'createdAt' => gmdate('c')
            ]
        ],
        'meta' => [
            'lastUpdated' => null
        ]
    ];
    writeJsonFile($tournamentJsonFile, $emptyTournament);
    
    // ✅ RESET: Scrivi config.json vuoto (configurazione iniziale) con struttura completa
    $configJsonFile = $tournamentDir . '/data/config.json';
    $emptyConfig = [
        'tournament' => [
            'name' => '',
            'maxTeams' => 16,
            'maxPlayersPerTeam' => 3,
            'maxPlayersOnCourt' => 2,
            'maxSubstitutions' => 0,
            'numGroups' => 0,
            'numSets' => 1,
            'winScore' => 21,
            'maxScore' => 25,
            'timePerSetMinutes' => 25,
            'setupTimeMinutes' => 5,
            'maxTimeoutsPerSet' => 2,
            'registrationsClosed' => false,
            'registrationDeadline' => ''
        ],
        'schedule' => [
            'courts' => [],
            'days' => [],
            'fields' => []
        ],
        'phases' => [
            [
                'phaseNumber' => 1,
                'name' => '',
                'type' => 'groups',
                'branch' => 'root',
                'qualifiedGoTo' => 'sa',
                'eliminatedGoTo' => 'sa',
                'numGroups' => 0,
                'teamsAdvance' => '',
                'hasRepescage' => false,
                'notes' => ''
            ]
        ],
        'contact' => [
            'managerEmail' => ''
        ],
        'display' => [
            'theme' => 'beachmaster',
            'customThemes' => [],
            'logoFile' => '',
            'backgroundFile' => ''
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
            'service' => '',
            'host' => '',
            'port' => 587,
            'secure' => 'tls',
            'auth' => false,
            'username' => '',
            'password' => '',
            'fromEmail' => '',
            'fromName' => '',
            'timeout' => 30
        ],
        'security' => [
            'encryptionEnabled' => false,
            'encryptionPassword' => ''
        ]
    ];
    writeJsonFile($configJsonFile, $emptyConfig);
    
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
        'path' => $tournamentCode,
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
        'redirectUrl' => $tournamentCode . '/admin.html'
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
    
    $tournamentDir = __DIR__ . '/' . $tournamentCode;
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

// ==================== SYNC FILES FROM ROOT ====================

// Sincronizza i file (api.php, index.html, scoreboard.html) dalla root al torneo corrente
if ($action === 'admin_sync_files_from_root' && $method === 'POST') {
    requireAdmin();
    
    try {
        $token = authToken();
        $tournamentCode = getTournamentCodeFromToken($token);
        
        // Se è single-tenant (local), non sincronizzare (i file sono nella root)
        if ($tournamentCode === 'local') {
            jsonResponse(400, [
                'ok' => false,
                'error' => 'La sincronizzazione non è disponibile in modalità single-tenant'
            ]);
        }
        
        if (!$tournamentCode) {
            jsonResponse(401, [
                'ok' => false,
                'error' => 'Torneo non identificato'
            ]);
        }
        
        $tournamentDir = __DIR__ . '/' . $tournamentCode;
        $rootDir = __DIR__;
        
        if (!is_dir($tournamentDir)) {
            jsonResponse(404, [
                'ok' => false,
                'error' => 'Directory torneo non trovata'
            ]);
        }
        
        $filesToSync = ['api.php', 'index.html', 'scoreboard.html'];
        $syncedFiles = [];
        $errors = [];
        
        foreach ($filesToSync as $filename) {
            $srcPath = $rootDir . '/' . $filename;
            $dstPath = $tournamentDir . '/' . $filename;
            
            // Verifica che il file esista nella root
            if (!file_exists($srcPath)) {
                $errors[] = "File $filename non trovato nella root";
                continue;
            }
            
            // Crea backup del file esistente nel torneo
            if (file_exists($dstPath)) {
                $backupPath = $dstPath . '.backup.' . date('YmdHis');
                if (!copy($dstPath, $backupPath)) {
                    $errors[] = "Non riuscito a creare backup di $filename";
                    continue;
                }
            }
            
            // Copia il file dalla root
            if (copy($srcPath, $dstPath)) {
                $syncedFiles[] = $filename;
            } else {
                $errors[] = "Errore nella copia di $filename";
            }
        }
        
        if (empty($syncedFiles) && !empty($errors)) {
            jsonResponse(400, [
                'ok' => false,
                'error' => 'Errore durante la sincronizzazione: ' . implode(', ', $errors)
            ]);
        }
        
        error_log("✅ admin_sync_files_from_root: Sincronizzati " . count($syncedFiles) . " file nel torneo $tournamentCode");
        
        jsonResponse(200, [
            'ok' => true,
            'message' => 'Sincronizzati ' . count($syncedFiles) . ' file: ' . implode(', ', $syncedFiles),
            'syncedFiles' => $syncedFiles,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        error_log("❌ admin_sync_files_from_root: " . $e->getMessage());
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore: ' . $e->getMessage()
        ]);
    }
}

// Verifica quali file della root sono diversi da quelli del torneo
if ($action === 'admin_check_files_sync' && $method === 'POST') {
    requireAdmin();
    
    try {
        $token = authToken();
        $tournamentCode = getTournamentCodeFromToken($token);
        
        // Se è single-tenant (local), non sincronizzare
        if ($tournamentCode === 'local') {
            jsonResponse(200, [
                'ok' => true,
                'filesNeedSync' => [],
                'needsSync' => false,
                'reason' => 'Single-tenant mode'
            ]);
        }
        
        if (!$tournamentCode) {
            jsonResponse(401, [
                'ok' => false,
                'error' => 'Torneo non identificato'
            ]);
        }
        
        $tournamentDir = __DIR__ . '/' . $tournamentCode;
        $rootDir = __DIR__;
        
        if (!is_dir($tournamentDir)) {
            jsonResponse(404, [
                'ok' => false,
                'error' => 'Directory torneo non trovata'
            ]);
        }
        
        $filesToCheck = ['api.php', 'index.html', 'scoreboard.html'];
        $filesNeedSync = [];
        
        foreach ($filesToCheck as $filename) {
            $srcPath = $rootDir . '/' . $filename;
            $dstPath = $tournamentDir . '/' . $filename;
            
            // Se il file non esiste nel torneo, deve essere sincronizzato
            if (!file_exists($dstPath)) {
                $filesNeedSync[] = $filename . ' (file non esiste)';
                continue;
            }
            
            // Confronta i hash MD5 dei file
            $srcHash = md5_file($srcPath);
            $dstHash = md5_file($dstPath);
            
            if ($srcHash !== $dstHash) {
                $filesNeedSync[] = $filename;
            }
        }
        
        error_log("🔍 admin_check_files_sync: Torneo $tournamentCode - " . count($filesNeedSync) . " file da sincronizzare");
        
        jsonResponse(200, [
            'ok' => true,
            'filesNeedSync' => $filesNeedSync,
            'needsSync' => count($filesNeedSync) > 0
        ]);
    } catch (Exception $e) {
        error_log("❌ admin_check_files_sync: " . $e->getMessage());
        jsonResponse(500, [
            'ok' => false,
            'error' => 'Errore: ' . $e->getMessage()
        ]);
    }
}

// ==================== MULTITENANT ====================

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
        
        // ✅ REFACTORED: Leggi i match dalle fasi
        $groupMatches = $state['phases'][0]['matches'] ?? [];
        $totalMatches = count($groupMatches);
        
        // ✅ REFACTORED: Leggi i gruppi dalle fasi
        $groupsCount = count($state['phases'][0]['groups'] ?? []);
        
        jsonResponse(200, [
            'ok' => true,
            'debug' => [
                'courts_count' => count($courts),
                'total_slots' => $totalSlots,
                'total_matches' => $totalMatches,
                'slots_list' => $slotsList,
                'groups_count' => $groupsCount,
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
define('FLOW_FILE', __DIR__ . '/data/tournament-flow.json');

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
        
        // ✅ REFACTORED: Leggi lo stato e cerca nelle fasi
        $state = readJsonFile(DATA_FILE, []);
        $found = false;
        
        // Cerca la partita in tutte le fasi
        foreach ($state['phases'] ?? [] as &$phase) {
            foreach ($phase['matches'] ?? [] as &$match) {
                if (($match['id'] ?? '') === $matchId) {
                    $match['duration'] = $duration;
                    $found = true;
                    break 2;
                }
            }
            unset($match);
        }
        unset($phase);
        
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
