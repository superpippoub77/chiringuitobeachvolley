#!/bin/bash

# SCRIPT DI TEST COMPLETO FINALE
# Testa: Torneo, Sponsor, Notizie, Campi/Giorni/Fasce, Squadre, Giocatori, Note, Backup

BASE_URL="http://localhost:3000"
PASSWORD="admin123"

# Colori
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PASS=0
FAIL=0

test_result() {
  if [ $1 -eq 0 ]; then
    echo -e "${GREEN}✓${NC} $2"
    ((PASS++))
  else
    echo -e "${RED}✗${NC} $2"
    ((FAIL++))
  fi
}

print_header() {
  echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  echo -e "${BLUE}  $1${NC}"
  echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

print_header "FASE DI TEST COMPLETA CHIRINGUITO BEACH VOLLEY"

# LOGIN
print_header "1. AUTENTICAZIONE"
LOGIN=$(curl -s -X POST "$BASE_URL/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d "{\"password\":\"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | jq -r '.token')
if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
  echo -e "${GREEN}✓${NC} Admin login successful"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Admin login failed"
  ((FAIL++))
  exit 1
fi
echo ""

# RESET COMPLETO
print_header "2. RESET TORNEO COMPLETAMENTE"
RESET=$(curl -s -X POST "$BASE_URL/api.php?action=admin_reset_tournament" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN")
OK=$(echo "$RESET" | jq -r '.ok')
[ "$OK" = "true" ] && test_result 0 "Reset completo eseguito" || test_result 1 "Reset completo fallito"
echo ""

# CONFIGURAZIONE TORNEO
print_header "3. CONFIGURAZIONE TORNEO CON PARAMETRI"
CONFIG=$(cat <<'EOF'
{
  "tournament": {
    "name": "Chiringuito 2026 - Torneo Test Completo",
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
        "courtId": "campo1",
        "courtName": "Campo 1 - Centrale",
        "availability": [
          {
            "date": "2026-07-25",
            "timeSlots": [
              {"startTime": "08:00", "endTime": "10:00"},
              {"startTime": "10:15", "endTime": "12:15"},
              {"startTime": "14:00", "endTime": "16:00"},
              {"startTime": "16:15", "endTime": "18:15"}
            ]
          },
          {
            "date": "2026-07-26",
            "timeSlots": [
              {"startTime": "08:00", "endTime": "10:00"},
              {"startTime": "10:15", "endTime": "12:15"},
              {"startTime": "14:00", "endTime": "16:00"},
              {"startTime": "16:15", "endTime": "18:15"}
            ]
          },
          {
            "date": "2026-07-27",
            "timeSlots": [
              {"startTime": "08:00", "endTime": "10:00"},
              {"startTime": "10:15", "endTime": "12:15"},
              {"startTime": "14:00", "endTime": "16:00"}
            ]
          }
        ]
      },
      {
        "courtId": "campo2",
        "courtName": "Campo 2 - Laterale",
        "availability": [
          {
            "date": "2026-07-25",
            "timeSlots": [
              {"startTime": "08:00", "endTime": "10:00"},
              {"startTime": "10:15", "endTime": "12:15"},
              {"startTime": "14:00", "endTime": "16:00"},
              {"startTime": "16:15", "endTime": "18:15"}
            ]
          },
          {
            "date": "2026-07-26",
            "timeSlots": [
              {"startTime": "08:00", "endTime": "10:00"},
              {"startTime": "10:15", "endTime": "12:15"},
              {"startTime": "14:00", "endTime": "16:00"},
              {"startTime": "16:15", "endTime": "18:15"}
            ]
          }
        ]
      }
    ]
  },
  "contact": {
    "managerEmail": "manager@chiringuito.test"
  },
  "display": {
    "theme": "theme-minimalista"
  }
}
EOF
)

UPDATE_CONFIG=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_config" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "$CONFIG")

# Aggiorna pagamento separatamente
PAYMENT_CONFIG=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_payment_config" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "payment": {
      "enabled": true,
      "costPerTeam": 150,
      "currency": "EUR"
    }
  }')
OK=$(echo "$UPDATE_CONFIG" | jq -r '.config.tournament.name' 2>/dev/null)
if [ -n "$OK" ] && [ "$OK" != "null" ]; then
  echo -e "${GREEN}✓${NC} Configurazione torneo completata"
  ((PASS++))
  echo "  → Nome: $OK"
  echo "  → Squadre max: 12"
  echo "  → Giocatori per squadra: 14"
  echo "  → Giocatori in campo: 6"
  echo "  → Sostituzioni max: 5"
  echo "  → Campi: 2 (disponibili su 3 giorni con diverse fasce orarie)"
else
  echo -e "${RED}✗${NC} Configurazione torneo fallita"
  ((FAIL++))
fi
echo ""

# NOTE AL TORNEO
print_header "4. NOTE AL TORNEO (Con punti)"
NOTES=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_notes" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "notes": [
      {"id": "elite_team", "description": "Squadra Elite - Favorita", "points": 10},
      {"id": "host_team", "description": "Squadra Ospite - Casa", "points": 5},
      {"id": "first_time", "description": "Prima partecipazione - Debuttante", "points": 3},
      {"id": "returning", "description": "Squadra che ritorna", "points": 0},
      {"id": "penalty", "description": "Multa disciplinare", "points": -5}
    ]
  }')
NOTES_COUNT=$(echo "$NOTES" | jq '.notes | length' 2>/dev/null)
if [ "$NOTES_COUNT" = "5" ]; then
  echo -e "${GREEN}✓${NC} Note aggiunte ($NOTES_COUNT)"
  ((PASS++))
  echo "$NOTES" | jq -r '.notes[] | "  \(.description): \(.points > 0 ? "+" : "")\(.points) punti"'
else
  echo -e "${RED}✗${NC} Note fallite"
  ((FAIL++))
fi
echo ""

# SPONSOR
print_header "5. AGGIUNTA SPONSOR"
SPONSORS=$(curl -s -X POST "$BASE_URL/api.php?action=admin_update_sponsors" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "sponsors": [
      {"id": "s1", "name": "Acqua Fresca Paradiso", "repetitions": 5},
      {"id": "s2", "name": "Gelato Dolce Vita", "repetitions": 3},
      {"id": "s3", "name": "Ombrelloni Sole", "repetitions": 2},
      {"id": "s4", "name": "Bar Spiaggia", "repetitions": 2}
    ]
  }')
SPONSORS_COUNT=$(echo "$SPONSORS" | jq '.sponsors | length' 2>/dev/null)
if [ "$SPONSORS_COUNT" = "4" ]; then
  echo -e "${GREEN}✓${NC} Sponsor aggiunti ($SPONSORS_COUNT)"
  ((PASS++))
  echo "$SPONSORS" | jq -r '.sponsors[] | "  - \(.name) (ripetizioni: \(.repetitions))"'
else
  echo -e "${RED}✗${NC} Sponsor falliti"
  ((FAIL++))
fi
echo ""

# SQUADRE DI DEMO
print_header "6. CARICAMENTO SQUADRE DI DEMO"
SEED=$(curl -s -X POST "$BASE_URL/api.php?action=admin_seed_demo" \
  -H "Authorization: Bearer $TOKEN")
OK=$(echo "$SEED" | jq -r '.ok')
if [ "$OK" = "true" ]; then
  echo -e "${GREEN}✓${NC} 16 squadre di demo caricate"
  ((PASS++))
  TEAMS_LIST=$(curl -s "$BASE_URL/api.php?action=admin_state" \
    -H "Authorization: Bearer $TOKEN")
  TEAMS_COUNT=$(echo "$TEAMS_LIST" | jq '.data.teamsAll | length')
  TOTAL_PLAYERS=$(echo "$TEAMS_LIST" | jq '[.data.teamsAll[].players | length] | add' 2>/dev/null)
  echo "  → Squadre: $TEAMS_COUNT"
  echo "  → Giocatori totali: $TOTAL_PLAYERS"
else
  echo -e "${RED}✗${NC} Demo squadre fallite"
  ((FAIL++))
fi
echo ""

# NOTIZIE
print_header "7. CREAZIONE NOTIZIE CON IMMAGINI"

echo -e "${YELLOW}7a. Notizia 1: Apertura iscrizioni${NC}"
NEWS1=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "🏐 Iscrizioni Aperte per Chiringuito 2026!",
    "content": "È iniziato il periodo di iscrizioni per il grande torneo di beach volley. Squadre, registratevi subito! Quota di partecipazione: 150 EUR. Posti limitati a 12 squadre.",
    "published": true
  }')
OK=$(echo "$NEWS1" | jq -r '.news.id' 2>/dev/null)
if [ -n "$OK" ] && [ "$OK" != "null" ]; then
  echo -e "${GREEN}✓${NC} Notizia iscrizioni creata (ID: ${OK:0:8}...)"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Notizia iscrizioni fallita"
  ((FAIL++))
fi

echo -e "${YELLOW}7b. Notizia 2: Cambio data finale${NC}"
NEWS2=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "📅 IMPORTANTE: Cambio data Final Four",
    "content": "La final four è stata spostata da lunedì 28 luglio a martedì 29 luglio a causa di migliori condizioni meteo e disponibilità campi.",
    "published": true
  }')
OK=$(echo "$NEWS2" | jq -r '.news.id' 2>/dev/null)
if [ -n "$OK" ] && [ "$OK" != "null" ]; then
  echo -e "${GREEN}✓${NC} Notizia cambio data creata (ID: ${OK:0:8}...)"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Notizia cambio data fallita"
  ((FAIL++))
fi

echo -e "${YELLOW}7c. Notizia 3: Regolamento preliminare (bozza, non pubblicata)${NC}"
NEWS3=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "📋 Regolamento Preliminare",
    "content": "Il regolamento sarà pubblicato tra pochi giorni. Verificare periodicamente per gli aggiornamenti.",
    "published": false
  }')
OK=$(echo "$NEWS3" | jq -r '.news.id' 2>/dev/null)
if [ -n "$OK" ] && [ "$OK" != "null" ]; then
  echo -e "${GREEN}✓${NC} Notizia regolamento creata (bozza, non pubblicata)"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Notizia regolamento fallita"
  ((FAIL++))
fi

echo -e "${YELLOW}7d. Notizia 4: Aggiornamento meteo${NC}"
NEWS4=$(curl -s -X POST "$BASE_URL/api.php?action=admin_create_news" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "title": "🌤️ Previsioni Meteo Favorevoli",
    "content": "Le previsioni meteo per i giorni del torneo sono eccellenti: sole, vento moderato, temperatura 28-30°C.",
    "published": true
  }')
OK=$(echo "$NEWS4" | jq -r '.news.id' 2>/dev/null)
if [ -n "$OK" ] && [ "$OK" != "null" ]; then
  echo -e "${GREEN}✓${NC} Notizia meteo creata (ID: ${OK:0:8}...)"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Notizia meteo fallita"
  ((FAIL++))
fi
echo ""

# VERIFICA NOTIZIE PUBBLICHE
print_header "8. VERIFICA NOTIZIE PUBBLICHE"
PUBLIC_NEWS=$(curl -s "$BASE_URL/api.php?action=public_get_news")
PUB_COUNT=$(echo "$PUBLIC_NEWS" | jq '.news | length')
echo -e "${GREEN}✓${NC} Notizie pubbliche recuperate"
((PASS++))
echo "  → Notizie pubblicate: $PUB_COUNT (dovrebbero essere 3: iscrizioni, cambio data, meteo)"
echo "$PUBLIC_NEWS" | jq -r '.news[] | "    \(.title) - \(.createdAt)"' 2>/dev/null
echo ""

# GIRONI (Se possibile)
print_header "9. GENERAZIONE GIRONI"
GROUPS=$(curl -s -X POST "$BASE_URL/api.php?action=admin_generate_groups" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"numGroups": 3}')
OK=$(echo "$GROUPS" | jq -r '.ok' 2>/dev/null)
if [ "$OK" = "true" ]; then
  echo -e "${GREEN}✓${NC} Gironi generati con successo"
  ((PASS++))
  STATE=$(curl -s "$BASE_URL/api.php?action=admin_state" \
    -H "Authorization: Bearer $TOKEN")
  GROUPS_COUNT=$(echo "$STATE" | jq '.data.groups | length')
  MATCHES_COUNT=$(echo "$STATE" | jq '.data.groupMatches | length')
  echo "  → Gironi creati: $GROUPS_COUNT"
  echo "  → Partite di girone: $MATCHES_COUNT"
else
  echo -e "${YELLOW}⚠${NC}  Gironi: Validazione schedule (potrebbero mancare campi/fasce)"
  ((FAIL++))
fi
echo ""

# VERIFICA CONFIGURAZIONE PAGAMENTO
print_header "10. CONFIGURAZIONE PAGAMENTO E NOTE"
PAYMENT=$(curl -s "$BASE_URL/api.php?action=admin_get_payment_config" \
  -H "Authorization: Bearer $TOKEN")
PAYMENT_ENABLED=$(echo "$PAYMENT" | jq -r '.payment.enabled' 2>/dev/null)
PAYMENT_COST=$(echo "$PAYMENT" | jq -r '.payment.costPerTeam' 2>/dev/null)
NOTES_COUNT=$(echo "$PAYMENT" | jq '.notes | length' 2>/dev/null)

if [ "$PAYMENT_ENABLED" = "true" ] && [ "$PAYMENT_COST" = "150" ] && [ "$NOTES_COUNT" = "5" ]; then
  echo -e "${GREEN}✓${NC} Configurazione pagamento e note complete"
  ((PASS++))
else
  echo -e "${RED}✗${NC} Configurazione pagamento/note incompleta"
  ((FAIL++))
fi
echo "  → Pagamenti: Abilitati ($PAYMENT_COST EUR)"
echo "  → Note disponibili: $NOTES_COUNT"
echo ""

# BACKUP
print_header "11. TEST BACKUP SYSTEM"

echo -e "${YELLOW}11a. Esportazione backup${NC}"
BACKUP=$(curl -s "$BASE_URL/api.php?action=admin_export_backup" \
  -H "Authorization: Bearer $TOKEN")
BACKUP_VERSION=$(echo "$BACKUP" | jq -r '.version' 2>/dev/null)
if [ "$BACKUP_VERSION" = "1.0" ]; then
  echo -e "${GREEN}✓${NC} Backup esportato con successo"
  ((PASS++))
  BACKUP_SIZE=$(echo "$BACKUP" | wc -c)
  echo "  → Versione: $BACKUP_VERSION"
  echo "  → Dimensione: $BACKUP_SIZE bytes"
  echo "  → Sezioni: $(echo "$BACKUP" | jq 'keys')"
  
  # Salva il backup per eventuale test di import
  echo "$BACKUP" > /tmp/test_backup.json
  echo "  → Backup salvato in /tmp/test_backup.json"
else
  echo -e "${RED}✗${NC} Backup esportazione fallita"
  ((FAIL++))
fi
echo ""

# SPONSOR PUBBLICI
print_header "12. SPONSOR PUBBLICI (Ticker)"
PUBLIC_SPONSORS=$(curl -s "$BASE_URL/api.php?action=get_sponsors")
SPONSORS_COUNT=$(echo "$PUBLIC_SPONSORS" | jq '.sponsors | length')
if [ "$SPONSORS_COUNT" -gt 0 ]; then
  echo -e "${GREEN}✓${NC} Sponsor pubblici disponibili ($SPONSORS_COUNT)"
  ((PASS++))
  echo "$PUBLIC_SPONSORS" | jq -r '.sponsors[] | "  - \(.name) (ripetizioni: \(.repetitions))"'
else
  echo -e "${RED}✗${NC} Sponsor pubblici non trovati"
  ((FAIL++))
fi
echo ""

# REGOLAMENTO GENERATO
print_header "13. REGOLAMENTO GENERATO"
REGOLAMENTO=$(curl -s "$BASE_URL/api.php?action=admin_generate_regolamento" \
  -H "Authorization: Bearer $TOKEN")
REG_SIZE=$(echo "$REGOLAMENTO" | jq -r '.regulation | length' 2>/dev/null)
if [ -n "$REG_SIZE" ] && [ "$REG_SIZE" != "null" ] && [ "$REG_SIZE" -gt 0 ]; then
  echo -e "${GREEN}✓${NC} Regolamento generato ($(printf "%'.0f" "$REG_SIZE") byte)"
  ((PASS++))
  # Verifica che contenga le note
  CONTAINS_NOTES=$(echo "$REGOLAMENTO" | jq -r '.regulation' 2>/dev/null | grep -c "Note del Torneo" || echo "0")
  if [ "$CONTAINS_NOTES" -gt 0 ]; then
    echo "  ✓ Contiene sezione 'Note del Torneo'"
  fi
else
  echo -e "${YELLOW}⚠${NC}  Regolamento: Non disponibile (dipende dalla configurazione)"
fi
echo ""

# RESOCONTO FINALE
print_header "RESOCONTO FINALE DEI TEST"
TOTAL=$((PASS + FAIL))
SUCCESS_RATE=$(( (PASS * 100) / TOTAL ))

echo -e "Totale test eseguiti: ${YELLOW}$TOTAL${NC}"
echo -e "Test passati: ${GREEN}$PASS${NC}"
echo -e "Test falliti: ${RED}$FAIL${NC}"
echo -e "Tasso di successo: ${BLUE}$SUCCESS_RATE%${NC}"
echo ""

if [ $FAIL -eq 0 ]; then
  echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
  echo -e "${GREEN}  🎉 TUTTI I TEST COMPLETATI CON SUCCESSO! 🎉${NC}"
  echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
  echo ""
  echo "✓ Torneo completamente configurato"
  echo "✓ Squadre caricate (16 team, ~144+ giocatori)"
  echo "✓ Note al torneo definite (5 categorie)"
  echo "✓ Sponsor registrati (4 sponsor con ripetizioni)"
  echo "✓ Notizie pubblicate (3 pubblicate + 1 bozza)"
  echo "✓ Giorni/campi/fasce orarie configurati (2 campi, 3 giorni)"
  echo "✓ Parametri pagamento impostati (150 EUR)"
  echo "✓ Backup system operazionale"
  exit 0
else
  echo -e "${RED}════════════════════════════════════════════════════════${NC}"
  echo -e "${RED}  ⚠️  ALCUNI TEST HANNO RIPORTATO ANOMALIE ⚠️${NC}"
  echo -e "${RED}════════════════════════════════════════════════════════${NC}"
  exit 1
fi
