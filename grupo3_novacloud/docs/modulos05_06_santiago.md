# Módulos 05 y 06 · Parte de Santiago

Según la propuesta aprobada (Módulo 05 = C01 Cartógrafo de relaciones,
Módulo 06 = C03 Laboratorio de índices), a Santiago le corresponde:

- **Módulo 05:** inferencia de relaciones por nombre y tipo compatible con las llaves primarias.
- **Módulo 06:** copia de la tabla, creación del índice y EXPLAIN comparativo (reutilizando `diag_lib`).

Las pantallas y el menú son de Andy; el conteo de huérfanos, el bloqueo, la
bitácora y el respaldo previo, de Gabriel; el lector del catálogo, la lista
cerrada de candidatos y los datos de prueba, de Karen.

| Archivo | Qué contiene |
|---|---|
| `includes/rel_inferencia.php` | Reglas de inferencia (función pura, sin base de datos). |
| `includes/catalogo_lib.php` | `cat_inferir()` ahora delega en `rel_inferir()`; el resto del archivo es de Karen. |
| `includes/lab_ensayo.php` | Ensayo completo del laboratorio sobre la copia `sbx_`. |
| `grupo3_novacloud/tools/probar_santiago_05_06.php` | Prueba por terminal de ambas partes. |

## Módulo 05 · Inferencia de relaciones

`rel_inferir_detalle($columnas, $pks, $declaradas)` recibe la salida de
`cat_columnas()`, `cat_llaves_primarias()` y `cat_relaciones_declaradas()` y devuelve:

- `relaciones`: propuestas aceptadas, cada una con `hijo`, `col_hijo`, `padre`, `col_padre`,
  `regla`, `confianza` (alta, media o baja), `motivo`, `tipo_hijo` y `tipo_padre`.
- `descartadas`: coincidencias de nombre rechazadas por el tipo, con el motivo.

`rel_inferir()` devuelve solo las aceptadas; es lo que usa `cat_inferir()`, así que
`cat_buscar_relacion()` valida exactamente las mismas relaciones que se muestran.

### Reglas

| Regla | Ejemplo | Confianza |
|---|---|---|
| `nombre_exacto`: la columna se llama igual que la llave primaria (de una columna) del padre | `diag_pedidos.id_cliente → clientes.id_cliente` | alta si el tipo es idéntico; media si son enteros de distinto tamaño |
| `patron`: la llave del padre es genérica (`id`) o se llama `id_X`, y la columna es `X_id`, `id_X` o `Xid`, con X sacado del nombre de la tabla (singular o plural) | `pedidos.producto_id → productos.id` | media |
| Ambigua: la misma columna encaja con varios padres | — | baja (se avisa en el motivo) |

Compatibilidad de tipo, a partir de `COLUMN_TYPE` (no solo `DATA_TYPE`):

- Misma familia (entero, decimal, flotante, texto, fecha, binario) y, en enteros, **mismo signo**.
  `int(11)` con signo frente a `int(10) unsigned` se descarta: MariaDB tampoco aceptaría esa FOREIGN KEY.
- Distinta familia (`varchar` frente a `int`) se descarta.

Exclusiones: relaciones ya declaradas, la columna que por sí sola es la llave primaria de su
tabla (sí se aceptan las que son parte de una llave compuesta), la tabla consigo misma y
las copias `sbx_` del laboratorio.

La inferencia es una **propuesta**: la confirma el conteo de huérfanos
(`cat_contar_huerfanos()`, de Karen/Gabriel).

## Módulo 06 · Ensayo del laboratorio

`lab_ensayar(PDO $pdo, array $cfg, string $caso, string $candidato): array`

1. Valida el caso y el candidato contra la lista cerrada (`lab_resolver()`); si no están, no ejecuta ninguna sentencia.
2. Verifica el límite de filas (`lab_verificar_limite()`, 50 000 por defecto).
3. `lab_crear_copia()`: `DROP TABLE IF EXISTS sbx_<tabla>` (restos de un ensayo interrumpido),
   `CREATE TABLE sbx_<tabla> LIKE <tabla>`, `INSERT … SELECT` con límite, **quita de la copia los
   índices secundarios heredados** (por ejemplo `idx_diag_estado_fecha` del Módulo 03) y `ANALYZE TABLE`.
4. Plan **antes** con `diag_explain()` y resumen con la consulta resumen del caso apuntando a la copia.
5. `CREATE INDEX idx_sbx_<x>` en la copia con las columnas del candidato y `ANALYZE TABLE`.
6. Plan **después**, interpretación (`diag_interpretar()`), diferencias (`diag_comparar()`),
   comparación de resúmenes (`diag_resumen_igual()`) y si el optimizador usó el índice.
7. En `finally`: `lab_eliminar_copia()` borra la copia y confirma en `information_schema` que ya no existe.

Campos que devuelve (para la pantalla): `ok`, `error`, `caso`, `candidato`, `indice`, `tabla`,
`inicio`, `duracion_ms`, `filas_origen`, `filas_copia`, `indices_quitados`, `sql_consulta`,
`sql_indice`, `plan_antes`, `plan_despues`, `interp_antes`, `interp_despues`, `diferencias`,
`resumen_antes`, `resumen_despues`, `resumen_igual`, `usa_indice`, `copia_eliminada`.

Uso esperado desde la página (Gabriel envuelve con bloqueo, bitácora y respaldo previo):

```php
$r = lab_ensayar(conectar_bd(), cargar_config(), $_POST['caso'] ?? '', $_POST['candidato'] ?? '');
```

### Resultados en la prueba local (MariaDB 10.11, 20 000 filas en diag_pedidos)

| Caso / candidato | Antes | Después | ¿Usa el índice? |
|---|---|---|---|
| Q01 / A (estado, fecha_pedido) | ALL, 19 653 filas, filesort | range, 393 filas, sin filesort | sí |
| Q01 / B (fecha_pedido) | ALL, 19 653 | sin cambios | no |
| Q01 / C (estado) | ALL, 19 653 | ref, 1 000 filas, sigue el filesort | sí |
| Q02 / A (id_cliente) | p ALL + c eq_ref | c ALL (250) + p ref (39); cambia el orden del JOIN | sí |
| Q02 / B (id_cliente, monto) | p ALL + c eq_ref | c ALL + p ref con «Using index» | sí |
| Q02 / C (monto) | p ALL + c eq_ref | sin cambios | no |

En todos los casos el resumen fue igual antes y después, la copia quedó eliminada y los
índices de `diag_pedidos` no cambiaron.

## Cómo probarlo en Virtualmin

Desde el clon del repositorio (o la carpeta de la app), con la base cargada con
`bd_prueba.sql` y `diag_datos.sql`:

```bash
php grupo3_novacloud/tools/probar_santiago_05_06.php
php grupo3_novacloud/tools/probar_santiago_05_06.php Q01 C
```

Debe terminar con «Todo correcto.». Para ver la advertencia de huérfanos:

```bash
mariadb -u USUARIO_BD -p NOMBRE_BD < grupo3_novacloud/db/rel_huerfano.sql
php grupo3_novacloud/tools/probar_santiago_05_06.php
mariadb -u USUARIO_BD -p NOMBRE_BD < grupo3_novacloud/db/rel_huerfano_limpiar.sql
```

El ensayo necesita los privilegios CREATE, INDEX, INSERT y DROP sobre la base propia
(los mismos que ya se usaron al cargar `diag_pedidos`).
