#!/bin/sh
set -eu
BASE="${BASE:-http://127.0.0.1:8201/1c_exchange.php}"
AUTH="${EXCHANGE_LOGIN:-1c}:${EXCHANGE_PASSWORD:-demo1c}"
DIR="$(cd "$(dirname "$0")" && pwd)/1c-samples"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

call() {
  curl -sS -b "$JAR" -c "$JAR" -u "$AUTH" "$@"
}

auth() {
  RESPONSE=$(call "$BASE?type=$1&mode=checkauth")
  STATUS=$(echo "$RESPONSE" | sed -n 1p)
  [ "$STATUS" = "success" ] || { echo "checkauth: $RESPONSE"; exit 1; }
  printf '127.0.0.1\tFALSE\t/\tFALSE\t0\t%s\t%s\n' "$(echo "$RESPONSE" | sed -n 2p)" "$(echo "$RESPONSE" | sed -n 3p)" > "$JAR"
  call "$BASE?type=$1&mode=init" > /dev/null
}

[ -f "$DIR/import.xml" ] || { echo "Нет выгрузки, сначала make samples"; exit 1; }

echo "1С → портал: каталог"
auth catalog
for FILE in import.xml offers.xml; do
  SIZE=$(du -h "$DIR/$FILE" | cut -f1)
  call -X POST --data-binary "@$DIR/$FILE" "$BASE?type=catalog&mode=file&filename=$FILE" > /dev/null
  START=$(date +%s%N)
  RESULT=$(call "$BASE?type=catalog&mode=import&filename=$FILE" | head -1)
  echo "  $FILE ($SIZE): $RESULT за $(( ($(date +%s%N) - START) / 1000000 )) мс"
done

echo "портал → 1С: заказы"
auth sale
ORDERS=$(call "$BASE?type=sale&mode=query")
echo "  получено документов: $(echo "$ORDERS" | grep -c '<Документ>' || true)"
echo "$ORDERS" > "$DIR/orders_from_portal.xml"
echo "  подтверждение: $(call "$BASE?type=sale&mode=success" | head -1)"
