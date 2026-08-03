#!/usr/bin/env bash

set -u

SCRIPT_PATH="${BASH_SOURCE[0]}"

case "${SCRIPT_PATH}" in
    */*) SCRIPT_DIRECTORY="$(CDPATH='' cd -- "${SCRIPT_PATH%/*}" && pwd)" ;;
    *) SCRIPT_DIRECTORY="${PWD}" ;;
esac

REPOSITORY_ROOT="$(CDPATH='' cd -- "${SCRIPT_DIRECTORY}/.." && pwd)"
BASE_URL=""
WITH_CODE_CHECKS=0
QUICK=0
SKIP_COMPOSE=0
FAILURES=0
TEMPORARY_BODY=""

usage() {
    printf '%s\n' \
        'Verwendung:' \
        '  bash scripts/verify-ai6-004-vps.sh --base-url=https://ai6.example.test [Optionen]' \
        '' \
        'Optionen:' \
        '  --with-code-checks  Führt zusätzlich die Repository-Prüfungen aus.' \
        '  --quick             Überspringt bei Repository-Prüfungen die Gesamtsuite.' \
        '  --skip-compose      Überspringt Docker-Compose-Prüfungen.' \
        '  --help              Zeigt diese Hilfe.'
}

cleanup() {
    if [ -n "${TEMPORARY_BODY}" ] && [ -f "${TEMPORARY_BODY}" ]; then
        rm -f -- "${TEMPORARY_BODY}"
    fi
}

trap cleanup EXIT INT TERM

for argument in "$@"; do
    case "${argument}" in
        --base-url=*)
            BASE_URL="${argument#--base-url=}"
            ;;
        --with-code-checks)
            WITH_CODE_CHECKS=1
            ;;
        --quick)
            QUICK=1
            ;;
        --skip-compose)
            SKIP_COMPOSE=1
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            printf '[ABBRUCH] Unbekanntes Argument: %s\n' "${argument}" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [ -z "${BASE_URL}" ]; then
    printf '[ABBRUCH] --base-url ist erforderlich.\n' >&2
    usage >&2
    exit 2
fi

case "${BASE_URL}" in
    http://*|https://*) ;;
    *)
        printf '[ABBRUCH] --base-url muss mit http:// oder https:// beginnen.\n' >&2
        exit 2
        ;;
esac

BASE_URL="${BASE_URL%/}"
cd "${REPOSITORY_ROOT}" || exit 2

check_result() {
    name="$1"
    passed="$2"
    detail="${3:-}"

    if [ "${passed}" -eq 1 ]; then
        printf '[OK] %s\n' "${name}"
    else
        printf '[FEHLER] %s\n' "${name}" >&2
        FAILURES=$((FAILURES + 1))
    fi

    if [ -n "${detail}" ]; then
        printf '       %s\n' "${detail}"
    fi
}

run_check() {
    name="$1"
    shift
    printf '\n==> %s\n' "${name}"

    if "$@"; then
        check_result "${name}" 1 'Exitcode: 0'
    else
        exit_code=$?
        check_result "${name}" 0 "Exitcode: ${exit_code}"
    fi
}

http_status_check() {
    name="$1"
    path="$2"
    expected_status="$3"
    require_health_body="${4:-0}"
    TEMPORARY_BODY="$(mktemp)" || exit 2

    printf '\n==> %s\n' "${name}"
    status_code="$(curl \
        --silent \
        --show-error \
        --max-time 20 \
        --output "${TEMPORARY_BODY}" \
        --write-out '%{http_code}' \
        "${BASE_URL}${path}")"
    curl_exit_code=$?
    passed=0

    if [ "${curl_exit_code}" -eq 0 ] && [ "${status_code}" = "${expected_status}" ]; then
        passed=1
    fi

    if [ "${passed}" -eq 1 ] && [ "${require_health_body}" -eq 1 ]; then
        if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "${TEMPORARY_BODY}"; then
            passed=0
        fi
    fi

    check_result \
        "${name}" \
        "${passed}" \
        "Erwartet: ${expected_status}; erhalten: ${status_code}; curl-Exitcode: ${curl_exit_code}"
    rm -f -- "${TEMPORARY_BODY}"
    TEMPORARY_BODY=""
}

if ! command -v curl >/dev/null 2>&1; then
    printf '[ABBRUCH] curl wurde nicht gefunden.\n' >&2
    exit 2
fi

if [ "${WITH_CODE_CHECKS}" -eq 1 ]; then
    if ! command -v php >/dev/null 2>&1; then
        printf '[ABBRUCH] PHP wurde für --with-code-checks nicht gefunden.\n' >&2
        exit 2
    fi

    if ! command -v composer >/dev/null 2>&1; then
        printf '[ABBRUCH] Composer wurde für --with-code-checks nicht gefunden.\n' >&2
        exit 2
    fi

    run_check \
        'AI6-004-Tickettests' \
        php artisan test tests/Unit/Auth tests/Feature/Auth tests/Feature/Projects

    if [ "${QUICK}" -eq 0 ]; then
        run_check 'Vollständige PHPUnit-Suite' php artisan test
    else
        printf '\n[ÜBERSPRUNGEN] Vollständige PHPUnit-Suite (--quick wurde verwendet).\n'
    fi

    run_check 'Pint-Formatprüfung' php vendor/bin/pint --test
    run_check 'PHPStan' php -d memory_limit=512M vendor/bin/phpstan analyse
    run_check 'Composer-Vertrag' composer validate --strict
    run_check 'Ticketmanifest' php scripts/generate-ticket-manifest.php --check
    run_check 'Git-Diff-Prüfung' git diff --check
fi

printf '\n==> Offene AI6-004-Vertragsstellen\n'
if [ -f 'app/AI6/Shared/Config/StrictPositiveIntegerParser.php' ]; then
    check_result 'Strikter Integerparser liegt im Shared-Konfigurationsmodul' 1
else
    check_result \
        'Strikter Integerparser liegt im Shared-Konfigurationsmodul' \
        0 \
        'app/AI6/Shared/Config/StrictPositiveIntegerParser.php fehlt.'
fi

if [ "${SKIP_COMPOSE}" -eq 0 ]; then
    if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        printf '[ABBRUCH] Docker Compose wurde nicht gefunden.\n' >&2
        exit 2
    fi

    run_check 'Docker-Compose-Dienste' docker compose ps

    session_driver="$(docker compose exec -T app printenv SESSION_DRIVER 2>/dev/null)"
    session_driver="${session_driver%$'\r'}"

    if [ "${session_driver}" = 'database' ]; then
        check_result 'App-Container verwendet Datenbank-Sessions' 1 'SESSION_DRIVER=database'
    else
        check_result \
            'App-Container verwendet Datenbank-Sessions' \
            0 \
            "SESSION_DRIVER=${session_driver:-<nicht lesbar>}"
    fi

    run_check \
        'Migrationsstatus im App-Container' \
        docker compose exec -T app php artisan migrate:status --no-ansi
fi

http_status_check 'Health-Endpunkt' '/health' '200' 1
http_status_check 'Keine öffentliche Registrierung' '/register' '404'
http_status_check 'Keine Passwort-vergessen-Route' '/forgot-password' '404'
http_status_check 'Keine Passwort-Reset-Route' '/reset-password/example-token' '404'
http_status_check 'Keine E-Mail-Verifizierungsroute' '/email/verify' '404'

if [ "${FAILURES}" -eq 0 ]; then
    printf '\nAI6-004-VPS-Prüfung erfolgreich.\n'
    exit 0
fi

printf '\nAI6-004-VPS-Prüfung mit %s Fehler(n) beendet.\n' "${FAILURES}" >&2
exit 1
