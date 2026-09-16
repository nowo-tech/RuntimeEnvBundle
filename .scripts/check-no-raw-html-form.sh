#!/usr/bin/env bash
# REQ-TWIG-005 — fail when kit Twig uses raw <form> / <input> outside form themes.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -d src/Resources/views ]]; then
  echo "check-no-raw-html-form: no src/Resources/views — OK"
  exit 0
fi

matches="$(grep -RInE '<form[[:space:]>]|<input[[:space:]>]' src/Resources/views --include='*.twig' || true)"
if [[ -n "$matches" ]]; then
  echo "ERROR: raw <form> / <input> in kit Twig (REQ-TWIG-005):" >&2
  echo "$matches" >&2
  exit 1
fi

echo "check-no-raw-html-form: OK"
