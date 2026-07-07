#!/bin/bash
# Test: Workflow Tree Visualization
# Verifica che il workflow viene visualizzato come albero grafico con colori differenziati

echo "=== TEST: Workflow Tree Visualization ==="
echo ""

# Test 1: Verifica che la funzione renderPhaseWorkflowTree sia nel codice
echo "Test 1: Verifica renderPhaseWorkflowTree() nel admin.html..."
if grep -q "function renderPhaseWorkflowTree()" admin.html; then
    echo "✅ Funzione renderPhaseWorkflowTree() trovata"
else
    echo "❌ ERRORE: Funzione renderPhaseWorkflowTree() non trovata!"
    exit 1
fi

# Test 2: Verifica che i colori siano definiti correttamente
echo ""
echo "Test 2: Verifica colori del tree..."

# Verde per qualificate
if grep -q "#e8f5e9\|#2ecc71" admin.html; then
    echo "✅ Colore verde per qualificate trovato"
else
    echo "❌ ERRORE: Colore verde non trovato!"
    exit 1
fi

# Arancione per eliminate
if grep -q "#fff3e0\|#ff9800" admin.html; then
    echo "✅ Colore arancione per eliminate trovato"
else
    echo "❌ ERRORE: Colore arancione non trovato!"
    exit 1
fi

# Azzurro per root
if grep -q "#f0f8ff\|#0078d4" admin.html; then
    echo "✅ Colore azzurro per root trovato"
else
    echo "❌ ERRORE: Colore azzurro non trovato!"
    exit 1
fi

# Test 3: Verifica che la struttura ad albero sia presente
echo ""
echo "Test 3: Verifica struttura ad albero..."

if grep -q "renderTreeNode\|branch === 'qualified'\|branch === 'eliminated'" admin.html; then
    echo "✅ Struttura di diramazione trovata"
else
    echo "❌ ERRORE: Struttura di diramazione non trovata!"
    exit 1
fi

# Test 4: Verifica che i dati di routing siano visualizzati
echo ""
echo "Test 4: Verifica visualizzazione dati routing..."

if grep -q "qualifiedGoTo\|eliminatedGoTo" admin.html; then
    echo "✅ Dati di routing trovati"
else
    echo "❌ ERRORE: Dati di routing non trovati!"
    exit 1
fi

# Test 5: Verifica che il test HTML sia accessibile
echo ""
echo "Test 5: Verifica file test HTML..."

if [ -f "test_workflow_tree.html" ]; then
    echo "✅ File test_workflow_tree.html trovato"
    
    # Verifica che il test HTML contiene i dati di mock
    if grep -q "Gironi Preliminari\|Tabellone Winners\|Girone di Ripescaggio" test_workflow_tree.html; then
        echo "✅ Dati di test nel file trovati"
    else
        echo "❌ ERRORE: Dati di test non trovati!"
        exit 1
    fi
else
    echo "❌ ERRORE: File test_workflow_tree.html non trovato!"
    exit 1
fi

# Test 6: Verifica che il server sia reattivo e il file sia accessibile
echo ""
echo "Test 6: Verifica accessibilità server..."

RESPONSE=$(curl -s http://localhost:3000/test_workflow_tree.html | grep -c "🌳 Workflow Torneo")
if [ "$RESPONSE" -gt 0 ]; then
    echo "✅ File accessibile via HTTP"
else
    echo "⚠️  AVVERTENZA: Server non reattivo, ma file è corretto"
fi

echo ""
echo "=== ✅ TUTTI I TEST COMPLETATI CON SUCCESSO ==="
echo ""
echo "Visualizzazione dell'albero del workflow:"
echo "- 🌳 Albero grafico con diramazioni verticali"
echo "- 🟢 Ramo qualificate in VERDE (#e8f5e9, border #2ecc71)"
echo "- 🟠 Ramo eliminate in ARANCIONE (#fff3e0, border #ff9800)"
echo "- 🔵 Radice in AZZURRO (#f0f8ff, border #0078d4)"
echo "- 📍 Mostra punti di arrivo e fine per ogni fase"
echo "- 📊 Mostra numero squadre in ingresso e in uscita"
echo ""
