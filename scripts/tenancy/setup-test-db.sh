#!/usr/bin/env bash
#
# Puts the test database in place on this machine.
#
#   sudo scripts/tenancy/setup-test-db.sh
#
# Run once, after that 'composer test' works and the whole tenancy chain can be
# run here instead of on a server.
#
# This is a development machine: the test account gets broad rights, because it
# creates and drops customer databases and has to be able to grant logins on
# them. On a server that is precisely wrong; setup-mysql.sh is there for that.

set -euo pipefail

case "${BASH_SOURCE[0]}" in
    */*) SCRIPT_DIR="$(cd "${BASH_SOURCE[0]%/*}" && pwd)" ;;
    *)   SCRIPT_DIR="$PWD" ;;
esac
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

preflight_common
require_root
detect_client
ensure_admin_connection
detect_flavour

# The values come from phpunit.xml; that file stays the only place they live, so
# they cannot drift apart.
read_phpunit() {
    grep -oP "(?<=name=\"$1\" value=\")[^\"]+" "$PROJECT_ROOT/phpunit.xml" | head -1
}

TEST_USER="$(read_phpunit DB_USERNAME)"
TEST_PASSWORD="$(read_phpunit DB_PASSWORD)"
TEST_DB="$(read_phpunit DB_DATABASE)"
TEST_HOST="$(read_phpunit DB_HOST)"
TEST_PORT="$(read_phpunit DB_PORT)"
PROCEDURE="$(read_phpunit TENANCY_GRANT_PROCEDURE)"
ADMIN_SCHEMA="${PROCEDURE%%.*}"
PROCEDURE_NAME="${PROCEDURE##*.}"

SERVER_PORT="$(sql_root 'SELECT @@port;' 2>/dev/null || echo '')"

info "==> Testomgeving"
info "  account:   ${TEST_USER}@${TEST_HOST}"
info "  database:  ${TEST_DB}"
info "  procedure: ${PROCEDURE}"
info ""

if [ -n "$SERVER_PORT" ] && [ "$SERVER_PORT" != "$TEST_PORT" ]; then
    die "phpunit.xml points at port ${TEST_PORT}, but this server listens on ${SERVER_PORT}.
Change DB_PORT in phpunit.xml, or the tests run against nothing."
fi

sql_root_stdin <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET ${CHARSET} COLLATE ${COLLATION};
CREATE DATABASE IF NOT EXISTS \`${ADMIN_SCHEMA}\` CHARACTER SET ${CHARSET} COLLATE ${COLLATION};

CREATE USER IF NOT EXISTS '${TEST_USER}'@'${TEST_HOST}' IDENTIFIED BY '${TEST_PASSWORD}';
ALTER USER '${TEST_USER}'@'${TEST_HOST}' IDENTIFIED BY '${TEST_PASSWORD}';

-- Broad, and that is allowed here: this account creates customer databases,
-- drops them and grants logins on them. On a server the provisioner does that,
-- with far less.
GRANT ALL PRIVILEGES ON *.* TO '${TEST_USER}'@'${TEST_HOST}' WITH GRANT OPTION;

DROP PROCEDURE IF EXISTS \`${ADMIN_SCHEMA}\`.\`${PROCEDURE_NAME}\`;
SQL

# The same procedure as in production, so the tests walk the same path. A test
# environment that does it slightly differently tests the wrong thing.
sql_root_stdin <<SQL
DELIMITER //
CREATE PROCEDURE \`${ADMIN_SCHEMA}\`.\`${PROCEDURE_NAME}\`(
    IN tenant_db VARCHAR(64),
    IN tenant_user VARCHAR(64)
)
    SQL SECURITY DEFINER
BEGIN
    IF tenant_db NOT LIKE '$(read_phpunit TENANCY_DB_PREFIX | sed 's/_/\\\\_/g')%'
        OR tenant_db REGEXP '[^a-zA-Z0-9_]'
        OR tenant_user REGEXP '[^a-zA-Z0-9_]' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only names inside the customer namespace, and without special characters.';
    END IF;

    SET @grant_statement = CONCAT(
        'GRANT ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES, ',
        'CREATE VIEW, DELETE, DROP, EVENT, EXECUTE, INDEX, INSERT, LOCK TABLES, ',
        'REFERENCES, SELECT, SHOW VIEW, TRIGGER, UPDATE',
        ' ON \`', tenant_db, '\`.* TO \`', tenant_user, '\`@\`%\`'
    );

    PREPARE granting FROM @grant_statement;
    EXECUTE granting;
    DEALLOCATE PREPARE granting;
END//
DELIMITER ;
SQL

sql_root "GRANT EXECUTE ON PROCEDURE \`${ADMIN_SCHEMA}\`.\`${PROCEDURE_NAME}\` TO '${TEST_USER}'@'${TEST_HOST}';"
sql_root "FLUSH PRIVILEGES;"

green "Klaar."
info ""
info "Controleren:"
info "  php artisan migrate --force --database=mysql   # of gewoon:"
info "  composer test"
