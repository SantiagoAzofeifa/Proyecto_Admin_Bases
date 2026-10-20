#!/usr/bin/env bash
# =====================================================================
# Crea la estructura de carpetas de la Consola DBA y aplica permisos.
# GENÉRICO: usa el HOME de quien lo ejecute; sirve en la cuenta de cualquier
# integrante. Se ejecuta SIN root:
#
#   bash crear_estructura.sh            # carpeta web: ~/public_html/dba
#   bash crear_estructura.sh consola    # carpeta web: ~/public_html/consola
#
# Es idempotente: se puede correr varias veces.
# =====================================================================
set -euo pipefail

APP_DIR="${1:-dba}"
cd "$(dirname "$0")"   # para encontrar dba.conf.ejemplo junto al script

echo "Cuenta: $(whoami)   HOME: $HOME   App web: ~/public_html/$APP_DIR"

# Carpeta web (visible desde el navegador)
mkdir -p "$HOME/public_html/$APP_DIR"
chmod 755 "$HOME/public_html/$APP_DIR"

# Carpetas privadas (fuera de public_html)
mkdir -p "$HOME/dba_config" "$HOME/dba_scripts" "$HOME/respaldos" "$HOME/dba_logs"
chmod 700 "$HOME/dba_config" "$HOME/dba_scripts" "$HOME/respaldos" "$HOME/dba_logs"

# Configuración: se crea desde el ejemplo solo si todavía no existe
if [ ! -f "$HOME/dba_config/dba.conf" ]; then
    if [ -f "dba.conf.ejemplo" ]; then
        cp dba.conf.ejemplo "$HOME/dba_config/dba.conf"
        echo "Creado ~/dba_config/dba.conf -> edítelo con los datos de ESTA cuenta."
    else
        echo "AVISO: no se encontró dba.conf.ejemplo junto a este script."
    fi
fi
[ -f "$HOME/dba_config/dba.conf" ] && chmod 600 "$HOME/dba_config/dba.conf"

# Copia el cargador de configuración a la carpeta web si está disponible
if [ -f "config.php" ]; then
    cp config.php "$HOME/public_html/$APP_DIR/config.php"
    chmod 644 "$HOME/public_html/$APP_DIR/config.php"
fi

# Bitácora de respaldos (la usará backup.sh)
touch "$HOME/dba_logs/respaldos.log"
chmod 600 "$HOME/dba_logs/respaldos.log"

echo
echo "Estructura final:"
ls -ld "$HOME/public_html/$APP_DIR" "$HOME/dba_config" "$HOME/dba_scripts" \
       "$HOME/respaldos" "$HOME/dba_logs"
ls -l "$HOME/dba_config"
