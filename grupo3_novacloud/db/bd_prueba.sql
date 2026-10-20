-- =====================================================================
-- Base de datos de prueba: TIENDA (MariaDB)
-- Grupo 3 NovaCloud - Administración de Bases de Datos - Proyectos 01 y 02
--
-- Uso (desde la terminal de la cuenta, SIN root; la BD ya existe en Virtualmin):
--   mariadb -u USUARIO_BD -p NOMBRE_BD < bd_prueba.sql
--
-- No contiene CREATE DATABASE ni USE: así funciona en cualquier cuenta
-- (cada integrante usa su propia base creada desde Virtualmin).
-- Se puede ejecutar varias veces: borra y recrea solo estas 5 tablas.
-- Requiere el motor SEQUENCE de MariaDB (seq_1_to_N), incluido desde 10.1.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS detalle_venta;
DROP TABLE IF EXISTS ventas;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS clientes;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. Creación de tablas, llaves primarias y relaciones
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
    id_categoria INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre       VARCHAR(60)  NOT NULL,
    PRIMARY KEY (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clientes (
    id_cliente     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(60)  NOT NULL,
    apellido       VARCHAR(60)  NOT NULL,
    correo         VARCHAR(120) NOT NULL,
    telefono       VARCHAR(20)  NULL,
    provincia      VARCHAR(30)  NULL,
    fecha_registro DATE         NOT NULL,
    PRIMARY KEY (id_cliente),
    UNIQUE KEY uq_clientes_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE productos (
    id_producto  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_categoria INT UNSIGNED  NOT NULL,
    nombre       VARCHAR(100)  NOT NULL,
    precio       DECIMAL(10,2) NOT NULL,
    stock        INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (id_producto),
    KEY idx_productos_categoria (id_categoria),
    CONSTRAINT fk_productos_categoria
        FOREIGN KEY (id_categoria) REFERENCES categorias (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ventas (
    id_venta   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_cliente INT UNSIGNED  NOT NULL,
    fecha      DATETIME      NOT NULL,
    estado     VARCHAR(15)   NOT NULL DEFAULT 'PAGADA',
    total      DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id_venta),
    KEY idx_ventas_cliente (id_cliente),
    KEY idx_ventas_fecha (fecha),
    CONSTRAINT fk_ventas_cliente
        FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE detalle_venta (
    id_detalle      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    id_venta        INT UNSIGNED  NOT NULL,
    id_producto     INT UNSIGNED  NOT NULL,
    cantidad        INT UNSIGNED  NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id_detalle),
    KEY idx_detalle_venta (id_venta),
    KEY idx_detalle_producto (id_producto),
    CONSTRAINT fk_detalle_venta
        FOREIGN KEY (id_venta) REFERENCES ventas (id_venta),
    CONSTRAINT fk_detalle_producto
        FOREIGN KEY (id_producto) REFERENCES productos (id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. Datos de prueba
--    categorias: 10 | clientes: 250 | productos: 150
--    ventas: 1000  | detalle_venta: 3000
-- ---------------------------------------------------------------------
INSERT INTO categorias (nombre) VALUES
    ('Electrónica'), ('Hogar'), ('Ropa'), ('Deportes'), ('Juguetes'),
    ('Libros'), ('Oficina'), ('Mascotas'), ('Jardín'), ('Belleza');

INSERT INTO clientes (nombre, apellido, correo, telefono, provincia, fecha_registro)
SELECT
    ELT(1 + FLOOR(RAND() * 10), 'Ana','Luis','María','José','Karla','Diego','Sofía','Mateo','Laura','Andrés'),
    ELT(1 + FLOOR(RAND() * 10), 'Mora','Vargas','Rojas','Jiménez','Solano','Araya','Campos','Núñez','Quesada','Salas'),
    CONCAT('cliente', seq, '@ejemplo.com'),
    CONCAT('8', LPAD(FLOOR(RAND() * 10000000), 7, '0')),
    ELT(1 + FLOOR(RAND() * 7), 'San José','Alajuela','Cartago','Heredia','Guanacaste','Puntarenas','Limón'),
    DATE_ADD('2024-01-01', INTERVAL FLOOR(RAND() * 900) DAY)
FROM seq_1_to_250;

INSERT INTO productos (id_categoria, nombre, precio, stock)
SELECT
    1 + FLOOR(RAND() * 10),
    CONCAT('Producto ', LPAD(seq, 3, '0')),
    ROUND(500 + RAND() * 49500, 2),
    FLOOR(RAND() * 200)
FROM seq_1_to_150;

INSERT INTO ventas (id_cliente, fecha, estado, total)
SELECT
    1 + FLOOR(RAND() * 250),
    DATE_ADD('2025-01-01 08:00:00', INTERVAL FLOOR(RAND() * 600 * 24 * 60) MINUTE),
    ELT(1 + FLOOR(RAND() * 4), 'PAGADA','PAGADA','PAGADA','PENDIENTE'),
    0
FROM seq_1_to_1000;

INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario)
SELECT
    1 + FLOOR(RAND() * 1000),
    1 + FLOOR(RAND() * 150),
    1 + FLOOR(RAND() * 5),
    0
FROM seq_1_to_3000;

-- El precio unitario se toma del producto y el total de cada venta se recalcula
UPDATE detalle_venta d
JOIN productos p ON p.id_producto = d.id_producto
SET d.precio_unitario = p.precio;

UPDATE ventas v
SET v.total = COALESCE(
    (SELECT SUM(d.cantidad * d.precio_unitario)
     FROM detalle_venta d
     WHERE d.id_venta = v.id_venta), 0);

-- ---------------------------------------------------------------------
-- 3. Consultas de verificación (evidencia para el documento)
-- ---------------------------------------------------------------------
SELECT 'categorias'    AS tabla, COUNT(*) AS registros FROM categorias
UNION ALL SELECT 'clientes',      COUNT(*) FROM clientes
UNION ALL SELECT 'productos',     COUNT(*) FROM productos
UNION ALL SELECT 'ventas',        COUNT(*) FROM ventas
UNION ALL SELECT 'detalle_venta', COUNT(*) FROM detalle_venta;

SELECT v.id_venta, CONCAT(c.nombre, ' ', c.apellido) AS cliente, v.fecha, v.estado, v.total
FROM ventas v
JOIN clientes c ON c.id_cliente = v.id_cliente
ORDER BY v.id_venta
LIMIT 10;
