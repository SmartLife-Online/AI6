#!/bin/sh
set -eu

mode=${1:-}
shift || true

if [ "$mode" = "direct" ]; then
    [ "${1:-}" = "--" ] || exit 78
    shift
    [ "$#" -gt 0 ] || exit 78
    exec "$@"
fi

[ "$mode" = "blocked" ] || exit 78
lock_path=${1:-}
expected_identity=${2:-}
wait_seconds=${3:-}
shift 3
[ "${1:-}" = "--" ] || exit 78
shift
[ "$#" -gt 0 ] || exit 78
[ -f "$lock_path" ] && [ ! -L "$lock_path" ] || exit 76

exec 9<"$lock_path" || exit 76
/usr/bin/flock --exclusive --timeout "$wait_seconds" 9 || exit 75

held_identity=$(/usr/bin/stat -Lc '%d:%i' "/proc/$$/fd/9") || exit 76
path_identity=$(/usr/bin/stat -Lc '%d:%i' -- "$lock_path") || exit 76
[ "$held_identity" = "$expected_identity" ] || exit 76
[ "$path_identity" = "$expected_identity" ] || exit 76

printf '__AI6_PROCESS_READY_V1__:%s\n' "$$"
IFS= read -r release_token || exit 77
[ "$release_token" = "AI6_RELEASE_V1" ] || exit 77

printf '__AI6_PROCESS_RELEASED_V1__:%s\n' "$$"
exec "$@"
