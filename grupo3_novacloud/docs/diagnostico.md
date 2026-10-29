# Módulo 03 · Diagnóstico de consultas (pantalla web)

Archivos del módulo:

| Archivo | Qué hace |
|---|---|
| `diagnostico.php` | Vista: contexto, selector Q01/Q02, SQL, plan, interpretación, índices, resultado y comparación antes/después. |
| `includes/diag_lib.php` | Lógica: `EXPLAIN`, `EXPLAIN FORMAT=JSON`, índices de `information_schema.STATISTICS`, interpretación, comparación y plan de referencia. |
| `includes/diag_catalogo.php` | Catálogo cerrado (Karen): SQL de Q01/Q02, consulta resumen y la mejora ensayada de Q01. |
| `css/estilos.css` | Sección "Diagnóstico (Módulo 03)" al final. |

Datos y scripts de terminal (Karen): `db/diag_datos.sql`, `db/diag_consultas.sql`,
`db/diag_mejora_q01.sql`, `db/diag_revertir_q01.sql`.

## Qué muestra la pantalla

| Elemento del enunciado | Dónde sale |
|---|---|
| Contexto | Tarjetas: motor y versión real del servidor (`SELECT VERSION()`), base (`DATABASE()`), usuario (`CURRENT_USER()`), fecha (`NOW()`) y versión 2.0 de la consola. |
| Caso | Identificador, propósito, tablas, SQL ejecutado y parámetros (ninguno: los valores están fijos en el SQL del servidor). |
| Plan | Resultado real de `EXPLAIN` del caso, como tabla, más `EXPLAIN FORMAT=JSON` desplegable. |
| Interpretación | Por cada fila del plan: tipo de acceso (`type`), índice usado o ausente (`key` / `possible_keys`), filas estimadas (`rows`) y frases de `Extra` (filesort, index condition, etc.). |
| Índices | Índices definidos en las tablas del caso (`information_schema.STATISTICS`). |
| Resultado | Consulta resumen (filas, suma, primer y último id) y las primeras 5 filas. |
| Comparación | Plan de referencia guardado ("antes") contra el plan actual, índices de cada momento, diferencias, mejora aplicada y tabla de resultados equivalentes. |

En MariaDB, `rows` es una **estimación de filas a examinar** del optimizador. No es
tiempo ni filas devueltas, y no equivale a los costos de PostgreSQL.

## Plan de referencia ("antes")

- Botón **Guardar plan actual como referencia**: guarda en
  `~/dba_planes/<caso>_antes.json` (carpeta 700, archivo 600, fuera de `public_html`)
  el caso, la fecha con zona horaria, la cuenta (`CURRENT_USER()`), la base, la versión
  del servidor, el SQL, el plan, los índices y el resultado resumen.
- Botón **Borrar referencia**: elimina solo ese archivo, para repetir el ensayo.
- La carpeta sale de `RUTA_PLANES` en `dba.conf` (por defecto `dba_planes`).
- Si el SQL del caso cambia después de guardar, la página avisa que la comparación ya no es válida.

## Seguridad

- La página solo recibe el identificador del caso (`?caso=Q01`). Se valida contra el
  catálogo con `diag_caso()`; cualquier otro valor se rechaza con «Caso no permitido» sin
  ejecutar SQL.
- No hay editor de SQL. Todo el SQL vive en `diag_catalogo.php`.
- Los nombres de tabla para la lista de índices se cruzan con `information_schema.TABLES`
  y se pasan como valores preparados (`IN (?, ?)`).
- Guardar/borrar la referencia va por POST con token CSRF, confirmación y
  POST → Redirect → GET. Sin sesión, redirige a `login.php`.
- La web no crea ni borra índices: la mejora se aplica desde la terminal.

## Ensayo completo en Webmin (paso a paso)

1. Respaldo previo desde el Módulo 02 (lo pide el enunciado).
2. Cargar los datos (una sola vez por cuenta):
   ```bash
   mariadb -u USUARIO_BD -p NOMBRE_BD < ~/Proyecto_Admin_Bases/grupo3_novacloud/db/diag_datos.sql
   ```
3. Abrir **Módulo 03 · Diagnóstico** → Q01. Plan esperado sin índice: `type = ALL`,
   `key = NULL`, `rows ≈ 20 000`, `Extra = Using where; Using filesort`.
4. Presionar **Guardar plan actual como referencia**.
5. Aplicar la mejora en la terminal:
   ```bash
   mariadb -u USUARIO_BD -p NOMBRE_BD < ~/Proyecto_Admin_Bases/grupo3_novacloud/db/diag_mejora_q01.sql
   ```
6. Recargar Q01. Resultado obtenido en la prueba local (MariaDB 10.11, 20 000 filas):

   | | Antes | Después |
   |---|---|---|
   | type | ALL | range |
   | key | NULL | idx_diag_estado_fecha |
   | rows (estimadas) | 19 653 | 393 |
   | Extra | Using where; Using filesort | Using index condition |
   | Resultado | 393 filas, suma 18119340.00 | 393 filas, suma 18119340.00 |

7. Q02 se consulta igual (sin ensayo obligatorio): `p` con `ALL` + filesort y `c` con
   `eq_ref` por `PRIMARY`.
8. Para repetir desde cero: **Borrar referencia** y
   `diag_revertir_q01.sql`.

Para contrastar con la terminal: `diag_consultas.sql` ejecuta los mismos `EXPLAIN` y el resumen.

## Despliegue

Archivos nuevos o modificados que hay que tener en la carpeta web:
`diagnostico.php`, `includes/diag_lib.php`, `includes/diag_catalogo.php`,
`includes/layout.php`, `includes/auth.php`, `config.php`, `index.php`,
`proximamente.php`, `monitor.php`, `respaldos.php`, `css/estilos.css`.

Crear la carpeta privada de planes (la página también la crea si no existe):

```bash
mkdir -p ~/dba_planes && chmod 700 ~/dba_planes
```
