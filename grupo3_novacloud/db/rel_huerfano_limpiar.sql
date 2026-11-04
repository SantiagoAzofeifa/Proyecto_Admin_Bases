-- Módulo 05 - Elimina la fila huérfana de prueba (solo estado 'PRUEBA' y cliente 999999).
--   mariadb -u USUARIO_BD -p NOMBRE_BD < rel_huerfano_limpiar.sql
DELETE FROM diag_pedidos WHERE estado = 'PRUEBA' AND id_cliente = 999999;

SELECT COUNT(*) AS huerfanos_ahora
FROM diag_pedidos h LEFT JOIN clientes p ON p.id_cliente = h.id_cliente
WHERE h.id_cliente IS NOT NULL AND p.id_cliente IS NULL;   -- debe dar 0
