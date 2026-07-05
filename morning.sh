#!/usr/bin/env bash
# Morning (greeninvoice) — recent customers & invoices via curl.
# Usage: MORNING_API_KEY=xxx MORNING_API_SECRET=yyy ./morning.sh
set -euo pipefail

BASE="https://api.greeninvoice.co.il/api/v1"
TOKEN_URL="https://api.morning.co/idp/v1/oauth/token"
: "${MORNING_API_KEY:?set MORNING_API_KEY}"
: "${MORNING_API_SECRET:?set MORNING_API_SECRET}"

# 1. Get JWT (OAuth2 client-credentials)
AUTH=$(curl -s -X POST "$TOKEN_URL" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=client_credentials' \
  --data-urlencode "client_id=$MORNING_API_KEY" \
  --data-urlencode "client_secret=$MORNING_API_SECRET")

TOKEN=$(echo "$AUTH" | jq -r '.accessToken // empty')
if [ -z "$TOKEN" ]; then
  echo "auth failed — API response:" >&2
  echo "$AUTH" >&2
  exit 1
fi

# 2. ALL invoices whose description contains "יום פתוח".
# Usage: ./morning.sh ["term"] [fromDate YYYY-MM-DD]
# fromDate is REQUIRED by the API to return everything — without it the search
# silently caps at ~1868 docs. 2020-01-01 unlocks the full set (~6842).
# ponytail: sort/toDate/text params are ignored by this API; don't bother sending them.
MATCH="${1:-יום פתוח}"
FROM="${2:-2020-01-01}"
echo "From: $FROM" >&2
echo "number,date,client,amount,description" > yom_patuach.csv

page=1
while :; do
  res=$(curl -s -X POST "$BASE/documents/search" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "{\"pageSize\":100,\"page\":$page,\"fromDate\":\"$FROM\"}")

  echo "=== page $page API response ===" >&2
  echo "$res" | jq . >&2

  echo "$res" | jq -r --arg m "$MATCH" '
    .items[] | select(.description | contains($m))
    | [.number, .documentDate, .client.name, .amount, .description] | @csv' \
    >> yom_patuach.csv

  pages=$(echo "$res" | jq -r '.pages // 1')
  [ "$page" -ge "$pages" ] && break
  page=$((page + 1))
done

n=$(($(wc -l < yom_patuach.csv) - 1))
echo "Wrote $n invoices matching \"$MATCH\" to ./yom_patuach.csv"
