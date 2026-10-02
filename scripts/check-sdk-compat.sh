#!/usr/bin/env bash
set -euo pipefail

: "${SDK:?set SDK to a ucp-php-sdk checkout that has the tags 0.0.5 and 0.0.7}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAGS=(0.0.5 0.0.7)
failures=0

symbols="$(grep -rhoE 'Ucp\\+Sdk(\\+[A-Za-z0-9_]+)+' "$ROOT/src" | sed -E 's/\\+/\//g' | sort -u)"
if [ -z "$symbols" ]; then
  echo "no Ucp/Sdk symbols found in src" >&2
  exit 1
fi

for symbol in $symbols; do
  relative="${symbol#Ucp/Sdk/}"
  if [[ "$relative" == Bundle/* ]]; then
    path="packages/symfony-bundle/src/${relative#Bundle/}.php"
  else
    path="packages/core/src/$relative.php"
  fi
  for tag in "${TAGS[@]}"; do
    if ! git -C "$SDK" cat-file -e "$tag:$path" 2>/dev/null; then
      echo "missing in $tag: $symbol ($path)" >&2
      failures=$((failures + 1))
    fi
  done
done

if grep -rqE 'UcpProtocolVersion::current' "$ROOT/src"; then
  echo "src calls UcpProtocolVersion::current, which 0.0.5 does not have" >&2
  failures=$((failures + 1))
fi

for tag in "${TAGS[@]}"; do
  count="$(git -C "$SDK" show "$tag:packages/symfony-bundle/src/DependencyInjection/UcpSdkExtension.php" | grep -c "setDefinition(RuntimeConfiguration::class" || true)"
  if [ "$count" != "1" ]; then
    echo "RuntimeConfiguration service definition count in $tag is $count, expected 1" >&2
    failures=$((failures + 1))
  fi
done

if [ "$failures" -ne 0 ]; then
  echo "$failures SDK compatibility failure(s)" >&2
  exit 1
fi

echo "all symbols present in 0.0.5 and 0.0.7"
