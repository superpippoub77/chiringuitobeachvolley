#!/usr/bin/env php
<?php
/**
 * Script per elaborare la coda email
 * Usare: php scripts/process-email-queue.php
 */

require_once __DIR__ . '/../config/email-config.php';
require_once __DIR__ . '/../api.php';

$processed = 0;
$failed = 0;

// Processa coda root
processQueue(__DIR__ . '/../data/email-queue');

// Processa code nei tournament
$beachmasterDir = __DIR__ . '/../beachmaster';
if (is_dir($beachmasterDir)) {
    foreach (scandir($beachmasterDir) as $tournament) {
        if ($tournament === '.' || $tournament === '..') continue;
        $queuePath = $beachmasterDir . '/' . $tournament . '/data/email-queue';
        if (is_dir($queuePath)) {
            processQueue($queuePath);
        }
    }
}

echo "📊 Risultati: $processed elaborate, $failed fallite\n";
exit($failed > 0 ? 1 : 0);

/**
 * Processa tutti i file nella coda
 */
function processQueue(string $queuePath): void {
    global $processed, $failed;
    
    if (!is_dir($queuePath)) return;
    
    foreach (scandir($queuePath) as $file) {
        if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;
        
        $filePath = $queuePath . '/' . $file;
        $queueData = json_decode(file_get_contents($filePath), true);
        
        if (!$queueData) continue;
        
        // Incrementa tentativo
        $queueData['attempts']++;
        
        // Non provare più di 5 volte
        if ($queueData['attempts'] > 5) {
            $queueData['status'] = 'failed';
            file_put_contents($filePath, json_encode($queueData, JSON_PRETTY_PRINT));
            $failed++;
            continue;
        }
        
        // Prova a inviare
        $result = sendEmail(
            $queueData['to'],
            $queueData['subject'],
            $queueData['body'],
            $queueData['from']
        );
        
        if ($result['success']) {
            $queueData['status'] = 'sent';
            unlink($filePath);
            $processed++;
            echo "✅ Email inviata: {$queueData['to']}\n";
        } else {
            file_put_contents($filePath, json_encode($queueData, JSON_PRETTY_PRINT));
            echo "⏳ Email in coda (tentativo {$queueData['attempts']}): {$queueData['to']}\n";
        }
    }
}
?>
