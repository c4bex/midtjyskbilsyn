#!/bin/sh
set -eu
mkdir -p /backups
while true; do
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  target="/backups/${MYSQL_DATABASE}-${stamp}.sql.gz"
  if mysqldump --host=mysql --user="$MYSQL_USER" --single-transaction --routines --triggers "$MYSQL_DATABASE" | gzip > "$target"; then
    printf '%s backup_complete %s\n' "$(date -u +%FT%TZ)" "$target"
    find /backups -type f -name '*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS}" -delete
  else
    rm -f "$target"
    printf '%s backup_failed\n' "$(date -u +%FT%TZ)" >&2
  fi
  sleep 86400
done
