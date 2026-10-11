#!/bin/sh
set -eu
test "$#" -ge 7 || exit 78
# Fixed verifier reads no prompt or credentials. Only its successful exit may
# hand stdin and the original argument list to the pinned provider executable.
/usr/local/bin/php -d display_errors=0 -d log_errors=0 /usr/local/lib/ai6/native-session-verify.php "$1" "$2" "$3" "$4" "$5" "$6" || exit 78
shift 6
exec "$@"
