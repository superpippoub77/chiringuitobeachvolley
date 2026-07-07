# SOLUZIONE: Salvataggio Fasi con Routing e Calcolo Automatico Squadre

## Problema Identificato
L'utente non riusciva a salvare le fasi con il nuovo sistema perché:
1. Il backend non salvava i campi `qualifiedGoTo` e `eliminatedGoTo` che definiscono il routing
2. Non c'era logica per calcolare automaticamente il numero di squadre che passano da una fase all'altra
3. Non era possibile gestire i due rami (qualificate/eliminate) quando il ripescaggio era abilitato

## Soluzione Implementata

### 1. Backend (api.php)

**Modifche all'endpoint `admin_update_config`:**
- Aggiunto salvataggio dei campi `branch`, `qualifiedGoTo`, `eliminatedGoTo`
- I dati ora vengono salvati nel config.json preservando il routing delle fasi

**Nuove funzioni helper:**
```php
calculatePhaseTeams(array $phase, int $teamsIn): array
  → Calcola squadre qualificate e eliminate da una fase
  
getTeamsForNextPhase(array $phases, int $phaseIdx, string $branch, int $teamsIn): int
  → Restituisce il numero di squadre per la fase successiva in base al branch
  
getInitialTeamsCount(array $config, array $state): int
  → Restituisce il numero di squadre approvate iniziali
```

**Nuovo endpoint `admin_calculate_next_phase_teams`:**
```
POST /api.php?action=admin_calculate_next_phase_teams
Input: {currentPhaseIdx, branch}
Output: {qualified, eliminated, teamsIn, message}
```

### 2. Frontend (admin.html)

**Nuova funzione:**
```javascript
calculateNextPhaseTeams(phaseIdx, branch): Promise<object>
  → Chiama l'endpoint del backend e calcola le squadre automaticamente
```

**Wizard migliorato:**
- Calcola dinamicamente le squadre qualificate e eliminate dalla fase precedente
- Mostra preview accurata del numero di squadre per il ramo successivo
- Supporta la creazione di due rami separati quando `hasRepescage = true`

## Flusso di Utilizzo

### Scenario: 8 squadre, Castello con Ripescaggio

```
FASE 1: Gironi (2 gironi × 4 squadre)
├─ Top 2 per girone = 4 qualificate
└─ 4 ripescate

FASE 2a: Winners Bracket (RAMO QUALIFICATE)
├─ 4 squadre qualificate dalla Fase 1
├─ Ottavi (4 → 2 vincitrici + 2 perdenti)
└─ 2 perdenti → vanno al Ripescaggio

FASE 2b: Ripescaggio (RAMO ELIMINATE)
├─ 4 ripescate dalla Fase 1 + 2 perdenti da Winners = 6 squadre
├─ Girone ripescaggio (6 → 2 qualificate + 4 eliminate)
└─ 2 qualificate alla finale ripescaggio

RISULTATO FINALE
├─ 🥇 Vincitori (da Winners)
├─ 🥈 Finalisti ripescaggio (da Ripescaggio)
└─ ❌ 4 eliminate dal torneo
```

## Verifiche

✅ Test 1: Fasi si salvano con routing `qualifiedGoTo` e `eliminatedGoTo`
✅ Test 2: Backend calcola correttamente squadre qualificate/eliminate
✅ Test 3: Scenario Castello con 8 squadre funziona correttamente
✅ Test 4: Tutti i rami gestiti univocamente (nessuna squadra conteggiata doppia)

## Come Usare

1. **Crea la prima fase (Gironi)**
   - Vai a "Fasi torneo"
   - Clicca "+ Aggiungi fase"
   - Scegli il ramo "root" (è la prima fase)
   - Configura: numero gironi, squadre che avanzano, ripescaggio
   - Salva

2. **Crea la seconda fase per il ramo qualificate**
   - Clicca "+ Aggiungi fase"
   - Scegli il ramo "qualificate"
   - Il sistema mostrerà il numero di squadre qualificate dalla fase precedente
   - Configura il tipo fase (gironi o knockout)
   - Salva

3. **Crea la terza fase per il ramo eliminate (se ripescaggio abilitato)**
   - Clicca "+ Aggiungi fase"
   - Scegli il ramo "eliminated"
   - Il sistema mostrerà il numero di squadre eliminate dalla fase precedente
   - Configura il ripescaggio
   - Salva

## Risultato

✅ Le fasi si salvano completamente con tutti i dati di routing
✅ Il numero di squadre viene calcolato automaticamente tra le fasi
✅ Supporto completo per torneo a castello con doppio ramo (winners/losers)
✅ Tutte le squadre sono tracciate univocamente attraverso le fasi
