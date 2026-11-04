-- =====================================================================
-- Módulo 06 (Laboratorio de índices) - Contraste por terminal
--   mariadb -u USUARIO_BD -p NOMBRE_BD < lab_prueba.sql
-- Reproduce a mano lo que hará la web con la copia sbx_diag_pedidos para el
-- caso Q01 y el candidato A (estado, fecha_pedido). No toca diag_pedidos.
-- Termina SIEMPRE con DROP de la copia.
-- =====================================================================
SET NAMES utf8mb4;

SELECT COUNT(*) AS filas_origen FROM diag_pedidos;   -- debe ser <= 50 000

DROP TABLE IF EXISTS sbx_diag_pedidos;
CREATE TABLE sbx_diag_pedidos LIKE diag_pedidos;
INSERT INTO sbx_diag_pedidos SELECT * FROM diag_pedidos;
ANALYZE TABLE sbx_diag_pedidos;

DROP INDEX IF EXISTS idx_diag_estado_fecha ON sbx_diag_pedidos;
ANALYZE TABLE sbx_diag_pedidos;

SELECT COUNT(*) AS filas_copia FROM sbx_diag_pedidos;

-- Antes
EXPLAIN SELECT id_pedido, id_cliente, estado, monto, fecha_pedido
FROM sbx_diag_pedidos
WHERE estado = 'CANCELADO' AND fecha_pedido >= '2026-01-01'
ORDER BY fecha_pedido;

CREATE INDEX idx_sbx_a ON sbx_diag_pedidos (estado, fecha_pedido);
ANALYZE TABLE sbx_diag_pedidos;

-- Después
EXPLAIN SELECT id_pedido, id_cliente, estado, monto, fecha_pedido
FROM sbx_diag_pedidos
WHERE estado = 'CANCELADO' AND fecha_pedido >= '2026-01-01'
ORDER BY fecha_pedido;

DROP TABLE sbx_diag_pedidos;
SHOW TABLES LIKE 'sbx\_%';    -- debe quedar vacío
