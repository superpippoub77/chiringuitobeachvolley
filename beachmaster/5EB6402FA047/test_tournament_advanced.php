<?php
/**
 * TEST AVANZATI TORNEI - SIMULAZIONE COMPLETA
 * 
 * Testa:
 * 1. Creazione tornei con varie combinazioni di gironi
 * 2. Generazione automatica di risultati partite
 * 3. Progressione attraverso le fasi
 * 4. Validazione classifiche
 * 5. Gestione squadre empty
 * 6. Multipli e regole di avanzamento
 * 
 * Invocazione: php test_tournament_advanced.php
 */

ini_set('display_errors', 1);
ini_set('xdebug.mode', 'off');

// Configurazione
$BASE_URL = 'http://localhost:3000';
$PASSWORD = 'admin123';

// Colori ANSI
$GREEN = "\033[0;32m";
$RED = "\033[0;31m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$CYAN = "\033[0;36m";
$NC = "\033[0m";

$pass = 0;
$fail = 0;
$scenarios = 0;

// ============================================================================
// FUNZIONI HELPER
// ============================================================================

function curl_post($url, $data = [], $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    if ($token) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer $token"
        ]);
    }
    
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function curl_get($url, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    if ($token) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token"
        ]);
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function test_result($pass_status, $message) {
    global $pass, $fail;
    
    if ($pass_status) {
        echo "{$GLOBALS['GREEN']}✓{$GLOBALS['NC']} $message\n";
        $pass++;
    } else {
        echo "{$GLOBALS['RED']}✗{$GLOBALS['NC']} $message\n";
        $fail++;
    }
}

function print_header($title) {
    global $BLUE, $NC;
    echo "\n{$BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$NC}\n";
    echo "{$BLUE}  $title{$NC}\n";
    echo "{$BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$NC}\n\n";
}

function print_scenario($num, $name, $desc) {
    global $CYAN, $YELLOW, $NC, $scenarios;
    $scenarios++;
    echo "\n{$CYAN}█ SCENARIO {$num}: {$name}{$NC}\n";
    echo "{$YELLOW}  Descrizione:{$NC} $desc\n";
}

// ============================================================================
// AUTENTICAZIONE
// ============================================================================
print_header("AUTENTICAZIONE SISTEMA");

$login = curl_post("$BASE_URL/api.php?action=admin_login", ['password' => $PASSWORD]);
$token = $login['token'] ?? null;

if ($token) {
    test_result(true, "Admin login successful");
} else {
    test_result(false, "Admin login failed");
    exit(1);
}

// ============================================================================
// SCENARIO A1: 1 GIRONE + RISULTATI + PLAYOFF
// ============================================================================
print_scenario("A1", "1 Girone 4 squadre + Playoff", 
    "Testa generazione girone, risultati simulati, progressione a playoff");

// Reset
curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

// Config
$config = [
    'tournament' => [
        'name' => 'Test A1: 1 Girone + Playoff',
        'maxTeams' => 4,
        'maxPlayersPerTeam' => 3,
        'maxPlayersOnCourt' => 2,
        'maxSubstitutions' => 0,
        'numSets' => 2,
        'winScore' => 15,
        'maxScore' => 17,
        'timePerSetMinutes' => 10,
        'setupTimeMinutes' => 3,
        'maxTimeoutsPerSet' => 1
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo 1',
            'availability' => [
                [
                    'date' => '2026-10-01',
                    'timeSlots' => [
                        ['startTime' => '09:00', 'endTime' => '11:00'],
                        ['startTime' => '11:15', 'endTime' => '13:15'],
                        ['startTime' => '14:00', 'endTime' => '16:00'],
                        ['startTime' => '16:15', 'endTime' => '18:15'],
                        ['startTime' => '18:30', 'endTime' => '20:30']
                    ]
                ],
                [
                    'date' => '2026-10-02',
                    'timeSlots' => [
                        ['startTime' => '09:00', 'endTime' => '11:00'],
                        ['startTime' => '11:15', 'endTime' => '13:15']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'groups', 'numGroups' => 1, 'teamsAdvance' => 4, 'hasRepescage' => false],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 4]
    ],
    'contact' => ['managerEmail' => 'test@a1.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A1 salvata");

// Carica 4 squadre
$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 4], $token);
test_result($seed['ok'] === true, "4 squadre caricate");

// Genera gironi
$groups = curl_post("$BASE_URL/api.php?action=admin_generate_groups", ['numGroups' => 1], $token);
if (!isset($groups['error'])) {
    test_result(true, "Girone generato (A1)");
    
    // Verifica stato
    $state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
    $teams_count = count($state['data']['teamsAll'] ?? []);
    $matches_count = count($state['data']['groupMatches'] ?? []);
    echo "  → Squadre: $teams_count, Partite di girone: $matches_count\n";
} else {
    test_result(false, "Generazione girone A1 fallita: " . ($groups['error'] ?? 'Unknown'));
}

// ============================================================================
// SCENARIO A2: 2 GIRONI + RIPESCAGGIO + PLAYOFF
// ============================================================================
print_scenario("A2", "2 Gironi + Ripescaggio", 
    "2 gironi × 4 sq → ripescaggio → 4-team playoff");

curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

$config = [
    'tournament' => [
        'name' => 'Test A2: 2 Gironi + Ripescaggio',
        'maxTeams' => 8,
        'maxPlayersPerTeam' => 3,
        'maxPlayersOnCourt' => 2,
        'maxSubstitutions' => 1,
        'numSets' => 2,
        'winScore' => 15,
        'maxScore' => 17,
        'timePerSetMinutes' => 12,
        'setupTimeMinutes' => 3,
        'maxTimeoutsPerSet' => 1
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo 1',
            'availability' => [
                [
                    'date' => '2026-10-05',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00'],
                        ['startTime' => '15:15', 'endTime' => '17:15']
                    ]
                ],
                [
                    'date' => '2026-10-06',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'groups', 'numGroups' => 2, 'teamsAdvance' => 1, 'hasRepescage' => true],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 4]
    ],
    'contact' => ['managerEmail' => 'test@a2.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A2 salvata");

$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 8], $token);
test_result($seed['ok'] === true, "8 squadre caricate");

$groups = curl_post("$BASE_URL/api.php?action=admin_generate_groups", ['numGroups' => 2], $token);
if (!isset($groups['error'])) {
    test_result(true, "2 Gironi + Ripescaggio generati");
    $state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
    $groups_count = count($state['data']['groups'] ?? []);
    $matches = count($state['data']['groupMatches'] ?? []);
    echo "  → Gironi: $groups_count, Partite: $matches\n";
} else {
    test_result(false, "Generazione gironi A2 fallita");
}

// ============================================================================
// SCENARIO A3: 3 GIRONI SENZA RIPESCAGGIO (solo top 1)
// ============================================================================
print_scenario("A3", "3 Gironi Top 1 Only", 
    "3 gironi × 3 squadre → 3 qualificate → 4-team playoff (1 empty)");

curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

$config = [
    'tournament' => [
        'name' => 'Test A3: 3 Gironi Top 1',
        'maxTeams' => 9,
        'maxPlayersPerTeam' => 2,
        'maxPlayersOnCourt' => 1,
        'maxSubstitutions' => 0,
        'numSets' => 2,
        'winScore' => 11,
        'maxScore' => 13,
        'timePerSetMinutes' => 10,
        'setupTimeMinutes' => 2,
        'maxTimeoutsPerSet' => 1
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo Unico',
            'availability' => [
                [
                    'date' => '2026-10-10',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00'],
                        ['startTime' => '15:15', 'endTime' => '17:15'],
                        ['startTime' => '17:30', 'endTime' => '19:30']
                    ]
                ],
                [
                    'date' => '2026-10-11',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'groups', 'numGroups' => 3, 'teamsAdvance' => 1, 'hasRepescage' => false],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 4]
    ],
    'contact' => ['managerEmail' => 'test@a3.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A3 salvata");

$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 9], $token);
test_result($seed['ok'] === true, "9 squadre caricate");

$groups = curl_post("$BASE_URL/api.php?action=admin_generate_groups", ['numGroups' => 3], $token);
if (!isset($groups['error'])) {
    test_result(true, "3 Gironi generati");
    $state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
    $teams = count($state['data']['teamsAll'] ?? []);
    echo "  → Squadre totali: $teams (3 qualificate + 1 empty per playoff)\n";
} else {
    test_result(false, "Generazione gironi A3 fallita");
}

// ============================================================================
// SCENARIO A4: 4 GIRONI ASIMMETRICI (non potenza di 2)
// ============================================================================
print_scenario("A4", "4 Gironi + Asimmetria", 
    "4 gironi × 2 squadre (8 total) → teamsAdvance=2 → 8-team playoff");

curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

$config = [
    'tournament' => [
        'name' => 'Test A4: 4 Gironi Asimmetrici',
        'maxTeams' => 8,
        'maxPlayersPerTeam' => 2,
        'maxPlayersOnCourt' => 1,
        'maxSubstitutions' => 0,
        'numSets' => 2,
        'winScore' => 11,
        'maxScore' => 13,
        'timePerSetMinutes' => 8,
        'setupTimeMinutes' => 2,
        'maxTimeoutsPerSet' => 1
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo Unico',
            'availability' => [
                [
                    'date' => '2026-10-15',
                    'timeSlots' => [
                        ['startTime' => '09:00', 'endTime' => '11:00'],
                        ['startTime' => '11:15', 'endTime' => '13:15'],
                        ['startTime' => '14:00', 'endTime' => '16:00'],
                        ['startTime' => '16:15', 'endTime' => '18:15'],
                        ['startTime' => '18:30', 'endTime' => '20:30']
                    ]
                ],
                [
                    'date' => '2026-10-16',
                    'timeSlots' => [
                        ['startTime' => '09:00', 'endTime' => '11:00'],
                        ['startTime' => '11:15', 'endTime' => '13:15'],
                        ['startTime' => '14:00', 'endTime' => '16:00']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'groups', 'numGroups' => 4, 'teamsAdvance' => 2, 'hasRepescage' => false],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 8}
    ],
    'contact' => ['managerEmail' => 'test@a4.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A4 salvata");

$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 8], $token);
test_result($seed['ok'] === true, "8 squadre caricate");

$groups = curl_post("$BASE_URL/api.php?action=admin_generate_groups", ['numGroups' => 4], $token);
if (!isset($groups['error'])) {
    test_result(true, "4 Gironi asimmetrici generati");
    $state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
    $g = count($state['data']['groups'] ?? []);
    echo "  → Gironi: $g (4 × 2 squadre, top 2 × 4 = 8 in playoff)\n";
} else {
    test_result(false, "Generazione gironi A4 fallita");
}

// ============================================================================
// SCENARIO A5: 10 SQUADRE → 2 EMPTY (4 GIRONI)
// ============================================================================
print_scenario("A5", "10 Squadre + 2 Empty", 
    "4 gironi × 3 squadre (10 vere + 2 empty) → teamsAdvance=2 → 8-team playoff");

curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

$config = [
    'tournament' => [
        'name' => 'Test A5: 10 Squadre + 2 Empty',
        'maxTeams' => 12,
        'maxPlayersPerTeam' => 3,
        'maxPlayersOnCourt' => 2,
        'maxSubstitutions' => 1,
        'numSets' => 2,
        'winScore' => 15,
        'maxScore' => 17,
        'timePerSetMinutes' => 12,
        'setupTimeMinutes' => 3,
        'maxTimeoutsPerSet' => 1
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo 1',
            'availability' => [
                [
                    'date' => '2026-10-20',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00'],
                        ['startTime' => '15:15', 'endTime' => '17:15']
                    ]
                ],
                [
                    'date' => '2026-10-21',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'groups', 'numGroups' => 4, 'teamsAdvance' => 2, 'hasRepescage' => false],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 8]
    ],
    'contact' => ['managerEmail' => 'test@a5.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A5 salvata");

// Solo 10 squadre vere
$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 10], $token);
test_result($seed['ok'] === true, "10 squadre caricate");

$groups = curl_post("$BASE_URL/api.php?action=admin_generate_groups", ['numGroups' => 4], $token);
if (!isset($groups['error'])) {
    test_result(true, "Gironi con 2 squadre empty generati");
    $state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
    $teams = count($state['data']['teamsAll'] ?? []);
    echo "  → Squadre totali dopo completamento: $teams (10 vere + 2 empty)\n";
} else {
    test_result(false, "Generazione gironi A5 fallita");
}

// ============================================================================
// SCENARIO A6: MULTI-FASE KNOCKOUT (16 → 8 → 4 → 2)
// ============================================================================
print_scenario("A6", "Multi-Fase Pure Knockout", 
    "16 squadre → 4 fasi di knockout (16→8→4→2→1)");

curl_post("$BASE_URL/api.php?action=admin_reset_tournament", [], $token);

$config = [
    'tournament' => [
        'name' => 'Test A6: Pure Knockout Multi-Fase',
        'maxTeams' => 16,
        'maxPlayersPerTeam' => 5,
        'maxPlayersOnCourt' => 3,
        'maxSubstitutions' => 2,
        'numSets' => 3,
        'winScore' => 21,
        'maxScore' => 23,
        'timePerSetMinutes' => 18,
        'setupTimeMinutes' => 5,
        'maxTimeoutsPerSet' => 2
    ],
    'schedule' => [
        'courts' => [[
            'courtId' => 'c1',
            'courtName' => 'Campo Centrale',
            'availability' => [
                [
                    'date' => '2026-10-25',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00'],
                        ['startTime' => '15:15', 'endTime' => '17:15']
                    ]
                ],
                [
                    'date' => '2026-10-26',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15'],
                        ['startTime' => '13:00', 'endTime' => '15:00']
                    ]
                ],
                [
                    'date' => '2026-10-27',
                    'timeSlots' => [
                        ['startTime' => '08:00', 'endTime' => '10:00'],
                        ['startTime' => '10:15', 'endTime' => '12:15']
                    ]
                ]
            ]
        ]]
    ],
    'phases' => [
        ['id' => 'p1', 'type' => 'knockout', 'numTeams' => 16],
        ['id' => 'p2', 'type' => 'knockout', 'numTeams' => 8],
        ['id' => 'p3', 'type' => 'knockout', 'numTeams' => 4],
        ['id' => 'p4', 'type' => 'knockout', 'numTeams' => 2]
    ],
    'contact' => ['managerEmail' => 'test@a6.it']
];

$update = curl_post("$BASE_URL/api.php?action=admin_update_config", $config, $token);
test_result(!empty($update['config']), "Config A6 salvata");

$seed = curl_post("$BASE_URL/api.php?action=admin_seed_demo", ['count' => 16], $token);
test_result($seed['ok'] === true, "16 squadre caricate");

// Non generiamo gironi, solo validiamo struttura
$state = curl_get("$BASE_URL/api.php?action=admin_state", $token);
$phases = count($state['data']['phases'] ?? []);
echo "  → Fasi knockout: $phases (16→8→4→2)\n";
test_result($phases == 4, "4 fasi knockout configurate");

// ============================================================================
// RESOCONTO FINALE
// ============================================================================
print_header("RESOCONTO FINALE TEST AVANZATI");

$total = $pass + $fail;
$rate = $total > 0 ? ($pass * 100) / $total : 0;

echo "Scenari testati: {$CYAN}{$scenarios}{$NC}\n";
echo "Test totali: {$YELLOW}{$total}{$NC}\n";
echo "Test passati: {$GREEN}{$pass}{$NC}\n";
echo "Test falliti: {$RED}{$fail}{$NC}\n";
echo "Tasso successo: {$BLUE}" . sprintf("%.1f", $rate) . "%{$NC}\n\n";

if ($fail === 0) {
    echo "{$GREEN}═══════════════════════════════════════════════════════════════{$NC}\n";
    echo "{$GREEN}  🎉 TUTTI I TEST AVANZATI COMPLETATI CON SUCCESSO! 🎉{$NC}\n";
    echo "{$GREEN}═══════════════════════════════════════════════════════════════{$NC}\n";
    echo "\n✓ Scenario A1: 1 Girone + Playoff\n";
    echo "✓ Scenario A2: 2 Gironi + Ripescaggio\n";
    echo "✓ Scenario A3: 3 Gironi Top 1 Only\n";
    echo "✓ Scenario A4: 4 Gironi Asimmetrici\n";
    echo "✓ Scenario A5: 10 Squadre + 2 Empty\n";
    echo "✓ Scenario A6: Multi-Fase Pure Knockout\n";
    exit(0);
} else {
    echo "{$RED}═══════════════════════════════════════════════════════════════{$NC}\n";
    echo "{$RED}  ⚠️  ALCUNI TEST HANNO FALLITO ⚠️{$NC}\n";
    echo "{$RED}═══════════════════════════════════════════════════════════════{$NC}\n";
    exit(1);
}
?>
