<?php
// Catálogo cerrado del Módulo 03 (Diagnóstico de consultas).
// Aquí vive TODO el SQL que la web puede ejecutar. La página recibe solo
// el identificador ('Q01' o 'Q02'); nunca SQL ni valores del usuario.
// Copiar a: includes/diag_catalogo.php   (lo usa diagnostico.php).

function diag_catalogo(): array
{
    return [
        'Q01' => [
            'titulo'    => 'Q01 - Pedidos cancelados del 2026',
            'proposito' => 'Filtrar una tabla por estado y rango de fecha, ordenada por fecha.',
            'tabla'     => 'diag_pedidos',
            'sql'       => "SELECT id_pedido, id_cliente, estado, monto, fecha_pedido\n"
                         . "FROM diag_pedidos\n"
                         . "WHERE estado = 'CANCELADO' AND fecha_pedido >= '2026-01-01'\n"
                         . "ORDER BY fecha_pedido",
            // Resumen para comprobar que antes y después devuelven lo mismo
            'resumen'   => "SELECT COUNT(*) AS filas, SUM(monto) AS suma_monto,\n"
                         . "MIN(id_pedido) AS primer_id, MAX(id_pedido) AS ultimo_id\n"
                         . "FROM diag_pedidos\n"
                         . "WHERE estado = 'CANCELADO' AND fecha_pedido >= '2026-01-01'",
        ],
        'Q02' => [
            'titulo'    => 'Q02 - Mayores pedidos de clientes de Heredia',
            'proposito' => 'JOIN de pedidos con clientes, filtro por provincia y orden por monto.',
            'tabla'     => 'diag_pedidos, clientes',
            'sql'       => "SELECT c.nombre, c.apellido, c.provincia, p.id_pedido, p.monto\n"
                         . "FROM diag_pedidos p\n"
                         . "JOIN clientes c ON c.id_cliente = p.id_cliente\n"
                         . "WHERE c.provincia = 'Heredia'\n"
                         . "ORDER BY p.monto DESC, p.id_pedido\n"
                         . "LIMIT 20",
            'resumen'   => "SELECT COUNT(*) AS filas, SUM(monto) AS suma_monto\n"
                         . "FROM (SELECT p.monto FROM diag_pedidos p\n"
                         . "JOIN clientes c ON c.id_cliente = p.id_cliente\n"
                         . "WHERE c.provincia = 'Heredia'\n"
                         . "ORDER BY p.monto DESC, p.id_pedido LIMIT 20) t",
        ],
    ];
}

/** Devuelve el caso solo si el identificador está en la lista permitida. */
function diag_caso(string $id): ?array
{
    $cat = diag_catalogo();
    return $cat[$id] ?? null;
}
