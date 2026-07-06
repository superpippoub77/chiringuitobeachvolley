<?php
/**
 * Script di seed per popolare dati di test - TENANT
 * Esegui: php beachmaster/A0B647506862/seed-data.php
 */

$dataFile = __DIR__ . '/data/tournament.json';

// Leggi il file attuale o crea uno nuovo
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true);
} else {
    $data = [
        'settings' => ['maxTeams' => 16, 'tournamentName' => 'Test Tournament'],
        'teams' => [],
        'groups' => [],
        'groupMatches' => []
    ];
}

echo "📊 Stato attuale:\n";
echo "  - Squadre totali: " . count($data['teams'] ?? []) . "\n";
echo "  - Squadre approvate: " . count(array_filter($data['teams'] ?? [], fn($t) => $t['approved'] ?? false)) . "\n\n";

// Aggiungi 4 squadre di test se non esiste
if (count($data['teams'] ?? []) < 4) {
    echo "➕ Aggiungendo squadre di test...\n";
    
    while (count($data['teams']) < 4) {
        $data['teams'][] = [
            'id' => substr(md5(uniqid()), 0, 16),
            'name' => 'Test Team ' . (count($data['teams']) + 1),
            'category' => 'Misto',
            'players' => [
                ['name' => 'Bot ' . (count($data['teams']) * 3 + 1), 'isCaptain' => false],
                ['name' => 'Bot ' . (count($data['teams']) * 3 + 2), 'isCaptain' => false],
                ['name' => 'Bot ' . (count($data['teams']) * 3 + 3), 'isCaptain' => false]
            ],
            'phone' => '+39123456789',
            'paid' => true,
            'approved' => true,
            'dummy' => true,
            'createdAt' => date('c')
        ];
    }
}

// Approva tutte le squadre
foreach ($data['teams'] as &$team) {
    $team['approved'] = true;
    $team['paid'] = true;
}

// Salva il file
file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Dati salvati!\n";
echo "📊 Stato finale:\n";
echo "  - Squadre totali: " . count($data['teams']) . "\n";
echo "  - Squadre approvate: " . count(array_filter($data['teams'], fn($t) => $t['approved'] ?? false)) . "\n";
echo "  - File: $dataFile\n";
?>
