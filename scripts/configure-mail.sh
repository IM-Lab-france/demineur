#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then echo "Exécutez avec sudo." >&2; exit 1; fi
secure_dir="${SECURE_DIR:-/var/www/secure}"
service_env="$secure_dir/minesweeper-service.env"
smtp_host="smtp-relay.brevo.com"
smtp_port="587"
smtp_login="b220fa001@smtp-brevo.com"
[[ -r "$service_env" ]] || { echo "Configuration absente: $service_env" >&2; exit 1; }

uri_encode() {
    local input=$1 output="" char hex i
    LC_ALL=C
    for ((i = 0; i < ${#input}; i++)); do
        char=${input:i:1}
        case "$char" in
            [a-zA-Z0-9.~_-]) output+="$char" ;;
            *) printf -v hex '%%%02X' "'$char"; output+="$hex" ;;
        esac
    done
    printf '%s' "$output"
}

read -r -p "Adresse d’expédition [no-reply@fozzy.fr] : " from_address
from_address=${from_address:-no-reply@fozzy.fr}
read -r -p "Nom d’expédition [Démineur] : " from_name
from_name=${from_name:-Démineur}
read -r -p "URL publique [https://demineur.fozzy.fr] : " public_url
public_url=${public_url:-https://demineur.fozzy.fr}
printf 'Serveur SMTP Brevo : %s:%s\nIdentifiant SMTP : %s\n' "$smtp_host" "$smtp_port" "$smtp_login"
read -r -s -p "Clé SMTP Brevo : " smtp_password
echo
[[ -n "$smtp_password" ]] || { echo "La clé SMTP ne peut pas être vide." >&2; exit 2; }
mailer_dsn="smtp://$(uri_encode "$smtp_login"):$(uri_encode "$smtp_password")@${smtp_host}:${smtp_port}"

[[ "$from_address" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || { echo "Adresse invalide." >&2; exit 2; }
[[ "$public_url" =~ ^https://[^[:space:]]+$ ]] || { echo "L’URL publique doit utiliser HTTPS." >&2; exit 2; }
[[ "$mailer_dsn" =~ ^smtps?://[^[:space:]]+$ ]] || { echo "DSN SMTP invalide." >&2; exit 2; }
[[ "$from_address$from_name$public_url$mailer_dsn" != *"'"* && "$from_name" != *$'\n'* && "$from_name" != *$'\r'* ]] || { echo "Caractère non autorisé dans la configuration." >&2; exit 2; }

temp=$(mktemp); trap 'rm -f "$temp"' EXIT
awk '!/^(MAILER_DSN|MAIL_FROM_ADDRESS|MAIL_FROM_NAME|APP_PUBLIC_URL)=/' "$service_env" > "$temp"
printf "MAILER_DSN='%s'\nMAIL_FROM_ADDRESS='%s'\nMAIL_FROM_NAME='%s'\nAPP_PUBLIC_URL='%s'\n" "$mailer_dsn" "$from_address" "$from_name" "$public_url" >> "$temp"
install -o root -g minesweeper -m 0640 "$temp" "$service_env"
install -o root -g minesweeper -m 0640 "$temp" "$secure_dir/.env"
systemctl restart minesweeper-websocket.service
systemctl start minesweeper-mail.service || true
systemctl reset-failed minesweeper-mail.service minesweeper-health.service || true
systemctl start minesweeper-health.service || true
echo "Configuration SMTP enregistrée. Testez une inscription avec une adresse que vous contrôlez."
