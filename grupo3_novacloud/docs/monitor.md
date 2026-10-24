# Proyecto 01 · Monitor de Base de Datos

Archivos del módulo:

| Archivo | Qué hace |
|---|---|
| `monitor.php` | Vista: tarjetas de resumen, tablas con mayor espacio, listado y consultas usadas. |
| `includes/monitor_lib.php` | Lógica: consultas a `information_schema`, `COUNT(*)` y `ANALYZE TABLE`. |
| `css/estilos.css` | Sección "Monitor (Proyecto 01)" al final del archivo. |

No usa Bash ni `exec()`: todo es PHP + PDO contra la base del estudiante, con la
conexión de `conectar_bd()` (`config.php`), que lee `~/dba_config/dba.conf`.
Ningún dato del usuario entra a las consultas.

## Qué muestra y de dónde sale

| Dato | Fuente en MariaDB |
|---|---|
| Motor y versión | `DB_MOTOR` de `dba.conf` + `SELECT VERSION()` |
| Base de datos | `SELECT DATABASE()` |
| Usuario conectado | `SELECT CURRENT_USER()` (cuenta con la que MariaDB autenticó, `usuario@host`) |
| Fecha y hora | `SELECT NOW()` (hora del servidor de BD) |
| Tamaño de la BD | `SUM(DATA_LENGTH + INDEX_LENGTH)` de `information_schema.TABLES` |
| Cantidad y listado de tablas | `information_schema.TABLES` con `TABLE_SCHEMA = DATABASE()` y `TABLE_TYPE = 'BASE TABLE'` |
| Tamaño por tabla | `DATA_LENGTH` (datos) + `INDEX_LENGTH` (índices) |
| Registros (estimado) | `TABLE_ROWS` |
| Registros (exacto) | `SELECT COUNT(*) FROM tabla` (solo si la estimación es ≤ 100 000 filas) |
| Tablas de mayor espacio | Las 3 primeras al ordenar por `DATA_LENGTH + INDEX_LENGTH DESC` |

### Consultas

```sql
-- Conexión
SELECT VERSION() AS version, DATABASE() AS base,
       CURRENT_USER() AS usuario, NOW() AS fecha;

-- Tamaño total y cantidad de tablas
SELECT COUNT(*) AS tablas,
       SUM(DATA_LENGTH + INDEX_LENGTH) AS bytes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

-- Detalle por tabla
SELECT TABLE_NAME AS tabla, ENGINE AS motor,
       TABLE_ROWS AS filas_est,
       DATA_LENGTH AS datos, INDEX_LENGTH AS indices,
       DATA_LENGTH + INDEX_LENGTH AS total
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
ORDER BY total DESC, tabla;

-- Conteo exacto (por cada tabla)
SELECT COUNT(*) FROM `tabla`;

-- Botón "Actualizar estadísticas" (por cada tabla)
ANALYZE TABLE `tabla`;
```

Las mismas consultas aparecen en la página, en "Consultas SQL utilizadas".

### Para explicar los resultados

- **`TABLE_ROWS` es una estimación en InnoDB.** InnoDB no guarda un contador de
  filas; calcula el valor muestreando páginas del índice. Por eso puede diferir
  del `COUNT(*)`. `ANALYZE TABLE` vuelve a muestrear y actualiza la estimación.
- **El tamaño es aproximado.** InnoDB guarda los datos en páginas de 16 KB, así
  que una tabla con pocas filas ocupa al menos 16 KB, y el tamaño crece de a
  páginas, no byte por byte.
- **`DATA_LENGTH`** es el índice primario (en InnoDB los datos viven dentro de la
  llave primaria). **`INDEX_LENGTH`** son los índices secundarios: en
  `detalle_venta` pesan tanto como los datos por las llaves foráneas
  `idx_detalle_venta` e `idx_detalle_producto`.
- `categorias` tiene 0 B de índices porque solo tiene la llave primaria.
- `information_schema` solo muestra las tablas sobre las que el usuario tiene
  privilegios, así que el monitor nunca ve bases de otros estudiantes.

### Seguridad

- Página protegida con `requerir_login()`.
- La única acción (`ANALYZE TABLE`) va por POST con token CSRF y patrón
  POST → Redirect → GET. No se reciben nombres de tabla desde el formulario:
  salen de `information_schema` y se escapan como identificador.
- Si falla la conexión se muestra el mensaje de PDO, que incluye usuario y host
  pero nunca la contraseña.

## Despliegue en Webmin/Virtualmin

Requisito: la extensión `pdo_mysql` de PHP. El monitor es el primer módulo que
usa `conectar_bd()`, así que conviene verificarlo (en la Terminal de Webmin):

```bash
php -m | grep -i pdo_mysql
```

Si la terminal la muestra pero la página da "could not find driver", el PHP del
sitio (FPM/CGI de Virtualmin) usa otra configuración: revisar en Virtualmin →
Web Configuration → PHP Options la versión de PHP del dominio.

### Opción A: con git en el servidor

Si `~/public_html/dba` es un clon del repositorio:

```bash
cd ~/public_html/dba
git pull
```

### Opción B: copiar los archivos

Con el File Manager de Webmin subir a `~/public_html/dba/`:

- `monitor.php`
- `includes/monitor_lib.php`
- `css/estilos.css` (reemplaza al anterior)

Permisos (igual que el resto de páginas): archivos 644, carpetas 755.

```bash
chmod 644 ~/public_html/dba/monitor.php ~/public_html/dba/includes/monitor_lib.php ~/public_html/dba/css/estilos.css
```

No hace falta tocar `dba.conf`: el monitor usa `DB_NAME`, `DB_USER` y
`DB_PASS` que ya están configurados para respaldos.

### Prueba en segunda cuenta

El monitor no tiene nada fijo de la cuenta: en otra cuenta basta con copiar la
app, crear `~/dba_config/dba.conf` con la base y usuario de esa persona
(`tools/crear_estructura.sh`) y cargar `db/bd_prueba.sql` en su base.
