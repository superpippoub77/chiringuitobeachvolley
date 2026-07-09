<?php
/**
 * Test: Teams Passing Per Group (Valori Separati da Virgola)
 */

echo "\n🧪 TEST: PARSING TEAMS PER GROUP\n";
echo str_repeat("=", 60) . "\n\n";

// Funzione di parsing (copiata da api.php)
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

// Test Case 1: Singolo numero (retrocompatibilità)
echo "TEST 1️⃣  Singolo numero: teamsAdvance='2', numGroups=4\n";
$result = parseTeamsAdvancePerGroup('2', 4);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [2, 2, 2, 2];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 2: Valori separati da virgola (asymmetric)
echo "TEST 2️⃣  Valori asimmetrici: teamsAdvance='2,2,3', numGroups=3\n";
$result = parseTeamsAdvancePerGroup('2,2,3', 3);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [2, 2, 3];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 3: Valori con spazi
echo "TEST 3️⃣  Con spazi: teamsAdvance=' 2 , 3 , 1 ', numGroups=3\n";
$result = parseTeamsAdvancePerGroup(' 2 , 3 , 1 ', 3);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [2, 3, 1];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 4: Meno valori del numGroups (dovrebbe aggiungere zeri)
echo "TEST 4️⃣  Pochi valori: teamsAdvance='3,2', numGroups=4\n";
$result = parseTeamsAdvancePerGroup('3,2', 4);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [3, 2, 0, 0];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 5: Numero intero (non stringa)
echo "TEST 5️⃣  Numero intero: teamsAdvance=3, numGroups=4\n";
$result = parseTeamsAdvancePerGroup(3, 4);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [3, 3, 3, 3];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 6: Più valori del numGroups (dovrebbe sliceare)
echo "TEST 6️⃣  Troppi valori: teamsAdvance='2,2,2,2,2', numGroups=3\n";
$result = parseTeamsAdvancePerGroup('2,2,2,2,2', 3);
echo "Risultato: " . json_encode($result) . "\n";
$expected = [2, 2, 2];
echo ($result === $expected ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test Case 7: Calcolo somma valori
echo "TEST 7️⃣  Verifica somma: teamsAdvance='2,2,3', numGroups=3\n";
$result = parseTeamsAdvancePerGroup('2,2,3', 3);
$sum = array_sum($result);
echo "Risultato array: " . json_encode($result) . "\n";
echo "Somma totale: $sum (atteso: 7)\n";
echo ($sum === 7 ? "✅ PASS" : "❌ FAIL") . "\n\n";

echo str_repeat("=", 60) . "\n";
echo "✅ TEST COMPLETATI!\n\n";
