# 🎯 Feature: Squadre Qualificate Evidenziate in Verde

## Overview
Nelle classifiche dei gironi, le squadre che passano alla fase successiva sono automaticamente **sottolineate di verde**, in base al valore configurato nella fase.

## Implementazione

### Backend
Non richiede modifiche backend - la configurazione è letta dal frontend direttamente da `config.phases[0].teamsAdvance` o `state.phases[phaseIdx].teamsAdvance`.

### Frontend - Scoreboard Pubblico (scoreboard.html)

**Nuova funzione helper:**
```javascript
function getTeamsAdvanceForGroup(groupLabel) {
  // Parsa il valore teamsAdvance e ritorna quante squadre passano da questo girone
  // Supporta: singolo numero "2" o valori comma-separated "2,2,3"
}
```

**Modifiche a `renderStandings()`:**
- Legge `config.phases[0].teamsAdvance`
- Per ogni girone, calcola quante squadre passano
- Applica stile: **sottolineatura verde + bordo verde + font bold**
- Colore: `#4caf50` (verde)

**Stile applicato alle righe qualificate:**
```css
border-bottom: 3px solid #4caf50;      /* Bordo inferiore verde spesso */
font-weight: 600;                       /* Testo più marcato */
text-decoration: underline;             /* Sottolineatura */
text-decoration-color: #4caf50;         /* Colore sottolineatura verde */
text-decoration-thickness: 3px;         /* Spessore sottolineatura */
text-underline-offset: 4px;             /* Distanza dal testo */
```

### Frontend - Admin Panel (admin.html)

**Modifiche a `renderPhaseStandings()`:**
- Legge `statePhase.teamsAdvance` (dalla fase nello state)
- Parsa il valore supportando sia singoli numeri che comma-separated
- Per ogni girone, applica lo stile alle prime N squadre
- Stile: **sottolineatura verde + background leggero + bordo verde + font bold**

**Stile applicato:**
```css
border-bottom: 3px solid #4caf50;      /* Bordo verde */
background: #e8f5e9;                   /* Background verde molto leggero */
font-weight: 600;                       /* Font marcato */
text-decoration: underline;
text-decoration-color: #4caf50;
text-decoration-thickness: 3px;
text-underline-offset: 4px;
```

## Come Funziona

### Esempio 1: Valore Singolo
```
teamsAdvance: 2
Gironi: A, B, C

Risultato:
- Girone A: top 2 sottolineati in verde
- Girone B: top 2 sottolineati in verde
- Girone C: top 2 sottolineati in verde
```

### Esempio 2: Valori Asimmetrici
```
teamsAdvance: "2,2,3"
Gironi: A, B, C

Risultato:
- Girone A (indice 0): top 2 sottolineati
- Girone B (indice 1): top 2 sottolineati
- Girone C (indice 2): top 3 sottolineati
```

### Esempio 3: Valori Con Zeri
```
teamsAdvance: "3,2,0"
Gironi: A, B, C

Risultato:
- Girone A: top 3 sottolineati
- Girone B: top 2 sottolineati
- Girone C: nessuno sottolineato (0 qualificati)
```

## Parsing dei Valori

Il parsing supporta:

✅ Singolo numero:
```
"2" → [2, 2, 2, 2, ...]  (per tutti i gironi)
```

✅ Valori comma-separated:
```
"2,2,3" → [2, 2, 3]
```

✅ Con spazi:
```
" 2 , 2 , 3 " → [2, 2, 3]  (spazi rimossi automaticamente)
```

✅ Pochi valori (completa con 0):
```
"3,2" con 4 gironi → [3, 2, 0, 0]
```

✅ Troppi valori (truncate):
```
"2,2,2,2,2" con 3 gironi → [2, 2, 2]  (ulteriori ignorati)
```

## Visibilità della Feature

### Nel Scoreboard Pubblico (scoreboard.html)
- **Pagina**: Classifiche gironi
- **Dove**: Nella tabella di ogni girone
- **Stile**: Sottolineatura verde spessa (3px) sul nome squadra + bordo inferiore verde della riga

### Nel Admin Panel (admin.html)
- **Pagina**: Fasi Torneo → Tab Classifiche
- **Dove**: Nelle tabelle dei gironi
- **Stile**: Sottolineatura verde + background leggero verde + bordo inferiore verde spesso

## Edge Cases Gestiti

| Caso | Comportamento |
|------|---------------|
| Nessun `teamsAdvance` configurato | Default: 1 squadra passa |
| Valore non numerico | Convertito a 0 |
| Girone con meno squadre di quanto passano | Estrae tutte quelle disponibili |
| Indice girone fuori range | Ignora silenziosamente |

## Files Modificati

✅ `scoreboard.html`:
- Aggiunta funzione `getTeamsAdvanceForGroup()`
- Modificata funzione `renderStandings()`
- Aggiunta sottolineatura verde alle squadre qualificate

✅ `admin.html`:
- Modificata funzione `renderPhaseStandings()`
- Aggiunto parsing dei valori comma-separated
- Applicati stili differenziati per squadre qualificate vs eliminate

✅ `todo.txt`:
- Feature marcata come completata ✅

## Retrocompatibilità

✅ Funziona con configurazioni vecchie (singolo numero)  
✅ Funziona con nuove configurazioni asimmetriche  
✅ Nessun breaking change  
✅ Fallback a default se config manca  

## Testing

Testare i seguenti scenari:

1. **Girone singolo con top 2**: 
   - Set `teamsAdvance: 2` → Verifica che top 2 sono sottolineati in verde

2. **Gironi asimmetrici**:
   - Set `teamsAdvance: "2,3,1"` → Verifica che ogni girone ha il giusto numero sottolineato

3. **Admin panel**:
   - Naviga a "Fasi Torneo" → "Classifiche"
   - Verifica che le squadre qualificate hanno stile verde

4. **Scoreboard pubblico**:
   - Apri scoreboard pubblico
   - Vai a "Classifiche"
   - Verifica che le squadre qualificate sono sottolineate in verde

## Possibili Miglioramenti Futuri

- Tooltip al hover: "Questa squadra passa alla fase successiva"
- Badge: "✓ QUALIFICATO" accanto al nome
- Animazione leggera al caricamento delle classifiche
- Esport classifiche con nota "*" per i qualificati

---

**Implementazione**: 2026-07-09  
**Status**: ✅ PRODUCTION READY
