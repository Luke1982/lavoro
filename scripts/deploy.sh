#!/usr/bin/env bash
# Deploy for the multi-tenant installation.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/.." ;;
    *)   cd "$PWD" ;;
esac

step() { printf '\n== %s ==\n' "$1"; }

step "Maintenance on"
php artisan app:maintenance --message="We zijn zo terug." || php artisan down

restore() { php artisan up || true; }
trap restore EXIT

# Say where it broke. With set -e this script stops at the first error, and that
# used to happen without a word: you saw a heading and then 'live' again, and
# had to run it with bash -x to see where.
trap 'echo "
  Stopped on line ${LINENO}: ${BASH_COMMAND}
  (the message above belongs to it; nothing has been deployed)" >&2' ERR

step "Backup of every database"
# The same script the nightly timer runs, into a folder of its own: these dumps
# exist to undo this one deploy, and the newest seven deploys are kept.
#
# --allow-unreachable: a customer whose database will not open is named and
# skipped. There is nothing to keep of a database that is gone, and that must
# not hold up the deploy. Any database that opens and does not dump stops it.
if ! scripts/tenancy/backup.sh --to=storage/backups/predeploy --keep=7 --allow-unreachable; then
    echo "  Nothing is deployed without a backup." >&2
    exit 1
fi

step "Code"
# The build makes public/service-worker.js again. As long as that file is still
# in git on this server, its own change blocks the pull that takes it out.
for GENERATED in public/service-worker.js; do
    if git ls-files --error-unmatch "$GENERATED" >/dev/null 2>&1; then
        git checkout -- "$GENERATED" 2>/dev/null || true
    fi
done

# The .gitignore files under storage/ are placeholders. They are in git for one
# reason -- to keep an otherwise empty folder in it -- and their entire content
# is '*' plus an exception for themselves. Nobody edits one on purpose, and a
# changed one is still enough to stop the pull: a deploy came to a halt over
# storage/fonts/.gitignore, which is written by nothing anybody can point at.
# So put them back, and say which, rather than refuse over them.
PLACEHOLDERS=$(git status --porcelain --untracked-files=no -- storage \
    | awk '$2 ~ /\/\.gitignore$/ { print $2 }')

if [ -n "$PLACEHOLDERS" ]; then
    while read -r PLACEHOLDER; do
        [ -n "$PLACEHOLDER" ] || continue
        git checkout -- "$PLACEHOLDER" 2>/dev/null || true
        echo "  put back ${PLACEHOLDER} (a placeholder for an empty folder, not somebody's change)"
    done <<< "$PLACEHOLDERS"
fi

# A pull with --ff-only refuses as soon as a tracked file is locally modified.
# That is right, but the bare git message does not say which file it is.
DIRTY=$(git status --porcelain --untracked-files=no)
if [ -n "$DIRTY" ]; then
    echo "$DIRTY"
    echo
    echo "  Local changes in tracked files: the pull would overwrite them."
    echo "  Look at them with 'git diff' and revert or commit them, then run this"
    echo "  deploy again. Nothing has been deployed; the site is back up."
    exit 1
fi

git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build

step "Migrations"
php artisan migrate --force          # central
php artisan tenants:migrate          # every tenant -- without this each schema stays behind

step "Caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Really restart both workers, not merely signal them.
#
# queue:restart sets a flag in the cache that the worker picks up between two
# jobs. That did not arrive here: after a deploy the doctor reported both
# workers still on the old code. The command restarts the units, stops whatever
# survived that restart, and waits until both queues report in with the code
# that is here now -- or says exactly which process does not.
php artisan tenancy:restart-workers || true

# Php under the web server holds on to the compiled code as well. Without this
# it keeps running the old classes while the templates are already new.
#
# lsphp first, because that is what this installation runs on. And with -f:
# pkill compares the process name exactly by default, and that is lsphp8.3 --
# so 'pkill lsphp' never found anything. LiteSpeed has no unit; the processes
# come back by themselves.
#
# systemctl only when it can go without a password: as an ordinary user reload
# asks for a polkit password, and then a deploy sits waiting for someone who is
# not watching.
#
# No php running is not a problem: the next request starts one on the new code.
# Only a php this account cannot restart is worth a note.
ME=$(id -un)
LSPHP=$(pgrep -f '[l]sphp' | head -n 1 || true)

# Whatever this machine calls its fpm unit -- php8.3-fpm here, php8.4-fpm on the
# next server. Hardcoding one name means the reload silently does nothing
# elsewhere.
FPM_UNIT=$(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null \
    | awk '{print $1}' | head -n 1 || true)
FPM_UNIT=${FPM_UNIT%.service}

if pgrep -u "$ME" -f '[l]sphp' >/dev/null; then
    if pkill -u "$ME" -f '[l]sphp'; then
        echo "  lsphp restarted (opcache cleared)"
    fi
elif [ -n "$LSPHP" ]; then
    echo "  Note: lsphp runs as $(ps -o user:32= -p "$LSPHP"), not as ${ME}; restart it as that account, or it keeps running the old code."
elif [ -n "$FPM_UNIT" ] && sudo -n systemctl reload "$FPM_UNIT" 2>/dev/null; then
    echo "  ${FPM_UNIT} reloaded (opcache cleared)"
elif [ -n "$FPM_UNIT" ]; then
    echo "  Note: could not reload ${FPM_UNIT} -- it keeps running the old code until you do:"
    echo "    sudo systemctl reload ${FPM_UNIT}"
    echo "  Run 'sudo scripts/tenancy/setup-sudoers.sh' once and the deploy does it itself."
else
    echo "  no php running under the web server; the next request starts on the new code"
fi

step "Check"
# Two checks, each with its own reach: the script looks at the rights of the
# database accounts (needs root), the doctor at the rest of the setup.
scripts/tenancy/verify-mysql.sh
# In an if, because the doctor returns an error code as soon as it has something
# to report. That is not a failed deploy -- that one is done by then -- and the
# ERR trap therefore said 'nothing has been deployed' while everything was there.
if php artisan tenancy:doctor; then
    echo "
  Done. The check found nothing."
else
    echo "
  Deployed. The check above found points that need attention; the new code is
  running."
fi

step "Maintenance off"
php artisan up
trap - EXIT
echo "Done."
