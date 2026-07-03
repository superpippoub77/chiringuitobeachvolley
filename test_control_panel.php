<?php
// Disabilita Xdebug
ini_set('xdebug.mode', 'off');

// Includi le funzioni base dal api.php senza eseguire l'endpoint principale

// Funzione per leggere JSON
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

// Funzione per scrivere JSON
function writeJsonFile(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Funzione per leggere registry
function getTournamentsRegistry(): array {
    $registryFile = __DIR__ . '/data/tournaments.json';
    return readJsonFile($registryFile, ['tournaments' => []]);
}

// Funzione per scrivere registry
function writeTournamentsRegistry(array $data): void {
    writeJsonFile(__DIR__ . '/data/tournaments.json', $data);
}

// Test
echo "=== TEST CONTROL PANEL ===\n";
$email = 'mario@rossi.it';
echo "1. Email: $email\n";

$registry = getTournamentsRegistry();
echo "2. Registry loaded. Keys: " . implode(', ', array_keys($registry)) . "\n";

$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
echo "3. Generated code: $code\n";

// Assicura che controlPanelCodes esista
if (!isset($registry['controlPanelCodes'])) {
    $registry['controlPanelCodes'] = [];
    echo "4. Created controlPanelCodes array\n";
}

$registry['controlPanelCodes'][] = [
    'email' => $email,
    'code' => $code,
    'createdAt' => date('Y-m-d H:i:s'),
    'expiresAt' => date('Y-m-d H:i:s', time() + 3600),
    'used' => false
];
echo "5. Added entry to controlPanelCodes\n";

writeTournamentsRegistry($registry);
echo "6. Registry written successfully\n";

// Verify
$registryCheck = getTournamentsRegistry();
echo "7. Registry re-read. controlPanelCodes count: " . count($registryCheck['controlPanelCodes'] ?? []) . "\n";
echo "8. Last entry: " . json_encode(end($registryCheck['controlPanelCodes'])) . "\n";
echo "\n✅ TEST COMPLETED SUCCESSFULLY\n";
?>
