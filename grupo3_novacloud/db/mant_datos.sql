-- =====================================================================
-- Módulo 04 - Tabla de práctica para el mantenimiento (MariaDB)
-- Grupo 3 NovaCloud - Administración de Bases de Datos - Proyecto Final
--
-- Uso (terminal de la cuenta, SIN root):
--   mariadb -u USUARIO_BD -p NOMBRE_BD < mant_datos.sql
--
-- Crea UNA tabla nueva: mant_prueba (10 000 filas). No toca ninguna otra.
-- Se puede ejecutar varias veces: solo borra y recrea mant_prueba.
-- STATS_AUTO_RECALC=0: el motor no recalcula las estadísticas por su cuenta,
-- así que las cargas posteriores no se reflejan hasta ejecutar ANALYZE TABLE.
-- =====================================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS mant_prueba;

CREATE TABLE mant_prueba (
    id_registro INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(20)   NOT NULL,
    valor       DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id_registro),
    KEY idx_mant_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 STATS_PERSISTENT=1 STATS_AUTO_RECALC=0;

-- 1 000 filas y estadísticas iniciales
INSERT INTO mant_prueba (codigo, valor)
SELECT CONCAT('C', LPAD(seq, 5, '0')), ROUND(100 + MOD(seq * 7919, 9000) / 10, 2)
FROM seq_1_to_1000;

ANALYZE TABLE mant_prueba;

-- 9 000 filas más SIN analizar: las estadísticas quedan desactualizadas
INSERT INTO mant_prueba (codigo, valor)
SELECT CONCAT('C', LPAD(seq, 5, '0')), ROUND(100 + MOD(seq * 7919, 9000) / 10, 2)
FROM seq_1001_to_10000;

-- Evidencia
SELECT COUNT(*) AS filas_exactas FROM mant_prueba;

SELECT TABLE_ROWS AS filas_estimadas, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mant_prueba';
