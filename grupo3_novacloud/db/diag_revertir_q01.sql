-- =====================================================================
-- Módulo 03 - Revertir la mejora de Q01 (volver al estado "antes")
--   mariadb -u USUARIO_BD -p NOMBRE_BD < diag_revertir_q01.sql
-- Solo elimina el índice creado por el ensayo; no toca datos ni otros índices.
-- =====================================================================

DROP INDEX IF EXISTS idx_diag_estado_fecha ON diag_pedidos;

ANALYZE TABLE diag_pedidos;

SHOW INDEX FROM diag_pedidos;
