#!/usr/bin/env bash
# Morning (greeninvoice) — copy/paste curl commands.
# Edit KEY and SECRET below, then run each block.

KEY="PUT_YOUR_API_KEY_ID"
SECRET="PUT_YOUR_API_SECRET"

# ── 1. Get token (form-encoded, on api.morning.co). Field is accessToken. ──
TOKEN=$(curl -s -X POST "https://api.morning.co/idp/v1/oauth/token" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=client_credentials' \
  --data-urlencode "client_id=$KEY" \
  --data-urlencode "client_secret=$SECRET" | jq -r .accessToken)
echo "token: ${TOKEN:0:20}..."

# ── 2. Search documents (fromDate REQUIRED — without it the API caps at ~1868). ──
curl -s -X POST "https://api.greeninvoice.co.il/api/v1/documents/search" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"pageSize":100,"page":1,"fromDate":"2020-01-01"}' | jq .

# ── 3. Search clients ──
curl -s -X POST "https://api.greeninvoice.co.il/api/v1/clients/search" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"pageSize":100,"page":1}' | jq .
