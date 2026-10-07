#!/bin/sh

# Seed only a new installation. Existing databases are the source of truth and
# must not be overwritten by the CSV shipped with a newer Git checkout.
if [ ! -f /app/database.sqlite ]; then
  php /app/seed.php
else
  echo "Existing database found; automatic seed skipped."
fi

# Execute the container's main command
exec "$@"
