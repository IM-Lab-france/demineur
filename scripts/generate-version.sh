#!/usr/bin/env bash
set -euo pipefail

project_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
version_file="$project_dir/.version"

if [[ -n ${APP_VERSION:-} ]]; then
  version=$APP_VERSION
elif git -C "$project_dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  version=$(git -C "$project_dir" describe --tags --always --dirty)
else
  echo "Impossible de déterminer la version (APP_VERSION absent et dépôt Git indisponible)." >&2
  exit 1
fi

printf '%s\n' "$version" > "$version_file"
chmod 0644 "$version_file"
echo "Version générée : $version"
