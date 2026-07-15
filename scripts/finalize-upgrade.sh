#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then echo "Exécutez avec sudo." >&2; exit 1; fi
project_dir=/var/www/demineur

"$project_dir/scripts/install-websocket-service.sh"

if [[ -d "$project_dir/ia/deminium/plugins/Jimbo" ]]; then
  systemctl stop minesweeper-ai@Jimbo.service 2>/dev/null || true
  if grep -q 'import pickle' "$project_dir/ia/deminium/plugins/Jimbo/move_strategy.py" 2>/dev/null; then
    cp -a "$project_dir/ia/deminium/plugins/Jimbo/move_strategy.py" "/var/backups/minesweeper/Jimbo-move_strategy-pre-json.py"
    install -o root -g minesweeper -m 0644 "$project_dir/ia/deminium/plugins/.template/move_strategy.py" "$project_dir/ia/deminium/plugins/Jimbo/move_strategy.py"
  fi
  rm -f "$project_dir/ia/deminium/plugins/Jimbo/memory.pkl" "$project_dir/ia/deminium/plugins/Jimbo/pid"
  chmod 0755 "$project_dir/ia/deminium/plugins/Jimbo"
  chmod 0644 "$project_dir/ia/deminium/plugins/Jimbo/move_strategy.py"
  systemctl start minesweeper-ai@Jimbo.service
fi

systemctl start minesweeper-backup.service
systemctl start minesweeper-backup-verify.service
systemctl start minesweeper-health.service
chown root:minesweeper /var/log/minesweeper/backup-status.json /var/log/minesweeper/restore-status.json
chmod 0640 /var/log/minesweeper/backup-status.json /var/log/minesweeper/restore-status.json

rm -rf "$project_dir/.vendor-pre-security-update"
apache2ctl configtest
systemctl reload apache2

echo "Mise à niveau appliquée."
systemctl --no-pager --full status minesweeper-websocket.service minesweeper-ai@Jimbo.service minesweeper-backup.timer minesweeper-backup-verify.timer minesweeper-health.timer
