#!/bin/sh
set -eu
latest="$(find /backups -type f -name '*.sql.gz' | sort | tail -n 1)"
test -n "$latest"
test_database="${MYSQL_DATABASE}_restore_test"
mysql --host=mysql --user=root -e "DROP DATABASE IF EXISTS \`${test_database}\`; CREATE DATABASE \`${test_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_danish_ci;"
gzip -dc "$latest" | mysql --host=mysql --user=root "$test_database"
tables="$(mysql --host=mysql --user=root --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${test_database}'")"
bookings="$(mysql --host=mysql --user=root --batch --skip-column-names -e "SELECT COUNT(*) FROM \`${test_database}\`.bookings")"
test "$tables" -gt 5
printf 'restore_test_complete file=%s tables=%s bookings=%s\n' "$latest" "$tables" "$bookings"
mysql --host=mysql --user=root -e "DROP DATABASE \`${test_database}\`;"
