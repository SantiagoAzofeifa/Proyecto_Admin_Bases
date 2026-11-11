# Rediseño visual de la Consola DBA (versión 2.0)

El rediseño cambia solo la presentación: la lógica, las consultas, las validaciones,
la bitácora y la seguridad de los seis módulos no se modificaron.

## Criterio

La consola es una herramienta de trabajo para administradores de bases de datos:

- datos densos pero legibles;
- identificadores (bases, tablas, columnas) y SQL en fuente de código;
- valores estimados distinguidos de los exactos;
- los mismos colores de estado en todos los módulos.

## Sistema

| Elemento | Decisión |
|---|---|
| Color | Marino `#13263E` y cian `#0B9CC4` del logo; fondo pizarra fría `#EEF2F5`; verde `#1F8A5B` = OK, rojo `#C23B30` = ERROR o huérfanos, ámbar `#A86A12` = estimados y advertencias. |
| Tema | Claro y oscuro (sigue la preferencia del sistema o el botón de la barra lateral; se recuerda en el navegador). |
| Tipografía | *Archivo* para la interfaz (el número de módulo en versión ancha y ligera); *JetBrains Mono* solo para SQL, planes e identificadores, sin ligaduras para que `>=` se vea como se escribe. |
| Estructura | Barra lateral con los módulos 01 a 06 y, abajo, la conexión actual (motor, base, usuario) siempre visible; en celular se pliega en un menú. |
| Indicadores | Las «tarjetas» sueltas pasan a ser una sola franja de lectura. |
| Inicio | Índice de módulos en filas, cada uno con su efecto sobre la base: solo lectura, escribe archivos, actualiza estadísticas o copia temporal. |
| Accesibilidad | Foco visible, enlace «Ir al contenido», respeto a `prefers-reduced-motion`, contraste en ambos temas. |

Todo está en `css/estilos.css` (tokens en `:root`), `includes/layout.php` y `js/consola.js`.

## Cartógrafo (Módulo 05)

`js/cartografo.js` convierte el mapa en un tablero interactivo, tipo diagrama entidad-relación:

- Cada tabla muestra su llave primaria (PK), las columnas que la relacionan (FK) y sus filas estimadas.
- Las relaciones se dibujan de columna a columna: continuas las declaradas, discontinuas y en movimiento
  las inferidas (con la estrella de NovaCloud), y en rojo, con brillo y el número de huérfanos, las que tienen filas huérfanas.
- Disposición automática: tablas padre a la izquierda e hijas a la derecha.
- Pasar el cursor resalta las relaciones de una tabla; clic en una tabla o una línea abre un panel con el detalle,
  la consulta SQL que cuenta los huérfanos y un botón para copiarla.
- Arrastrar tablas, mover el fondo, acercar (botones o Ctrl + rueda), encuadrar, reordenar y pantalla completa.
- Buscar tablas y filtrar declaradas, inferidas o solo las relaciones con huérfanos.
- La tabla de relaciones de abajo resalta cada relación en el mapa.
- Teclado: Tab recorre las tablas, Enter las elige, las flechas las mueven y Esc cierra el detalle.

Seguridad: los datos llegan en un `<script type="application/json">` codificado con `JSON_HEX_*`
y se insertan con `textContent`, nunca con `innerHTML`. Es solo de lectura: no envía nada al servidor.
Sin JavaScript se sigue viendo el mapa generado en el servidor (`carto_svg()`).
