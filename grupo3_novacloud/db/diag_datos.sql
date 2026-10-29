-- =====================================================================
-- Módulo 03 - Datos de prueba para el diagnóstico de consultas (MariaDB)
-- Grupo 3 NovaCloud - Administración de Bases de Datos - Proyecto Final
--
-- Uso (terminal de la cuenta, SIN root; la base ya existe en Virtualmin):
--   mariadb -u USUARIO_BD -p NOMBRE_BD < diag_datos.sql
--
-- Qué hace:
--   * Crea UNA tabla nueva: diag_pedidos (20 000 filas).
--   * Se relaciona con clientes (de la base de la primera entrega).
--   * NO toca categorias, clientes, productos, ventas ni detalle_venta.
--   * Es reproducible: no usa RAND(); dos ejecuciones dejan los mismos datos.
--   * Se puede ejecutar varias veces: solo borra y recrea diag_pedidos.
--   * La tabla nace SIN índice secundario (solo la llave primaria) para que
--     el ensayo de mejora (diag_mejora_q01.sql) tenga un "antes" real.
-- Requisitos: tabla clientes con 250 filas (bd_prueba.sql) y motor SEQUENCE.
-- =====================================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS diag_pedidos;

CREATE TABLE diag_pedidos (
    id_pedido    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cliente   INT UNSIGNED  NOT NULL,
    estado       VARCHAR(15)   NOT NULL,
    monto        DECIMAL(10,2) NOT NULL,
    fecha_pedido DATE          NOT NULL,
    PRIMARY KEY (id_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20 000 filas deterministas:
--   estado: 5 % CANCELADO, 15 % PENDIENTE, 80 % PAGADO
--   fecha : 601 días a partir de 2025-01-01 (sin relación con el estado)
--   cliente: reparte los pedidos entre los 250 clientes
INSERT INTO diag_pedidos (id_cliente, estado, monto, fecha_pedido)
SELECT
    1 + MOD(seq * 37, 250),
    CASE
        WHEN MOD(seq, 20) = 0        THEN 'CANCELADO'
        WHEN MOD(seq, 20) IN (1,2,3) THEN 'PENDIENTE'
        ELSE 'PAGADO'
    END,
    ROUND(1000 + MOD(seq * 7919, 90000), 2),
    DATE_ADD('2025-01-01', INTERVAL MOD(seq * 13, 601) DAY)
FROM seq_1_to_20000;

-- Estadísticas iniciales (operación sobre una tabla propia)
ANALYZE TABLE diag_pedidos;

-- ---------------------------------------------------------------------
-- Evidencia para el documento
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS filas_diag_pedidos FROM diag_pedidos;

SELECT estado, COUNT(*) AS filas
FROM diag_pedidos
GROUP BY estado
ORDER BY estado;

SELECT COUNT(*) AS filas_sin_cliente
FROM diag_pedidos p
LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
WHERE c.id_cliente IS NULL;      -- debe dar 0

SHOW INDEX FROM diag_pedidos;    -- solo PRIMARY (estado "antes")
