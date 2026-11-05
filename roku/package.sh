#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
OUT="${1:-memories-roku.zip}"
rm -f "$OUT"
zip -r "$OUT" manifest LICENSE README.md package.sh source components images \
  -x "*.DS_Store" -x "*__MACOSX*" -x "*.zip" -x ".gitignore"
echo "Wrote $(pwd)/$OUT"
