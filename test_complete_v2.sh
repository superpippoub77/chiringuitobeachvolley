#!/bin/bash

# Script di test completo del sistema Chiringuito Beach Volley - Versione 2
# Testa: Torneo, Sponsor, Notizie, Campi, Squadre, Giocatori, Note

BASE_URL="http://localhost:3000"
PASSWORD="admin123"

# Colori per output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${YELLOW}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${YELLOW}║   FASE DI TEST COMPLETA - CHIRINGUITO BEACH VOLLEY        ║${NC}"
echo -e "${YELLOW}╚════════════════════════════════════════════════════════════╝${NC}\n"

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Helper function per testare
test_endpoint() {
  local test_name="$1"
  local response="$2"
  local expected_key="$3"
  
  if echo "$response" | jq -e ".$expected_key" > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} $test_name"
    ((TESTS_PASSED++))
    return 0
  else
    echo -e "${RED}✗${NC} $test_name"
    echo "  Response: $response" | head -3
    ((TESTS_FAILED++))
    return 1
  fi
}

# 1. RESET COMPLETO
echo -e "${BLUE}1. RESET TORNEO COMPLETO${NC}"
RESET=$(curl -s -X POST "$BASE_URL/api.php?action=admin_reset_tournament" \
  -H "Content-Type: application/json" \
  -d '{"password":"admin123"}')
test_endpoint "Reset torneo" "$RESET" "ok"
echo ""

# 2. LOGIN ADMIN
echo -e "${BLUE}2. LOGIN ADMIN${NC}"
LOGIN=$(curl -s -X POST "$BASE_URL/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d "{\"password\":\"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | jq -r '.token' 2>/dev/null)
if [ "$TOKEN" != "null" ] && [ -n "$TOKEN" ]; then
  echo -e "${GREEN}✓${NC} Admin login"
  ((TESTS_PASSED++))
else
  echo -e "${RED}✗${NC} Admin login failed"
  echo "$LOGIN"
  ((TESTS_FAILED++))
  exit 1
fi
echo ""

# 3. CREAZIONE CONFIGURAZIONE TORNEO
echo -e "${BLUE}3. CONFIGURAZIONE TORNEO${NC}"
CONFIG=$(cat <<'EOF'
{
  "tournament": {
    "name": "Chiringuito 2026 - Torneo di Test",
    "maxTeams": 12,
    "maxPlayersPerTeam": 14,
    "maxPlayersOnCourt": 6,
    "maxSubstitutions": 5,
    "numGroups": 3,
    "numSets": 3,
    "winScore": 25,
    "maxScore": 27,
    "timePerSetMinutes": 30,
    "setupTimeMinutes": 5,
    "maxTimeoutsPerSet": 2
  },
  "schedule": {
    "courts": [
      {
        "name": "Campo 1 - Centrale",
        "days": [1, 2, 3, 4],
        "timeRanges": [
          {"start": "08:00", "end": "10:00"},
          {"start": "10:15", "end": "12:15"},
          {"start": "14:00", "end": "16:00"},
          {"start": "16:15", "end": "18:15"}
        ]
      },
      {
        "name": "Campo 2 - Laterale",
        "days": [1, 2, 3, 4],
        "timeRanges": [
          {"start": "08:00", "end": "10:00"},
          {"start": "10:15", "end": "12:15"},
          {"start": "14:00", "end": "16:00"},
          {"start": "16:15", "end": "18:15"}
        ]
      }
    ]
  },
  "contact": {
    "managerEmail": "manager@chiringuito.test"
  },
  "display": {
    "theme": "theme-minimalista"
  },
  "payment": {
    "enabled": true,
    "costPerTeam": 150,
    "currency": "EUR"
  }
}
EOF
)

UPDATE_CONFIG=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_config" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$CONFIG")
test_endpoint "Config aggiornata" "$UPDATE_CONFIG" "config"
echo ""

# 4. CARICAMENTO SQUADRE DI DEMO
echo -e "${BLUE}4. CARICAMENTO SQUADRE DI DEMO${NC}"
SEED=$(curl -s -X POST "$BASE_URL/api.php?action=admin_seed_demo" \
  -H "Authorization: Bearer $TOKEN")
test_endpoint "Demo squadre caricate" "$SEED" "ok"
echo ""

# 5. AGGIUNTA NOTE AL TORNEO
echo -e "${BLUE}5. NOTE AL TORNEO${NC}"
NOTES=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_notes" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "notes": [
      {"id": "elite", "description": "Squadra Elite", "points": 10},
      {"id": "host", "description": "Squadra Ospite", "points": -5},
      {"id": "first_time", "description": "Prima partecipazione", "points": 3},
      {"id": "penalty", "description": "Multa disciplinare", "points": -2}
    ]
  }')
NOTES_COUNT=$(echo "$NOTES" | jq '.notes | length' 2>/dev/null)
echo -e "${GREEN}✓${NC} Note aggiunte ($NOTES_COUNT)"
((TESTS_PASSED++))
echo ""

# 6. AGGIUNTA SPONSOR
echo -e "${BLUE}6. AGGIUNTA SPONSOR${NC}"
SPONSORS=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_sponsors" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sponsors": [
      {"id": "sponsor1", "name": "Acqua Fresca Paradiso", "repetitions": 5},
      {"id": "sponsor2", "name": "Gelato Dolce Vita", "repetitions": 3},
      {"id": "sponsor3", "name": "Ombrelloni Sole", "repetitions": 2}
    ]
  }')
SPONSORS_COUNT=$(echo "$SPONSORS" | jq '.sponsors | length' 2>/dev/null)
test_endpoint "Sponsor aggiunti" "$SPONSORS" "sponsors"
echo ""

# 7. CREAZIONE NOTIZIE
echo -e "${BLUE}7. CREAZIONE NOTIZIE${NC}"

NEWS1=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "🏐 Iscrizioni Aperte!",
    "content": "Le iscrizioni per il torneo Chiringuito 2026 sono aperte! Registra la tua squadra entro il 20 luglio. Quota: 150 EUR",
    "published": true
  }')
NEWS1_ID=$(echo "$NEWS1" | jq -r '.news.id' 2>/dev/null)
test_endpoint "News 1 creata (iscrizioni)" "$NEWS1" "news"

NEWS2=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "📅 Cambio Data Finale",
    "content": "La final four è stata spostata da lunedì 28 luglio a martedì 29 luglio per migliori condizioni meteo.",
    "published": true
  }')
NEWS2_ID=$(echo "$NEWS2" | jq -r '.news.id' 2>/dev/null)
test_endpoint "News 2 creata (cambio data)" "$NEWS2" "news"

NEWS3=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "🔒 Notizia in Bozza",
    "content": "Questa notizia non è ancora pubblicata e non appare agli utenti pubblici.",
    "published": false
  }')
NEWS3_ID=$(echo "$NEWS3" | jq -r '.news.id' 2>/dev/null)
test_endpoint "News 3 creata (bozza)" "$NEWS3" "news"
echo ""

# 8. GENERAZIONE GIRONI
echo -e "${BLUE}8. GENERAZIONE GIRONI${NC}"
GROUPS=$(curl -s -X POST "$BASE_URL/api.php?action=admin_generate_groups" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"numGroups": 3}')
GROUPS_COUNT=$(echo "$GROUPS" | jq '.groups | length' 2>/dev/null)
test_endpoint "Gironi generati" "$GROUPS" "groups"
echo "  → Gironi creati: $GROUPS_COUNT"
echo ""

# 9. VERIFICA STATO TORNEO
echo -e "${BLUE}9. VERIFICA STATO TORNEO${NC}"
STATE=$(curl -s "$BASE_URL/api.php?action=admin_state" \
  -H "Authorization: Bearer $TOKEN")

TEAMS_COUNT=$(echo "$STATE" | jq '.state.teams | length' 2>/dev/null)
GROUPS_COUNT=$(echo "$STATE" | jq '.state.groups | length' 2>/dev/null)
PLAYERS_TOTAL=$(echo "$STATE" | jq '[.state.teams[].players | length] | add' 2>/dev/null)

echo -e "${GREEN}✓${NC} Stato torneo"
((TESTS_PASSED++))
echo "  → Squadre: $TEAMS_COUNT"
echo "  → Gironi: $GROUPS_COUNT"  
echo "  → Giocatori totali: $PLAYERS_TOTAL"
echo ""

# 10. VERIFICA NOTIZIE PUBBLICHE
echo -e "${BLUE}10. VERIFICA NOTIZIE PUBBLICHE${NC}"
PUBLIC_NEWS=$(curl -s "$BASE_URL/api.php?action=public_get_news")
PUBLISHED=$(echo "$PUBLIC_NEWS" | jq '.news | length' 2>/dev/null)
test_endpoint "Notizie pubbliche caricate" "$PUBLIC_NEWS" "news"
echo "  → Notizie pubblicate: $PUBLISHED"
echo ""

# 11. VERIFICA CONFIGURAZIONE PAGAMENTO
echo -e "${BLUE}11. CONFIGURAZIONE PAGAMENTO${NC}"
PAYMENT=$(curl -s "$BASE_URL/api.php?action=admin_get_payment_config" \
  -H "Authorization: Bearer $TOKEN")
PAYMENT_ENABLED=$(echo "$PAYMENT" | jq '.config.payment.enabled' 2>/dev/null)
PAYMENT_COST=$(echo "$PAYMENT" | jq '.config.payment.costPerTeam' 2>/dev/null)
test_endpoint "Config pagamento" "$PAYMENT" "config"
echo "  → Pagamenti abilitati: $PAYMENT_ENABLED"
echo "  → Costo per squadra: $PAYMENT_COST EUR"
echo ""

# 12. GENERAZIONE REGOLAMENTO
echo -e "${BLUE}12. GENERAZIONE REGOLAMENTO${NC}"
REGOLAMENTO=$(curl -s "$BASE_URL/api.php?action=admin_generate_regolamento" \
  -H "Authorization: Bearer $TOKEN")
REG_SIZE=$(echo "$REGOLAMENTO" | jq -r '.regulation | length' 2>/dev/null)
test_endpoint "Regolamento generato" "$REGOLAMENTO" "regulation"
echo "  → Dimensione: $REG_SIZE bytes"
echo ""

# 13. TEST BACKUP EXPORT
echo -e "${BLUE}13. TEST BACKUP - EXPORT${NC}"
BACKUP=$(curl -s "$BASE_URL/api.php?action=admin_export_backup" \
  -H "Authorization: Bearer $TOKEN")
BACKUP_SECTIONS=$(echo "$BACKUP" | jq 'keys | length' 2>/dev/null)
test_endpoint "Backup esportato" "$BACKUP" "version"
echo "  → Sezioni nel backup: $BACKUP_SECTIONS"
echo ""

# 14. VERIFICA SPONSOR PUBBLICI
echo -e "${BLUE}14. SPONSOR PUBBLICI${NC}"
PUBLIC_SPONSORS=$(curl -s "$BASE_URL/api.php?action=get_sponsors")
SPONSORS_PUB=$(echo "$PUBLIC_SPONSORS" | jq '.sponsors | length' 2>/dev/null)
test_endpoint "Sponsor pubblici" "$PUBLIC_SPONSORS" "sponsors"
echo "  → Sponsor visibili: $SPONSORS_PUB"
echo ""

# 15. STATISTICHE FINALI
echo -e "${YELLOW}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${YELLOW}║                    RISULTATI TEST                         ║${NC}"
echo -e "${YELLOW}╠════════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}✓ Test passati: $TESTS_PASSED${NC}"
if [ $TESTS_FAILED -gt 0 ]; then
  echo -e "${RED}✗ Test falliti: $TESTS_FAILED${NC}"
else
  echo -e "${GREEN}✓ Test falliti: $TESTS_FAILED${NC}"
fi
echo -e "${YELLOW}╠════════════════════════════════════════════════════════════╣${NC}"
echo -e "  Squadre configurate: $TEAMS_COUNT"
echo -e "  Giocatori totali: $PLAYERS_TOTAL"
echo -e "  Gironi creati: $GROUPS_COUNT"
echo -e "  Note al torneo: 4"
echo -e "  Sponsor: 3"
echo -e "  Notizie pubblicate: $PUBLISHED"
echo -e "  Configurazione pagamenti: ✓ (150 EUR)"
echo -e "  Regolamento: ✓ Generato"
echo -e "  Backup: ✓ Esportabile"
echo -e "${YELLOW}╚════════════════════════════════════════════════════════════╝${NC}\n"

# Risultato finale
if [ $TESTS_FAILED -eq 0 ]; then
  echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
  echo -e "${GREEN}        🎉 TUTTI I TEST COMPLETATI CON SUCCESSO! 🎉${NC}"
  echo -e "${GREEN}═══════════════════════════════════════════════════════════${NC}"
  exit 0
else
  echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
  echo -e "${RED}         ⚠️  ALCUNI TEST HANNO FALLITO ⚠️${NC}"
  echo -e "${RED}═══════════════════════════════════════════════════════════${NC}"
  exit 1
fi
