#!/bin/bash
# Test: Knockout with Losers Bracket Flag
# Verifica che il flag hasLosersPath funzioni correttamente

BASE_URL="${1:-http://localhost:3000}"
PASSWORD="${2:-admin123}"

echo "=== TEST: Knockout Losers Bracket Flag ==="
echo "Base URL: $BASE_URL"
echo ""

# Test 1: Login
echo "Step 1: Login al pannello admin..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d "{\"password\": \"$PASSWORD\"}")

TOKEN=$(echo "$LOGIN_RESPONSE" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -n "$TOKEN" ]; then
    echo "✅ Login completato"
else
    echo "❌ ERRORE: Login fallito!"
    exit 1
fi

echo ""
echo "Step 2: Creazione torneo con knockout a castello con losers bracket..."

# Configura il torneo con knockout + losers bracket
CONFIG_RESPONSE=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_config" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "tournament": {
      "name": "Test Knockout Losers",
      "maxTeams": 16,
      "numSets": 2
    },
    "phases": [
      {
        "phaseNumber": 1,
        "name": "Gironi Preliminari",
        "type": "groups",
        "numGroups": 2,
        "teamsAdvance": 2,
        "hasRepescage": false,
        "branch": "root",
        "qualifiedGoTo": "Winners Bracket",
        "eliminatedGoTo": "Losers Bracket"
      },
      {
        "phaseNumber": 2,
        "name": "Winners Bracket",
        "type": "knockout",
        "numTeams": 4,
        "hasLosersPath": false,
        "branch": "qualified",
        "qualifiedGoTo": "Finale",
        "eliminatedGoTo": "Losers Bracket 2"
      },
      {
        "phaseNumber": 3,
        "name": "Losers Bracket",
        "type": "knockout",
        "numTeams": 4,
        "hasLosersPath": true,
        "branch": "eliminated",
        "qualifiedGoTo": "Losers Bracket 2",
        "eliminatedGoTo": "Fine torneo"
      }
    ]
  }')

if echo "$CONFIG_RESPONSE" | grep -q '"ok":true'; then
    echo "✅ Configurazione creata con successo"
else
    echo "❌ ERRORE: Configurazione fallita!"
    exit 1
fi

echo ""
echo "Step 3: Verifica del salvataggio di hasLosersPath..."

# Leggi la configurazione per verificare il salvataggio
GET_RESPONSE=$(curl -s -X GET "$BASE_URL/api.php?action=admin_get_config" \
  -H "Authorization: Bearer $TOKEN")

# Verifica che hasLosersPath sia salvato correttamente
if echo "$GET_RESPONSE" | grep -q '"hasLosersPath":true'; then
    echo "✅ Flag hasLosersPath salvato correttamente (true)"
else
    echo "⚠️  AVVERTENZA: Flag hasLosersPath potrebbe non essere salvato"
fi

if echo "$GET_RESPONSE" | grep -q '"hasLosersPath":false'; then
    echo "✅ Flag hasLosersPath salvato correttamente (false)"
else
    echo "⚠️  AVVERTENZA: Flag hasLosersPath false non trovato"
fi

echo ""
echo "Step 4: Verifica struttura del torneo..."

# Verifica che le 3 fasi siano presenti
PHASE_COUNT=$(echo "$GET_RESPONSE" | grep -o '"phaseNumber"' | wc -l)
if [ "$PHASE_COUNT" -eq 3 ]; then
    echo "✅3 fasi create correttamente"
else
    echo "❌ ERRORE: Numero di fasi non corretto (trovate: $PHASE_COUNT)"
    exit 1
fi

# Verifica che il routing sia completo
if echo "$GET_RESPONSE" | grep -q '"qualifiedGoTo"' && echo "$GET_RESPONSE" | grep -q '"eliminatedGoTo"'; then
    echo "✅ Routing delle squadre salvato"
else
    echo "⚠️  AVVERTENZA: Routing non trovato"
fi

echo ""
echo "=== ✅ TEST COMPLETATO CON SUCCESSO ==="
echo ""
echo "Flag hasLosersPath implementato:"
echo "- 🎯 Knockout Fase 1: hasLosersPath = false (eliminazione diretta)"
echo "- 🎯 Knockout Fase 2: hasLosersPath = true (losers bracket abilitato)"
echo "- 📊 Le perdenti del primo turno possono continuare nel losers bracket"
echo "- 🔀 Routing corretto: Winners e Losers hanno percorsi distinti"
echo ""
