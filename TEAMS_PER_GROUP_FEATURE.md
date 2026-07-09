# 🎯 Feature: Squadre Passanti per Girone (Valori Asimmetrici)

## Overview
Permette di specificare quante squadre passano dal **singolo girone** alla fase successiva, invece che un numero uniforme per tutti i gironi.

## Problema Risolto
Prima potevi solo dire "2 squadre passano per girone" da tutti i gironi uniformemente.
Adesso puoi dire "dal girone A passano 2, dal B passano 2, dal C passano 3" in modo indipendente.

## Come Usare

### Nel Admin Panel

1. **Vai a**: "Fasi Torneo" → Sezione gironi
2. **Campo**: "Squadre che Passano per Girone"

#### Opzione 1: Singolo Numero (Retrocompatibile)
- Inserisci: `2`
- Effetto: Da ogni girone passano 2 squadre (identico a prima)

#### Opzione 2: Valori Separati da Virgola (NUOVO)
- Inserisci: `2,2,3` (per 3 gironi)
- Effetto: 
  - Girone A → passano 2 squadre (top 2 classifica)
  - Girone B → passano 2 squadre (top 2 classifica)
  - Girone C → passano 3 squadre (top 3 classifica)

## Esempi Pratici

### Scenario 1: Torneo Asimmetrico 8 squadre
```
Gironi: 3 (A, B, C)
Squadre per girone: ~3 squadre cadauno
Squadre che passano: "2,2,2"

Risultato:
- Girone A (3 sq) → passano top 2
- Girone B (3 sq) → passano top 2  
- Girone C (2 sq) → passano top 2
---
Totale qualificate: 6 squadre → Fase knockout (con 2 bye)
```

### Scenario 2: Girone Principale + Gironi Piccoli
```
Gironi: 4 (A grande, B, C, D piccoli)
Squadre per girone: A=6, B=3, C=3, D=2
Squadre che passano: "3,2,2,1"

Risultato:
- Girone A (6 sq) → passano top 3 (i forti)
- Girone B (3 sq) → passano top 2
- Girone C (3 sq) → passano top 2
- Girone D (2 sq) → passa top 1 (solo il migliore)
---
Totale qualificate: 8 squadre → Knockout standard 8 team
```

### Scenario 3: Ripescaggio con Diversi Passaggi
```
Gironi: 4
Squadre che passano: "2,2,2,2"  (singolo numero)
Ha ripescaggio: SÌ

Effetto:
- 8 squadre passano (2 per girone) → Fase knockout
- 24 squadre vengono eliminate → Girone di ripescaggio (dalla loro classifica escono nuove qualificate)
```

## Validazione e Comportamento

### Cosa Succede se...

#### ...i valori non corrispondono al numero di gironi?
- **Pochi valori**: gli ultimi gironi hanno valore 0 (vengono aggiunti zeri)
  - Esempio: teamsAdvance="2,2", numGroups=4 → [2, 2, 0, 0]
  - Gironi C e D non mandano nessuno qualificato

- **Troppi valori**: vengono ignorati gli ulteriori valori
  - Esempio: teamsAdvance="2,2,2,2", numGroups=3 → [2, 2, 2]
  - Il quarto valore viene scartato

#### ...inserisco testo non valido?
- Valori non numerici vengono convertiti a 0
- Spazi prima/dopo virgole vengono rimossi
- Stringhe vuote diventano 0

#### ...la somma supera le squadre disponibili?
Il sistema estrae comunque i top N dal girone anche se non ci sono abbastanza squadre
- Se Girone A ha 3 squadre ma chiedi 5, ne estrae 3 disponibili

## Implementazione Tecnica

### Funzioni Backend

```php
// Parse valore in array di interi
parseTeamsAdvancePerGroup($teamsAdvance, $numGroups) → array

// Estrai squadre qualificate rispettando il valore per girone
extractQualifiedTeamsByGroup($phases, $standings, $teamsPerGroup) → array
```

### Flusso di Creazione Fase Knockout

1. Leggi config della fase gironi
2. Parsa `teamsAdvance` da config
3. Chiama `extractQualifiedTeamsByGroup()` con i valori per girone
4. Crea match di knockout dalla squadre estratte

### Backward Compatibility

- ✅ Input singolo numero funziona come prima: `2` → [2,2,2,2]
- ✅ JSON config preserva sia int che string
- ✅ Se fase gironi non trovata, fallback a top metà squadre

## Frontend

### Input Field
- **Tipo**: Text (non Number per permettere virgole)
- **Placeholder**: "es: 2 oppure 2,2,3"
- **Validazione**: Lato server durante parsing

### Hint Visivo
Piccolo testo informativo sotto il campo spiega:
> 💡 Puoi usare un singolo numero (es: `2`) per 2 squadre da ogni girone,  
> oppure valori separati da virgola (es: `2,2,3`) per specificare per ogni girone.

## Casi di Test

✅ Singolo numero con 4 gironi: `2` → [2,2,2,2]  
✅ Asimmetrico: `2,2,3` → [2,2,3]  
✅ Con spazi: ` 2 , 3 , 1 ` → [2,3,1]  
✅ Pochi valori: `3,2` (numGroups=4) → [3,2,0,0]  
✅ Numero intero: `3` → [3,3,3,3]  
✅ Troppi valori: `2,2,2,2,2` (numGroups=3) → [2,2,2]  
✅ Somma: `2,2,3` → somma=7 ✓

## Files Modificati

- **api.php**:
  - `parseTeamsAdvancePerGroup()` - Parsing della stringa
  - `extractQualifiedTeamsByGroup()` - Estrazione squadre per girone
  - Modifica knockout generation per usare i nuovi valori

- **admin.html**:
  - Input type change: number → text
  - Helper text informativo
  - JavaScript parsing preserva virgole

- **todo.txt**:
  - Feature marcata come completata ✅

## Uso Avanzato: Combinazioni

### Gironi Simmetrici + Differenti Passaggi Finali
```
Girone 1: 3,3,3,3 (da 4 gironi passano tutti 3)
= 12 qualificate

Knockout 1: 12 → 6
Knockout 2 (Semis): 6 → 2  
Finale: 2 → 1
```

### Coppa Italia Style
```
Gironi: 8 piccoli gironi
Squadre per girone: 1,1,1,1,1,1,1,1 (solo il vincitore di ogni girone)
= 8 qualificate → Ottavi diretti
```

### Ripescaggio Asimmetrico
```
Gironi: "3,2,2,2" (da primo girone escono 3, dagli altri 2)
Ha ripescaggio: SÌ
= 9 qualificate + N eliminate nel ripescaggio
```

## Note

- La feature è fully retrocompatibile con configurazioni esistenti
- Lavora con ripescaggio: la somma di teamsAdvance determina le qualificate
- Il rendering nel wizard mostra il numero per girone nella sezione recap
- Log error_log per debug: mostra perGroup array e totale

---

**Implementazione**: 2026-07-09  
**Status**: ✅ PRODUCTION READY
