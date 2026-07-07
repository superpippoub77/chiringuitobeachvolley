# 🌳 Workflow Tree Visualization - Documentazione

## Panoramica

La visualizzazione del workflow torneo è stata trasformata da una **semplice sequenza orizzontale** a un **albero grafico con diramazioni e colori differenziati**.

## Caratteristiche

### 1. **Struttura ad Albero**
- **Radice (Root)**: Prima fase (input totale squadre)
- **Diramazioni**: Due rami separati per squadre qualificate (verde) e eliminate (arancione)
- **Nodi**: Ogni fase è rappresentata come un blocco colorato con informazioni complete

### 2. **Codificazione dei Colori**

| Colore | Ramo | Significato | Border |
|--------|------|-------------|--------|
| 🟢 Verde | Qualificate | Squadre che avanzano | #2ecc71 |
| 🟠 Arancione | Eliminate | Squadre ripescate o eliminate | #ff9800 |
| 🔵 Azzurro | Root | Fase iniziale | #0078d4 |

### 3. **Informazioni Visualizzate per Fase**

Ogni nodo dell'albero mostra:
- **Nome fase** con icona semantica (📥 input, ✅ qualificate, ❌ eliminate)
- **Numero squadre in ingresso** (📥)
- **Numero squadre qualificate** (✓)
- **Numero squadre eliminate/ripescate** (✗ o ⟳)
- **Destinazione** (qualifiedGoTo, eliminatedGoTo)

### 4. **Esempio Visualizzazione**

```
                    ┌─────────────────────┐
                    │   📥 Gironi (16)    │
                    │  ✓ Qualif: 8        │
                    │  ⟳ Ripescag: 8      │
                    └──────────┬──────────┘
                               │
                    ┌──────────┴──────────┐
                    │                     │
            ┌───────▼────────┐   ┌────────▼────────┐
            │ ✅ Winners (8) │   │❌ Ripescag (8)  │
            │ ✓ Qualif: 4    │   │ ✓ Qualif: 4     │
            │ ✗ Elim: 4      │   │ ✗ Elim: 4       │
            └────────────────┘   └─────────────────┘
```

## Come Usare

### Nel Pannello Admin

1. **Accedi al pannello admin**
   - URL: `http://localhost:3000/admin.html`
   - Password: `admin123`

2. **Naviga al tab "Fasi torneo"**
   - Clicca su "Fasi torneo" nel menu principale

3. **Visualizza il Workflow Tree**
   - Sottosezione "📊 Workflow Torneo - Albero Diramazioni"
   - L'albero mostra automaticamente tutte le fasi create
   - I colori indicano il tipo di ramo (qualificate/eliminate)

4. **Leggi le Informazioni**
   - Numero squadre per ogni fase
   - Destinazione qualificate e eliminate
   - Struttura ad albero per capire il flusso

### Responsività Mobile

- **Desktop**: Layout completo con tutte le diramazioni
- **Tablet**: Scroll orizzontale se necessario
- **Mobile**: Scroll orizzontale ottimizzato per piccoli schermi

## Struttura Tecnica

### Funzioni Principali

```javascript
renderPhaseWorkflowTree()
  ├─ Legge config.phases[]
  ├─ Calcola squadre qualificate/eliminate
  └─ renderTreeNode(phaseIdx, branch, teamsIn)
     ├─ Crea nodo per la fase
     ├─ Applica colore in base al branch
     └─ Ricorre per fasi successive

```

### CSS Variabili

- `--bg`: Background colore tema
- `--ink`: Colore testo
- `--accent`: Colore primario (arancio)
- `--accent-2`: Colore secondario (verde)
- `--line`: Colore bordo (#d8c39f)

## Test

### Test Unitario
```bash
bash test_workflow_tree.sh
```
Verifica:
- ✅ Funzione renderPhaseWorkflowTree() presente
- ✅ Colori differenziati definiti
- ✅ Struttura ad albero implementata
- ✅ Dati di routing visualizzati

### Test di Integrazione
```bash
bash test_workflow_tree_integration.sh http://localhost:3000 admin123
```
Verifica:
- ✅ Login completato
- ✅ Configurazione torneo salvata
- ✅ Tutte le fasi salvate con routing
- ✅ Dati recuperabili correttamente

### Test HTML (Standalone)
```
http://localhost:3000/test_workflow_tree.html
```
Visualizzazione standalone con dati di esempio per verifica grafica.

## Flag hasLosersPath per Knockout ⭐

### Nuova Feature: Knockout con Losers Bracket

A partire dalla v2.0, il knockout supporta il flag `hasLosersPath` per permettere alle squadre perdenti del primo turno di continuare in un **losers bracket**.

### Modalità Operative

#### Knockout Standard (hasLosersPath = false)
```
Input: 8 squadre
├─ Qualificate (Winners): 4 squadre → Fase successiva
└─ Eliminate (Losers): 4 squadre ❌ Eliminate

Risultato: Eliminazione diretta
```

#### Knockout con Losers Bracket (hasLosersPath = true)
```
Input: 8 squadre
├─ Qualificate (Winners): 4 squadre → Winners Bracket
└─ Losers Bracket 🎯: 4 squadre → Losers Bracket (continuano)

Risultato: Doppio ramo come nel ripescaggio gironi
```

### Configurazione nel Wizard

**Step 2 - Configurazione Knockout:**
- ✅ Campo: "🎯 Permettere un ramo per le perdenti del primo turno"
- ✅ Checkbox abilitabile per ogni knockout
- ✅ Quando abilitato, il preview mostra "🎯 Losers Bracket" con label arancione

### Visualizzazione nel Workflow Tree

Quando `hasLosersPath = true`, il tree mostra:

```
┌─────────────────────────┐
│  📥 Knockout (8)        │
│  ✓ Qualificate: 4       │
│  🎯 Losers Bracket: 4   │ ← Arancione anziché rosso
└──────────┬──────────────┘
           │
    ┌──────┴──────────┐
    │                 │
┌───▼────────┐  ┌────▼──────────┐
│Winners (4) │  │Losers (4) 🎯  │
│✓ Qualif: 2 │  │✓ Qualif: 2    │
│✗ Elim: 2   │  │✗ Elim: 2      │
└────────────┘  └───────────────┘
```

### Struttura Dati

```json
{
  "phaseNumber": 2,
  "name": "Knockout Fase 1",
  "type": "knockout",
  "numTeams": 8,
  "hasLosersPath": true,
  "branch": "root",
  "qualifiedGoTo": "Winners Bracket",
  "eliminatedGoTo": "Losers Bracket"
}
```

### Differenza con Ripescaggio (Groups)

| Aspetto | Groups + Ripescaggio | Knockout + Losers |
|---------|----------------------|-------------------|
| Flag | `hasRepescage` | `hasLosersPath` |
| Applicabile a | Gironi (groups) | Knockout (knockout) |
| Destinazione Eliminati | Ripescaggio | Losers Bracket |
| Colore Label | 🟠 Arancione | 🟠 Arancione |
| Squadre nel ramo | Tutte eliminate | Perdenti primo turno |

### Test

```bash
# Test integrazione completa
bash test_knockout_losers_path.sh

# Output:
# ✅ Login completato
# ✅ Configurazione creata con successo
# ✅ Flag hasLosersPath salvato correttamente (true)
# ✅ Flag hasLosersPath salvato correttamente (false)
# ✅ 3 fasi create correttamente
# ✅ Routing delle squadre salvato
```

### Casi d'Uso

#### Caso 1: Torneo con Doppi Rami (Winners + Losers)
```
Gironi (32) → hasRepescage=true
├─ Winners (8) → Knockout Winners (hasLosersPath=true)
│  ├─ Winners: 4 → Semifinali Winners
│  └─ Losers: 4 → Finali Losers
└─ Losers (8) → Knockout Losers (hasLosersPath=false)
   ├─ Winners: 4 → Finali Losers
   └─ Eliminate: 4 ✗
```

#### Caso 2: Torneo Semplice con Losers
```
Gironi (16) → hasRepescage=false
├─ Winners (8) → Knockout Winners (hasLosersPath=false)
└─ Losers (8) → Eliminate ✗
```

## Miglioramenti Futuri

1. **Esportazione SVG**: Esporta l'albero come immagine SVG
2. **Animazioni**: Animazione di espansione/contrazione dei rami
3. **Interattività**: Click su nodi per modificare fase
4. **Legenda**: Legenda dei colori e simboli
5. **Stampa**: Stampa dell'albero in PDF
6. **Simulazione**: Visualizza il percorso di una squadra specifica

## File Modificati

- `/workspaces/chiringuitobeachvolley/admin.html`
  - `renderPhaseWorkflowTree()` - Nuova funzione per albero
  - `renderPhaseWorkflow()` - Wrapper che chiama la nuova funzione

## Conclusione

La nuova visualizzazione ad albero rende molto più chiaro il flusso delle fasi del torneo, con colori semantici che indicano il ramo (qualificate/eliminate) e informazioni complete su squadre in ingresso, in uscita e destinazioni.

✅ **Feature Completata**: Visualizzazione del workflow come albero grafico con diramazioni e colori differenziati.
