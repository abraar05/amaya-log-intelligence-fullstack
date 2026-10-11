#!/bin/bash
# End-to-end HTTP verification against PHP's built-in server.
set -u
export PATH=/usr/bin:$PATH
ROOT=/tmp/opencode/fullstack
PORT=8901
BASE="http://127.0.0.1:$PORT"
export DB_DRIVER=sqlite DB_PATH=$ROOT/storage/test.sqlite APP_DEBUG=true COOKIE_SECURE=false APP_BASE_URL=""

cd "$ROOT"

# Fresh database so the run is deterministic and repeatable.
export DB_DRIVER=sqlite DB_PATH=$ROOT/storage/test.sqlite
rm -f "$DB_PATH"
php83 bin/console.php migrate > /dev/null 2>&1
php83 bin/console.php user:create admin   admin   --password='AmayaSecure2026' > /dev/null 2>&1
php83 bin/console.php user:create viewer  viewer  --password='ViewerPass2026' > /dev/null 2>&1
php83 bin/console.php user:create analyst analyst --password='AnalystPass2026' > /dev/null 2>&1

php83 -S 127.0.0.1:$PORT -t public > /tmp/opencode/srv2.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
sleep 2

PASS=0; FAIL=0
ck() { # ck <label> <expected> <actual>
  if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  ok   $1"; else FAIL=$((FAIL+1)); echo "  FAIL $1 (expected '$2', got '$3')"; fi
}
jq_() { php83 -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; echo is_array($d)&&isset($d[$k])?json_encode($d[$k]):"MISSING";' "$1"; }

login() { # login <user> <pass> <jarfile>
  local u=$1 p=$2 jar=$3
  rm -f "$jar"
  curl -sS -c "$jar" "$BASE/login.php" -o /tmp/opencode/_l.html
  local c; c=$(grep -o 'name="csrf" value="[a-f0-9]*"' /tmp/opencode/_l.html | head -1 | sed 's/.*value="//;s/"//')
  curl -sS -b "$jar" -c "$jar" -X POST -d "username=$u&password=$p&csrf=$c" "$BASE/login.php" -o /dev/null
  curl -sS -b "$jar" -H "Accept: application/json" "$BASE/api.php?action=session" | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["csrf"]??"";'
}

echo "== authentication =="
J=/tmp/opencode/admin.jar; A=$(login admin 'AmayaSecure2026' $J)
ck "admin obtains API CSRF token" "yes" "$([ ${#A} -gt 20 ] && echo yes || echo no)"
V=/tmp/opencode/viewer.jar; AV=$(login viewer 'ViewerPass2026' $V)
ck "viewer obtains API CSRF token" "yes" "$([ ${#AV} -gt 20 ] && echo yes || echo no)"

BAD=$(curl -sS -o /dev/null -w '%{http_code}' -H "Accept: application/json" "$BASE/api.php?action=session")
ck "anonymous API call returns 401" "401" "$BAD"

echo "== CSRF enforcement =="
ST=$(curl -sS -b $J -H "Accept: application/json" -H "X-CSRF-Token: BOGUS" \
     -F "file=@/tmp/opencode/imei.csv" "$BASE/api.php?action=import.csv" -o /tmp/opencode/_c.json -w '%{http_code}')
ck "bad CSRF returns 419" "419" "$ST"
OKFLAG=$(cat /tmp/opencode/_c.json | jq_ ok)
ck "bad CSRF body says ok=false" "false" "$OKFLAG"
BODIES=$(grep -o '"ok":true' /tmp/opencode/_c.json | wc -l | tr -d ' ')
ck "no second response body emitted" "0" "$BODIES"

echo "== CSV import (admin) =="
curl -sS -b $J -H "Accept: application/json" -H "X-CSRF-Token: $A" -F "file=@/tmp/opencode/imei.csv" \
     "$BASE/api.php?action=import.csv" -o /tmp/opencode/_i.json -w '%{http_code}' > /tmp/opencode/_i.code
ck "import succeeds" "200" "$(cat /tmp/opencode/_i.code)"
INS=$(cat /tmp/opencode/_i.json | jq_ inserted); ck "7 rows inserted" "7" "$INS"
DUP=$(cat /tmp/opencode/_i.json | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["reconciliation"]["duplicate_imeis"];')
ck "1 duplicate IMEI detected" "1" "$DUP"
RATE=$(cat /tmp/opencode/_i.json | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["reconciliation"]["match_rate"];')
ck "match rate 60.0%" "60" "$RATE"

echo "== record integrity =="
LEAD=$(curl -sS -b $J -H "Accept: application/json" "$BASE/api.php?action=records&limit=50" \
       | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);$f=0;foreach($d["records"] as $r){if(str_starts_with($r["imei"],"0"))$f=1;}echo $f;')
ck "leading-zero IMEI preserved through DB" "1" "$LEAD"

echo "== sheet preload reflects private status (checked before any delete) =="
curl -sS -b $J -H "Accept: application/json" "$BASE/api.php?action=sources" -o /tmp/opencode/_s.json
PRIV=$(cat /tmp/opencode/_s.json | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);$n=0;foreach($d["sources"] as $s){if($s["last_status"]==="private")$n++;}echo $n;')
ck "2 sheets flagged private" "2" "$PRIV"
CONN=$(cat /tmp/opencode/_s.json | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);$n=0;foreach($d["sources"] as $s){if($s["last_status"]==="connected")$n++;}echo $n;')
ck "1 sheet marked connected" "1" "$CONN"

echo "== role-based access control =="
VS=$(curl -sS -b $V -H "Accept: application/json" -H "X-CSRF-Token: $AV" -F "file=@/tmp/opencode/imei.csv" \
     "$BASE/api.php?action=import.csv" -o /dev/null -w '%{http_code}')
ck "viewer cannot import (403)" "403" "$VS"
SID=$(curl -sS -b $J -H "Accept: application/json" "$BASE/api.php?action=sources" \
      | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["sources"][0]["id"];')
VD=$(curl -sS -b $V -H "Accept: application/json" -H "X-CSRF-Token: $AV" -X POST -d "id=$SID" \
     "$BASE/api.php?action=source.delete" -o /dev/null -w '%{http_code}')
ck "viewer cannot delete source (403)" "403" "$VD"
AD=$(curl -sS -b $J -H "Accept: application/json" -H "X-CSRF-Token: $A" -X POST -d "id=$SID" \
     "$BASE/api.php?action=source.delete" -o /dev/null -w '%{http_code}')
ck "admin can delete source (200)" "200" "$AD"

echo "== quality + reconciliation endpoints =="
curl -sS -b $J -H "Accept: application/json" "$BASE/api.php?action=quality" -o /tmp/opencode/_q.json
QI=$(cat /tmp/opencode/_q.json | php83 -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["quality"]["score"];')
echo "  info quality score = $QI"
SC=$(curl -sS -b $J -H "Accept: application/json" "$BASE/api.php?action=reconciliation" -o /dev/null -w '%{http_code}')
ck "reconciliation endpoint 200" "200" "$SC"

echo
echo "──────────────────────────────"
echo "passed: $PASS   failed: $FAIL"
[ $FAIL -eq 0 ] && echo "ALL HTTP TESTS PASSED" || echo "HTTP TESTS FAILED"
exit $FAIL