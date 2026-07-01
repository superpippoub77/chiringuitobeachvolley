<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DATA_FILE = __DIR__ . '/data/tournament.json';
const SESSION_FILE = __DIR__ . '/data/sessions.json';
const CONFIG_FILE = __DIR__ . '/data/config.json';
const ADMIN_PASSWORD = 'admin123';

function jsonResponse(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

function writeJsonFile(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function defaultConfig(): array {
    return [
        'tournament' => [
            'name' => 'Chiringuito Beach Volley',
            'maxTeams' => 16,
            'numGroups' => 4,
            'numSets' => 2,
            'winScore' => 21,
            'maxScore' => 25,
            'timePerSetMinutes' => 35,
            'setupTimeMinutes' => 5,
            'maxTimeoutsPerSet' => 2
        ],
        'schedule' => [
            'courts' => [
                [
                    'courtId' => '1',
                    'courtName' => 'Campo 1',
                    'availability' => [
                        [
                            'date' => date('Y-m-d'),
                            'timeSlots' => [
                                ['startTime' => '19:30', 'endTime' => '20:30'],
                                ['startTime' => '20:30', 'endTime' => '21:30']
                            ]
                        ]
                    ]
                ],
                [
                    'courtId' => '2',
                    'courtName' => 'Campo 2',
                    'availability' => [
                        [
                            'date' => date('Y-m-d'),
                            'timeSlots' => [
                                ['startTime' => '19:30', 'endTime' => '20:30'],
                                ['startTime' => '20:30', 'endTime' => '21:30']
                            ]
                        ]
                    ]
                ],
                [
                    'courtId' => '3',
                    'courtName' => 'Campo 3',
                    'availability' => [
                        [
                            'date' => date('Y-m-d'),
                            'timeSlots' => [
                                ['startTime' => '19:30', 'endTime' => '20:30'],
                                ['startTime' => '20:30', 'endTime' => '21:30']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        'phases' => [],
        'display' => [
            'theme' => 'chiringuito'
        ]
    ];
}

function mergeConfig(array $existingConfig, array $defaultConfig): array {
    // Funzione per merge ricorsivo intelligente
    $merged = $defaultConfig;
    
    // Preserva i dati critici dell'utente dal config esistente
    if (isset($existingConfig['tournament'])) {
        $merged['tournament'] = array_merge(
            $defaultConfig['tournament'] ?? [],
            $existingConfig['tournament']
        );
    }
    
    if (isset($existingConfig['schedule'])) {
        $merged['schedule'] = $existingConfig['schedule'];
    }
    
    if (isset($existingConfig['phases'])) {
        $merged['phases'] = $existingConfig['phases'];
    }
    
    // Preserva i temi personalizzati
    if (isset($existingConfig['display']['customThemes'])) {
        if (!isset($merged['display'])) {
            $merged['display'] = [];
        }
        $merged['display']['customThemes'] = $existingConfig['display']['customThemes'];
    }
    
    // Preserva il tema corrente se è personalizzato
    if (isset($existingConfig['display']['theme'])) {
        if (!isset($merged['display'])) {
            $merged['display'] = [];
        }
        $merged['display']['theme'] = $existingConfig['display']['theme'];
    }
    
    // Preserva logo e background
    if (isset($existingConfig['display']['logoFile'])) {
        $merged['display']['logoFile'] = $existingConfig['display']['logoFile'];
    }
    if (isset($existingConfig['display']['backgroundFile'])) {
        $merged['display']['backgroundFile'] = $existingConfig['display']['backgroundFile'];
    }
    
    return $merged;
}

function readConfig(): array {
    $default = defaultConfig();
    
    if (!file_exists(CONFIG_FILE)) {
        // Se il file non esiste, crea il default
        writeJsonFile(CONFIG_FILE, $default);
        return $default;
    }
    
    $existing = readJsonFile(CONFIG_FILE, $default);
    
    // Se il config esiste, fai un merge intelligente per upgrade compatibility
    return mergeConfig($existing, $default);
}

function writeConfig(array $config): void {
    writeJsonFile(CONFIG_FILE, $config);
}

function withStateTransaction(callable $callback): array {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile aprire il file dati']);
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        jsonResponse(500, ['ok' => false, 'error' => 'Impossibile bloccare il file dati']);
    }

    $raw = stream_get_contents($fp);
    $loadedState = json_decode($raw ?: '', true);
    
    if (is_array($loadedState)) {
        // Lo state esiste - fai un merge intelligente per upgrade compatibility
        $newState = initialState();
        $state = mergeState($loadedState, $newState);
    } else {
        // Lo state non esiste o non è valido - crea uno nuovo
        $state = initialState();
    }

    $result = $callback($state);
    $state['meta']['lastUpdated'] = gmdate('c');

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return is_array($result) ? $result : [];
}

function initialState(): array {
    $config = readConfig();
    return [
        'settings' => [
            'maxTeams' => $config['tournament']['maxTeams'] ?? 16,
            'tournamentName' => $config['tournament']['name'] ?? 'Torneo Beach Volley Chiringuito'
        ],
        'teams' => [],
        'groups' => [],
        'groupMatches' => [],
        'playoff' => [
            'quarterFinals' => [],
            'semiFinals' => [],
            'thirdPlace' => null,
            'final' => null
        ],
        'finalRanking' => [],
        'meta' => [
            'lastUpdated' => null
        ]
    ];
}

function mergeState(array $existingState, array $newState): array {
    // Preserva i dati critici del torneo dal state esistente
    $merged = $newState;
    
    // Preserva squadre, gironi, partite e risultati
    if (isset($existingState['teams']) && is_array($existingState['teams'])) {
        $merged['teams'] = $existingState['teams'];
    }
    
    if (isset($existingState['groups']) && is_array($existingState['groups'])) {
        $merged['groups'] = $existingState['groups'];
    }
    
    if (isset($existingState['groupMatches']) && is_array($existingState['groupMatches'])) {
        $merged['groupMatches'] = $existingState['groupMatches'];
    }
    
    if (isset($existingState['playoff']) && is_array($existingState['playoff'])) {
        $merged['playoff'] = $existingState['playoff'];
    }
    
    if (isset($existingState['finalRanking']) && is_array($existingState['finalRanking'])) {
        $merged['finalRanking'] = $existingState['finalRanking'];
    }
    
    // Preserva i metadata
    if (isset($existingState['meta']) && is_array($existingState['meta'])) {
        $merged['meta'] = array_merge($merged['meta'] ?? [], $existingState['meta']);
    }
    
    return $merged;
}

function bodyJson(): array {
    $raw = file_get_contents('php://input');
    if (($raw === false || trim($raw) === '') && PHP_SAPI === 'cli') {
        $stdin = stream_get_contents(STDIN);
        if ($stdin !== false) {
            $raw = $stdin;
        }
    }
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse(400, ['ok' => false, 'error' => 'JSON non valido']);
    }
    return $decoded;
}

function uid(): string {
    return bin2hex(random_bytes(8));
}

function randomInt(int $min, int $max): int {
    return random_int($min, $max);
}

function randomScore(): array {
    $a = randomInt(10, 21);
    $b = randomInt(10, 21);
    while ($a === $b) {
        $b = randomInt(10, 21);
    }
    return [$a, $b];
}

function shuffleArray(array $arr): array {
    $copy = $arr;
    shuffle($copy);
    return $copy;
}

function getTeamMap(array $state): array {
    $map = [];
    foreach ($state['teams'] as $team) {
        $map[$team['id']] = $team;
    }
    return $map;
}

function approvedTeams(array $state): array {
    return array_values(array_filter($state['teams'], fn($t) => !empty($t['approved'])));
}

function generateDummyTeamName(int $index): string {
    $names = [
        'Sand Force', 'Wave Warriors', 'Sunset Strikers', 'Beach Kings',
        'Ace Smash', 'Spike Force', 'Net Ninjas', 'Block Party',
        'Dig Deep', 'Serve Smash', 'Jump Set Go', 'Power Volley',
        'Sand Sharks', 'Top Spin', 'Last Stand', 'Blue Fire'
    ];
    return $names[$index % count($names)] . (intdiv($index, count($names)) > 0 ? ' ' . (intdiv($index, count($names))) : '');
}

function tournamentStarted(array $state): bool {
    if (count($state['groups']) > 0 || count($state['groupMatches']) > 0) {
        return true;
    }

    if (
        count($state['playoff']['quarterFinals']) > 0 ||
        count($state['playoff']['semiFinals']) > 0 ||
        $state['playoff']['thirdPlace'] !== null ||
        $state['playoff']['final'] !== null
    ) {
        return true;
    }

    return count($state['finalRanking']) > 0;
}

function publicState(array $state): array {
    $teamMap = getTeamMap($state);
    $config = readConfig();
    $dayDateMap = [];
    foreach ($config['schedule']['days'] ?? [] as $d) {
        $dayDateMap[$d['dayNumber']] = $d['date'];
    }
    return [
        'settings' => $state['settings'],
        'teams' => array_values(array_map(function ($t) {
            return [
                'id' => $t['id'],
                'name' => $t['name'],
                'players' => $t['players'],
                'category' => 'Misto',
                'paid' => (bool)($t['paid'] ?? false),
                'approved' => (bool)($t['approved'] ?? false)
            ];
        }, array_filter($state['teams'], fn($t) => !empty($t['approved'])))),
        'pendingCount' => count(array_filter($state['teams'], fn($t) => empty($t['approved']))),
        'groups' => array_values(array_map(function ($g) use ($teamMap) {
            return [
                'name' => $g['name'],
                'teams' => array_values(array_map(function ($id) use ($teamMap) {
                    return [
                        'id' => $id,
                        'name' => $teamMap[$id]['name'] ?? 'N/D'
                    ];
                }, $g['teamIds']))
            ];
        }, $state['groups'])),
        'groupMatches' => array_values(array_map(function ($m) use ($teamMap, $dayDateMap) {
            return [
                'id' => $m['id'],
                'group' => $m['group'],
                'team1Id' => $m['team1Id'],
                'team2Id' => $m['team2Id'],
                'team1Name' => $teamMap[$m['team1Id']]['name'] ?? 'N/D',
                'team2Name' => $teamMap[$m['team2Id']]['name'] ?? 'N/D',
                'score1' => $m['score1'],
                'score2' => $m['score2'],
                'day' => $m['day'],
                'dayDate' => $dayDateMap[$m['day']] ?? '',
                'time' => $m['time']
            ];
        }, $state['groupMatches'])),
        'standings' => computeStandings($state),
        'playoff' => playoffView($state),
        'finalRanking' => $state['finalRanking'],
        'meta' => $state['meta']
    ];
}

function playoffView(array $state): array {
    $teamMap = getTeamMap($state);
    $mapFn = function ($m) use ($teamMap) {
        return [
            'id' => $m['id'],
            'label' => $m['label'],
            'team1Id' => $m['team1Id'],
            'team2Id' => $m['team2Id'],
            'team1Name' => $teamMap[$m['team1Id']]['name'] ?? '-',
            'team2Name' => $teamMap[$m['team2Id']]['name'] ?? '-',
            'score1' => $m['score1'],
            'score2' => $m['score2']
        ];
    };

    return [
        'quarterFinals' => array_values(array_map($mapFn, $state['playoff']['quarterFinals'])),
        'semiFinals' => array_values(array_map($mapFn, $state['playoff']['semiFinals'])),
        'thirdPlace' => $state['playoff']['thirdPlace'] ? $mapFn($state['playoff']['thirdPlace']) : null,
        'final' => $state['playoff']['final'] ? $mapFn($state['playoff']['final']) : null
    ];
}

function computeStandings(array $state): array {
    $teamMap = getTeamMap($state);
    $out = [];

    foreach ($state['groups'] as $group) {
        $rows = [];
        foreach ($group['teamIds'] as $teamId) {
            $rows[$teamId] = [
                'teamId' => $teamId,
                'name' => $teamMap[$teamId]['name'] ?? 'N/D',
                'played' => 0,
                'won' => 0,
                'lost' => 0,
                'points' => 0,
                'scored' => 0,
                'conceded' => 0,
                'diff' => 0
            ];
        }

        foreach ($state['groupMatches'] as $match) {
            if ($match['group'] !== $group['name']) {
                continue;
            }
            if ($match['score1'] === null || $match['score2'] === null) {
                continue;
            }
            $t1 = $match['team1Id'];
            $t2 = $match['team2Id'];
            if (!isset($rows[$t1], $rows[$t2])) {
                continue;
            }

            $rows[$t1]['played']++;
            $rows[$t2]['played']++;
            $rows[$t1]['scored'] += $match['score1'];
            $rows[$t1]['conceded'] += $match['score2'];
            $rows[$t2]['scored'] += $match['score2'];
            $rows[$t2]['conceded'] += $match['score1'];

            if ($match['score1'] > $match['score2']) {
                $rows[$t1]['won']++;
                $rows[$t2]['lost']++;
                $rows[$t1]['points'] += 2;
            } elseif ($match['score2'] > $match['score1']) {
                $rows[$t2]['won']++;
                $rows[$t1]['lost']++;
                $rows[$t2]['points'] += 2;
            }
        }

        foreach ($rows as &$r) {
            $r['diff'] = $r['scored'] - $r['conceded'];
        }
        unset($r);

        $rows = array_values($rows);
        usort($rows, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['diff'] !== $b['diff']) return $b['diff'] <=> $a['diff'];
            if ($a['scored'] !== $b['scored']) return $b['scored'] <=> $a['scored'];
            return strcmp($a['name'], $b['name']);
        });

        $out[] = [
            'group' => $group['name'],
            'rows' => $rows
        ];
    }

    return $out;
}

function validateScheduleForTournament(array $state): array {
    $config = readConfig();
    $schedule = $config['schedule']['days'] ?? [];

    if (empty($schedule)) {
        return ['valid' => false, 'message' => 'Nessun giorno schedulato nel torneo'];
    }

    // Calcola numero totale di partite nei gironi
    $totalMatches = 0;
    foreach ($state['groups'] as $group) {
        $teamCount = count($group['teamIds']);
        if ($teamCount >= 2) {
            $totalMatches += intdiv($teamCount * ($teamCount - 1), 2);
        }
    }

    // Calcola numero totale di slot disponibili (giorno * timeSlot * courtCount)
    $totalSlots = 0;
    foreach ($schedule as $day) {
        foreach ($day['timeSlots'] as $slot) {
            $totalSlots += ($slot['courtCount'] ?? 1);
        }
    }

    if ($totalMatches === 0) {
        return ['valid' => false, 'message' => 'Nessuna partita da programmare'];
    }

    if ($totalMatches > $totalSlots) {
        return ['valid' => false, 'message' => 'Il sistema non è in grado di generare il torneo sei prega di modificare i giorni e i time range'];
    }

    return ['valid' => true];
}

function buildGroupMatches(array &$state): void {
    $matches = [];
    $day = 1;
    $slot = 0;

    foreach ($state['groups'] as $group) {
        $ids = $group['teamIds'];
        for ($i = 0; $i < count($ids) - 1; $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $hour = 19 + intdiv($slot, 2);
                $mins = ($slot % 2 === 0) ? '30' : '55';
                $matches[] = [
                    'id' => uid(),
                    'group' => $group['name'],
                    'team1Id' => $ids[$i],
                    'team2Id' => $ids[$j],
                    'score1' => null,
                    'score2' => null,
                    'day' => $day,
                    'time' => $hour . ':' . $mins
                ];
                $slot++;
                if ($slot > 5) {
                    $slot = 0;
                    $day++;
                }
            }
        }
    }

    $state['groupMatches'] = $matches;
}

function winnerLoser(?array $match): array {
    if (!$match || $match['score1'] === null || $match['score2'] === null) {
        return ['winner' => null, 'loser' => null];
    }
    if ($match['score1'] > $match['score2']) {
        return ['winner' => $match['team1Id'], 'loser' => $match['team2Id']];
    }
    if ($match['score2'] > $match['score1']) {
        return ['winner' => $match['team2Id'], 'loser' => $match['team1Id']];
    }
    return ['winner' => null, 'loser' => null];
}

function createPlayoff(array &$state): bool {
    $standings = computeStandings($state);
    $first = [];
    $second = [];

    foreach ($standings as $g) {
        if (!empty($g['rows'][0])) $first[] = $g['rows'][0]['teamId'];
        if (!empty($g['rows'][1])) $second[] = $g['rows'][1]['teamId'];
    }

    $qualified = array_slice(array_merge($first, $second), 0, 8);
    if (count($qualified) < 8) {
        return false;
    }

    $state['playoff']['quarterFinals'] = [
        ['id' => uid(), 'label' => 'QF1', 'team1Id' => $qualified[0], 'team2Id' => $qualified[7], 'score1' => null, 'score2' => null],
        ['id' => uid(), 'label' => 'QF2', 'team1Id' => $qualified[3], 'team2Id' => $qualified[4], 'score1' => null, 'score2' => null],
        ['id' => uid(), 'label' => 'QF3', 'team1Id' => $qualified[1], 'team2Id' => $qualified[6], 'score1' => null, 'score2' => null],
        ['id' => uid(), 'label' => 'QF4', 'team1Id' => $qualified[2], 'team2Id' => $qualified[5], 'score1' => null, 'score2' => null]
    ];

    $state['playoff']['semiFinals'] = [
        ['id' => uid(), 'label' => 'SF1', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null],
        ['id' => uid(), 'label' => 'SF2', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null]
    ];
    $state['playoff']['thirdPlace'] = ['id' => uid(), 'label' => '3P', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null];
    $state['playoff']['final'] = ['id' => uid(), 'label' => 'F', 'team1Id' => null, 'team2Id' => null, 'score1' => null, 'score2' => null];

    return true;
}

function updatePlayoffTree(array &$state): void {
    $qf = $state['playoff']['quarterFinals'];
    $sf = &$state['playoff']['semiFinals'];
    $final = &$state['playoff']['final'];
    $third = &$state['playoff']['thirdPlace'];

    if (count($qf) !== 4 || count($sf) !== 2 || !$final || !$third) {
        return;
    }

    $qw = array_map(fn($m) => winnerLoser($m)['winner'], $qf);
    $sf[0]['team1Id'] = $qw[0];
    $sf[0]['team2Id'] = $qw[1];
    $sf[1]['team1Id'] = $qw[2];
    $sf[1]['team2Id'] = $qw[3];

    $sf1 = winnerLoser($sf[0]);
    $sf2 = winnerLoser($sf[1]);

    $final['team1Id'] = $sf1['winner'];
    $final['team2Id'] = $sf2['winner'];
    $third['team1Id'] = $sf1['loser'];
    $third['team2Id'] = $sf2['loser'];
}

function computeFinalRanking(array &$state): void {
    $teamMap = getTeamMap($state);
    $standings = computeStandings($state);
    $rankingIds = [];

    $finalWL = winnerLoser($state['playoff']['final']);
    $thirdWL = winnerLoser($state['playoff']['thirdPlace']);

    if ($finalWL['winner']) $rankingIds[] = $finalWL['winner'];
    if ($finalWL['loser']) $rankingIds[] = $finalWL['loser'];
    if ($thirdWL['winner']) $rankingIds[] = $thirdWL['winner'];
    if ($thirdWL['loser']) $rankingIds[] = $thirdWL['loser'];

    foreach ($state['playoff']['semiFinals'] as $sf) {
        $wl = winnerLoser($sf);
        if ($wl['loser']) $rankingIds[] = $wl['loser'];
    }

    foreach ($standings as $g) {
        foreach ($g['rows'] as $r) {
            $rankingIds[] = $r['teamId'];
        }
    }

    $rankingIds = array_values(array_unique($rankingIds));
    $state['finalRanking'] = [];
    foreach ($rankingIds as $idx => $teamId) {
        $state['finalRanking'][] = [
            'position' => $idx + 1,
            'teamId' => $teamId,
            'name' => $teamMap[$teamId]['name'] ?? 'N/D'
        ];
    }
}

function calculateMatchDuration(array $state, ?int $score1, ?int $score2): int {
    $config = readConfig();
    $tournament = $config['tournament'];
    
    if ($score1 === null || $score2 === null) {
        return $tournament['setupTimeMinutes'] + ($tournament['numSets'] * $tournament['timePerSetMinutes']);
    }
    
    $setsPlayed = 1;
    $s1Win = ($score1 >= $tournament['winScore'] && $score1 - $score2 >= 2);
    $s2Win = ($score2 >= $tournament['winScore'] && $score2 - $score1 >= 2);
    
    if ($s1Win || $s2Win) {
        $setsPlayed = 1;
    } elseif (($score1 >= $tournament['winScore'] && $score2 >= $tournament['winScore'] - 1) ||
              ($score2 >= $tournament['winScore'] && $score1 >= $tournament['winScore'] - 1)) {
        $setsPlayed = 2;
    }
    
    return $tournament['setupTimeMinutes'] + ($setsPlayed * $tournament['timePerSetMinutes']);
}

function parseTime(string $timeStr): int {
    [$h, $m] = explode(':', $timeStr);
    return (int)$h * 60 + (int)$m;
}

function addMinutes(string $timeStr, int $minutes): string {
    $totalMin = parseTime($timeStr) + $minutes;
    $h = intdiv($totalMin, 60);
    $m = $totalMin % 60;
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

function buildGroupMatchesWithSchedule(array &$state): void {
    $config = readConfig();
    $schedule = $config['schedule']['days'] ?? [];
    
    if (empty($schedule)) {
        buildGroupMatches($state);
        return;
    }
    
    $matches = [];
    $dayIndex = 0;
    $slotIndex = 0;
    $matchesInSlot = 0;
    
    foreach ($state['groups'] as $group) {
        $groupMatches = [];
        $teamIds = $group['teamIds'];
        for ($i = 0; $i < count($teamIds) - 1; $i++) {
            for ($j = $i + 1; $j < count($teamIds); $j++) {
                $groupMatches[] = [
                    'id' => uid(),
                    'group' => $group['name'],
                    'team1Id' => $teamIds[$i],
                    'team2Id' => $teamIds[$j],
                    'score1' => null,
                    'score2' => null,
                    'day' => null,
                    'time' => null,
                    'endTime' => null,
                    'timeSlot' => null
                ];
            }
        }
        
        // Raggruppa partite dello stesso girone nello stesso giorno
        foreach ($groupMatches as $match) {
            if ($dayIndex >= count($schedule)) {
                // Finiti gli slot disponibili
                break 2;
            }
            
            $courtsPerSlot = $schedule[$dayIndex]['timeSlots'][$slotIndex]['courtCount'] ?? 3;
            
            if ($matchesInSlot >= $courtsPerSlot) {
                $slotIndex++;
                $matchesInSlot = 0;
                
                if ($slotIndex >= count($schedule[$dayIndex]['timeSlots'])) {
                    $dayIndex++;
                    $slotIndex = 0;
                }
            }
            
            if ($dayIndex >= count($schedule)) {
                // Finiti gli slot disponibili
                break 2;
            }
            
            $currentDay = $schedule[$dayIndex];
            $currentSlot = $currentDay['timeSlots'][$slotIndex] ?? null;
            
            if ($currentDay && $currentSlot) {
                $match['day'] = $currentDay['dayNumber'] ?? ($dayIndex + 1);
                $match['timeSlot'] = $slotIndex;
                $match['time'] = $currentSlot['startTime'];
                $duration = calculateMatchDuration($state, $match['score1'], $match['score2']);
                $match['endTime'] = addMinutes($match['time'], $duration);
            }
            
            $matches[] = $match;
            $matchesInSlot++;
        }
    }
    
    $state['groupMatches'] = $matches;
}

function simulateAll(array &$state): bool {
    if (count($state['groups']) === 0) {
        $approved = shuffleArray(approvedTeams($state));
        $approved = array_slice($approved, 0, (int)$state['settings']['maxTeams']);
        if (count($approved) < 4) {
            return false;
        }

        $groupCount = min(4, max(1, (int)ceil(count($approved) / 4)));
        $groups = [];
        for ($i = 0; $i < $groupCount; $i++) {
            $groups[] = ['name' => chr(65 + $i), 'teamIds' => []];
        }
        foreach ($approved as $i => $team) {
            $groups[$i % $groupCount]['teamIds'][] = $team['id'];
        }

        $state['groups'] = $groups;
        buildGroupMatches($state);
    }

    foreach ($state['groupMatches'] as &$match) {
        [$a, $b] = randomScore();
        $match['score1'] = $a;
        $match['score2'] = $b;
    }
    unset($match);

    if (count($state['playoff']['quarterFinals']) === 0) {
        if (!createPlayoff($state)) {
            return false;
        }
    }

    foreach ($state['playoff']['quarterFinals'] as &$qf) {
        [$a, $b] = randomScore();
        $qf['score1'] = $a;
        $qf['score2'] = $b;
    }
    unset($qf);

    updatePlayoffTree($state);

    foreach ($state['playoff']['semiFinals'] as &$sf) {
        if (!$sf['team1Id'] || !$sf['team2Id']) continue;
        [$a, $b] = randomScore();
        $sf['score1'] = $a;
        $sf['score2'] = $b;
    }
    unset($sf);

    updatePlayoffTree($state);

    if ($state['playoff']['thirdPlace']['team1Id'] && $state['playoff']['thirdPlace']['team2Id']) {
        [$a, $b] = randomScore();
        $state['playoff']['thirdPlace']['score1'] = $a;
        $state['playoff']['thirdPlace']['score2'] = $b;
    }
    if ($state['playoff']['final']['team1Id'] && $state['playoff']['final']['team2Id']) {
        [$a, $b] = randomScore();
        $state['playoff']['final']['score1'] = $a;
        $state['playoff']['final']['score2'] = $b;
    }

    computeFinalRanking($state);
    return true;
}

function authToken(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (strpos($auth, 'Bearer ') !== 0) return null;
    return substr($auth, 7);
}

function validSession(string $token): bool {
    $sessions = readJsonFile(SESSION_FILE, ['tokens' => []]);
    return in_array($token, $sessions['tokens'], true);
}

function requireAdmin(): void {
    $token = authToken();
    if (!$token || !validSession($token)) {
        jsonResponse(401, ['ok' => false, 'error' => 'Non autorizzato']);
    }
}

$stateInit = readJsonFile(DATA_FILE, initialState());
if ($stateInit === []) {
    writeJsonFile(DATA_FILE, initialState());
}
readJsonFile(SESSION_FILE, ['tokens' => []]);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'get_public' && $method === 'GET') {
    $state = readJsonFile(DATA_FILE, initialState());
    jsonResponse(200, ['ok' => true, 'data' => publicState($state)]);
}

if ($action === 'register_team' && $method === 'POST') {
    $body = bodyJson();
    $name = trim((string)($body['name'] ?? ''));
    $p1 = trim((string)($body['player1'] ?? ''));
    $p2 = trim((string)($body['player2'] ?? ''));
    $p3 = trim((string)($body['player3'] ?? ''));
    $category = 'Misto';
    $phone = trim((string)($body['phone'] ?? ''));

    if ($name === '' || $p1 === '' || $p2 === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'Compila nome squadra e almeno 2 giocatori']);
    }

    withStateTransaction(function (&$state) use ($name, $p1, $p2, $p3, $category, $phone) {
        foreach ($state['teams'] as $team) {
            if (strtolower($team['name']) === strtolower($name)) {
                jsonResponse(409, ['ok' => false, 'error' => 'Nome squadra gia presente']);
            }
        }

        if (count($state['teams']) >= (int)$state['settings']['maxTeams']) {
            jsonResponse(422, ['ok' => false, 'error' => 'Torneo pieno']);
        }

        $state['teams'][] = [
            'id' => uid(),
            'name' => $name,
            'category' => $category,
            'players' => [$p1, $p2, $p3],
            'phone' => $phone,
            'paid' => false,
            'approved' => false,
            'createdAt' => gmdate('c')
        ];

        return ['message' => 'Registrazione inviata. In attesa approvazione admin.'];
    });

    jsonResponse(200, ['ok' => true, 'message' => 'Registrazione inviata. In attesa approvazione admin.']);
}

if ($action === 'admin_login' && $method === 'POST') {
    $body = bodyJson();
    $password = (string)($body['password'] ?? '');

    if ($password !== ADMIN_PASSWORD) {
        jsonResponse(401, ['ok' => false, 'error' => 'Password amministratore non valida']);
    }

    $token = bin2hex(random_bytes(20));
    $sessions = readJsonFile(SESSION_FILE, ['tokens' => []]);
    $sessions['tokens'][] = $token;
    $sessions['tokens'] = array_values(array_unique($sessions['tokens']));
    writeJsonFile(SESSION_FILE, $sessions);

    jsonResponse(200, ['ok' => true, 'token' => $token]);
}

if (str_starts_with($action, 'admin_')) {
    requireAdmin();
}

if ($action === 'admin_state' && $method === 'GET') {
    $state = readJsonFile(DATA_FILE, initialState());
    jsonResponse(200, ['ok' => true, 'data' => array_merge(publicState($state), ['teamsAll' => $state['teams']])]);
}

if ($action === 'admin_update_team' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    if ($id === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'ID squadra obbligatorio']);
    }

    withStateTransaction(function (&$state) use ($body, $id) {
        $found = false;
        foreach ($state['teams'] as &$team) {
            if ($team['id'] !== $id) continue;
            $found = true;
            if (isset($body['name'])) {
                $name = trim((string)$body['name']);
                if ($name !== '') {
                    $team['name'] = $name;
                }
            }
            if (isset($body['paid'])) {
                $team['paid'] = (bool)$body['paid'];
            }
            if (isset($body['approved'])) {
                $team['approved'] = (bool)$body['approved'];
            }
            if (isset($body['players']) && is_array($body['players'])) {
                $team['players'] = array_values(array_pad(array_slice($body['players'], 0, 3), 3, ''));
            }
            if (isset($body['phone'])) {
                $team['phone'] = trim((string)$body['phone']);
            }
            $team['category'] = 'Misto';
            break;
        }
        unset($team);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_delete_team' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    if ($id === '') {
        jsonResponse(422, ['ok' => false, 'error' => 'ID squadra obbligatorio']);
    }

    withStateTransaction(function (&$state) use ($id) {
        if (tournamentStarted($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Non puoi cancellare squadre: il torneo e gia iniziato']);
        }

        $before = count($state['teams']);
        $state['teams'] = array_values(array_filter($state['teams'], fn($t) => $t['id'] !== $id));

        if (count($state['teams']) === $before) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_generate_groups' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        $approved = approvedTeams($state);
        if (count($approved) < 4) {
            jsonResponse(422, ['ok' => false, 'error' => 'Servono almeno 4 squadre approvate']);
        }

        $maxTeams = (int)$state['settings']['maxTeams'];
        $approved = array_slice(shuffleArray($approved), 0, $maxTeams);

        // Aggiungere squadre fittive se non si raggiunge il massimo
        while (count($approved) < $maxTeams) {
            $dummyTeam = [
                'id' => uid(),
                'name' => generateDummyTeamName(count($approved)),
                'category' => 'Misto',
                'players' => ['Bot 1', 'Bot 2', 'Bot 3'],
                'phone' => '',
                'paid' => true,
                'approved' => true,
                'dummy' => true,
                'createdAt' => gmdate('c')
            ];
            $state['teams'][] = $dummyTeam;
            $approved[] = $dummyTeam;
        }

        $groupCount = min(4, max(1, (int)ceil(count($approved) / 4)));
        $groups = [];
        for ($i = 0; $i < $groupCount; $i++) {
            $groups[] = ['name' => chr(65 + $i), 'teamIds' => []];
        }
        foreach ($approved as $idx => $team) {
            $groups[$idx % $groupCount]['teamIds'][] = $team['id'];
        }

        $state['groups'] = $groups;
        
        // Valida lo schedule prima di generare le partite
        $validation = validateScheduleForTournament($state);
        if (!$validation['valid']) {
            jsonResponse(422, ['ok' => false, 'error' => $validation['message']]);
        }
        
        buildGroupMatchesWithSchedule($state);
        $state['playoff'] = [
            'quarterFinals' => [],
            'semiFinals' => [],
            'thirdPlace' => null,
            'final' => null
        ];
        $state['finalRanking'] = [];

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_move_team_group' && $method === 'POST') {
    $body = bodyJson();
    $teamId = (string)($body['teamId'] ?? '');
    $newGroup = (string)($body['newGroup'] ?? '');

    withStateTransaction(function (&$state) use ($teamId, $newGroup) {
        // Verifica che il torneo non sia iniziato
        if (tournamentStarted($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Impossibile spostare squadre: il torneo è già iniziato']);
        }

        // Trova il girone attuale
        $currentGroup = null;
        $currentGroupIdx = null;
        foreach ($state['groups'] as $idx => &$group) {
            $pos = array_search($teamId, $group['teamIds'], true);
            if ($pos !== false) {
                $currentGroup = $group['name'];
                $currentGroupIdx = $idx;
                unset($group['teamIds'][$pos]);
                $group['teamIds'] = array_values($group['teamIds']); // Re-index array
                break;
            }
        }

        if ($currentGroup === null) {
            jsonResponse(404, ['ok' => false, 'error' => 'Squadra non trovata in nessun girone']);
        }

        if ($currentGroup === $newGroup) {
            jsonResponse(400, ['ok' => false, 'error' => 'La squadra è già in questo girone']);
        }

        // Aggiunge squadra al nuovo girone
        $newGroupFound = false;
        foreach ($state['groups'] as &$group) {
            if ($group['name'] === $newGroup) {
                $group['teamIds'][] = $teamId;
                $newGroupFound = true;
                break;
            }
        }

        if (!$newGroupFound) {
            jsonResponse(404, ['ok' => false, 'error' => 'Girone di destinazione non trovato']);
        }

        // Rigenerare le partite
        buildGroupMatches($state);

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_update_group_match' && $method === 'POST') {
    $body = bodyJson();
    $id = (string)($body['id'] ?? '');

    withStateTransaction(function (&$state) use ($body, $id) {
        $found = false;
        foreach ($state['groupMatches'] as &$m) {
            if ($m['id'] !== $id) continue;
            $found = true;
            if (array_key_exists('score1', $body)) {
                $m['score1'] = is_null($body['score1']) ? null : (int)$body['score1'];
            }
            if (array_key_exists('score2', $body)) {
                $m['score2'] = is_null($body['score2']) ? null : (int)$body['score2'];
            }
            if (isset($body['time'])) {
                $m['time'] = trim((string)$body['time']);
            }
            if (isset($body['day'])) {
                $m['day'] = (int)$body['day'];
            }
            break;
        }
        unset($m);

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Partita non trovata']);
        }

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_create_playoff' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (!createPlayoff($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Playoff non generabile: servono almeno 8 squadre classificate']);
        }
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_update_playoff_match' && $method === 'POST') {
    $body = bodyJson();
    $phase = (string)($body['phase'] ?? '');
    $id = (string)($body['id'] ?? '');

    withStateTransaction(function (&$state) use ($body, $phase, $id) {
        $found = false;

        if (in_array($phase, ['quarterFinals', 'semiFinals'], true)) {
            foreach ($state['playoff'][$phase] as &$m) {
                if ($m['id'] !== $id) continue;
                $found = true;
                $m['score1'] = array_key_exists('score1', $body) ? (is_null($body['score1']) ? null : (int)$body['score1']) : $m['score1'];
                $m['score2'] = array_key_exists('score2', $body) ? (is_null($body['score2']) ? null : (int)$body['score2']) : $m['score2'];
                break;
            }
            unset($m);
        } elseif (in_array($phase, ['thirdPlace', 'final'], true)) {
            if ($state['playoff'][$phase] && $state['playoff'][$phase]['id'] === $id) {
                $found = true;
                $state['playoff'][$phase]['score1'] = array_key_exists('score1', $body) ? (is_null($body['score1']) ? null : (int)$body['score1']) : $state['playoff'][$phase]['score1'];
                $state['playoff'][$phase]['score2'] = array_key_exists('score2', $body) ? (is_null($body['score2']) ? null : (int)$body['score2']) : $state['playoff'][$phase]['score2'];
            }
        }

        if (!$found) {
            jsonResponse(404, ['ok' => false, 'error' => 'Match playoff non trovato']);
        }

        updatePlayoffTree($state);
        computeFinalRanking($state);
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_simulate_all' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (!simulateAll($state)) {
            jsonResponse(422, ['ok' => false, 'error' => 'Simulazione non possibile: approva almeno 4 squadre']);
        }
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_seed_demo' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        $demo = [
            'Sunset Duo','Sand Storm','Wave Riders','Beach Kings','Ace Smash','Spike Force','Net Ninjas','Block Party',
            'Dig Deep','Serve Smash','Jump Set Go','Power Volley','Sand Sharks','Top Spin','Last Stand','Blue Fire'
        ];

        $state['teams'] = [];
        foreach ($demo as $idx => $name) {
            $state['teams'][] = [
                'id' => uid(),
                'name' => $name,
                'category' => 'Misto',
                'players' => ['Giocatore ' . ($idx + 1), 'Giocatrice ' . ($idx + 1), ''],
                'phone' => '',
                'paid' => (bool)($idx % 2),
                'approved' => true,
                'createdAt' => gmdate('c')
            ];
        }

        $state['groups'] = [];
        $state['groupMatches'] = [];
        $state['playoff'] = ['quarterFinals' => [], 'semiFinals' => [], 'thirdPlace' => null, 'final' => null];
        $state['finalRanking'] = [];

        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_reset' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        $state = initialState();
        return ['ok' => true];
    });

    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_upload_logo' && $method === 'POST') {
    if (!isset($_FILES['logo'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $file = $_FILES['logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(CONFIG_FILE) . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'tournament-logo.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    $config['display']['logoFile'] = 'data/uploads/' . $filename;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'logoFile' => $config['display']['logoFile']]);
}

if ($action === 'admin_upload_background' && $method === 'POST') {
    if (!isset($_FILES['background'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nessun file caricato']);
    }

    $file = $_FILES['background'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, ['ok' => false, 'error' => 'Errore nel caricamento del file']);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Estensione file non valida']);
    }

    $uploadsDir = dirname(CONFIG_FILE) . '/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }

    $filename = 'tournament-background.' . $ext;
    $filepath = $uploadsDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore nel salvataggio del file']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    $config['display']['backgroundFile'] = 'data/uploads/' . $filename;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'backgroundFile' => $config['display']['backgroundFile']]);
}

if ($action === 'admin_export_backup' && $method === 'GET') {
    $config = readConfig();
    $state = readJsonFile(DATA_FILE, initialState());
    
    $backup = [
        'exportDate' => gmdate('c'),
        'version' => '1.0',
        'config' => $config,
        'state' => $state
    ];
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="chiringuito-backup-' . date('Y-m-d-His') . '.json"');
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'admin_import_backup' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $backup = json_decode($raw, true);
    
    if (!is_array($backup)) {
        jsonResponse(400, ['ok' => false, 'error' => 'File backup non valido']);
    }
    
    if (!isset($backup['config']) || !isset($backup['state'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Backup incompleto: mancano config o state']);
    }
    
    try {
        // Ripristina la configurazione
        if (is_array($backup['config'])) {
            writeConfig($backup['config']);
        }
        
        // Ripristina lo stato del torneo
        if (is_array($backup['state'])) {
            writeJsonFile(DATA_FILE, $backup['state']);
        }
        
        jsonResponse(200, ['ok' => true, 'message' => 'Backup ripristinato con successo']);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore durante il ripristino: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_reset_tournament' && $method === 'POST') {
    try {
        // Ripristina configurazione ai valori di default
        $defaultCfg = defaultConfig();
        writeConfig($defaultCfg);
        
        // Ripristina stato ai valori iniziali
        $initialData = initialState();
        writeJsonFile(DATA_FILE, $initialData);
        
        jsonResponse(200, ['ok' => true, 'message' => 'Torneo azzerato completamente. Sistema ripristinato a stato iniziale.']);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => 'Errore durante il reset: ' . $e->getMessage()]);
    }
}

if ($action === 'admin_save_custom_theme' && $method === 'POST') {
    $body = bodyJson();
    $themeName = trim((string)($body['name'] ?? 'Tema Personalizzato'));
    $headingFont = trim((string)($body['headingFont'] ?? 'Poppins'));
    $bodyFont = trim((string)($body['bodyFont'] ?? 'Roboto'));
    $primaryColor = trim((string)($body['primaryColor'] ?? '#667eea'));
    $secondaryColor = trim((string)($body['secondaryColor'] ?? '#764ba2'));
    $headingColor = trim((string)($body['headingColor'] ?? '#000000'));

    if (empty($themeName)) {
        jsonResponse(400, ['ok' => false, 'error' => 'Nome tema richiesto']);
    }

    $config = readConfig();
    if (!isset($config['display'])) {
        $config['display'] = [];
    }
    if (!isset($config['display']['customThemes'])) {
        $config['display']['customThemes'] = [];
    }

    $customTheme = [
        'id' => 'custom_' . time(),
        'name' => $themeName,
        'headingFont' => $headingFont,
        'bodyFont' => $bodyFont,
        'primaryColor' => $primaryColor,
        'secondaryColor' => $secondaryColor,
        'headingColor' => $headingColor,
        'createdAt' => gmdate('c')
    ];

    $config['display']['customThemes'][] = $customTheme;
    writeConfig($config);

    jsonResponse(200, ['ok' => true, 'theme' => $customTheme]);
}

if ($action === 'admin_get_custom_themes' && $method === 'GET') {
    $config = readConfig();
    $customThemes = $config['display']['customThemes'] ?? [];
    jsonResponse(200, ['ok' => true, 'customThemes' => $customThemes]);
}

if ($action === 'admin_delete_custom_theme' && $method === 'POST') {
    $body = bodyJson();
    $themeId = trim((string)($body['themeId'] ?? ''));

    if (empty($themeId)) {
        jsonResponse(400, ['ok' => false, 'error' => 'ID tema richiesto']);
    }

    $config = readConfig();
    if (!isset($config['display']['customThemes'])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Tema non trovato']);
    }

    $config['display']['customThemes'] = array_filter(
        $config['display']['customThemes'],
        fn($t) => $t['id'] !== $themeId
    );

    if (isset($config['display']['theme']) && $config['display']['theme'] === $themeId) {
        $config['display']['theme'] = 'chiringuito';
    }

    writeConfig($config);
    jsonResponse(200, ['ok' => true, 'message' => 'Tema eliminato']);
}

if ($action === 'admin_validate_config' && $method === 'GET') {
    $config = readConfig();
    $errors = [];
    $details = [];
    
    // Valida tournament
    if (empty($config['tournament']['name'])) $errors[] = 'Nome torneo mancante';
    if (($config['tournament']['maxTeams'] ?? 0) < 2) $errors[] = 'Numero massimo squadre insufficiente';
    
    // Valida schedule
    $courts = $config['schedule']['courts'] ?? [];
    if (empty($courts)) {
        $errors[] = 'Nessun campo configurato';
    } else {
        $details[] = 'Campi configurati: ' . count($courts);
        $totalSlots = 0;
        foreach ($courts as $court) {
            $courtSlots = 0;
            foreach ($court['availability'] ?? [] as $avail) {
                $courtSlots += count($avail['timeSlots'] ?? []);
            }
            $totalSlots += $courtSlots;
        }
        $details[] = 'Slot temporali totali: ' . $totalSlots;
    }
    
    // Valida phases
    $phases = $config['phases'] ?? [];
    if (empty($phases)) {
        $errors[] = 'Nessuna fase configurata';
    } else {
        $details[] = 'Fasi configurate: ' . count($phases);
        foreach ($phases as $p) {
            if ($p['type'] === 'knockout' && !in_array($p['numTeams'], [2,4,8,16,32,64,128])) {
                $errors[] = 'Fase knockout deve avere un numero di squadre potenza di 2';
            }
        }
    }
    
    $valid = empty($errors);
    jsonResponse(200, ['ok' => true, 'valid' => $valid, 'errors' => $errors, 'details' => $details]);
}

if ($action === 'admin_load_tournament_template' && $method === 'POST') {
    try {
        $body = bodyJson();
        $templateId = $body['templateId'] ?? '';
        
        jsonResponse(200, ['ok' => true, 'message' => 'Template caricato', 'templateId' => $templateId]);
    } catch (Exception $e) {
        jsonResponse(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'admin_get_config' && $method === 'GET') {
    $config = readConfig();
    jsonResponse(200, ['ok' => true, 'config' => $config]);
}

if ($action === 'get_config' && $method === 'GET') {
    // Endpoint pubblico per ottenere la configurazione display (tema e logo)
    $config = readConfig();
    $publicConfig = [
        'display' => $config['display'] ?? []
    ];
    jsonResponse(200, ['ok' => true, 'config' => $publicConfig]);
}

if ($action === 'get_themes' && $method === 'GET') {
    $themes = [
        ['id' => 'chiringuito', 'name' => '🏖️ Chiringuito', 'description' => 'Tema spiaggia con colori caldi'],
        ['id' => 'moderno', 'name' => '🎨 Moderno', 'description' => 'Tema moderno con colori vibranti'],
        ['id' => 'scuro', 'name' => '🌙 Scuro', 'description' => 'Tema dark mode'],
        ['id' => 'minimalista', 'name' => '✨ Minimalista', 'description' => 'Tema pulito e minimalista']
    ];
    jsonResponse(200, ['ok' => true, 'themes' => $themes]);
}

if ($action === 'admin_update_config' && $method === 'POST') {
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['tournament'])) {
        $t = $body['tournament'];
        if (isset($t['name'])) $config['tournament']['name'] = trim((string)$t['name']);
        if (isset($t['maxTeams'])) $config['tournament']['maxTeams'] = max(4, min(100, (int)$t['maxTeams']));
        if (isset($t['numGroups'])) $config['tournament']['numGroups'] = max(1, min(8, (int)$t['numGroups']));
        if (isset($t['numSets'])) $config['tournament']['numSets'] = ((int)$t['numSets'] === 1 ? 1 : 2);
        if (isset($t['winScore'])) $config['tournament']['winScore'] = max(15, min(30, (int)$t['winScore']));
        if (isset($t['timePerSetMinutes'])) $config['tournament']['timePerSetMinutes'] = max(20, min(60, (int)$t['timePerSetMinutes']));
        if (isset($t['setupTimeMinutes'])) $config['tournament']['setupTimeMinutes'] = max(1, min(15, (int)$t['setupTimeMinutes']));
        if (isset($t['maxTimeoutsPerSet'])) $config['tournament']['maxTimeoutsPerSet'] = max(0, min(5, (int)$t['maxTimeoutsPerSet']));
    }
    
    if (isset($body['schedule']) && is_array($body['schedule']['courts'] ?? null)) {
        $config['schedule']['courts'] = [];
        foreach ($body['schedule']['courts'] as $courtData) {
            $courtId = trim((string)($courtData['courtId'] ?? ''));
            $courtName = trim((string)($courtData['courtName'] ?? 'Campo'));
            
            $availability = [];
            foreach ($courtData['availability'] ?? [] as $dateAvail) {
                $date = trim((string)($dateAvail['date'] ?? date('Y-m-d')));
                $timeSlots = [];
                foreach ($dateAvail['timeSlots'] ?? [] as $slot) {
                    $timeSlots[] = [
                        'startTime' => trim((string)($slot['startTime'] ?? '19:30')),
                        'endTime' => trim((string)($slot['endTime'] ?? '20:30'))
                    ];
                }
                if (count($timeSlots) > 0) {
                    $availability[] = [
                        'date' => $date,
                        'timeSlots' => $timeSlots
                    ];
                }
            }
            
            if (count($availability) > 0) {
                $config['schedule']['courts'][] = [
                    'courtId' => $courtId ?: bin2hex(random_bytes(4)),
                    'courtName' => $courtName ?: 'Campo',
                    'availability' => $availability
                ];
            }
        }
    }
    
    if (isset($body['phases']) && is_array($body['phases'])) {
        $phases = [];
        foreach ($body['phases'] as $idx => $phase) {
            $phaseData = [
                'phaseNumber' => $idx + 1,
                'name' => trim((string)($phase['name'] ?? "Fase $idx")),
                'type' => in_array($phase['type'] ?? '', ['groups', 'knockout']) ? $phase['type'] : 'groups'
            ];
            
            if ($phaseData['type'] === 'groups') {
                $phaseData['numGroups'] = max(1, min(16, (int)($phase['numGroups'] ?? 4)));
                $phaseData['teamsAdvance'] = max(1, min(8, (int)($phase['teamsAdvance'] ?? 2)));
                $phaseData['hasRepescage'] = (bool)($phase['hasRepescage'] ?? false);
            } elseif ($phaseData['type'] === 'knockout') {
                $numTeams = (int)($phase['numTeams'] ?? 4);
                $validPowers = [2, 4, 8, 16, 32, 64, 128];
                $phaseData['numTeams'] = in_array($numTeams, $validPowers) ? $numTeams : 4;
            }
            
            $phases[] = $phaseData;
        }
        
        if (count($phases) > 0) {
            $config['phases'] = $phases;
        }
    }
    
    if (isset($body['display']) && is_array($body['display'])) {
        $theme = trim((string)($body['display']['theme'] ?? 'chiringuito'));
        $validThemes = ['chiringuito', 'moderno', 'scuro', 'minimalista'];
        
        // Consenti temi built-in o temi custom
        if (in_array($theme, $validThemes)) {
            $config['display']['theme'] = $theme;
        } elseif (strpos($theme, 'custom_') === 0) {
            // Verifica che il tema custom esista
            $customThemes = $config['display']['customThemes'] ?? [];
            if (array_filter($customThemes, fn($t) => $t['id'] === $theme)) {
                $config['display']['theme'] = $theme;
            }
        }
    }
    
    writeConfig($config);
    jsonResponse(200, ['ok' => true, 'config' => $config]);
}

if ($action === 'admin_update_schedule' && $method === 'POST') {
    $body = bodyJson();
    $config = readConfig();
    
    if (isset($body['courts']) && is_array($body['courts'])) {
        $config['schedule']['courts'] = [];
        foreach ($body['courts'] as $courtData) {
            $courtId = trim((string)($courtData['courtId'] ?? ''));
            $courtName = trim((string)($courtData['courtName'] ?? 'Campo'));
            
            $availability = [];
            foreach ($courtData['availability'] ?? [] as $dateAvail) {
                $date = trim((string)($dateAvail['date'] ?? date('Y-m-d')));
                $timeSlots = [];
                foreach ($dateAvail['timeSlots'] ?? [] as $slot) {
                    $timeSlots[] = [
                        'startTime' => trim((string)($slot['startTime'] ?? '19:30')),
                        'endTime' => trim((string)($slot['endTime'] ?? '20:30'))
                    ];
                }
                $availability[] = [
                    'date' => $date,
                    'timeSlots' => $timeSlots
                ];
            }
            
            $config['schedule']['courts'][] = [
                'courtId' => $courtId ?: bin2hex(random_bytes(4)),
                'courtName' => $courtName ?: 'Campo',
                'availability' => $availability
            ];
        }
    }
    
    writeConfig($config);
    jsonResponse(200, ['ok' => true]);
}

if ($action === 'admin_reschedule_matches' && $method === 'POST') {
    withStateTransaction(function (&$state) {
        if (empty($state['groups'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nessun girone generato']);
        }
        
        $config = readConfig();
        if (empty($config['schedule']['days'])) {
            jsonResponse(400, ['ok' => false, 'error' => 'Nessun giorno di schedule configurato']);
        }
        
        // Valida lo schedule prima di rigenerare le partite
        $validation = validateScheduleForTournament($state);
        if (!$validation['valid']) {
            jsonResponse(422, ['ok' => false, 'error' => $validation['message']]);
        }
        
        buildGroupMatchesWithSchedule($state);
        return ['ok' => true];
    });
    
    jsonResponse(200, ['ok' => true]);
}

jsonResponse(404, ['ok' => false, 'error' => 'Endpoint non trovato']);
