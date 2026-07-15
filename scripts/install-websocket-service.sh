#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Ce script doit être exécuté avec sudo." >&2
    exit 1
fi

project_dir=/var/www/demineur
secure_dir=/var/www/secure
legacy_ia_accounts="$project_dir/ia/deminium/ia_accounts.json"
service_name=minesweeper-websocket.service
service_source="$project_dir/deploy/systemd/$service_name"
service_target="/etc/systemd/system/$service_name"
service_env="$secure_dir/minesweeper-service.env"
source_env="$secure_dir/.env"
config_env="$service_env"
[[ -f "$config_env" ]] || config_env="$source_env"
sudoers_target=/etc/sudoers.d/minesweeper-websocket-admin
apache_proxy_source="$project_dir/deploy/apache/minesweeper-websocket.conf"
apache_proxy_target=/etc/apache2/conf-available/minesweeper-websocket.conf
apache_security_source="$project_dir/deploy/apache/minesweeper-security.conf"
apache_security_target=/etc/apache2/conf-available/minesweeper-security.conf
backup_service_source="$project_dir/deploy/systemd/minesweeper-backup.service"
backup_timer_source="$project_dir/deploy/systemd/minesweeper-backup.timer"
backup_verify_service_source="$project_dir/deploy/systemd/minesweeper-backup-verify.service"
backup_verify_timer_source="$project_dir/deploy/systemd/minesweeper-backup-verify.timer"
ai_service_source="$project_dir/deploy/systemd/minesweeper-ai@.service"
health_service_source="$project_dir/deploy/systemd/minesweeper-health.service"
health_timer_source="$project_dir/deploy/systemd/minesweeper-health.timer"
mail_service_source="$project_dir/deploy/systemd/minesweeper-mail.service"
mail_timer_source="$project_dir/deploy/systemd/minesweeper-mail.timer"

[[ -f "$service_source" ]] || { echo "Unité absente: $service_source" >&2; exit 1; }
[[ -f "$config_env" ]] || { echo "Configuration absente dans $secure_dir" >&2; exit 1; }
[[ -f "$apache_proxy_source" ]] || { echo "Configuration Apache absente: $apache_proxy_source" >&2; exit 1; }
[[ -f "$apache_security_source" ]] || { echo "Configuration de sécurité Apache absente: $apache_security_source" >&2; exit 1; }

if ! id minesweeper >/dev/null 2>&1; then
    useradd --system --home-dir "$project_dir" --shell /usr/sbin/nologin minesweeper
fi

# Apache administre les comptes IA et lance leurs processus. Un groupe commun
# lui donne l'accès nécessaire sans rendre les secrets lisibles aux autres.
usermod -a -G minesweeper www-data
install -d -o root -g minesweeper -m 2770 "$secure_dir"
if [[ "$config_env" != "$service_env" ]]; then
    install -o root -g minesweeper -m 0640 "$config_env" "$service_env"
else
    chown root:minesweeper "$service_env"
    chmod 0640 "$service_env"
fi
# Apache/PHP et systemd doivent toujours utiliser le même secret.
install -o root -g minesweeper -m 0640 "$service_env" "$secure_dir/.env"
set -a
# shellcheck disable=SC1090
source "$service_env"
set +a
if [[ ! "${APP_TOTP_KEY:-}" =~ ^[A-Za-z0-9+/]{43}=$ ]]; then
    APP_TOTP_KEY=$(openssl rand -base64 32 | tr -d '\n')
    config_tmp=$(mktemp)
    awk '!/^APP_TOTP_KEY=/' "$service_env" > "$config_tmp"
    printf 'APP_TOTP_KEY=%s\n' "$APP_TOTP_KEY" >> "$config_tmp"
    install -o root -g minesweeper -m 0640 "$config_tmp" "$service_env"
    install -o root -g minesweeper -m 0640 "$config_tmp" "$secure_dir/.env"
    rm -f "$config_tmp"
    export APP_TOTP_KEY
fi
if [[ -f "$project_dir/install/migrations/20260715_active_games.sql" ]]; then
    MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$project_dir/install/migrations/20260715_active_games.sql"
fi
if [[ -f "$project_dir/install/migrations/20260715_auth_sessions.sql" ]]; then
    MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$project_dir/install/migrations/20260715_auth_sessions.sql"
fi
if [[ -f "$project_dir/install/migrations/20260715_admin_totp.sql" ]]; then
    MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$project_dir/install/migrations/20260715_admin_totp.sql"
    # Conserver une éventuelle activation historique basée sur le secret global.
    # Le secret Base32 généré par l’ancien outil ne contient que A-Z et 2-7.
    if [[ "${ADMIN_TOTP_SECRET:-}" =~ ^[A-Z2-7]{16,64}$ ]]; then
        printf "UPDATE users SET totp_secret='%s', totp_enabled_at=COALESCE(totp_enabled_at,CURRENT_TIMESTAMP) WHERE is_admin=1 AND totp_secret IS NULL;\n" "$ADMIN_TOTP_SECRET" \
          | MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME"
    fi
fi
if [[ -f "$project_dir/install/migrations/20260715_admin_login_throttle.sql" ]]; then
    MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$project_dir/install/migrations/20260715_admin_login_throttle.sql"
fi
if [[ -f "$project_dir/install/migrations/20260715_email_accounts.sql" ]]; then
    MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$project_dir/install/migrations/20260715_email_accounts.sql"
fi
if [[ ! -f "$secure_dir/ia_accounts.json" && -f "$legacy_ia_accounts" ]]; then
    install -o root -g minesweeper -m 0640 "$legacy_ia_accounts" "$secure_dir/ia_accounts.json"
    rm -f "$legacy_ia_accounts"
fi
if [[ -f "$secure_dir/ia_accounts.json" ]]; then
    chown root:minesweeper "$secure_dir/ia_accounts.json"
    chmod 0640 "$secure_dir/ia_accounts.json"
fi
install -d -o minesweeper -g minesweeper -m 0750 /var/log/minesweeper
install -d -o www-data -g minesweeper -m 2770 /var/log/minesweeper/ai
install -o root -g root -m 0644 "$service_source" "$service_target"
install -o root -g root -m 0644 "$apache_proxy_source" "$apache_proxy_target"
install -o root -g root -m 0644 "$apache_security_source" "$apache_security_target"
install -d -o root -g root -m 0700 /var/backups/minesweeper
install -o root -g root -m 0644 "$backup_service_source" /etc/systemd/system/minesweeper-backup.service
install -o root -g root -m 0644 "$backup_timer_source" /etc/systemd/system/minesweeper-backup.timer
install -o root -g root -m 0644 "$backup_verify_service_source" /etc/systemd/system/minesweeper-backup-verify.service
install -o root -g root -m 0644 "$backup_verify_timer_source" /etc/systemd/system/minesweeper-backup-verify.timer
install -o root -g root -m 0644 "$health_service_source" /etc/systemd/system/minesweeper-health.service
install -o root -g root -m 0644 "$health_timer_source" /etc/systemd/system/minesweeper-health.timer
install -o root -g root -m 0644 "$mail_service_source" /etc/systemd/system/minesweeper-mail.service
install -o root -g root -m 0644 "$mail_timer_source" /etc/systemd/system/minesweeper-mail.timer
install -o root -g root -m 0644 "$ai_service_source" /etc/systemd/system/minesweeper-ai@.service

sudoers_tmp=$(mktemp)
trap 'rm -f "$sudoers_tmp"' EXIT
cat >"$sudoers_tmp" <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start minesweeper-websocket.service, /usr/bin/systemctl stop minesweeper-websocket.service, /usr/bin/systemctl start minesweeper-ai@*.service, /usr/bin/systemctl stop minesweeper-ai@*.service
EOF
visudo -cf "$sudoers_tmp"
install -o root -g root -m 0440 "$sudoers_tmp" "$sudoers_target"

systemctl daemon-reload
systemctl enable "$service_name"
systemctl enable --now minesweeper-backup.timer
systemctl enable --now minesweeper-backup-verify.timer
systemctl enable --now minesweeper-health.timer
systemctl enable --now minesweeper-mail.timer
systemctl restart "$service_name"
a2enmod proxy proxy_http proxy_wstunnel headers expires rewrite
a2enconf minesweeper-websocket minesweeper-security
apache2ctl configtest
systemctl reload apache2

if ! systemctl is-active --quiet "$service_name"; then
    systemctl status "$service_name" --no-pager --full >&2 || true
    journalctl -u "$service_name" -n 100 --no-pager >&2 || true
    exit 1
fi

echo "Le backend WebSocket est actif."
systemctl status "$service_name" --no-pager --full
