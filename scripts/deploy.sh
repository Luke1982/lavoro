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
STAMP=$(date +%Y-%m-%d_%H-%M-%S)
mkdir -p storage/backups

# The credentials come from the app itself: the central ones from the
# configuration, each customer's from the registry. Without credentials
# mysqldump falls back to the socket and tries as the linux user, and that one
# has no MySQL account -- 'Access denied for user lavoro@localhost', right after
# the maintenance page went up.
#
# Customers whose database will not open are skipped and named. There is nothing
# to keep of a database that is gone, and that must not hold up the deploy.
#
# Through a command of its own and not through tinker: that is a shell around a
# REPL which writes its own messages and returns an exit code that says nothing.
# And the error stays visible -- there used to be a 2>/dev/null here, on exactly
# the command whose failure aborts the whole deploy, so there was nothing to see
# but a heading and then 'live' again.
#
# stderr included, and shown on failure: artisan writes its error message to
# stdout, so it disappeared into this variable and there was nothing to see.
if ! LINES=$(php artisan tenancy:backup-targets 2>&1); then
    echo "  Could not ask what has to be backed up:" >&2
    # Without the DUMP lines: they hold passwords, and those do not belong in
    # the report or in the scrollback.
    printf '%s\n' "$LINES" | grep -v '^DUMP' | sed 's/^/    /' >&2 || true
    exit 1
fi

if ! printf '%s\n' "$LINES" | grep -q '^DUMP'; then
    echo "  Not a single database to keep -- did the central database come up?" >&2
    exit 1
fi

# With a here-string and not through a pipe: in 'grep | while' the loop runs in
# a subshell, and everything falling over inside it dies there quietly. All you
# saw was that the pipeline failed, without the message from the loop itself --
# and an exit from that loop did not even stop the script.
while IFS=$'\t' read -r MARK REST; do
    [ "$MARK" = "OVERSLAAN" ] && echo "  skipped (database unreachable): ${REST}"
done <<< "$LINES"

while IFS=$'\t' read -r MARK DB USER PASS HOST PORT; do
    [ "$MARK" = "DUMP" ] || continue

    # Through a temporary file and not on the command line: there anyone with ps
    # reads the password along.
    CONFIG=$(mktemp)
    chmod 600 "$CONFIG"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$USER" "$PASS" "$HOST" "$PORT" > "$CONFIG"

    TARGET="storage/backups/${DB}-${STAMP}.sql.gz"

    # --no-tablespaces: reading tablespaces asks for the PROCESS right, and
    # these accounts deliberately do not have it.
    if ! mysqldump --defaults-extra-file="$CONFIG" --single-transaction --no-tablespaces "$DB" | gzip > "${TARGET}.part"; then
        rm -f "$CONFIG" "${TARGET}.part"
        echo "  Backup of ${DB} failed. Nothing is deployed without a backup." >&2
        exit 1
    fi

    rm -f "$CONFIG"

    if ! mv "${TARGET}.part" "$TARGET"; then
        echo "  Could not put the backup of ${DB} in place. Is storage/backups writable for $(id -un)?" >&2
        exit 1
    fi

    echo "  ${DB} ($(du -h "$TARGET" | cut -f1))"
done <<< "$LINES"

step "Code"
# The build makes public/service-worker.js again. As long as that file is still
# in git on this server, its own change blocks the pull that takes it out.
for GENERATED in public/service-worker.js; do
    if git ls-files --error-unmatch "$GENERATED" >/dev/null 2>&1; then
        git checkout -- "$GENERATED" 2>/dev/null || true
    fi
done

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
if pkill -f lsphp 2>/dev/null; then
    echo "  lsphp restarted (opcache cleared)"
elif sudo -n systemctl reload php8.3-fpm 2>/dev/null; then
    echo "  php-fpm reloaded (opcache cleared)"
else
    echo "  Note: restart php under the web server yourself, or it keeps running the old code."
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
