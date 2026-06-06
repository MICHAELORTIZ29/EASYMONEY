<?php
if (ob_get_length()) ob_end_clean();
include 'includes/conexion.php';

$cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;
if ($cliente_id <= 0) die("ID inválido");

/* ================= CLIENTE ================= */
$sqlCliente = "SELECT * FROM clientes WHERE id = $cliente_id";
$resCliente = mysqli_query($conexion, $sqlCliente);
$cliente = mysqli_fetch_assoc($resCliente);
if (!$cliente) die("Cliente no encontrado");

/* ================= FUNCION ================= */
function diasRestantes($fecha) {
    $hoy = new DateTime(date('Y-m-d'));
    $fin = new DateTime($fecha);
    return (int)$hoy->diff($fin)->format('%r%a');
}

/* ================= VENTAS ================= */
$sqlVentas = "
SELECT 
    v.*,
    IFNULL(p.total_pagado,0) AS total_pagado,
    DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) AS fecha_venc
FROM ventas v
LEFT JOIN (
    SELECT venta_id, SUM(monto) total_pagado
    FROM pagos
    GROUP BY venta_id
) p ON v.id = p.venta_id
WHERE v.cliente_id = $cliente_id
";
$resVentas = mysqli_query($conexion, $sqlVentas);

/* ================= HEADERS EXCEL ================= */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=reporte_cliente.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo "<meta charset='UTF-8'>";

/* ================= TITULO ================= */
echo "<h2>REPORTE DE CLIENTE</h2>";
echo "<b>Cliente:</b> {$cliente['nombre']}<br>";
echo "<b>DNI:</b> {$cliente['dni']}<br><br>";

/* ================= TABLA DETALLE ================= */
echo "<table border='1'>
<tr style='background:#eee;font-weight:bold'>
    <th>Producto</th>
    <th>Tipo</th>
    <th>Capital</th>
    <th>Interés / Ganancia</th>
    <th>Total</th>
    <th>Pagado</th>
    <th>Saldo</th>
    <th>Fecha Venc.</th>
    <th>Días</th>
    <th>Pagos</th>
</tr>";

$ef_capital=$ef_interes=$ef_total=$ef_pagado=0;
$ar_capital=$ar_ganancia=$ar_total=$ar_pagado=0;

while ($v = mysqli_fetch_assoc($resVentas)) {

    if ($v['tipo_venta'] === 'artefacto') {
        $producto = $v['producto'];
        $capital  = (float)$v['precio_compra'];
        $interes  = (float)($v['precio_venta'] - $v['precio_compra']);
        $total    = (float)$v['precio_venta'];

        $ar_capital  += $capital;
        $ar_ganancia += $interes;
        $ar_total    += $total;
        $ar_pagado   += $v['total_pagado'];
    } else {
        $producto = 'Préstamo en efectivo';
        $capital  = (float)$v['monto'];
        $interes  = $capital * ((float)$v['interes'] / 100);
        $total    = $capital + $interes;

        $ef_capital += $capital;
        $ef_interes += $interes;
        $ef_total   += $total;
        $ef_pagado  += $v['total_pagado'];
    }

    $pagado = (float)$v['total_pagado'];
    $saldo  = max(0, $total - $pagado);
    if ($saldo <= 0) continue;

    $dias = diasRestantes($v['fecha_venc']);

    /* PAGOS */
    $pagosTxt = '';
    $resPagos = mysqli_query($conexion, "SELECT * FROM pagos WHERE venta_id={$v['id']}");
    while ($p = mysqli_fetch_assoc($resPagos)) {
        $pagosTxt .= "{$p['fecha_pago']} | {$p['metodo_pago']} | S/ {$p['monto']} \n";
    }

    echo "<tr>
        <td>$producto</td>
        <td>{$v['tipo_venta']}</td>
        <td>S/ ".number_format($capital,2)."</td>
        <td>S/ ".number_format($interes,2)."</td>
        <td>S/ ".number_format($total,2)."</td>
        <td>S/ ".number_format($pagado,2)."</td>
        <td><b>S/ ".number_format($saldo,2)."</b></td>
        <td>{$v['fecha_venc']}</td>
        <td>$dias</td>
        <td>".nl2br($pagosTxt)."</td>
    </tr>";
}

echo "</table><br><br>";

/* ================= RESUMEN ================= */
echo "<h3>Resumen General</h3>";

if ($ef_total > 0) {
    echo "<b>Préstamos en efectivo</b>";
    echo "<table border='1'>
    <tr style='background:#eee;font-weight:bold'>
        <th>Capital</th><th>Interés</th><th>Total</th><th>Pagado</th><th>Saldo</th>
    </tr>
    <tr>
        <td>S/ ".number_format($ef_capital,2)."</td>
        <td>S/ ".number_format($ef_interes,2)."</td>
        <td>S/ ".number_format($ef_total,2)."</td>
        <td>S/ ".number_format($ef_pagado,2)."</td>
        <td>S/ ".number_format($ef_total-$ef_pagado,2)."</td>
    </tr>
    </table><br>";
}

if ($ar_total > 0) {
    echo "<b>Préstamos de artefactos</b>";
    echo "<table border='1'>
    <tr style='background:#eee;font-weight:bold'>
        <th>Capital</th><th>Ganancia</th><th>Total</th><th>Pagado</th><th>Saldo</th>
    </tr>
    <tr>
        <td>S/ ".number_format($ar_capital,2)."</td>
        <td>S/ ".number_format($ar_ganancia,2)."</td>
        <td>S/ ".number_format($ar_total,2)."</td>
        <td>S/ ".number_format($ar_pagado,2)."</td>
        <td>S/ ".number_format($ar_total-$ar_pagado,2)."</td>
    </tr>
    </table>";
}
