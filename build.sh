#!/usr/bin/env bash
# Builds netarz-ai-<version>.zip with a top-level netarz-ai/ folder, ready for
# «Plugins → Add New → Upload Plugin».
set -euo pipefail
cd "$(dirname "$0")"
version=$(sed -n 's/^ \* Version: *//p' netarz-ai.php | tr -d '[:space:]')
out="$(pwd)/netarz-ai-${version}.zip"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/netarz-ai"
git ls-files -z 2>/dev/null | grep -zvE '^(\.github/|\.git|build\.sh$)' | xargs -0 -I{} cp --parents {} "$tmp/netarz-ai/" \
  || rsync -a --exclude .git --exclude .github --exclude build.sh --exclude '*.zip' ./ "$tmp/netarz-ai/"
rm -f "$out"
(cd "$tmp" && zip -qr "$out" netarz-ai)
echo "$out"
