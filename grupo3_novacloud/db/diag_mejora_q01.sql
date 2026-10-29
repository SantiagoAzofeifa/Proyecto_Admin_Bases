-- =====================================================================
-- Módulo 03 - Mejora ensayada sobre Q01
-- Ejecutar DESPUÉS de guardar el plan "antes" (ver ensayo_diag.sh / web).
--
--   mariadb -u USUARIO_BD -p NOMBRE_BD < diag_mejora_q01.sql
--
-- Justificación: Q01 filtra por estado (igualdad) y por un rango de
-- fecha_pedido, y además ordena por fecha_pedido. Un índice compuesto
-- (estado, fecha_pedido) permite buscar solo las filas CANCELADO del
-- rango y devolverlas ya ordenadas, sin recorrer toda la tabla ni
-- ordenar aparte. Se crea sobre una tabla propia (diag_pedidos) y no
-- altera llaves primarias, foráneas ni índices de otros módulos.
--
-- Las mismas filas se devuelven antes y después: solo cambia el plan.
-- =====================================================================

CREATE INDEX idx_diag_estado_fecha ON diag_pedidos (estado, fecha_pedido);

ANALYZE TABLE diag_pedidos;

SHOW INDEX FROM diag_pedidos;
