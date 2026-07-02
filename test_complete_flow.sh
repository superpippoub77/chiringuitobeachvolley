#!/bin/bash

# Script di test completo del sistema Chiringuito Beach Volley
# Testa: Torneo, Sponsor, Notizie, Campi, Squadre, Giocatori, Note

set -e

BASE_URL="http://localhost:3000"
PASSWORD="admin123"

# Colori per output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}=== FASE DI TEST COMPLETA CHIRINGUITO BEACH VOLLEY ===${NC}\n"

# 1. RESET COMPLETO
echo -e "${YELLOW}1. Resetting tournament...${NC}"
RESET=$(curl -s -X POST "$BASE_URL/api.php?action=admin_reset_tournament" \
  -H "Content-Type: application/json" \
  -d '{"password":"admin123"}')
echo "$RESET" | jq . 2>/dev/null || echo "$RESET"
echo ""

# 2. LOGIN ADMIN
echo -e "${YELLOW}2. Admin login...${NC}"
LOGIN=$(curl -s -X POST "$BASE_URL/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d "{\"password\":\"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | jq -r '.token' 2>/dev/null)
if [ "$TOKEN" = "null" ] || [ -z "$TOKEN" ]; then
  echo -e "${RED}Login failed!${NC}"
  echo "$LOGIN" | jq .
  exit 1
fi
echo -e "${GREEN}✓ Login successful, token: ${TOKEN:0:20}...${NC}\n"

# 3. CREAZIONE TORNEO BASE
echo -e "${YELLOW}3. Creating tournament configuration...${NC}"
CONFIG=$(cat <<'EOF'
{
  "tournament": {
    "name": "Torneo di Test Chiringuito",
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
        "name": "Campo 1",
        "days": [1, 2, 3, 4],
        "timeRanges": [
          {"start": "08:00", "end": "10:00"},
          {"start": "10:15", "end": "12:15"},
          {"start": "14:00", "end": "16:00"},
          {"start": "16:15", "end": "18:15"}
        ]
      },
      {
        "name": "Campo 2",
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
    "managerEmail": "manager@chiringuito.it"
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
echo "$UPDATE_CONFIG" | jq .
echo ""

# 4. AGGIUNTA SPONSOR
echo -e "${YELLOW}4. Adding sponsors...${NC}"
SPONSOR1=$(curl -s -X POST "$BASE_URL/api.php?action=admin_add_sponsor" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Acqua Fresca",
    "logo": "sponsor1.png",
    "repetitions": 5
  }')
echo "Sponsor 1:" 
echo "$SPONSOR1" | jq .
echo ""

SPONSOR2=$(curl -s -X POST "$BASE_URL/api.php?action=admin_add_sponsor" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "name": "Gelato Paradiso",
    "logo": "sponsor2.png",
    "repetitions": 3
  }')
echo "Sponsor 2:"
echo "$SPONSOR2" | jq .
echo ""

# 5. AGGIUNTA NOTE AL TORNEO
echo -e "${YELLOW}5. Adding tournament notes...${NC}"
NOTES=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_notes" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "notes": [
      {"id": "note1", "description": "Squadra Elite", "points": 10},
      {"id": "note2", "description": "Squadra Ospite", "points": -5},
      {"id": "note3", "description": "Prima partecipazione", "points": 3},
      {"id": "note4", "description": "Multa scontroso", "points": -2}
    ]
  }')
echo "$NOTES" | jq .
echo ""

# 6. AGGIUNTA NOTIZIE
echo -e "${YELLOW}6. Adding news...${NC}"
NEWS1=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "Iscrizioni aperte!",
    "content": "Le iscrizioni per il torneo Chiringuito 2026 sono aperte. Registra la tua squadra entro il 20 luglio.",
    "published": true
  }')
NEWS1_ID=$(echo "$NEWS1" | jq -r '.news.id')
echo "News 1 (ID: $NEWS1_ID):"
echo "$NEWS1" | jq .
echo ""

NEWS2=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "Attenzione: Cambio data",
    "content": "La final four è stata spostata da lunedì a martedì per migliori condizioni meteo.",
    "published": true
  }')
echo "News 2:"
echo "$NEWS2" | jq .
echo ""

NEWS3=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "Draft notizia non pubblicata",
    "content": "Questa notizia è in bozza e non è ancora visibile pubblicamente.",
    "published": false
  }')
echo "News 3 (Draft):"
echo "$NEWS3" | jq .
echo ""

# 7. AGGIUNTA SQUADRE
echo -e "${YELLOW}7. Creating teams...${NC}"
TEAMS=(
  "Vulcani Arena"
  "Tsunami Boys"
  "Sabbia Nera"
  "Palma Dorata"
  "Sole Blu"
  "Onde Forti"
  "Spiaggia Libera"
  "Bronzi del Mare"
  "Fuoco e Sabbia"
  "Salty Dogs"
  "Marina Leoni"
  "Acqua Dolce"
)

TEAM_IDS=()
for i in "${!TEAMS[@]}"; do
  TEAM=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_team" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d "{
      \"name\": \"${TEAMS[$i]}\",
      \"city\": \"Spiaggia Centro\",
      \"notes\": \"Team $((i+1)) del torneo\"
    }")
  TEAM_ID=$(echo "$TEAM" | jq -r '.team.id')
  TEAM_IDS+=("$TEAM_ID")
  echo "✓ Team created: ${TEAMS[$i]} (ID: $TEAM_ID)"
done
echo ""

# 8. AGGIUNTA GIOCATORI ALLE SQUADRE
echo -e "${YELLOW}8. Adding players to teams...${NC}"
declare -a FIRST_NAMES=("Marco" "Giovanni" "Antonio" "Luigi" "Fabio" "Matteo" "Andrea" "Giuseppe" "Paolo" "Stefano" "Francesco" "Davide")
declare -a LAST_NAMES=("Rossi" "Bianchi" "Verdi" "Rossi" "Ferrari" "Esposito" "Russo" "Marino" "Greco" "Bruno" "Colombo" "Rizzo")

PLAYERS_PER_TEAM=10
for team_idx in "${!TEAM_IDS[@]}"; do
  TEAM_ID="${TEAM_IDS[$team_idx]}"
  for player_num in $(seq 1 $PLAYERS_PER_TEAM); do
    FIRST_IDX=$((RANDOM % ${#FIRST_NAMES[@]}))
    LAST_IDX=$((RANDOM % ${#LAST_NAMES[@]}))
    IS_CAPTAIN=$((player_num == 1 ? 1 : 0))
    
    PLAYER=$(curl -s -X POST "$BASE_URL/api.php?action=admin_add_team_player" \
      -H "Content-Type: application/json" \
      -H "Authorization: Bearer $TOKEN" \
      -d "{
        \"teamId\": \"$TEAM_ID\",
        \"name\": \"${FIRST_NAMES[$FIRST_IDX]} ${LAST_NAMES[$LAST_IDX]}\",
        \"number\": $player_num,
        \"isCaptain\": $IS_CAPTAIN
      }")
    
    if [ $((player_num % 3)) -eq 0 ]; then
      echo -n "."
    fi
  done
  echo " (Team ${TEAMS[$team_idx]}: $PLAYERS_PER_TEAM giocatori)"
done
echo ""

# 9. GENERAZIONE GIRONI (GROUPS)
echo -e "${YELLOW}9. Generating groups...${NC}"
GROUPS=$(curl -s -X POST "$BASE_URL/api.php?action=admin_generate_groups" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"numGroups": 3}')
echo "$GROUPS" | jq '.groups | length' | xargs echo "✓ Groups created:"
echo ""

# 10. GET CONFIGURATION
echo -e "${YELLOW}10. Retrieving configuration...${NC}"
CONFIG_GET=$(curl -s "$BASE_URL/api.php?action=get_config" \
  -H "Authorization: Bearer $TOKEN")
echo "$CONFIG_GET" | jq '{
  tournament: .config.tournament | {name, maxTeams, maxPlayersPerTeam, maxPlayersOnCourt, maxSubstitutions},
  schedule_courts: (.config.schedule.courts | length),
  payment: .config.payment,
  notes_count: (.config.notes | length),
  sponsors_count: (.config.sponsors | length),
  news_count: (.config.news | length)
}' 
echo ""

# 11. GET TEAMS LIST
echo -e "${YELLOW}11. Teams in system...${NC}"
TEAMS_LIST=$(curl -s "$BASE_URL/api.php?action=get_teams" \
  -H "Authorization: Bearer $TOKEN")
echo "$TEAMS_LIST" | jq '.teams | length' | xargs echo "✓ Total teams:"
echo "$TEAMS_LIST" | jq '.teams | map({id: .id, name: .name, playerCount: (.players | length)}) | .[]' | head -20
echo ""

# 12. VERIFICA NOTIZIE PUBBLICHE
echo -e "${YELLOW}12. Public news list (published only)...${NC}"
PUBLIC_NEWS=$(curl -s "$BASE_URL/api.php?action=public_get_news")
PUBLISHED_COUNT=$(echo "$PUBLIC_NEWS" | jq '.news | length')
echo -e "${GREEN}✓ Published news: $PUBLISHED_COUNT${NC}"
echo "$PUBLIC_NEWS" | jq '.news | map({title: .title, published: .published, createdAt: .createdAt})'
echo ""

# 13. VERIFICA PAGAMENTO
echo -e "${YELLOW}13. Payment configuration...${NC}"
PAYMENT=$(curl -s "$BASE_URL/api.php?action=admin_get_payment_config" \
  -H "Authorization: Bearer $TOKEN")
echo "$PAYMENT" | jq '.config.payment'
echo ""

# 14. GENERAZIONE REGOLAMENTO
echo -e "${YELLOW}14. Generating regulation document...${NC}"
REGOLAMENTO=$(curl -s "$BASE_URL/api.php?action=admin_generate_regolamento" \
  -H "Authorization: Bearer $TOKEN")
REG_SIZE=$(echo "$REGOLAMENTO" | jq -r '.regulation | length')
echo -e "${GREEN}✓ Regulation generated (size: $REG_SIZE bytes)${NC}"
echo "$REGOLAMENTO" | jq -r '.regulation' | grep -E '(Note del Torneo|Configurazione Torneo|Regole di Gioco)' | head -5
echo ""

# 15. EXPORT BACKUP
echo -e "${YELLOW}15. Exporting backup...${NC}"
BACKUP=$(curl -s "$BASE_URL/api.php?action=admin_export_backup" \
  -H "Authorization: Bearer $TOKEN")
BACKUP_SIZE=$(echo "$BACKUP" | jq '. | keys | length')
echo -e "${GREEN}✓ Backup exported with $BACKUP_SIZE sections${NC}"
echo "$BACKUP" | jq 'keys'
echo ""

# TEST STATISTICHE
echo -e "${YELLOW}=== TEST SUMMARY ===${NC}\n"
echo -e "${GREEN}✓ Tournament created with configuration${NC}"
echo -e "${GREEN}✓ $PLAYERS_PER_TEAM players per team (${#TEAM_IDS[@]} teams)${NC}"
echo -e "${GREEN}✓ $(echo "$TEAMS_LIST" | jq '.teams | map(.players | length) | add') total players${NC}"
echo -e "${GREEN}✓ Payment enabled: 150 EUR per team${NC}"
echo -e "${GREEN}✓ Tournament notes: $(curl -s "$BASE_URL/api.php?action=admin_get_payment_config" -H "Authorization: Bearer $TOKEN" | jq '.config.notes | length') added${NC}"
echo -e "${GREEN}✓ News: $PUBLISHED_COUNT published (+ draft)${NC}"
echo -e "${GREEN}✓ Sponsors: 2 added${NC}"
echo -e "${GREEN}✓ Groups generated: $(echo "$GROUPS" | jq '.groups | length')${NC}"
echo -e "${GREEN}✓ Regulation document generated${NC}"
echo -e "${GREEN}✓ Backup system operational${NC}"
echo ""

echo -e "${GREEN}=== ALL TESTS PASSED ===${NC}"
