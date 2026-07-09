<?php
/**
 * 🧪 Script di Test Email SMTP - Diagnostica Completa
 * Esegui con: php test-email-smtp.php
 */

echo "🔍 TEST EMAIL SMTP - DIAGNOSTICA COMPLETA\n";
echo str_repeat("=", 60) . "\n\n";

// Carica config
$configFile = __DIR__ . '/../data/config.json';
if (!file_exists($configFile)) {
    echo "❌ ERRORE: File config.json non trovato\n";
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);
if (!$config) {
    echo "❌ ERRORE: config.json non valido\n";
    exit(1);
}

$emailConfig = $config['email'] ?? [];

echo "1️⃣  STATO DELLA CONFIGURAZIONE\n";
echo str_repeat("-", 60) . "\n";
echo "Abilitato: " . ($emailConfig['enabled'] ? '✅ SÌ' : '❌ NO') . "\n";
echo "Host: " . ($emailConfig['host'] ? '✅ ' . $emailConfig['host'] : '❌ MANCA') . "\n";
echo "Porta: " . ($emailConfig['port'] ? '✅ ' . $emailConfig['port'] : '❌ MANCA') . "\n";
echo "Username: " . ($emailConfig['username'] ? '✅ ' . $emailConfig['username'] : '❌ MANCA') . "\n";
echo "Password: " . (!empty($emailConfig['password']) ? '✅ Presente (' . strlen($emailConfig['password']) . ' caratteri)' : '❌ MANCA') . "\n";
echo "From Email: " . ($emailConfig['fromEmail'] ? '✅ ' . $emailConfig['fromEmail'] : '⚠️  default') . "\n";
echo "From Name: " . ($emailConfig['fromName'] ? '✅ ' . $emailConfig['fromName'] : '⚠️  default') . "\n";
echo "Secure: " . ($emailConfig['secure'] ? '✅ ' . $emailConfig['secure'] : '⚠️  default TLS') . "\n";
echo "Timeout: " . ($emailConfig['timeout'] ? '✅ ' . $emailConfig['timeout'] . 's' : '⚠️  default 10s') . "\n\n";

if (!$emailConfig['enabled']) {
    echo "⚠️  AVVISO: Email non abilitata nel pannello admin!\n\n";
}

// Validazione parametri
echo "2️⃣  VALIDAZIONE PARAMETRI\n";
echo str_repeat("-", 60) . "\n";

$errors = [];
if (empty($emailConfig['host'])) $errors[] = "Host SMTP mancante";
if (empty($emailConfig['port'])) $errors[] = "Porta SMTP mancante";
if (empty($emailConfig['username'])) $errors[] = "Username SMTP mancante";
if (empty($emailConfig['password'])) $errors[] = "Password SMTP mancante";

if (!empty($errors)) {
    foreach ($errors as $error) {
        echo "❌ $error\n";
    }
    echo "\n";
} else {
    echo "✅ Tutti i parametri obbligatori sono presenti\n\n";
}

// Controlla PHPMailer
echo "3️⃣  DIPENDENZE\n";
echo str_repeat("-", 60) . "\n";

$phpmailerPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
if (file_exists($phpmailerPath)) {
    echo "✅ PHPMailer installato\n";
} else {
    echo "❌ PHPMailer NON installato - installa con: composer install\n";
}

echo "\n";

// Test connessione SMTP
echo "4️⃣  TEST CONNESSIONE SMTP\n";
echo str_repeat("-", 60) . "\n";

if (empty($emailConfig['host']) || empty($emailConfig['username']) || empty($emailConfig['password'])) {
    echo "⏭️  Saltato (parametri incompleti)\n\n";
} else {
    if (!file_exists($phpmailerPath)) {
        echo "⏭️  Saltato (PHPMailer non disponibile)\n\n";
    } else {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $emailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $emailConfig['username'];
            $mail->Password = $emailConfig['password'];
            
            $secure = $emailConfig['secure'] ?? 'tls';
            $mail->SMTPSecure = ($secure === 'ssl') 
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            
            $mail->Port = (int)($emailConfig['port'] ?? 587);
            $mail->Timeout = (int)($emailConfig['timeout'] ?? 10);
            $mail->SMTPDebug = 0; // Disabilita verbose per questo test
            
            // Tenta connessione
            echo "Tentativo connessione a: " . $emailConfig['host'] . ":" . $emailConfig['port'] . "\n";
            
            if ($mail->smtpConnect()) {
                echo "✅ Connessione SMTP riuscita\n";
                $mail->smtpClose();
            } else {
                echo "❌ Connessione SMTP fallita: " . $mail->ErrorInfo . "\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ ECCEZIONE: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n";

// Test invio email
echo "5️⃣  TEST INVIO EMAIL\n";
echo str_repeat("-", 60) . "\n";

if (empty($emailConfig['host']) || empty($emailConfig['username']) || empty($emailConfig['password'])) {
    echo "⏭️  Saltato (parametri incompleti)\n\n";
} else {
    if (!file_exists($phpmailerPath)) {
        echo "⏭️  Saltato (PHPMailer non disponibile)\n\n";
    } else {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $emailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $emailConfig['username'];
            $mail->Password = $emailConfig['password'];
            
            $secure = $emailConfig['secure'] ?? 'tls';
            $mail->SMTPSecure = ($secure === 'ssl') 
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            
            $mail->Port = (int)($emailConfig['port'] ?? 587);
            $mail->Timeout = (int)($emailConfig['timeout'] ?? 10);
            
            // Configurazione email
            $mail->setFrom(
                $emailConfig['fromEmail'] ?? 'noreply@beachmaster.local',
                $emailConfig['fromName'] ?? 'BeachMaster'
            );
            
            // Destinatario di test
            echo "Inserisci email di destinazione per il test (es: test@gmail.com): ";
            $testEmail = trim(fgets(STDIN));
            
            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                echo "❌ Email non valida\n\n";
            } else {
                $mail->addAddress($testEmail);
                $mail->isHTML(true);
                $mail->Subject = '[BeachMaster] Test Email - ' . date('Y-m-d H:i:s');
                $mail->Body = '<h2>🧪 Test Email BeachMaster</h2>';
                $mail->Body .= '<p>Se ricevi questo messaggio, la configurazione SMTP è corretta!</p>';
                $mail->Body .= '<p><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '</p>';
                $mail->AltBody = strip_tags($mail->Body);
                
                echo "\nTentativo invio a: $testEmail\n";
                
                if ($mail->send()) {
                    echo "✅ EMAIL INVIATA CON SUCCESSO!\n";
                    echo "Controlla la tua casella di posta (includi lo spam)\n";
                } else {
                    echo "❌ INVIO FALLITO: " . $mail->ErrorInfo . "\n";
                }
            }
            
        } catch (\Exception $e) {
            echo "❌ ECCEZIONE: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n";

// Log file
echo "6️⃣  EMAIL LOG\n";
echo str_repeat("-", 60) . "\n";

$logFile = __DIR__ . '/../data/email.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $lines = explode("\n", trim($logContent));
    $recentLines = array_slice($lines, -10); // Ultimi 10 log
    
    echo "Ultimi 10 log (più recenti):\n";
    foreach ($recentLines as $line) {
        if (!empty($line)) {
            echo $line . "\n";
        }
    }
} else {
    echo "File email.log non ancora creato\n";
}

echo "\n";
echo "✅ TEST COMPLETATO\n";
