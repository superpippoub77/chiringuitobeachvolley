#!/bin/bash

# QUICK START - TEST AUTOMATICI TORNEI
# 
# Esegui questo script per avviare tutti i test automaticamente
# Prerequisito: npm start deve essere in esecuzione in un altro terminale

set -e

BASE_URL="http://localhost:3000"
PASSWORD="admin123"

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║                                                                ║"
echo "║            BEACHMASTER TEST SUITE - QUICK START                ║"
echo "║                                                                ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# Verifiche preliminari
echo "📋 Verifiche preliminari..."
echo ""

# Controlla se curl è disponibile
if ! command -v curl &> /dev/null; then
    echo "❌ curl non trovato. Installa curl e riprova."
    exit 1
fi
echo "✓ curl disponibile"

# Controlla se jq è disponibile
if ! command -v jq &> /dev/null; then
    echo "⚠️  jq non trovato. Alcuni output potrebbero non essere parseati correttamente."
    echo "   Per il parsing completo: sudo apt install jq"
fi
echo ""

# Controlla connessione al server
echo "🔍 Verificazione connessione server..."
HEALTH=$(curl -s -m 5 "$BASE_URL/api.php?action=get_version" || echo "FAIL")

if [[ "$HEALTH" == "FAIL" ]] || [[ -z "$HEALTH" ]]; then
    echo "❌ Impossibile connettersi a $BASE_URL"
    echo "   Avvia il server con: npm start"
    echo "   In un altro terminale, poi riprova questo script."
    exit 1
fi
echo "✓ Server raggiungibile su $BASE_URL"
echo ""

# Conteggio dei test disponibili
BASH_TESTS=1
PHP_TESTS=1

echo "📊 Test disponibili:"
echo "   • test_tournament_combinations.sh (11 scenari bash)"
echo "   • test_tournament_advanced.php (6 scenari PHP)"
echo ""

# Menu scelta test
echo "Quale set di test desideri eseguire?"
echo ""
echo "  1) Solo Test Bash (11 scenari)"
echo "  2) Solo Test PHP (6 scenari)"
echo "  3) Tutti i Test (17 scenari totali)"
echo "  4) Esci"
echo ""

read -p "Scelta [1-4]: " choice

case $choice in
    1)
        echo ""
        echo "🚀 Avvio Test Bash..."
        echo ""
        bash test_tournament_combinations.sh "$BASE_URL" "$PASSWORD"
        exit $?
        ;;
    2)
        echo ""
        echo "🚀 Avvio Test PHP..."
        echo ""
        php test_tournament_advanced.php
        exit $?
        ;;
    3)
        echo ""
        echo "🚀 Avvio TUTTI i Test..."
        echo ""
        
        # Test Bash
        echo ""
        echo "═══════════════════════════════════════════════════════════════"
        echo "  ESECUZIONE: Test Bash (11 scenari)"
        echo "═══════════════════════════════════════════════════════════════"
        echo ""
        bash test_tournament_combinations.sh "$BASE_URL" "$PASSWORD"
        BASH_RESULT=$?
        
        echo ""
        sleep 2
        
        # Test PHP
        echo ""
        echo "═══════════════════════════════════════════════════════════════"
        echo "  ESECUZIONE: Test PHP (6 scenari)"
        echo "═══════════════════════════════════════════════════════════════"
        echo ""
        php test_tournament_advanced.php
        PHP_RESULT=$?
        
        # Resoconto finale
        echo ""
        echo ""
        echo "╔════════════════════════════════════════════════════════════════╗"
        echo "║              RESOCONTO FINALE DI TUTTI I TEST                  ║"
        echo "╚════════════════════════════════════════════════════════════════╝"
        echo ""
        
        if [ $BASH_RESULT -eq 0 ]; then
            echo "✅ Test Bash: PASSATI (11/11)"
        else
            echo "❌ Test Bash: FALLITI"
        fi
        
        if [ $PHP_RESULT -eq 0 ]; then
            echo "✅ Test PHP: PASSATI (6/6)"
        else
            echo "❌ Test PHP: FALLITI"
        fi
        
        echo ""
        
        if [ $BASH_RESULT -eq 0 ] && [ $PHP_RESULT -eq 0 ]; then
            echo "🎉 TUTTI I TEST COMPLETATI CON SUCCESSO!"
            echo ""
            echo "✅ 17 scenari di test automatici validati"
            echo "✅ Tutte le combinazioni di tornei testate"
            echo "✅ Sistema pronto per deployment in produzione"
            exit 0
        else
            echo "⚠️  ALCUNI TEST HANNO FALLITO"
            echo ""
            echo "Leggi gli output sopra per identificare i problemi."
            exit 1
        fi
        ;;
    4)
        echo "Arrivederci!"
        exit 0
        ;;
    *)
        echo "❌ Scelta non valida. Seleziona 1, 2, 3 o 4."
        exit 1
        ;;
esac
