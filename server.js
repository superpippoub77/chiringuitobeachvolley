const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const PORT = process.env.PORT || 3000;
const ADMIN_KEY = process.env.ADMIN_KEY || 'chiringuito2026';
const ROOT = __dirname;
const DATA_FILE = path.join(ROOT, 'data', 'tournament.json');

const sessions = new Set();

function createInitialState() {
  return {
    settings: { maxTeams: 16 },
    teams: [],
    groups: [],
    groupMatches: [],
    playoff: {
      quarterFinals: [],
      semiFinals: [],
      thirdPlace: null,
      final: null
    },
    finalRanking: [],
    meta: { lastUpdated: null }
  };
}

function readState() {
  try {
    const raw = fs.readFileSync(DATA_FILE, 'utf8');
    return JSON.parse(raw);
  } catch (_err) {
    const fallback = createInitialState();
    writeState(fallback);
    return fallback;
  }
}

function writeState(state) {
  state.meta.lastUpdated = new Date().toISOString();
  fs.writeFileSync(DATA_FILE, JSON.stringify(state, null, 2));
}

function sendJson(res, code, payload) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload));
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk.toString('utf8');
      if (body.length > 1024 * 1024) {
        reject(new Error('Body too large'));
      }
    });
    req.on('end', () => {
      if (!body) return resolve({});
      try {
        resolve(JSON.parse(body));
      } catch (_err) {
        reject(new Error('Invalid JSON body'));
      }
    });
  });
}

function uid() {
  return crypto.randomBytes(10).toString('hex');
}

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function shuffle(list) {
  const arr = [...list];
  for (let i = arr.length - 1; i > 0; i -= 1) {
    const j = Math.floor(Math.random() * (i + 1));
    [arr[i], arr[j]] = [arr[j], arr[i]];
  }
  return arr;
}

function getApprovedTeams(state) {
  return state.teams.filter((t) => t.approved);
}

function sanitizeTeamForPublic(team) {
  return {
    id: team.id,
    name: team.name,
    category: team.category,
    players: team.players,
    paid: team.paid,
    approved: team.approved
  };
}

function buildGroupMatches(state) {
  const matches = [];
  let day = 1;
  let slot = 0;
  for (const group of state.groups) {
    for (let i = 0; i < group.teamIds.length - 1; i += 1) {
      for (let j = i + 1; j < group.teamIds.length; j += 1) {
        const h = 19 + Math.floor(slot / 2);
        const m = slot % 2 === 0 ? '30' : '55';
        matches.push({
          id: uid(),
          group: group.name,
          team1Id: group.teamIds[i],
          team2Id: group.teamIds[j],
          score1: null,
          score2: null,
          day,
          time: `${h}:${m}`
        });
        slot += 1;
        if (slot > 3) {
          slot = 0;
          day += 1;
        }
      }
    }
  }
  state.groupMatches = matches;
}

function getTeamMap(state) {
  const map = new Map();
  for (const team of state.teams) map.set(team.id, team);
  return map;
}

function computeGroupStandings(state) {
  const teamMap = getTeamMap(state);
  const standings = [];

  for (const group of state.groups) {
    const rows = group.teamIds.map((teamId) => ({
      teamId,
      name: teamMap.get(teamId)?.name || 'N/D',
      played: 0,
      won: 0,
      lost: 0,
      points: 0,
      scored: 0,
      conceded: 0,
      diff: 0
    }));

    const rowMap = new Map(rows.map((r) => [r.teamId, r]));

    for (const match of state.groupMatches.filter((m) => m.group === group.name)) {
      if (match.score1 === null || match.score2 === null) continue;
      const r1 = rowMap.get(match.team1Id);
      const r2 = rowMap.get(match.team2Id);
      if (!r1 || !r2) continue;

      r1.played += 1;
      r2.played += 1;
      r1.scored += match.score1;
      r1.conceded += match.score2;
      r2.scored += match.score2;
      r2.conceded += match.score1;

      if (match.score1 > match.score2) {
        r1.won += 1;
        r2.lost += 1;
        r1.points += 2;
      } else if (match.score2 > match.score1) {
        r2.won += 1;
        r1.lost += 1;
        r2.points += 2;
      }
    }

    for (const row of rows) row.diff = row.scored - row.conceded;

    rows.sort((a, b) => b.points - a.points || b.diff - a.diff || b.scored - a.scored || a.name.localeCompare(b.name));
    standings.push({ group: group.name, rows });
  }

  return standings;
}

function createPlayoffFromStandings(state) {
  const standings = computeGroupStandings(state);
  const first = [];
  const second = [];

  for (const g of standings) {
    if (g.rows[0]) first.push(g.rows[0].teamId);
    if (g.rows[1]) second.push(g.rows[1].teamId);
  }

  const qualified = [...first, ...second].slice(0, 8);
  if (qualified.length < 8) return false;

  state.playoff.quarterFinals = [
    { id: uid(), label: 'QF1', team1Id: qualified[0], team2Id: qualified[7], score1: null, score2: null },
    { id: uid(), label: 'QF2', team1Id: qualified[3], team2Id: qualified[4], score1: null, score2: null },
    { id: uid(), label: 'QF3', team1Id: qualified[1], team2Id: qualified[6], score1: null, score2: null },
    { id: uid(), label: 'QF4', team1Id: qualified[2], team2Id: qualified[5], score1: null, score2: null }
  ];

  state.playoff.semiFinals = [
    { id: uid(), label: 'SF1', team1Id: null, team2Id: null, score1: null, score2: null },
    { id: uid(), label: 'SF2', team1Id: null, team2Id: null, score1: null, score2: null }
  ];
  state.playoff.thirdPlace = { id: uid(), label: '3P', team1Id: null, team2Id: null, score1: null, score2: null };
  state.playoff.final = { id: uid(), label: 'F', team1Id: null, team2Id: null, score1: null, score2: null };
  return true;
}

function getWinnerLoser(match) {
  if (!match || match.score1 === null || match.score2 === null) return { winner: null, loser: null };
  if (match.score1 > match.score2) return { winner: match.team1Id, loser: match.team2Id };
  if (match.score2 > match.score1) return { winner: match.team2Id, loser: match.team1Id };
  return { winner: null, loser: null };
}

function updatePlayoffTree(state) {
  const qf = state.playoff.quarterFinals;
  const sf = state.playoff.semiFinals;
  if (!qf.length || !sf.length || !state.playoff.final || !state.playoff.thirdPlace) return;

  const qWinners = qf.map((m) => getWinnerLoser(m).winner);
  sf[0].team1Id = qWinners[0];
  sf[0].team2Id = qWinners[1];
  sf[1].team1Id = qWinners[2];
  sf[1].team2Id = qWinners[3];

  const sf1 = getWinnerLoser(sf[0]);
  const sf2 = getWinnerLoser(sf[1]);
  state.playoff.final.team1Id = sf1.winner;
  state.playoff.final.team2Id = sf2.winner;
  state.playoff.thirdPlace.team1Id = sf1.loser;
  state.playoff.thirdPlace.team2Id = sf2.loser;
}

function computeFinalRanking(state) {
  const teamMap = getTeamMap(state);
  const standings = computeGroupStandings(state);

  const ranking = [];
  const finalWL = getWinnerLoser(state.playoff.final);
  const thirdWL = getWinnerLoser(state.playoff.thirdPlace);

  if (finalWL.winner) ranking.push(finalWL.winner);
  if (finalWL.loser) ranking.push(finalWL.loser);
  if (thirdWL.winner) ranking.push(thirdWL.winner);
  if (thirdWL.loser) ranking.push(thirdWL.loser);

  const added = new Set(ranking);
  for (const sf of state.playoff.semiFinals) {
    const wl = getWinnerLoser(sf);
    if (wl.loser && !added.has(wl.loser)) {
      ranking.push(wl.loser);
      added.add(wl.loser);
    }
  }

  for (const row of standings.flatMap((g) => g.rows)) {
    if (!added.has(row.teamId)) {
      ranking.push(row.teamId);
      added.add(row.teamId);
    }
  }

  state.finalRanking = ranking.map((teamId, idx) => ({
    position: idx + 1,
    teamId,
    name: teamMap.get(teamId)?.name || 'N/D'
  }));
}

function randomMatchScore() {
  let s1 = randomInt(10, 21);
  let s2 = randomInt(10, 21);
  while (s1 === s2) s2 = randomInt(10, 21);
  return [s1, s2];
}

function simulateAll(state) {
  if (!state.groups.length) {
    const approved = shuffle(getApprovedTeams(state)).slice(0, state.settings.maxTeams);
    if (approved.length < 4) return false;

    const groupCount = Math.min(4, Math.ceil(approved.length / 4));
    const groups = [];
    for (let i = 0; i < groupCount; i += 1) groups.push({ name: String.fromCharCode(65 + i), teamIds: [] });

    approved.forEach((team, idx) => groups[idx % groupCount].teamIds.push(team.id));
    state.groups = groups;
    buildGroupMatches(state);
  }

  for (const match of state.groupMatches) {
    const [a, b] = randomMatchScore();
    match.score1 = a;
    match.score2 = b;
  }

  if (!state.playoff.quarterFinals.length) {
    const ok = createPlayoffFromStandings(state);
    if (!ok) return false;
  }

  for (const qf of state.playoff.quarterFinals) {
    const [a, b] = randomMatchScore();
    qf.score1 = a;
    qf.score2 = b;
  }
  updatePlayoffTree(state);

  for (const sf of state.playoff.semiFinals) {
    if (!sf.team1Id || !sf.team2Id) continue;
    const [a, b] = randomMatchScore();
    sf.score1 = a;
    sf.score2 = b;
  }
  updatePlayoffTree(state);

  if (state.playoff.thirdPlace.team1Id && state.playoff.thirdPlace.team2Id) {
    const [a, b] = randomMatchScore();
    state.playoff.thirdPlace.score1 = a;
    state.playoff.thirdPlace.score2 = b;
  }

  if (state.playoff.final.team1Id && state.playoff.final.team2Id) {
    const [a, b] = randomMatchScore();
    state.playoff.final.score1 = a;
    state.playoff.final.score2 = b;
  }

  computeFinalRanking(state);
  return true;
}

function getPublicState(state) {
  const teamMap = getTeamMap(state);
  return {
    settings: state.settings,
    teams: state.teams.filter((t) => t.approved).map(sanitizeTeamForPublic),
    pendingCount: state.teams.filter((t) => !t.approved).length,
    groups: state.groups.map((g) => ({
      name: g.name,
      teams: g.teamIds.map((id) => ({ id, name: teamMap.get(id)?.name || 'N/D' }))
    })),
    groupMatches: state.groupMatches.map((m) => ({
      ...m,
      team1Name: teamMap.get(m.team1Id)?.name || 'N/D',
      team2Name: teamMap.get(m.team2Id)?.name || 'N/D'
    })),
    standings: computeGroupStandings(state),
    playoff: {
      quarterFinals: state.playoff.quarterFinals.map((m) => ({ ...m, team1Name: teamMap.get(m.team1Id)?.name || '-', team2Name: teamMap.get(m.team2Id)?.name || '-' })),
      semiFinals: state.playoff.semiFinals.map((m) => ({ ...m, team1Name: teamMap.get(m.team1Id)?.name || '-', team2Name: teamMap.get(m.team2Id)?.name || '-' })),
      thirdPlace: state.playoff.thirdPlace
        ? { ...state.playoff.thirdPlace, team1Name: teamMap.get(state.playoff.thirdPlace.team1Id)?.name || '-', team2Name: teamMap.get(state.playoff.thirdPlace.team2Id)?.name || '-' }
        : null,
      final: state.playoff.final
        ? { ...state.playoff.final, team1Name: teamMap.get(state.playoff.final.team1Id)?.name || '-', team2Name: teamMap.get(state.playoff.final.team2Id)?.name || '-' }
        : null
    },
    finalRanking: state.finalRanking
  };
}

function isAdmin(req) {
  const auth = req.headers.authorization || '';
  if (!auth.startsWith('Bearer ')) return false;
  const token = auth.slice('Bearer '.length);
  return sessions.has(token);
}

function serveStatic(req, res) {
  const safePath = decodeURIComponent(req.url.split('?')[0]);
  const filePath = safePath === '/'
    ? path.join(ROOT, 'index.html')
    : path.join(ROOT, safePath.replace(/^\//, ''));

  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Not found');
      return;
    }

    const ext = path.extname(filePath).toLowerCase();
    const ct = {
      '.html': 'text/html; charset=utf-8',
      '.css': 'text/css; charset=utf-8',
      '.js': 'application/javascript; charset=utf-8',
      '.json': 'application/json; charset=utf-8'
    }[ext] || 'application/octet-stream';

    res.writeHead(200, { 'Content-Type': ct });
    res.end(data);
  });
}

const server = http.createServer(async (req, res) => {
  try {
    const { method, url } = req;
    if (!url) return sendJson(res, 400, { error: 'Invalid request' });

    if (method === 'GET' && url.startsWith('/api/public/state')) {
      const state = readState();
      return sendJson(res, 200, getPublicState(state));
    }

    if (method === 'POST' && url === '/api/register') {
      const body = await parseBody(req);
      const name = (body.name || '').trim();
      const player1 = (body.player1 || '').trim();
      const player2 = (body.player2 || '').trim();

      if (!name || !player1 || !player2) {
        return sendJson(res, 400, { error: 'Nome squadra e primi due giocatori sono obbligatori' });
      }

      const state = readState();
      if (state.teams.length >= state.settings.maxTeams) {
        return sendJson(res, 400, { error: 'Numero massimo squadre raggiunto' });
      }

      if (state.teams.some((t) => t.name.toLowerCase() === name.toLowerCase())) {
        return sendJson(res, 400, { error: 'Nome squadra gia esistente' });
      }

      state.teams.push({
        id: uid(),
        name,
        category: body.category || 'Misto',
        players: [player1, player2, (body.player3 || '').trim()].filter(Boolean),
        phone: (body.phone || '').trim(),
        note: (body.note || '').trim(),
        approved: false,
        paid: false,
        createdAt: new Date().toISOString()
      });

      writeState(state);
      return sendJson(res, 201, { ok: true, message: 'Iscrizione ricevuta. In attesa di approvazione admin.' });
    }

    if (method === 'POST' && url === '/api/admin/login') {
      const body = await parseBody(req);
      if ((body.key || '') !== ADMIN_KEY) {
        return sendJson(res, 401, { error: 'Chiave admin non valida' });
      }
      const token = uid();
      sessions.add(token);
      return sendJson(res, 200, { token });
    }

    if (url.startsWith('/api/admin/')) {
      if (!isAdmin(req)) {
        return sendJson(res, 401, { error: 'Non autorizzato' });
      }
    }

    if (method === 'GET' && url === '/api/admin/state') {
      const state = readState();
      return sendJson(res, 200, { ...getPublicState(state), teamsFull: state.teams });
    }

    if (method === 'PATCH' && /^\/api\/admin\/team\/[a-z0-9]+$/i.test(url)) {
      const teamId = url.split('/').pop();
      const body = await parseBody(req);
      const state = readState();
      const team = state.teams.find((t) => t.id === teamId);
      if (!team) return sendJson(res, 404, { error: 'Squadra non trovata' });

      if (typeof body.name === 'string') team.name = body.name.trim() || team.name;
      if (typeof body.category === 'string') team.category = body.category;
      if (Array.isArray(body.players)) {
        team.players = body.players.map((p) => String(p).trim()).filter(Boolean).slice(0, 3);
      }
      if (typeof body.phone === 'string') team.phone = body.phone.trim();
      if (typeof body.note === 'string') team.note = body.note.trim();
      if (typeof body.approved === 'boolean') team.approved = body.approved;
      if (typeof body.paid === 'boolean') team.paid = body.paid;

      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'DELETE' && /^\/api\/admin\/team\/[a-z0-9]+$/i.test(url)) {
      const teamId = url.split('/').pop();
      const state = readState();
      state.teams = state.teams.filter((t) => t.id !== teamId);
      state.groups = state.groups.map((g) => ({ ...g, teamIds: g.teamIds.filter((id) => id !== teamId) }));
      state.groupMatches = state.groupMatches.filter((m) => m.team1Id !== teamId && m.team2Id !== teamId);
      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'POST' && url === '/api/admin/generate-groups') {
      const state = readState();
      const approved = shuffle(getApprovedTeams(state)).slice(0, state.settings.maxTeams);
      if (approved.length < 4) {
        return sendJson(res, 400, { error: 'Servono almeno 4 squadre approvate' });
      }

      const groupCount = Math.min(4, Math.ceil(approved.length / 4));
      const groups = [];
      for (let i = 0; i < groupCount; i += 1) groups.push({ name: String.fromCharCode(65 + i), teamIds: [] });
      approved.forEach((team, idx) => groups[idx % groupCount].teamIds.push(team.id));

      state.groups = groups;
      buildGroupMatches(state);
      state.playoff = { quarterFinals: [], semiFinals: [], thirdPlace: null, final: null };
      state.finalRanking = [];

      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'PATCH' && /^\/api\/admin\/group-match\/[a-z0-9]+$/i.test(url)) {
      const matchId = url.split('/').pop();
      const body = await parseBody(req);
      const state = readState();
      const match = state.groupMatches.find((m) => m.id === matchId);
      if (!match) return sendJson(res, 404, { error: 'Partita non trovata' });

      if (typeof body.day === 'number') match.day = Math.max(1, Math.floor(body.day));
      if (typeof body.time === 'string') match.time = body.time;
      if (typeof body.score1 === 'number' || body.score1 === null) match.score1 = body.score1;
      if (typeof body.score2 === 'number' || body.score2 === null) match.score2 = body.score2;
      if (typeof body.swapTeams === 'boolean' && body.swapTeams) {
        const t1 = match.team1Id;
        match.team1Id = match.team2Id;
        match.team2Id = t1;
      }

      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'POST' && url === '/api/admin/create-playoff') {
      const state = readState();
      const ok = createPlayoffFromStandings(state);
      if (!ok) {
        return sendJson(res, 400, { error: 'Servono almeno 8 qualificate (prime 2 per girone)' });
      }
      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'PATCH' && /^\/api\/admin\/playoff\/(quarterFinals|semiFinals|thirdPlace|final)\/[a-z0-9]+$/i.test(url)) {
      const parts = url.split('/');
      const phase = parts[4];
      const matchId = parts[5];
      const body = await parseBody(req);
      const state = readState();

      let match = null;
      if (phase === 'thirdPlace' || phase === 'final') {
        match = state.playoff[phase];
      } else {
        match = state.playoff[phase].find((m) => m.id === matchId);
      }
      if (!match || (phase !== 'thirdPlace' && phase !== 'final' && match.id !== matchId)) {
        return sendJson(res, 404, { error: 'Partita playoff non trovata' });
      }

      if (typeof body.score1 === 'number' || body.score1 === null) match.score1 = body.score1;
      if (typeof body.score2 === 'number' || body.score2 === null) match.score2 = body.score2;
      updatePlayoffTree(state);
      computeFinalRanking(state);

      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'POST' && url === '/api/admin/simulate-full') {
      const state = readState();
      const ok = simulateAll(state);
      if (!ok) return sendJson(res, 400, { error: 'Simulazione impossibile: approva almeno 4 squadre' });
      writeState(state);
      return sendJson(res, 200, { ok: true });
    }

    if (method === 'POST' && url === '/api/admin/reset') {
      const reset = createInitialState();
      writeState(reset);
      return sendJson(res, 200, { ok: true });
    }

    return serveStatic(req, res);
  } catch (err) {
    return sendJson(res, 500, { error: err.message || 'Errore interno' });
  }
});

server.listen(PORT, () => {
  console.log(`Server avviato su http://localhost:${PORT}`);
});
