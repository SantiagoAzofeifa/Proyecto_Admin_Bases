# Módulo 05 · Cartógrafo de relaciones · Parte de Gabriel

Según la propuesta aprobada (C01), a Gabriel le corresponde: **conteo de huérfanos, límites, bitácora y pruebas de error.**

| Archivo | Qué contiene |
|---|---|
| `includes/cartografo_control.php` | Límites, validación de `?tabla=`, conteo de huérfanos con tope de filas y de tiempo, bitácora. |
| `includes/cartografo_lib.php` | `carto_analizar()` ahora usa lo anterior (antes llamaba directo a `cat_contar_huerfanos()`). |
| `cartografo.php` | Valida la tabla pedida, registra cada análisis (también los errores), columna **Estado** y tabla de bitácora. |
| `grupo3_novacloud/tools/probar_gabriel_05.php` | Prueba por terminal de todo lo anterior. |

## Conteo de huérfanos

Para cada relación (declarada o inferida) se ejecuta, con identificadores del catálogo escapados con `cat_ident()`:

```sql
SELECT COUNT(*) FROM `hijo` h LEFT JOIN `padre` p ON p.`col_padre` = h.`col_hijo`
WHERE h.`col_hijo` IS NOT NULL AND p.`col_padre` IS NULL;
```

Estado de cada relación: **OK** (0 huérfanos), **ADVERTENCIA** (1 o más; muestra hasta 5 valores) o
**NO DISPONIBLE** (no se contó por un límite o el motor la rechazó; nunca se muestra un 0 inventado).

## Límites

| Límite | Valor | Qué pasa al superarlo |
|---|---|---|
| Filas estimadas (hijo o padre) | 200 000 (ajustable con `CARTO_MAX_FILAS` en `dba.conf`, entre 1 000 y 1 000 000) | La relación queda «No disponible» y el motivo lo explica. |
| Relaciones contadas por análisis | 60 | El resto queda «No disponible». |
| Tiempo por conteo | 5 s (`max_statement_time` de la sesión; se restaura al terminar) | El motor cancela la consulta; queda «No disponible». |
| Tiempo total de los conteos | 20 s | Las relaciones restantes quedan «No disponible». |
| Tablas dibujadas en el mapa | 20 (Andy) | Las demás solo aparecen en la tabla. |
| Largo del nombre de tabla pedido | 64 caracteres | Se rechaza. |

## Errores controlados

`?tabla=` solo se acepta si es **exactamente** el nombre de una tabla propia (`cat_tablas()`, que excluye
las copias `sbx_`). Se rechaza: tabla inexistente, de otro esquema, con otra capitalización, con espacios,
con texto SQL, vacía, demasiado larga o que no sea un texto (`?tabla[]=x`). Al rechazar se muestra
**ERROR**, se sigue mostrando el mapa completo y se registra una línea ERROR. Un fallo de conexión también
se registra como ERROR.

## Bitácora

`~/dba_logs/cartografo.log` (fuera de `public_html`, permisos 600, la carpeta se crea con 700 si falta).
Una línea por análisis, con los mismos 7 campos que `mantenimiento.log`:

```
inicio | motor | base | tabla | acción | resultado | detalle
2026-11-08 05:22:36 -06:00 | MariaDB | tienda | (todas) | Análisis de relaciones | ADVERTENCIA | 5 relaciones analizadas, 1 filas huérfanas. Duración: 0.028 s.
```

Resultado: `OK`, `ADVERTENCIA` (hay huérfanos) o `ERROR`. Los `|` y saltos de línea de lo que escriba el usuario se
eliminan antes de escribir, para que nadie pueda falsificar líneas. Si no se puede escribir, la pantalla lo avisa.

## Cómo probarlo

Desde la carpeta de la app (la que tiene `config.php`), con la base cargada con `bd_prueba.sql` y `diag_datos.sql`:

```bash
php grupo3_novacloud/tools/probar_gabriel_05.php                  # solo lectura
php grupo3_novacloud/tools/probar_gabriel_05.php --con-huerfano   # inserta un pedido huérfano, lo prueba y lo borra
```

Debe terminar con «Todo correcto.». La prueba manual del caso de la propuesta:

```bash
mariadb -u USUARIO_BD -p NOMBRE_BD < grupo3_novacloud/db/rel_huerfano.sql          # inserta el huérfano
# abrir cartografo.php: diag_pedidos.id_cliente -> clientes marca ADVERTENCIA con 1 huérfano
mariadb -u USUARIO_BD -p NOMBRE_BD < grupo3_novacloud/db/rel_huerfano_limpiar.sql  # lo elimina
tail -n 5 ~/dba_logs/cartografo.log
```

## Respaldo previo

El módulo es de solo lectura, pero antes de la demostración conviene generar un respaldo en el **Módulo 02**
(sobre todo antes de insertar la fila huérfana de prueba).
