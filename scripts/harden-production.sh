#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Exécutez ce script avec sudo." >&2
    exit 1
fi

project_dir=/var/www/demineur
security_conf="$project_dir/deploy/apache/minesweeper-security.conf"

install -o root -g root -m 0644 "$security_conf" /etc/apache2/conf-available/minesweeper-security.conf
a2enmod headers expires rewrite proxy proxy_http proxy_wstunnel
a2enconf minesweeper-security minesweeper-websocket

install -d -o root -g minesweeper -m 2770 /var/www/secure
install -d -o minesweeper -g minesweeper -m 0750 /var/log/minesweeper
install -d -o www-data -g minesweeper -m 2770 /var/log/minesweeper/ai
usermod -a -G minesweeper www-data

# Archiver les anciens journaux qui se trouvaient sous le site.
while IFS= read -r -d '' log_dir; do
    ia_name=$(basename "$(dirname "$log_dir")")
    target="/var/log/minesweeper/ai/$ia_name"
    install -d -o www-data -g minesweeper -m 2750 "$target"
    find "$log_dir" -maxdepth 1 -type f -exec mv -t "$target" -- {} +
    chown -R www-data:minesweeper "$target"
    chmod -R u=rwX,g=rX,o= "$target"
    rmdir "$log_dir" 2>/dev/null || true
done < <(find "$project_dir/ia/deminium/plugins" -mindepth 2 -maxdepth 2 -type d -name logs -print0)

# MySQL ne doit être publié que localement pour cette application.
mysql_config=/etc/mysql/mysql.conf.d/mysqld.cnf
if [[ -f "$mysql_config" ]]; then
    cp -a "$mysql_config" "$mysql_config.pre-minesweeper-hardening"
    sed -i -E 's/^[[:space:]]*bind-address[[:space:]]*=.*/bind-address = 127.0.0.1/' "$mysql_config"
fi

# Le code devient non modifiable par Apache. Les répertoires d'exécution
# explicitement nécessaires restent gérés séparément.
if [[ ${HARDEN_CODE_PERMISSIONS:-0} == 1 ]]; then
    plugins_dir="$project_dir/ia/deminium/plugins"
    find "$project_dir" -path "$plugins_dir" -prune -o -exec chown root:root {} +
    find "$project_dir" -path "$plugins_dir" -prune -o -type d -exec chmod 0755 {} +
    find "$project_dir" -path "$plugins_dir" -prune -o -type f -exec chmod 0644 {} +
    chown -R www-data:www-data "$plugins_dir"
    chmod -R u=rwX,g=rX,o= "$plugins_dir"
    install -d -o www-data -g www-data -m 0750 "$project_dir/admin/locks"
fi

apache2ctl configtest
systemctl reload apache2
systemctl restart mysql

echo "Durcissement appliqué. Faites maintenant tourner les identifiants MySQL exposés."
echo "Si Samba est inutile, désactivez-le séparément : systemctl disable --now smbd nmbd"
