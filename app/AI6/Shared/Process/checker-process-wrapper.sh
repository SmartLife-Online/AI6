#!/usr/bin/dash
set -eu

[ "$#" -ge 5 ] || exit 78
workspace=$1
input=$2
output=$3
heartbeat=$4
shift 4
[ "$1" = "--" ] || exit 78
shift
[ "$#" -gt 0 ] || exit 78

nspid=
while read -r key values; do
    if [ "$key" = "NSpid:" ]; then
        for value in $values; do nspid=$value; done
    fi
done < /proc/self/status
[ "$nspid" = "1" ] || exit 79
/usr/bin/mount --make-rprivate /
for protected in "$input" "$output" "$heartbeat"; do
    [ -d "$protected" ] || exit 80
    /usr/bin/mount -t tmpfs -o nosuid,nodev,noexec,mode=000,size=4096 tmpfs "$protected"
done
case "$PWD/" in
    "$workspace"/*) ;;
    *) exit 81 ;;
esac

for protected in "$input" "$output" "$heartbeat"; do
    [ -z "$(/usr/bin/find "$protected" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ] || exit 82
done

exec /usr/bin/setpriv --no-new-privs --bounding-set=-all --inh-caps=-all --ambient-caps=-all -- "$@"
