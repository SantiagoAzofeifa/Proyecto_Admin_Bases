# Módulo 06 · Laboratorio de índices · Parte de Gabriel

Según la propuesta aprobada (C03), a Gabriel le corresponde: **limpieza garantizada de la copia, bloqueo, respaldo previo y bitácora.**
Es la única opción con DDL, así que todo se hace alrededor del ensayo de Santiago (`lab_ensayar()`), que no se modificó.

| Archivo | Qué contiene |
|---|---|
| `includes/lab_control.php` | `lab_solicitar()` (la función que `laboratorio.php` ya esperaba), bloqueo, limpieza y bitácora. |
| `laboratorio.php` | Llama a `lab_solicitar()`, muestra el respaldo previo, registra los rechazos y muestra la bitácora. |
| `grupo3_novacloud/tools/probar_gabriel_06.php` | Prueba por terminal de todo lo anterior. |

## Qué hace `lab_solicitar($pdo, $cfg, $caso, $candidato)`

1. **Valida** caso, candidato (lista cerrada) y límite de filas. Si falla: ERROR y registro; no se bloquea, no se respalda y no se toca la base.
2. **Bloqueo:** `flock` no bloqueante sobre `~/dba_logs/laboratorio.lock`. Si ya hay un ensayo en curso, se rechaza con ERROR (se libera solo, incluso si PHP muere).
3. **Respaldo previo:** `respaldos_generar()` del Módulo 02 (`backup.sh`). Si falla, **no se ejecuta el ensayo** y no se crea la copia.
4. **Limpia restos** de ensayos interrumpidos (solo `sbx_<tabla>` de las tablas del catálogo cerrado) y ejecuta `lab_ensayar()`.
5. **Siempre** (pase lo que pase): confirma en `information_schema` que la copia ya no existe, suelta el bloqueo y escribe la bitácora.

## Limpieza garantizada: tres capas

| Capa | Cubre |
|---|---|
| `finally` de `lab_ensayar()` (Santiago) | Errores del ensayo. |
| `finally` de `lab_solicitar()` | Cualquier excepción; si la conexión se cayó, reconecta para borrar la copia. |
| `register_shutdown_function` | Que PHP muera a media operación (tiempo agotado, error fatal, `exit`): borra la copia y deja una línea ERROR. |

Además `ignore_user_abort(true)` evita que cerrar el navegador interrumpa el ensayo, y el tiempo máximo de PHP es 300 s.
Si aun así el proceso fuera eliminado a la fuerza (kill -9), el siguiente ensayo borra el resto al empezar (paso 4).

## Bitácora

`~/dba_logs/laboratorio.log` (permisos 600, fuera de `public_html`), mismos 7 campos que `mantenimiento.log` y `cartografo.log`:

```
inicio | motor | base | tabla | acción | resultado | detalle
2026-11-08 05:57:28 -06:00 | MariaDB | tienda | diag_pedidos | Ensayo Q01/A | OK | Respaldo previo: respaldo_tienda_2026-11-08_055728.sql.gz. Filas copiadas: 20000. Índice usado por el optimizador: sí. Copia eliminada: sí. Duración: 0.170 s.
```

Resultado: `OK` o `ERROR`. Se registran también los rechazos (candidato fuera de la lista, tabla sobre el límite,
bloqueo ocupado, respaldo fallido). Los `|` y saltos de línea de lo que llegue del formulario se eliminan.

## Cómo probarlo

```bash
php grupo3_novacloud/tools/probar_gabriel_06.php     # debe terminar con «Todo correcto.»
```

Hace DDL real (crea y borra `sbx_diag_pedidos`) y genera **un** respaldo real; `diag_pedidos` no se modifica.

Prueba manual desde la web (`laboratorio.php`): elegir Q01 · candidato A → Revisar ensayo → Confirmar. Debe mostrar el respaldo
previo, el plan sin índice (ALL, ~19 653 filas) frente al plan con índice, «Copia eliminada: Sí» y una línea OK en la bitácora.
Contraste por terminal: `grupo3_novacloud/db/lab_prueba.sql`. Después de cualquier prueba:

```bash
mariadb -u USUARIO_BD -p NOMBRE_BD -e "SHOW TABLES LIKE 'sbx\_%'"    # debe quedar vacío
tail -n 5 ~/dba_logs/laboratorio.log
```

Error controlado: abrir `laboratorio.php?caso=Q01&candidato=Z` (candidato fuera de la lista) muestra ERROR y lo registra.
Para la tabla sobre el límite: poner `LAB_MAX_FILAS='1000'` en `~/dba_config/dba.conf`, intentar el ensayo y quitar la línea.
