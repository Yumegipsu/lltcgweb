#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
FAIL=0
while IFS= read -r -d '' file; do
  if ! php -l "$file" >/dev/null; then
    php -l "$file"
    FAIL=1
  fi
done < <(find . -name '*.php' -not -path './vendor/*' -print0)
if [ "$FAIL" -ne 0 ]; then
  exit 1
fi
echo "PHP syntax OK"
