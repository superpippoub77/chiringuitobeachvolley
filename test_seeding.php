<?php
// Test seeding con la classifica attuale

$classifica = [
    ['seed' => 1, 'name' => 'Giuli-Alby', 'group' => 'B'],
    ['seed' => 2, 'name' => 'Misto Mare', 'group' => 'C'],
    ['seed' => 3, 'name' => 'Prina Colada', 'group' => 'A'],
    ['seed' => 4, 'name' => 'Xxx', 'group' => 'C'],
    ['seed' => 5, 'name' => 'Double B', 'group' => 'B'],
    ['seed' => 6, 'name' => 'Spingere', 'group' => 'A'],
    ['seed' => 7, 'name' => 'le gessi girls', 'group' => 'C'],
    ['seed' => 8, 'name' => 'Dustinucci', 'group' => 'B'],
];

// SEEDING STANDARD (1 vs 8, 2 vs 7, 3 vs 6, 4 vs 5)
echo "=== SEEDING STANDARD (1vs8, 2vs7, 3vs6, 4vs5) ===\n";
$standardMatches = [
    [1, 8], [2, 7], [3, 6], [4, 5]
];

$samgroupCount = 0;
foreach ($standardMatches as $idx => $pair) {
    $t1 = $classifica[$pair[0]-1];
    $t2 = $classifica[$pair[1]-1];
    $same = $t1['group'] === $t2['group'] ? '❌ STESSO GIRONE' : '✓ GIRONI DIVERSI';
    if ($t1['group'] === $t2['group']) $samgroupCount++;
    echo ($idx+1) . ". " . $t1['seed'] . " " . str_pad($t1['name'], 18) . " (" . $t1['group'] . ") vs " . 
         $t2['seed'] . " " . str_pad($t2['name'], 18) . " (" . $t2['group'] . ")  $same\n";
}
echo "⚠️ Rematch dello stesso girone: $samgroupCount su 4\n\n";

// SEEDING CON EVITAMENTO (greedy matching)
echo "=== SEEDING CON EVITAMENTO REMATCH ===\n";

// Dividi in forti e deboli
$forti = array_slice($classifica, 0, 4);  // 1-4
$deboli = array_slice($classifica, 4, 4);  // 5-8

// Greedy: per ogni forte, trova il debole di girone diverso
$usedDeboli = [];
$pairedDeboli = [];

foreach ($forti as $forte) {
    $bestDebole = null;
    
    // Preferenza 1: debole di girone diverso, non ancora usato
    foreach ($deboli as $debole) {
        $debolIdx = $debole['seed'] - 1; // indice nell'array deboli
        if (isset($usedDeboli[$debolIdx])) continue;
        if ($debole['group'] !== $forte['group']) {
            $bestDebole = $debolIdx;
            break;
        }
    }
    
    // Fallback: qualsiasi debole non ancora usato
    if ($bestDebole === null) {
        foreach ($deboli as $debole) {
            $debolIdx = $debole['seed'] - 1;
            if (!isset($usedDeboli[$debolIdx])) {
                $bestDebole = $debolIdx;
                break;
            }
        }
    }
    
    if ($bestDebole !== null) {
        $usedDeboli[$bestDebole] = true;
        $pairedDeboli[] = $deboli[$bestDebole];
    }
}

$samgroupCount2 = 0;
foreach ($forti as $idx => $forte) {
    $debole = $pairedDeboli[$idx];
    $same = $forte['group'] === $debole['group'] ? '❌ STESSO GIRONE' : '✓ GIRONI DIVERSI';
    if ($forte['group'] === $debole['group']) $samgroupCount2++;
    echo ($idx+1) . ". " . $forte['seed'] . " " . str_pad($forte['name'], 18) . " (" . $forte['group'] . ") vs " . 
         $debole['seed'] . " " . str_pad($debole['name'], 18) . " (" . $debole['group'] . ")  $same\n";
}
echo "✅ Rematch del medesimo girone: $samgroupCount2 su 4\n";
?>
