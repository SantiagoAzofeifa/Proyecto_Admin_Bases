#!/usr/bin/env bash
# =====================================================================
# Verifica que la cuenta actual quedó lista para la Consola DBA.
# Sirve para la "prueba en segunda cuenta": cada integrante lo corre en la suya.
#   bash verificar_entorno.sh
# No imprime contraseñas. Útil para capturas de evidencia.
# =====================================================================
set -uo pipefail

CONF="${DBA_CONF:-$HOME/dba_config/dba.conf}"
ok()   { echo "  [OK]    $1"; }
fail() { echo "  [ERROR] $1"; FALLOS=$((FALLOS+1)); }
FALLOS=0

echo "== Cuenta =="
echo "  Usuario Linux: $(whoami)"
echo "  HOME:          $HOME"

echo "== Carpetas y permisos =="
for d in dba_config dba_scripts respaldos dba_logs; do
    if [ -d "$HOME/$d" ]; then
        ok "~/$d existe (permisos $(stat -c %a "$HOME/$d"))"
    else
        fail "~/$d no existe (ejecute crear_estructura.sh)"
    fi
done

echo "== Configuración =="
if [ ! -f "$CONF" ]; then
    fail "no existe $CONF"
    echo; echo "Fallos: $FALLOS"; exit 1
fi
ok "dba.conf encontrado (permisos $(stat -c %a "$CONF"), esperado 600)"
[ "$(stat -c %a "$CONF")" = "600" ] || fail "dba.conf debería tener permisos 600"

# shellcheck disable=SC1090
source "$CONF"
for k in DB_NAME DB_USER DB_PASS ADMIN_USER ADMIN_HASH; do
    v="${!k:-}"
    if [ -z "$v" ] || [[ "$v" == CAMBIAR* ]] || [[ "$v" == PEGAR* ]]; then
        fail "$k sin definir en dba.conf"
    else
        ok "$k definido"
    fi
done

echo "== Conexión a la base =="
if command -v mariadb >/dev/null 2>&1; then CLI=mariadb; else CLI=mysql; fi
if MYSQL_PWD="${DB_PASS:-}" "$CLI" -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" \
        -u "${DB_USER:-}" "${DB_NAME:-}" -e "SELECT 1" >/dev/null 2>&1; then
    ok "conexión exitosa a ${DB_NAME} como ${DB_USER}"
    N=$(MYSQL_PWD="$DB_PASS" "$CLI" -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" \
        -u "$DB_USER" "$DB_NAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" 2>/dev/null)
    ok "la base tiene $N tablas"
else
    fail "no se pudo conectar (revise DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)"
fi

echo "== Herramientas =="
for t in php gzip "$CLI" mariadb-dump; do
    command -v "$t" >/dev/null 2>&1 && ok "$t disponible" || fail "$t no encontrado"
done

echo
[ "$FALLOS" -eq 0 ] && echo "Todo listo." || echo "Fallos: $FALLOS"
exit "$FALLOS"
