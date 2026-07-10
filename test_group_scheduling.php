<?php
/**
 * Test: Group-Based Priority Scheduling
 * 
 * Verifica che le partite dello stesso girone siano assegnate al MEDESIMO 
 * giorno e orario PRIMA di passare al girone successivo.
 * 
 * Configurazione:
 * - 16 squadre
 * - 3 gironi
 * - 1 campo con 1 fascia oraria (19:30-23:30)
 * - Match duration: 30 minuti
 */

require_once 'api.php';

echo "=== Test: Group-Based Priority Scheduling ===\n\n";

// 1. Crea una configurazione di test
echo "1️⃣  Setting up test configuration...\n";

$config = [
    "tournament" => [
        "name" => "Test Group Scheduling",
        "maxTeams" => 16,
        "maxPlayersPerTeam" => 3,
        "maxPlayersOnCourt" => 2,
        "maxSubstitutions" => 0,
        "numGroups" => 0,
        "numSets" => 1,
        "winScore" => 21,
        "maxScore" => 25,
        "timePerSetMinutes" => 25,
        "setupTimeMinutes" => 5,
        "maxTimeoutsPerSet" => 2,
        "registrationsClosed" => false,
        "registrationDeadline" => ""
    ],
    "schedule" => [
        "courts" => [
            [
                "courtId" => "court-test-1",
                "courtName" => "Campo 1",
                "availability" => [
                    [
                        "date" => "2026-07-13",
                        "timeSlots" => [
                            [
                                "startTime" => "19:30",
                                "endTime" => "23:30"
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ],
    "phases" => [
        [
            "phaseNumber" => 1,
            "name" => "Gironi",
            "type" => "groups",
            "branch" => "root",
            "qualifiedGoTo" => "Playoff",
            "eliminatedGoTo" => "Ripescaggio",
            "numGroups" => 3,
            "teamsAdvance" => "2,2,1",
            "hasRepescage" => false,
            "notes" => ""
        ]
    ],
    "autosave" => ["enabled" => false],
    "email" => ["enabled" => false],
    "security" => ["encryptionEnabled" => false],
    "contact" => ["managerEmail" => ""],
    "display" => ["theme" => "beachmaster"],
    "sponsors" => [],
    "payment" => ["enabled" => false],
    "news" => []
];

// 2. Crea squadre di test
echo "2️⃣  Creating test teams...\n";

$state = [
    'teams' => [],
    'phases' => []
];

// Crea 16 squadre
for ($i = 1; $i <= 16; $i++) {
    $state['teams'][] = [
        'id' => 'team-' . $i,
        'name' => 'Team ' . $i,
        'approved' => true,
        'paid' => true,
        'players' => [
            ['name' => 'Player ' . $i . '-1', 'isCaptain' => true],
            ['name' => 'Player ' . $i . '-2', 'isCaptain' => false]
        ]
    ];
}

echo "  ✅ Created 16 teams\n";

// 3. Crea i gruppi
echo "\n3️⃣  Creating groups...\n";

$teamIds = array_column($state['teams'], 'id');
$groups = [
    [
        'name' => 'A',
        'teamIds' => array_slice($teamIds, 0, 6)  // 6 teams in group A
    ],
    [
        'name' => 'B',
        'teamIds' => array_slice($teamIds, 6, 5)  // 5 teams in group B
    ],
    [
        'name' => 'C',
        'teamIds' => array_slice($teamIds, 11, 5) // 5 teams in group C
    ]
];

$state['phases'][0] = [
    'phaseNumber' => 1,
    'name' => 'Gironi',
    'type' => 'groups',
    'groups' => $groups
];

echo "  ✅ Created 3 groups:\n";
foreach ($groups as $g) {
    echo "    - Group {$g['name']}: " . count($g['teamIds']) . " teams\n";
}

// 4. Salva config e state
echo "\n4️⃣  Saving config and state...\n";

file_put_contents('data/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents('data/tournament.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "  ✅ Saved config.json and tournament.json\n";

// 5. Carica il config per trigger buildGroupMatchesWithSchedule
echo "\n5️⃣  Generating matches with new scheduler...\n";

// Ricarica lo state dal file
$state = json_decode(file_get_contents('data/tournament.json'), true);

// Chiama buildGroupMatchesWithSchedule
buildGroupMatchesWithSchedule($state);

// 6. Analizza i risultati
echo "\n6️⃣  Analyzing scheduling results...\n\n";

$matches = $state['phases'][0]['matches'] ?? [];

if (empty($matches)) {
    echo "  ❌ ERROR: No matches generated!\n";
    exit(1);
}

echo "  Total matches generated: " . count($matches) . "\n\n";

// Organizza i match per girone
$matchesByGroup = [];
foreach ($matches as $match) {
    $group = $match['group'];
    if (!isset($matchesByGroup[$group])) {
        $matchesByGroup[$group] = [];
    }
    $matchesByGroup[$group][] = $match;
}

// Analizza ogni girone
foreach (['A', 'B', 'C'] as $groupName) {
    if (!isset($matchesByGroup[$groupName])) {
        echo "  ⚠️  Group $groupName: NO MATCHES\n";
        continue;
    }
    
    $groupMatches = $matchesByGroup[$groupName];
    echo "  ▶️  GROUP $groupName (" . count($groupMatches) . " matches):\n";
    
    // Raccogli le date/orari uniche
    $dateTimeBlocks = [];
    foreach ($groupMatches as $match) {
        $key = $match['date'] . ' ' . $match['startTime'];
        if (!isset($dateTimeBlocks[$key])) {
            $dateTimeBlocks[$key] = 0;
        }
        $dateTimeBlocks[$key]++;
    }
    
    // Verifica se tutti i match sono nello stesso orario
    $uniqueBlocks = count($dateTimeBlocks);
    echo "      Date/Time blocks used: $uniqueBlocks\n";
    
    if ($uniqueBlocks === 1) {
        $block = array_key_first($dateTimeBlocks);
        echo "      ✅ ALL MATCHES IN SAME TIME BLOCK: $block\n";
    } else {
        echo "      ⚠️  MATCHES SPREAD ACROSS MULTIPLE TIME BLOCKS:\n";
        foreach ($dateTimeBlocks as $block => $count) {
            echo "         - $block: $count matches\n";
        }
    }
    
    // Mostra i dettagli di ogni match
    echo "      Matches:\n";
    foreach ($groupMatches as $match) {
        $team1 = $match['team1Id'];
        $team2 = $match['team2Id'];
        echo "        {$match['startTime']} - {$match['endTime']}: $team1 vs $team2\n";
    }
    echo "\n";
}

// 7. Verifica il criterio principale
echo "\n7️⃣  Final Verification:\n\n";

$allPassed = true;
$testSummary = [];

foreach (['A', 'B', 'C'] as $groupName) {
    if (!isset($matchesByGroup[$groupName])) continue;
    
    $groupMatches = $matchesByGroup[$groupName];
    
    // Estrai le date/orari
    $dateTimeBlocks = [];
    foreach ($groupMatches as $match) {
        $key = $match['date'] . ' ' . $match['startTime'];
        $dateTimeBlocks[$key] = true;
    }
    
    $uniqueBlocks = count($dateTimeBlocks);
    $testSummary[$groupName] = $uniqueBlocks;
    
    if ($uniqueBlocks === 1) {
        echo "  ✅ Group $groupName: ALL MATCHES IN SAME TIME BLOCK\n";
    } else {
        echo "  ❌ Group $groupName: MATCHES SPREAD ACROSS $uniqueBlocks TIME BLOCKS (FAILED)\n";
        $allPassed = false;
    }
}

echo "\n";
if ($allPassed) {
    echo "✅ TEST PASSED: All groups have their matches in the same time block!\n";
} else {
    echo "❌ TEST FAILED: Some groups have matches spread across different time blocks.\n";
    exit(1);
}

echo "\n=== Test Complete ===\n";
