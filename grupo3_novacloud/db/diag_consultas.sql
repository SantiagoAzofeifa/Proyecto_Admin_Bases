-- =====================================================================
-- Módulo 03 - Catálogo Q01 / Q02 y comprobación de resultados
-- Para correr en terminal y contrastar con lo que muestra la web:
--   mariadb -u USUARIO_BD -p NOMBRE_BD < diag_consultas.sql
-- Estas mismas consultas están definidas en el servidor
-- (includes/diag_catalogo.php); la web NO acepta SQL escrito por el usuario.
-- =====================================================================

SELECT VERSION() AS version_servidor, DATABASE() AS base, CURRENT_USER() AS usuario;

-- ---------------------------------------------------------------------
-- Q01: filtrar una tabla por un dato significativo (pedidos cancelados
--      del 2026) y ordenar por fecha.
-- ---------------------------------------------------------------------
EXPLAIN
SELECT id_pedido, id_cliente, estado, monto, fecha_pedido
FROM diag_pedidos
WHERE estado = 'CANCELADO'
  AND fecha_pedido >= '2026-01-01'
ORDER BY fecha_pedido;

-- Resultado de Q01 (para comprobar equivalencia antes/después)
SELECT COUNT(*)          AS filas,
       SUM(monto)        AS suma_monto,
       MIN(id_pedido)    AS primer_id,
       MAX(id_pedido)    AS ultimo_id
FROM diag_pedidos
WHERE estado = 'CANCELADO'
  AND fecha_pedido >= '2026-01-01';

-- ---------------------------------------------------------------------
-- Q02: relacionar dos tablas con JOIN, filtrar por provincia y ordenar
--      por monto (los 20 pedidos más altos de clientes de Heredia).
-- ---------------------------------------------------------------------
EXPLAIN
SELECT c.nombre, c.apellido, c.provincia, p.id_pedido, p.monto
FROM diag_pedidos p
JOIN clientes c ON c.id_cliente = p.id_cliente
WHERE c.provincia = 'Heredia'
ORDER BY p.monto DESC, p.id_pedido
LIMIT 20;

SELECT c.nombre, c.apellido, c.provincia, p.id_pedido, p.monto
FROM diag_pedidos p
JOIN clientes c ON c.id_cliente = p.id_cliente
WHERE c.provincia = 'Heredia'
ORDER BY p.monto DESC, p.id_pedido
LIMIT 20;
