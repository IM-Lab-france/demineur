#!/usr/bin/env bash
set -euo pipefail

project_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

git -C "$project_dir" config core.hooksPath .githooks
chmod 0755 "$project_dir"/.githooks/post-commit "$project_dir"/.githooks/post-merge "$project_dir"/.githooks/post-checkout
"$project_dir/scripts/generate-version.sh"

echo "Hooks Git activés pour ce dépôt."
