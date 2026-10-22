#!/bin/bash
# =============================================================
#  backup.sh - Respaldo de MariaDB (Proyecto 02, Consola DBA v1.0)
#  Uso: backup.sh [ruta/a/dba.conf]
#  No recibe nombres de base ni de archivo desde la web:
#  todo sale de dba.conf (el mismo que lee PHP).
# =============================================================
set -o pipefail

# HOME real de la cuenta
HOME_DIR="$(getent passwd "$(id -un)" | cut -d: -f6)"
HOME_DIR="${HOME_DIR:-$HOME}"

CONF="${1:-$HOME_DIR/dba_config/dba.conf}"
if [ ! -r "$CONF" ]; then
    echo "ERROR: no se puede leer la configuracion: $CONF" >&2
    exit 1
fi
source "$CONF"

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DIR_RESPALDOS="$HOME_DIR/${RUTA_RESPALDOS:-respaldos}"
LOG_FILE="$HOME_DIR/${ARCHIVO_LOG_RESPALDOS:-dba_logs/respaldos.log}"

for v in DB_NAME DB_USER DB_PASS; do
    if [ -z "${!v:-}" ]; then
        echo "ERROR: falta $v en $CONF" >&2
        exit 1
    fi
done

mkdir -p "$DIR_RESPALDOS" "$(dirname "$LOG_FILE")" || {
    echo "ERROR: no se pudo crear $DIR_RESPALDOS" >&2
    exit 1
}
chmod 700 "$DIR_RESPALDOS"

# Bitacora: fecha/hora | archivo | resultado | detalle
log() {
    printf '%s | %s | %s | %s\n' "$(date '+%F %T')" "$1" "$2" "$3" >> "$LOG_FILE"
}

# Evita dos respaldos simultaneos (doble clic)
exec 9>"$DIR_RESPALDOS/.backup.lock"
flock -n 9 || { echo "ERROR: ya hay un respaldo en curso" >&2; exit 2; }

ARCHIVO="respaldo_${DB_NAME}_$(date +%F_%H%M%S).sql.gz"
DESTINO="$DIR_RESPALDOS/$ARCHIVO"
TMP="$DESTINO.partial"     # nunca aparece en el listado hasta ser valido
ERR="$DESTINO.err"

# La clave viaja por variable de entorno, no por argumento (no sale en ps)
MYSQL_PWD="$DB_PASS" mariadb-dump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
    --single-transaction --routines --triggers \
    "$DB_NAME" 2>"$ERR" | gzip > "$TMP"
RESULTADO=$?     # con pipefail falla si falla el dump O gzip

if [ "$RESULTADO" -eq 0 ] \
   && gzip -t "$TMP" 2>/dev/null \
   && zcat "$TMP" | tail -n 1 | grep -q 'Dump completed'; then
    mv "$TMP" "$DESTINO" && chmod 600 "$DESTINO"
    rm -f "$ERR"
    log "$ARCHIVO" "OK" "tamano $(du -h "$DESTINO" | cut -f1)"
    echo "$ARCHIVO"
    exit 0
else
    DETALLE="$(head -c 200 "$ERR" 2>/dev/null | tr '\n' ' ')"
    rm -f "$TMP" "$ERR"
    log "$ARCHIVO" "ERROR" "${DETALLE:-fallo el dump}"
    echo "ERROR: fallo el respaldo. $DETALLE" >&2
    exit 1
fi
