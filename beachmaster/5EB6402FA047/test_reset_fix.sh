#!/bin/bash

# Script di test fix rapido - Reset con Authorization

BASE_URL="http://localhost:3000"
PASSWORD="admin123"

echo "1. Login..."
LOGIN=$(curl -s -X POST "$BASE_URL/api.php?action=admin_login" \
  -H "Content-Type: application/json" \
  -d "{\"password\":\"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | jq -r '.token')
echo "Token: ${TOKEN:0:20}..."
echo ""

echo "2. Reset tournament con Authorization..."
RESET=$(curl -s -X POST "$BASE_URL/api.php?action=admin_reset_tournament" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN")
echo "Reset response:"
echo "$RESET" | jq .
echo ""

echo "3. Verifica stato dopo reset..."
STATE=$(curl -s "$BASE_URL/api.php?action=admin_state" \
  -H "Authorization: Bearer $TOKEN")
echo "$STATE" | jq '.state | {teams: (.teams | length), groups: (.groups | length)}'
