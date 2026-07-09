# 🏁 Feature: Torneo Iniziale Vuoto

## Problema
Quando viene creato un nuovo torneo tramite l'endpoint `/create_tournament`, i file `tournament.json` e `config.json` venivano copiati dal template con dati di esempio o da tornei precedenti, rendendo necessaria una pulizia manuale.

## Soluzione
L'endpoint `create_tournament` ora scrive file completamente **vuoti** per `tournament.json` e `config.json` dopo la copia della directory.

## File Modificati

### api.php
**Endpoint**: `POST /api.php?action=create_tournament`

**Modifiche**:
- Dopo la creazione della directory `/data/uploads`
- Prima di scrivere il file di configurazione del tenant (`.tournament-config.json`)
- Scrive due file JSON vuoti:

#### 1. `/data/tournament.json`
```json
{
  "teams": [],
  "phases": [],
  "settings": {
    "maxTeams": 0,
    "tournamentName": ""
  },
  "meta": {
    "lastUpdated": null
  }
}
```

#### 2. `/data/config.json`
```json
{
  "tournament": {
    "name": "",
    "maxTeams": 0,
    "testTeams": 0
  },
  "phases": [],
  "schedule": {
    "days": [],
    "fields": []
  },
  "email": {
    "enabled": false,
    "host": "",
    "port": 587,
    "username": "",
    "password": "",
    "fromEmail": "",
    "fromName": "",
    "secure": "tls",
    "timeout": 30
  },
  "payment": {
    "costPerTeam": 0,
    "notes": []
  },
  "theme": {
    "template": "beachmaster",
    "logoUrl": "",
    "backgroundUrl": "",
    "fontFamily": ""
  }
}
```

## Comportamento

### Prima
1. Torneo nuovo → copia template → eredita dati old
2. Admin deve pulire manualmente i dati
3. Possibili conflitti con fase precedente

### Dopo
1. Torneo nuovo → copia template → scrive file JSON vuoti
2. Admin vede UI completamente vuota
3. Può iniziare da zero senza dati sporchi

## Strutture Iniziali

Le strutture vuote mantengono **la forma corretta** (chiavi necessarie per l'app) ma con **valori nulli/vuoti**:

| Campo | Tipo | Valore Iniziale | Significato |
|-------|------|-----------------|-------------|
| `teams[]` | Array | `[]` | Nessuna squadra |
| `phases[]` | Array | `[]` | Nessuna fase |
| `maxTeams` | Int | `0` | Non configurato |
| `tournamentName` | String | `""` | Non configurato |
| `days[]` | Array | `[]` | Nessun giorno |
| `fields[]` | Array | `[]` | Nessun campo |
| `email.enabled` | Bool | `false` | Email disabilitata |

## Sequenza di Operazioni

```
POST /create_tournament
├── Crea GUID torneo
├── Copia directory root → beachmaster/{GUID}/
├── Rimuove cartella beachmaster annidata (se presente)
├── Rimuove index.html (landing page)
├── Rinomina scoreboard.html → index.html
├── Aggiorna admin.html (link scoreboard → index)
├── Crea data/uploads
├── ✅ SCRIVE data/tournament.json VUOTO
├── ✅ SCRIVE data/config.json VUOTO
├── Scrive .tournament-config.json (credenziali tenant)
└── Registra torneo in lista globale
```

## Testing

### Caso 1: Nuovo Torneo
```bash
curl -X POST http://localhost:8080/beachmaster/TOURNAMENT-CODE/api.php?action=create_tournament \
  -H "Content-Type: application/json" \
  -d '{
    "managerEmail": "admin@example.com",
    "managerPassword": "securepass123",
    "tournamentName": "My Tournament"
  }'
```

**Risultato atteso**:
- Nuova directory creata: `beachmaster/{GUID}/`
- File `data/tournament.json`: vuoto con struttura corretta
- File `data/config.json`: vuoto con struttura corretta
- Admin panel: mostra UI completamente vuota (nessun dato preesistente)

### Caso 2: Verifica File
```bash
ls -la beachmaster/{GUID}/data/
cat beachmaster/{GUID}/data/tournament.json    # Deve essere vuoto
cat beachmaster/{GUID}/data/config.json        # Deve essere vuoto
```

## Edge Cases Gestiti

✅ Struttura corretta anche se vuota (non genera errori frontend)  
✅ Formati coerenti: `[]` per array, `""` per string, `0` per int  
✅ Non scrive valori di default inutili  
✅ Email, payment, theme sections vuote ma presenti  
✅ Phase array vuoto ma pronto per aggiungere fasi  

## Retrocompatibilità

✅ Nessun breaking change  
✅ Tornei esistenti non sono affettati  
✅ Solo applica a **nuovi** tornei creati dopo questo update  
✅ Frontend supporta strutture vuote (default values applicati lato JS)  

## Prossimi Passi per Admin

Dopo la creazione del torneo, l'admin può:
1. Configurare `config.tournament.maxTeams`
2. Aggiungere giorni e campi in `config.schedule`
3. Creare fasi in `config.phases[]`
4. Iscrivere squadre (populate `teams[]`)
5. Generare gironi/knockout

Tutto partendo da **zero**.

---

**Implementazione**: 2026-07-09  
**Status**: ✅ PRODUCTION READY  
**Impact**: Migliora UX di creazione torneo, elimina confusione da dati sporchi
