# Architettura Fasi Torneo

## Struttura di State

### Stato Attuale (Da Migrare)
```json
{
  "groups": [],
  "groupMatches": [],
  "playoff": { "quarterFinals": [], "semiFinals": [], "final": null },
  "finalRanking": []
}
```

### Nuovo State con Fasi
```json
{
  "phases": [
    {
      "id": "phase-1-groups",
      "phaseIdx": 1,
      "name": "Fase 1 - Gironi",
      "type": "groups",
      "status": "active|completed|pending",
      "groups": [],              // gironi di questa fase
      "matches": [],             // partite della fase
      "standings": {             // classifiche della fase
        "A": [...teams with stats],
        "B": [...teams with stats]
      },
      "createdAt": "2026-07-07T12:00:00Z"
    },
    {
      "id": "phase-2-knockout",
      "phaseIdx": 2,
      "name": "Fase 2 - Playoff",
      "type": "knockout",
      "status": "pending",
      "matches": {
        "quarterFinals": [],
        "semiFinals": [],
        "thirdPlace": null,
        "final": null
      },
      "standings": {},           // top 8, etc.
      "createdAt": null
    }
  ],
  "currentPhaseIdx": 1,          // fase attualmente attiva
  "teams": [],
  "settings": {}
}
```

## Operazioni Principali

### 1. Generazione Gironi (Fase 1)
- `admin_create_groups` → Crea phase-1-groups con:
  - groups: array di gironi
  - matches: partite generate
  - standings: classifiche iniziali (vuote/calcolate)
  - status: "active"

### 2. Generazione Playoff (Fase 2)
- `admin_create_playoff` → Crea phase-2-knockout con:
  - matches: quarterFinals, semiFinals, final
  - standings: calcolate dalla fase 1
  - status: "pending" → "active" quando inizi a giocare

### 3. Nuovi Sotto-Tornei (Fase N)
- `admin_create_subtournament` → Crea fase-N con configurazione nuova
  - Supporta gironi oppure knockout
  - Usa squadre dalla fase precedente

## Funzioni Utility Necessarie

```php
function initializePhase(&$state, $phaseIdx, $name, $type, $config = [])
function getPhase(&$state, $phaseIdx)
function setCurrentPhase(&$state, $phaseIdx)
function getPhaseMatches(&$state, $phaseIdx)
function getPhaseStandings(&$state, $phaseIdx)
function updatePhaseStatus(&$state, $phaseIdx, $status)
function computePhaseStandings(&$state, $phaseIdx)
```

## UI Changes in admin.html

### Sezione Partite
- Selettore dropdown: "Seleziona Fase" (Phase 1 - Gironi, Phase 2 - Playoff, etc.)
- Visualizza partite della fase selezionata
- Per gironi: sub-tab per gruppo (A, B, C)
- Per playoff: tab per round (QF, SF, Final)

### Sezione Classifiche
- Selettore dropdown: "Seleziona Fase"
- Visualizza classifiche della fase (non globali)
- Per gironi: tabella con punti, vittorie, etc. per girone
- Per playoff: bracket visuale

### Timeline Fasi
- Mostro grafico che indica fase corrente e prossime fasi
- Click per navigare tra fasi

## Migrazione Dati Esistenti

Se tournament.json esiste con la vecchia struttura:
```javascript
if (!state.phases) {
  // Migra da vecchia a nuova struttura
  state.phases = [
    {
      id: "phase-1-groups",
      phaseIdx: 1,
      name: "Fase 1 - Gironi",
      type: "groups",
      status: state.groups.length > 0 ? "active" : "pending",
      groups: state.groups,
      matches: state.groupMatches,
      standings: state.standings || {}
    },
    {
      id: "phase-2-knockout",
      phaseIdx: 2,
      name: "Fase 2 - Playoff",
      type: "knockout",
      status: "pending",
      matches: state.playoff,
      standings: {}
    }
  ];
  state.currentPhaseIdx = 1;
  state.finalRanking = [];  // Rimossa, calcolata da ultima fase
}
```

## Calcolo Classifiche

Per ogni fase:
```
computePhaseStandings(phaseIdx):
  1. Leggi matches della fase
  2. Raggruppa per girone (se groups) o round (se knockout)
  3. Calcola: punti, vittorie, sconfitte, pf, pc, diff
  4. Salva in phase.standings
```

## Endpoints Modificati

- `admin_create_groups` POST → Crea phase 1
- `admin_create_playoff` POST → Crea phase 2
- `admin_update_group_match` POST → Aggiorna match e ricalcola standings
- `admin_update_playoff_match` POST → Aggiorna match e ricalcola standings
- `admin_select_phase` POST → Cambia fase attuale (visualizzazione)
- `admin_get_phase_details` GET → Ritorna dettagli fase specifica

## Timeline Implementazione

1. **Fase 1**: Struttura phases + migrazione dati
2. **Fase 2**: Aggiornamento endpoints creazione
3. **Fase 3**: UI fasi in admin.html
4. **Fase 4**: Calcolo classifiche per-fase
5. **Fase 5**: Visualizzazione classifiche/partite per fase
