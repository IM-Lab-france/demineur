#!/usr/bin/env bash
set -euo pipefail

base_url=${1:-http://127.0.0.1}
host_header=${2:-fozzy.fr}
failed=0

check_blocked() {
    local path=$1
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: $host_header" "$base_url$path")
    if [[ $code == 403 || $code == 404 ]]; then
        echo "OK $path ($code)"
    else
        echo "ÉCHEC $path est accessible ($code)" >&2
        failed=1
    fi
}

check_blocked '/.env'
check_blocked '/.git/HEAD'
check_blocked '/install/install.php'
check_blocked '/ia/deminium/plugins/Jimbo/logs/run.log'

exit "$failed"
