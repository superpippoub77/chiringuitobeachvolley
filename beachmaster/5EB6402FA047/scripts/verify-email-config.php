#!/usr/bin/env php
<?php
/**
 * Script di verifica configurazione email
 * Usare: php scripts/verify-email-config.php
 */

echo "🔍 Verificazione Configurazione Email\n";
echo "====================================\n\n";

$errors = [];
$warnings = [];
$info = [];

// 1. Controlla se Composer/PHPMailer è installato
echo "1️⃣  PHPMailer...\n";
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "   ✅ PHPMailer installato\n";
    $info[] = "PHPMailer disponibile";
} else {
    echo "   ⚠️  PHPMailer non trovato\n";
    $warnings[] = "Esegui: composer install";
}

// 2. Controlla config file
echo "\n2️⃣  File di configurazione...\n";
$configFile = __DIR__ . '/../config/email-config.php';
if (file_exists($configFile)) {
    echo "   ✅ File config esiste\n";
    $config = include $configFile;
    
    if ($config['enabled']) {
        echo "   ✅ Email ABILITATA\n";
    } else {
        echo "   ⚠️  Email DISABILITATA\n";
        $warnings[] = "Email non abilitata - imposta 'enabled' => true";
    }
} else {
    echo "   ❌ File config non trovato\n";
    $errors[] = "Copia config/email-config.example.php a config/email-config.php";
}

// 3. Controlla estensione PHP mail
echo "\n3️⃣  Estensioni PHP...\n";
if (extension_loaded('openssl')) {
    echo "   ✅ OpenSSL disponibile (per TLS/SSL)\n";
} else {
    echo "   ⚠️  OpenSSL non trovato\n";
    $warnings[] = "OpenSSL consigliato per connessioni SMTP sicure";
}

// 4. Controlla credenziali SMTP
echo "\n4️⃣  Credenziali SMTP...\n";
if (isset($config)) {
    $service = $config['service'];
    $smtpConfig = $config[$service] ?? $config['custom'];
    
    if (empty($smtpConfig['host'])) {
        echo "   ❌ Host SMTP non configurato\n";
        $errors[] = "Configura host SMTP in config/email-config.php";
    } else {
        echo "   ✅ Host: {$smtpConfig['host']}:{$smtpConfig['port']}\n";
    }
    
    if (empty($smtpConfig['username'])) {
        echo "   ❌ Username non configurato\n";
        $errors[] = "Aggiungi username SMTP";
    } else {
        $masked = substr($smtpConfig['username'], 0, 3) . '***';
        echo "   ✅ Username: $masked\n";
    }
    
    if (empty($smtpConfig['password'])) {
        echo "   ❌ Password non configurata\n";
        $errors[] = "Aggiungi password SMTP";
    } else {
        echo "   ✅ Password: (configurata)\n";
    }
}

// 5. Controlla log file
echo "\n5️⃣  Log email...\n";
$logFile = __DIR__ . '/../data/email.log';
if (is_file($logFile)) {
    $lines = count(file($logFile));
    echo "   ✅ Log file esiste ($lines righe)\n";
    
    // Mostra ultime righe
    $lastLines = array_slice(file($logFile), -3);
    echo "   📋 Ultimi 3 log:\n";
    foreach ($lastLines as $line) {
        echo "      " . trim($line) . "\n";
    }
} else {
    echo "   ⚠️  Log file non ancora creato (sarà creato al primo invio)\n";
}

// 6. Controlla directory coda
echo "\n6️⃣  Coda email...\n";
$queueDir = __DIR__ . '/../data/email-queue';
if (is_dir($queueDir)) {
    $queueFiles = count(glob($queueDir . '/*.json'));
    echo "   ✅ Directory coda esiste ($queueFiles file in coda)\n";
    if ($queueFiles > 0) {
        $warnings[] = "Ci sono email in coda - esegui: php scripts/process-email-queue.php";
    }
} else {
    echo "   ℹ️  Directory coda non ancora creata (sarà creata se necessario)\n";
}

// 7. Test di connessione (se PHPMailer e config sono ok)
echo "\n7️⃣  Test di connessione SMTP...\n";
if (file_exists(__DIR__ . '/../vendor/autoload.php') && isset($config) && $config['enabled']) {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $service = $config['service'];
        $smtpConfig = $config[$service] ?? $config['custom'];
        
        $mail->isSMTP();
        $mail->Host = $smtpConfig['host'];
        $mail->Port = $smtpConfig['port'];
        $mail->SMTPSecure = $smtpConfig['secure'] ?: PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = $smtpConfig['auth'];
        $mail->Username = $smtpConfig['username'];
        $mail->Password = $smtpConfig['password'];
        
        if ($mail->smtpConnect()) {
            echo "   ✅ Connessione SMTP OK\n";
            $info[] = "Server SMTP raggiungibile e configurato correttamente";
            $mail->smtpClose();
        } else {
            echo "   ❌ Impossibile connettersi al server SMTP\n";
            $errors[] = "Verifica credenziali SMTP e configurazione server";
        }
    } catch (\Exception $e) {
        echo "   ❌ Errore: {$e->getMessage()}\n";
        $errors[] = $e->getMessage();
    }
} else {
    echo "   ⏭️  Salta (PHPMailer o config non disponibile)\n";
}

// Riepilogo
echo "\n\n📊 RIEPILOGO\n";
echo "============\n";

if (empty($errors) && empty($warnings)) {
    echo "✅ Tutto è configurato correttamente!\n";
    echo "   Prova a inviare una email dal pannello admin.\n";
} else {
    if (!empty($errors)) {
        echo "\n❌ ERRORI (da risolvere):\n";
        foreach ($errors as $i => $error) {
            echo "   " . ($i+1) . ". $error\n";
        }
    }
    
    if (!empty($warnings)) {
        echo "\n⚠️  AVVERTIMENTI (da considerare):\n";
        foreach ($warnings as $i => $warning) {
            echo "   " . ($i+1) . ". $warning\n";
        }
    }
}

if (!empty($info)) {
    echo "\nℹ️  INFO:\n";
    foreach ($info as $i => $inf) {
        echo "   • $inf\n";
    }
}

echo "\n📖 Documentazione: EMAIL_SETUP_GUIDE.md\n";

exit(empty($errors) ? 0 : 1);
?>
