-- =====================================================================
-- Módulo 05 (Cartógrafo de relaciones) - Fila huérfana de prueba
--   mariadb -u USUARIO_BD -p NOMBRE_BD < rel_huerfano.sql
-- Inserta UN pedido en diag_pedidos cuyo id_cliente (999999) no existe en
-- clientes. diag_pedidos.id_cliente es una relación IMPLÍCITA (sin FOREIGN
-- KEY), por eso el motor lo permite. Estado 'PRUEBA': no afecta a Q01 ni Q02
-- (Q01 filtra CANCELADO; 999999 no hace JOIN en Q02).
-- Para quitarlo: rel_huerfano_limpiar.sql
-- =====================================================================
SET NAMES utf8mb4;

-- Antes: debe dar 0
SELECT COUNT(*) AS huerfanos_antes
FROM diag_pedidos h LEFT JOIN clientes p ON p.id_cliente = h.id_cliente
WHERE h.id_cliente IS NOT NULL AND p.id_cliente IS NULL;

INSERT INTO diag_pedidos (id_cliente, estado, monto, fecha_pedido)
VALUES (999999, 'PRUEBA', 1.00, '2026-01-01');

-- Después: debe dar 1 (es la cifra que debe mostrar la web)
SELECT COUNT(*) AS huerfanos_despues
FROM diag_pedidos h LEFT JOIN clientes p ON p.id_cliente = h.id_cliente
WHERE h.id_cliente IS NOT NULL AND p.id_cliente IS NULL;

SELECT id_pedido, id_cliente, estado FROM diag_pedidos WHERE estado = 'PRUEBA';
