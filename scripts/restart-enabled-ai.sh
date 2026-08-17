#!/usr/bin/env bash
set -euo pipefail

# Les instances activées correspondent aux IA que l'administrateur avait
# laissées en marche. Une IA arrêtée explicitement est désactivée et ne figure
# donc pas dans cette liste.
mapfile -t enabled_ai < <(
    systemctl list-unit-files 'minesweeper-ai@*.service' --state=enabled --no-legend \
        | awk '{print $1}'
)

for unit in "${enabled_ai[@]}"; do
    systemctl start "$unit"
done
