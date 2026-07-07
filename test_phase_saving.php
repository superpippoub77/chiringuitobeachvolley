<?php
/**
 * Test: Verifica che le fasi si salvano correttamente con routing
 */

echo "=== TEST: Phase Saving with Routing ===\n\n";

// Simula il salvataggio delle fasi
$phasesData = [
    [
        "phaseNumber" => 1,
        "name" => "Fase Gironi",
        "type" => "groups",
        "branch" => "root",
        "numGroups" => 4,
        "teamsAdvance" => 2,
        "hasRepescage" => true,
        "qualifiedGoTo" => "Fase Winners Bracket",
        "eliminatedGoTo" => "Fase Losers Bracket"
    ],
    [
        "phaseNumber" => 2,
        "name" => "Winners Bracket",
        "type" => "knockout",
        "branch" => "qualified",
        "numTeams" => 8,
        "qualifiedGoTo" => "Finale",
        "eliminatedGoTo" => "Semifinale Losers"
    ],
    [
        "phaseNumber" => 3,
        "name" => "Losers Bracket",
        "type" => "knockout",
        "branch" => "eliminated",
        "numTeams" => 8,
        "qualifiedGoTo" => "Semifinale Losers",
        "eliminatedGoTo" => "Finale Terzo Posto"
    ]
];

// Prova a salvare sul file temporaneo
$testFile = '/tmp/test-phases-config.json';
file_put_contents($testFile, json_encode($phasesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Leggi il file salvato
$loaded = json_decode(file_get_contents($testFile), true);

echo "✅ TEST 1: Salvataggio Fasi\n";
echo "Fasi salvate: " . count($phasesData) . "\n";
echo "Fasi caricate: " . count($loaded) . "\n\n";

// Test 2: Verifica che i campi routing sono preservati
echo "✅ TEST 2: Campi Routing Preservati\n";
foreach ($loaded as $idx => $phase) {
    echo "\nFase " . ($idx + 1) . ": " . $phase['name'] . "\n";
    echo "  - Branch: " . $phase['branch'] . "\n";
    echo "  - Qualificate vanno a: " . $phase['qualifiedGoTo'] . "\n";
    echo "  - Eliminate/Ripescate vanno a: " . $phase['eliminatedGoTo'] . "\n";
    
    if (empty($phase['qualifiedGoTo']) || empty($phase['eliminatedGoTo'])) {
        echo "  ❌ ERRORE: Campi routing mancanti!\n";
    } else {
        echo "  ✅ OK\n";
    }
}

// Test 3: Test della funzione calculatePhaseTeams
echo "\n\n=== TEST 3: Calcolo Squadre tra Fasi ===\n\n";

function calculatePhaseTeams($phase, $teamsIn) {
    if ($phase['type'] === 'groups') {
        $numGroups = $phase['numGroups'] ?? 4;
        $teamsAdvance = $phase['teamsAdvance'] ?? 2;
        
        $qualified = $numGroups * $teamsAdvance;
        $teamsPerGroup = (int)ceil($teamsIn / $numGroups);
        $totalTeamsInGroups = $numGroups * $teamsPerGroup;
        $eliminated = max(0, $totalTeamsInGroups - $qualified);
        
        return [
            'qualified' => min($qualified, $teamsIn),
            'eliminated' => min($eliminated, $teamsIn)
        ];
    } elseif ($phase['type'] === 'knockout') {
        $numTeams = $phase['numTeams'] ?? 8;
        $qualified = (int)ceil($numTeams / 2);
        $eliminated = (int)floor($numTeams / 2);
        
        return [
            'qualified' => $qualified,
            'eliminated' => $eliminated
        ];
    }
    
    return ['qualified' => $teamsIn, 'eliminated' => 0];
}

// Scenario: 16 squadre totali
$teamsIn = 16;
echo "SCENARIO: 16 squadre iniziali\n\n";

// Fase 1: Gironi
$phase1 = $loaded[0]; // Gironi
$teams1 = calculatePhaseTeams($phase1, $teamsIn);
echo "Fase 1 (Gironi 4x2):\n";
echo "  - Squadre in ingresso: $teamsIn\n";
echo "  - Squadre qualificate: {$teams1['qualified']}\n";
echo "  - Squadre eliminate: {$teams1['eliminated']}\n";
echo "  - Ramo qualificate → " . $phase1['qualifiedGoTo'] . "\n";
echo "  - Ramo eliminate → " . $phase1['eliminatedGoTo'] . "\n\n";

// Fase 2: Winners Bracket
$phase2 = $loaded[1]; // Winners
$teams2 = calculatePhaseTeams($phase2, $teams1['qualified']); // Usa le qualificate della fase 1
echo "Fase 2 (Winners Knockout 8):\n";
echo "  - Squadre in ingresso: {$teams1['qualified']}\n";
echo "  - Squadre qualificate: {$teams2['qualified']}\n";
echo "  - Squadre eliminate: {$teams2['eliminated']}\n\n";

// Fase 3: Losers Bracket
$phase3 = $loaded[2]; // Losers
$teams3 = calculatePhaseTeams($phase3, $teams1['eliminated']); // Usa le eliminate della fase 1
echo "Fase 3 (Losers Knockout 8):\n";
echo "  - Squadre in ingresso: {$teams1['eliminated']}\n";
echo "  - Squadre qualificate: {$teams3['qualified']}\n";
echo "  - Squadre eliminate: {$teams3['eliminated']}\n\n";

echo "✅ Tutti i test completati!\n";

// Cleanup
unlink($testFile);
?>
