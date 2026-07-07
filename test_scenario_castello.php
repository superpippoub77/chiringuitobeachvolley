<?php
/**
 * Test: Scenario specifico dell'utente
 * - 8 squadre iniziali
 * - Fase 1: 2 gironi da 4 squadre, top 2 per girone = 4 qualificate
 * - Ripescaggio abilitato: 4 squadre ripescate
 * - Fase 2 (Winners): Ottavi (4 → 2 vincitrici)
 * - Fase 3 (Losers): Quadrangolare ripescaggio (4 squadre)
 */

echo "=== TEST: Scenario Castello con Ripescaggio (8 Squadre) ===\n\n";

// Helper function
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

$phases = [
    [
        "name" => "Gironi Fase 1",
        "type" => "groups",
        "numGroups" => 2,
        "teamsAdvance" => 2,
        "hasRepescage" => true
    ],
    [
        "name" => "Winners (Ottavi)",
        "type" => "knockout",
        "numTeams" => 4
    ],
    [
        "name" => "Ripescaggio",
        "type" => "groups",
        "numGroups" => 1,
        "teamsAdvance" => 2,
        "hasRepescage" => false
    ]
];

$teamsStart = 8;
echo "INIZIO TORNEO: $teamsStart squadre\n\n";

// Fase 1
$p1 = $phases[0];
$t1 = calculatePhaseTeams($p1, $teamsStart);
echo "FASE 1: {$p1['name']}\n";
echo "  Configurazione: {$p1['numGroups']} gironi, top {$p1['teamsAdvance']} per girone\n";
echo "  Ripescaggio: " . ($p1['hasRepescage'] ? "SÌ" : "NO") . "\n";
echo "  📥 In ingresso: $teamsStart squadre\n";
echo "  ✓ Qualificate al Winners: {$t1['qualified']} squadre\n";
echo "  ✗ Ripescate al Ripescaggio: {$t1['eliminated']} squadre\n";
echo "  \n";

// Fase 2 (Winners) - Usa qualificate dalla fase 1
$p2 = $phases[1];
$t2 = calculatePhaseTeams($p2, $t1['qualified']);
echo "FASE 2: {$p2['name']} (RAMO QUALIFICATE)\n";
echo "  Configurazione: {$p2['numTeams']} squadre, eliminazione diretta\n";
echo "  📥 In ingresso: {$t1['qualified']} squadre (qualificate da Fase 1)\n";
echo "  ✓ Vincitrici: {$t2['qualified']} squadre\n";
echo "  ✗ Perdenti: {$t2['eliminated']} squadre\n";
echo "  \n";

// Fase 3 (Ripescaggio) - Usa eliminate dalla fase 1 + perdenti dalla fase 2
echo "FASE 3: {$phases[2]['name']} (RAMO ELIMINATE)\n";
$p3 = $phases[2];
$ripescaggiTeams = $t1['eliminated'] + $t2['eliminated'];
echo "  Configurazione: {$p3['numGroups']} girone ripescaggio\n";
echo "  📥 In ingresso: $ripescaggiTeams squadre\n";
echo "    - {$t1['eliminated']} ripescate dalla Fase 1\n";
echo "    - {$t2['eliminated']} perdenti dalla Fase 2\n";
$t3 = calculatePhaseTeams($p3, $ripescaggiTeams);
echo "  ✓ Qualificate da ripescaggio: {$t3['qualified']} squadre\n";
echo "  ✗ Eliminate dal torneo: {$t3['eliminated']} squadre\n";
echo "  \n";

// Riepilogo finale - Conta il flusso corretto
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "RIEPILOGO FINALE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Percorsi dei rami:\n\n";
echo "RAMO QUALIFICATE (Winners):\n";
echo "  🥇 Vincitori Finale: {$t2['qualified']} squadra\n";
echo "  🥈 Finalisti Perdenti: {$t2['eliminated']} squadre → vanno al Ripescaggio\n\n";
echo "RAMO RIPESCAGGIO (merge di due rami):\n";
echo "  📥 Squadre in ingresso: {$t1['eliminated']} ripescate dai gironi + {$t2['eliminated']} da Winners = $ripescaggiTeams\n";
echo "  ✓ Qualificate a fase finale: {$t3['qualified']} squadre\n";
echo "  ✗ Eliminate dal ripescaggio: {$t3['eliminated']} squadre\n\n";

// Conta correttamente le squadre
$vincitori = $t2['qualified']; // 1 squadra
$finalisti = $t2['eliminated']; // 2 squadre (perdenti dei semifinali)
$qualifRipescaggio = $t3['qualified']; // 2 squadre (da ripescaggio)
$eliminateRipescaggio = $t3['eliminated']; // 4 squadre
$totalPartecipantiFase3 = $t1['eliminated'] + $t2['eliminated']; // 6 squadre in ripescaggio

echo "Conteggio squadre (tracking completo):\n";
echo "  - Vincitori finale: $vincitori\n";
echo "  - Finalisti (perdenti semifinali): $finalisti\n";
echo "  - Ripescati dai gironi: {$t1['eliminated']}\n";
echo "  = Squadre in fase ripescaggio: $totalPartecipantiFase3\n\n";
echo "  Risultato ripescaggio:\n";
echo "    - Qualificate da ripescaggio: $qualifRipescaggio\n";
echo "    - Eliminate da ripescaggio: $eliminateRipescaggio\n\n";

// Verifica il conteggio totale
$totalUnici = $vincitori + $finalisti + $t1['eliminated'];
echo "Verifica conteggio squadre UNICHE:\n";
echo "  - Fase 1: $teamsStart squadre\n";
echo "  - Fase Winners: {$t2['qualified']} vincitrici + {$t2['eliminated']} perdenti = {$t1['qualified']} da Fase 1\n";
echo "  - Squadre UNICHE: $vincitori (vincitori) + $finalisti (finalisti) + {$t1['eliminated']} (ripescati) = $totalUnici\n";

if ($totalUnici === $teamsStart) {
    echo "\n✅ CORRETTO! Tutte le $teamsStart squadre sono gestite in modo univoco!\n";
    echo "\nFlusso finale:\n";
    echo "  - $vincitori squadra vince il torneo 🏆\n";
    echo "  - $finalisti squadre giocano finale ripescaggio\n";
    echo "  - {$t1['eliminated']} squadre vengono eliminate subito dai gironi\n";
} else {
    echo "\n❌ Errore: $totalUnici ≠ $teamsStart\n";
}

?>
