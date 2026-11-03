#!/bin/sh
# Entrypoint for PostgreSQL-containeren.
# Første oppstart (tomt volum): initdb -> åpne for nettverk -> opprett app-bruker/DB -> kjør init/*.sql
# Senere oppstarter: hopper rett til å starte postgres.
set -eu

: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD må være satt (se .env)}"
: "${DB_NAME:?DB_NAME må være satt (se .env)}"
: "${DB_USER:?DB_USER må være satt (se .env)}"
: "${DB_PASSWORD:?DB_PASSWORD må være satt (se .env)}"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
    echo "[entrypoint] Tomt datavolum - initialiserer database i $PGDATA"

    # Superbruker-passord via fil, så det ikke havner i prosesslisten
    pwfile="$(mktemp)"
    printf '%s\n' "$POSTGRES_PASSWORD" > "$pwfile"
    initdb -D "$PGDATA" -U postgres --pwfile="$pwfile" \
           --auth-local=trust --auth-host=scram-sha-256 -E UTF8
    rm -f "$pwfile"

    # Lytt på alle grensesnitt i containeren, og tillat passordinnlogging fra docker-nettet.
    # (Standard er kun localhost - da når ikke web-containeren databasen.)
    echo "listen_addresses = '*'"             >> "$PGDATA/postgresql.conf"
    echo "host  all  all  all  scram-sha-256"  >> "$PGDATA/pg_hba.conf"

    # Midlertidig server uten TCP, kun unix-socket, mens vi kjører init
    pg_ctl -D "$PGDATA" -o "-c listen_addresses=''" -w start

    # App-bruker og database. psql-variabler (:"x" / :'x') gir korrekt quoting.
    psql -v ON_ERROR_STOP=1 -U postgres -d postgres \
         -v db_name="$DB_NAME" -v db_user="$DB_USER" -v db_pass="$DB_PASSWORD" <<'SQL'
CREATE ROLE :"db_user" LOGIN PASSWORD :'db_pass';
CREATE DATABASE :"db_name" OWNER :"db_user";
SQL

    # Schema og testdata kjøres som app-brukeren, så den eier tabellene
    for f in /docker-init/*.sql; do
        [ -e "$f" ] || continue
        echo "[entrypoint] Kjører $f"
        psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$DB_NAME" -f "$f"
    done

    pg_ctl -D "$PGDATA" -m fast -w stop
    echo "[entrypoint] Initialisering ferdig"
fi

# exec: postgres blir PID 1 og mottar SIGTERM ved 'docker compose down' (ren nedstengning)
exec postgres -D "$PGDATA"
