#!/bin/bash

# Test: Group-Based Priority Scheduling
# Script bash che simula la configurazione dell'utente e verifica il fix

BASE_URL="http://localhost:3000"
PASSWORD="admin"

echo "=== Test: Group-Based Priority Scheduling ==="
echo ""

# 1. Login
echo "1️⃣  Login..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "admin_login",
    "password": "'$PASSWORD'"
  }')

TOKEN=$(echo $LOGIN_RESPONSE | jq -r '.token' 2>/dev/null)
if [ -z "$TOKEN" ] || [ "$TOKEN" = "null" ]; then
  echo "  ❌ Login failed!"
  echo "  Response: $LOGIN_RESPONSE"
  exit 1
fi

echo "  ✅ Login successful"
echo ""

# 2. Reset tournament
echo "2️⃣  Resetting tournament..."
curl -s -X POST "$BASE_URL/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action": "admin_reset_tournament"}' > /dev/null

echo "  ✅ Tournament reset"
echo ""

# 3. Configure tournament
echo "3️⃣  Configuring tournament..."
curl -s -X POST "$BASE_URL/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "admin_update_config",
    "config": {
      "tournament": {
        "name": "Test Group Scheduling",
        "maxTeams": 16,
        "maxPlayersPerTeam": 3,
        "maxPlayersOnCourt": 2,
        "numSets": 1,
        "timePerSetMinutes": 25,
        "setupTimeMinutes": 5
      }
    }
  }' > /dev/null

echo "  ✅ Tournament configured"
echo ""

# 4. Add 16 teams
echo "4️⃣  Adding 16 test teams..."
for i in {1..16}; do
  curl -s -X POST "$BASE_URL/api.php" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{
      "action": "admin_add_test_team",
      "count": 1
    }' > /dev/null
done

echo "  ✅ Added 16 teams"
echo ""

# 5. Configure schedule - 1 campo, 1 fascia oraria
echo "5️⃣  Configuring schedule (1 court, 1 time slot)..."
curl -s -X POST "$BASE_URL/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "admin_update_config",
    "config": {
      "schedule": {
        "courts": [
          {
            "courtId": "court-1",
            "courtName": "Campo 1",
            "availability": [
              {
                "date": "2026-07-13",
                "timeSlots": [
                  {
                    "startTime": "19:30",
                    "endTime": "23:30"
                  }
                ]
              }
            ]
          }
        ]
      }
    }
  }' > /dev/null

echo "  ✅ Schedule configured"
echo ""

# 6. Configure phases - 3 groups
echo "6️⃣  Configuring 3 groups..."
curl -s -X POST "$BASE_URL/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "admin_update_config",
    "config": {
      "phases": [
        {
          "phaseNumber": 1,
          "name": "Gironi",
          "type": "groups",
          "branch": "root",
          "qualifiedGoTo": "Playoff",
          "eliminatedGoTo": "Ripescaggio",
          "numGroups": 3,
          "teamsAdvance": "2,2,1",
          "hasRepescage": false,
          "notes": ""
        }
      ]
    }
  }' > /dev/null

echo "  ✅ Phases configured"
echo ""

# 7. Generate groups
echo "7️⃣  Generating groups..."
curl -s -X POST "$BASE_URL/api.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "admin_generate_phase",
    "phaseIdx": 0,
    "type": "groups"
  }' > /dev/null

echo "  ✅ Groups generated"
echo ""

# 8. Get matches and analyze
echo "8️⃣  Analyzing scheduling results..."
echo ""

RESPONSE=$(curl -s "$BASE_URL/api.php?action=publicState")

# Estrae i match
MATCHES=$(echo $RESPONSE | jq '.matches // []' 2>/dev/null)
MATCH_COUNT=$(echo $MATCHES | jq 'length' 2>/dev/null)

echo "  Total matches: $MATCH_COUNT"
echo ""

# Analizza per girone
for GROUP in "A" "B" "C"; do
  echo "  ▶️  GROUP $GROUP:"
  
  GROUP_MATCHES=$(echo $MATCHES | jq ".[] | select(.groupName == \"$GROUP\")" 2>/dev/null)
  COUNT=$(echo "$GROUP_MATCHES" | jq -s 'length')
  
  if [ $COUNT -eq 0 ]; then
    echo "    ⚠️  No matches found"
    continue
  fi
  
  # Estrai date/orari unici
  DATE_TIMES=$(echo "$GROUP_MATCHES" | jq -r '"\(.date) \(.startTime)"' | sort -u)
  UNIQUE_BLOCKS=$(echo "$DATE_TIMES" | wc -l)
  
  echo "    Matches: $COUNT"
  echo "    Unique date/time blocks: $UNIQUE_BLOCKS"
  
  if [ $UNIQUE_BLOCKS -eq 1 ]; then
    echo "    ✅ ALL MATCHES IN SAME TIME BLOCK"
  else
    echo "    ❌ MATCHES SPREAD ACROSS $UNIQUE_BLOCKS BLOCKS"
  fi
  
  echo "$DATE_TIMES" | while read DT; do
    BLOCK_COUNT=$(echo "$GROUP_MATCHES" | jq "select(.date == \"${DT%% *}\" and .startTime == \"${DT##* }\") " | jq -s 'length')
    echo "      - $DT: $BLOCK_COUNT matches"
  done
  
  echo ""
done

echo "=== Test Complete ==="
