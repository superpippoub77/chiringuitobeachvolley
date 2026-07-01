<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const DATA_FILE = __DIR__ . '/data/tournament.json';
const SESSION_FILE = __DIR__ . '/data/sessions.json';
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
    $state = json_decode($raw ?: '', true);
    if (!is_array($state)) {
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
    return [
        'settings' => [
            'maxTeams' => 16,
            'tournamentName' => 'Torneo Beach Volley Chiringuito'
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
        'groupMatches' => array_values(array_map(function ($m) use ($teamMap) {
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

        $approved = array_slice(shuffleArray($approved), 0, (int)$state['settings']['maxTeams']);
        $groupCount = min(4, max(1, (int)ceil(count($approved) / 4)));
        $groups = [];
        for ($i = 0; $i < $groupCount; $i++) {
            $groups[] = ['name' => chr(65 + $i), 'teamIds' => []];
        }
        foreach ($approved as $idx => $team) {
            $groups[$idx % $groupCount]['teamIds'][] = $team['id'];
        }

        $state['groups'] = $groups;
        buildGroupMatches($state);
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

jsonResponse(404, ['ok' => false, 'error' => 'Endpoint non trovato']);
