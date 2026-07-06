# Test Automatici Combinazioni Tornei - Documentazione Completa

## 📋 Panoramica

Questo set di test automatici verifica tutte le combinazioni possibili di creazione tornei nel sistema BeachMaster:

- **Test Bash** (`test_tournament_combinations.sh`): 11 scenari di configurazione tornei
- **Test PHP** (`test_tournament_advanced.php`): 6 scenari avanzati con logica complessa

## 🎯 Scenari Testati

### Test Bash - 11 Scenari di Configurazione

#### Scenario 1: 1 Girone → Playoff a 4 Squadre
```
Tipologia: Semplice
Squadre: 4
Fasi: [Gironi (1×4)] → [Knockout (4→2→1)]
Descrizione: Torneo di base con 1 girone di 4 squadre che passano direttamente al playoff
Validazioni: Creazione gironi, generazione partite, playoff bracket
```

#### Scenario 2: 2 Gironi + Ripescaggio + Playoff
```
Tipologia: Con Ripescaggio
Squadre: 12 (6×2 gironi)
Fasi: [Gironi (2×6)] → [Ripescaggio] → [Knockout (8→4→2→1)]
Descrizione: 2 gironi da 6 squadre, 1 girone di ripescaggio, playoff a 8
Validazioni: Classifiche, transizione ripescaggio, qualificate
```

#### Scenario 3: 3 Gironi (Top 1 Per Girone)
```
Tipologia: Selezione Ristretta
Squadre: 12 (4×3 gironi)
Fasi: [Gironi (3×4)] → [Knockout (4→2→1)]
Descrizione: 3 gironi, solo il primo di ogni girone passa al playoff
Validazioni: Multipli, ordinamento per ranking
```

#### Scenario 4: 4 Gironi + 2 Squadre Empty
```
Tipologia: Completamento Automatico
Squadre: 10 reali + 2 fittizie
Fasi: [Gironi (4×3)] → [Knockout (8→4→2→1)]
Descrizione: 4 gironi da 3, 10 squadre reali, 2 empty per completamento
Validazioni: Gestione squadre fittizie, loro comportamento
```

#### Scenario 5: Gironi + Ripescaggio + Multi-Fase Knockout
```
Tipologia: Complesso Multi-Fase
Squadre: 16 (8×2 gironi)
Fasi: [Gironi (2×8)] → [Ripescaggio] → [KO 8] → [KO 4] → [KO 2]
Descrizione: Torneo complesso con ripescaggio e 3 fasi knockout separate
Validazioni: Progressione tra fasi, ripescaggio a metà
```

#### Scenario 6: Validazione Campi Insufficienti
```
Tipologia: Validazione Errore
Squadre: 8
Fasi: [Gironi (2×4)] → [Knockout (4→2)]
Descrizione: Solo 1 campo con 1 fascia oraria per 8 squadre
Risultato Atteso: Errore di validazione schedule
```

#### Scenario 7: Validazione Fasce Orarie Insufficienti
```
Tipologia: Validazione Errore
Squadre: 6
Fasi: [Gironi (2×3)] → [Knockout (4→2)]
Descrizione: 2 fasce orarie totali per 6 squadre (3 gironi = 3+ partite/giorno)
Risultato Atteso: Errore di validazione fasce
```

#### Scenario 8: 1 Grande Girone + Castello Diretto
```
Tipologia: Passaggio Diretto
Squadre: 8
Fasi: [Gironi (1×8)] → [KO 8] → [KO 4] → [KO 2]
Descrizione: Tutte le squadre in 1 girone, poi semifinali/finali
Validazioni: Girone singolo, numero potenza di 2 per playoff
```

#### Scenario 9: 5 Squadre (1 Empty) + Ripescaggio
```
Tipologia: Asimmetrico
Squadre: 5 reali + 1 fittizia
Fasi: [Gironi (2×3)] → [Ripescaggio] → [Knockout (4→2)]
Descrizione: 2 gironi da 3, con 1 squadra empty per completamento, ripescaggio
Validazioni: Empty handling, ripescaggio con numero dispari
```

#### Scenario 10: 7 Squadre (1 Empty) + 3 Gironi
```
Tipologia: Asimmetrico Multi-Girone
Squadre: 7 reali + 1 fittizia
Fasi: [Gironi (3×3)] → [Knockout (8→4→2)]
Descrizione: 3 gironi da 3 squadre (7 reali + 1 empty), playoff a 8
Validazioni: Distribuzione non uniforme, empty placement
```

#### Scenario 11: Puro Castello Diretto (16 Squadre)
```
Tipologia: Solo Knockout
Squadre: 16
Fasi: [KO 16] → [KO 8] → [KO 4] → [KO 2]
Descrizione: Nessun girone, direttamente 4 fasi di playoff
Validazioni: Potenze di 2, bracket generation, no groups
```

### Test PHP - 6 Scenari Avanzati

#### Scenario A1: 1 Girone 4 Squadre + Playoff
```
Testato:
  ✓ Configurazione torneo
  ✓ Caricamento squadre demo
  ✓ Generazione gironi
  ✓ Validazione numero partite
  ✓ Verifica stato/classifiche
```

#### Scenario A2: 2 Gironi + Ripescaggio
```
Testato:
  ✓ Config multi-girone
  ✓ 8 squadre caricate
  ✓ Generazione gironi + ripescaggio
  ✓ Conteggio partite (gironi + ripescaggio)
  ✓ Divisione squadre per girone
```

#### Scenario A3: 3 Gironi (Top 1 Only)
```
Testato:
  ✓ Config con teamsAdvance=1
  ✓ 9 squadre caricate
  ✓ Generazione 3 gironi
  ✓ Verifica: 3 qualificate + 1 empty per playoff
```

#### Scenario A4: 4 Gironi Asimmetrici
```
Testato:
  ✓ 4 gironi × 2 squadre (8 totali)
  ✓ teamsAdvance=2 → 8 in playoff
  ✓ Validazione configurazione asimmetrica
```

#### Scenario A5: 10 Squadre + 2 Empty
```
Testato:
  ✓ 10 squadre reali caricate
  ✓ Sistema aggiunge 2 empty automaticamente
  ✓ 4 gironi × 3 squadre (10+2)
  ✓ teamsAdvance=2 → 8 in playoff
```

#### Scenario A6: Multi-Fase Knockout (16→8→4→2)
```
Testato:
  ✓ 4 fasi knockout pure
  ✓ 16 squadre caricate
  ✓ Validazione potenze di 2
  ✓ Verifica numero fasi configurate
```

## 🚀 Come Eseguire i Test

### Prerequisiti
1. Server PHP locale in esecuzione: `npm start` (apre su `http://localhost:3000`)
2. Password admin: `admin123` (di default nel file `api.php`)
3. Bash e curl disponibili nel sistema

### Test Bash (Combinazioni di Configurazione)

```bash
# Eseguire tutti gli 11 scenari
bash test_tournament_combinations.sh

# Eseguire con URL personalizzato
bash test_tournament_combinations.sh "http://127.0.0.1:3000"

# Eseguire con password personalizzata
bash test_tournament_combinations.sh "http://localhost:3000" "miapassword"
```

**Output Atteso:**
```
════════════════════════════════════════════════════════
  🎉 TUTTI GLI SCENARI TESTATI CON SUCCESSO! 🎉
════════════════════════════════════════════════════════

✓ Scenario 1: 1 Girone + Playoff a 4
✓ Scenario 2: 2 Gironi + Ripescaggio + Playoff
...
```

### Test PHP (Scenari Avanzati)

```bash
# Eseguire tutti gli 6 scenari avanzati
php test_tournament_advanced.php

# Con output dettagliato
php -d display_errors=1 test_tournament_advanced.php
```

**Output Atteso:**
```
═══════════════════════════════════════════════════════════════
  🎉 TUTTI I TEST AVANZATI COMPLETATI CON SUCCESSO! 🎉
═══════════════════════════════════════════════════════════════

✓ Scenario A1: 1 Girone + Playoff
✓ Scenario A2: 2 Gironi + Ripescaggio
...
```

## 🔍 Cosa Viene Testato in Dettaglio

### Per Ogni Scenario
- ✅ **Reset torneo completo** - Cancella dati precedenti
- ✅ **Configurazione torneo** - Salvataggio parametri
- ✅ **Schedule (campi/giorni/fasce)** - Validazione disponibilità
- ✅ **Caricamento squadre** - Demo teams population
- ✅ **Generazione gironi** - Creazione corretta dei gironi
- ✅ **Conteggio partite** - Verifica numero partite generate
- ✅ **Classifiche** - Ordinamento squadre per punti
- ✅ **Progressione fasi** - Qualificate alla fase successiva
- ✅ **Squadre empty** - Gestione automatica completamento

### Validazioni Speciali
- **Campi insufficienti** - Sistema rileva e rifiuta
- **Fasce orarie insufficienti** - Sistema rileva e rifiuta
- **Potenze di 2 per knockout** - Validazione per playoff bracket
- **Multipli e ranking** - Ordinamento per punti, set, punti
- **Ripescaggio** - Tutte le squadre eliminate hanno classifica
- **Empty teams** - Comportamento corretto, non giocano
- **Transizioni tra fasi** - Qualificate passano correttamente

## 📊 Metriche di Successo

### Test Bash
- **Total Scenarios**: 11
- **Pass Rate Target**: 100%
- **Skip Acceptable**: Scenarios 6-7 (validazioni errore)

### Test PHP
- **Total Scenarios**: 6
- **Pass Rate Target**: 100%
- **Coverage**: Config, generation, validation

## 🐛 Troubleshooting

### Errore: "Admin login failed"
```
Soluzione: Verificare che il server sia in esecuzione
$ npm start
# Attendere: "PHP server started on http://0.0.0.0:3000"
```

### Errore: "Schedule insufficient"
```
Questo è atteso per i Scenari 6-7
- Scenario 6: Solo 1 campo, 2 fasce → insuff. per 8 squadre
- Scenario 7: 2 fasce totali → insuff. per 6 squadre
```

### Errore: "Unknown teams count"
```
Soluzione: Il seed_demo potrebbe fallire silenziosamente
Controllare che il config sia stato salvato correttamente
Verificare authorization token valido
```

### Tests passano a volte, falliscono altre
```
Possibile causa: Race condition su file JSON
Soluzione: Aumentare sleep tra operazioni in bash script
Modifiare: sleep 0.5 → sleep 1.0 nella funzione test_scenario_setup()
```

## 📝 Aggiornamenti Futuri

Potenziali estensioni del test suite:

1. **Simulazione partite** - Generare risultati randomici, calcolare classifiche
2. **Update scoring** - Testare aggiornamento punteggi durante torneo
3. **Rollback fasi** - Testare eliminazione di una fase
4. **Merge tornei** - Testare import/export e merge di configurazioni
5. **Stress test** - 100+ squadre, schedule complesso
6. **Backup/Restore** - Test di ripristino da snapshot
7. **Email notifications** - Test invio notifiche tra fasi
8. **API validation** - Test errori HTTP e codici status

## 📞 Supporto

Se i test falliscono:

1. Verificare che `npm start` sia attivo
2. Controllare file `api.php` per password corretta
3. Leggere l'output dettagliato dell'errore
4. Verificare file `data/config.json` per stato torneo
5. Controllare `data/history.json` per cronologia operazioni

## ✨ Best Practices

### Prima di eseguire i test
```bash
# Assicurarsi che il server sia pulito
rm data/config.json data/tournament.json 2>/dev/null

# Avviare il server
npm start

# In un'altra finestra di terminale, eseguire i test
bash test_tournament_combinations.sh
php test_tournament_advanced.php
```

### Interpretare i risultati
- **Verde (✓)**: Test passato
- **Rosso (✗)**: Test fallito, leggere il messaggio
- **Giallo (⚠)**: Avvertimento, operazione non critica

### Salvare i risultati
```bash
# Salvare output di test bash
bash test_tournament_combinations.sh > results_bash.log 2>&1

# Salvare output di test PHP
php test_tournament_advanced.php > results_php.log 2>&1

# Combinare risultati
cat results_bash.log results_php.log > test_results_complete.log
```

## 🎓 Riferimenti Interni

### File API Correlati
- `api.php` - Endpoint main (admin_login, admin_update_config, admin_generate_groups, etc.)
- `beachmaster/{GUID}/api.php` - API per tenant specifico
- `data/config.json` - Configurazione torneo
- `data/tournament.json` - Stato torneo (gironi, partite, playoff)

### Endpoint Utilizzati
```
POST /api.php?action=admin_login
POST /api.php?action=admin_reset_tournament
POST /api.php?action=admin_update_config
POST /api.php?action=admin_seed_demo
POST /api.php?action=admin_generate_groups
GET  /api.php?action=admin_state
```

### Funzioni Backend Importanti
- `admin_generate_groups()` - Genera gironi
- `validSchedule()` - Valida schedule
- `calculateGroupMatches()` - Calcola partite girone
- `assignGroups()` - Assegna squadre ai gironi
- `addRepescageGroup()` - Aggiunge girone di ripescaggio

---

**Versione**: 1.0  
**Data**: 2026-07-06  
**Autore**: BeachMaster Test Suite  
**Status**: ✅ Completo e Funzionante
