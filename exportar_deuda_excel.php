<?php
include 'includes/conexion.php';

/* ================= CABECERAS EXCEL ================= */
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=Reporte_Deudas.xls");
header("Pragma: no-cache");
header("Expires: 0");

echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';

/* ================= CONSULTA ================= */
$sql = "
SELECT 
    c.nombre,
    v.tipo_venta,
    v.producto,
    v.monto,
    v.interes,
    v.precio_compra,
    v.precio_venta,
    IFNULL(pag.total_pagado,0) AS total_pagado
FROM clientes c
INNER JOIN ventas v ON c.id = v.cliente_id
LEFT JOIN (
    SELECT venta_id, SUM(monto) AS total_pagado
    FROM pagos
    GROUP BY venta_id
) pag ON v.id = pag.venta_id
ORDER BY c.nombre
";

$result = mysqli_query($conexion, $sql);

/* ================= TABLA ================= */
echo "<table border='1' cellpadding='6' cellspacing='0' width='100%'>";

echo "
<tr style='background:#eaeaea; font-weight:bold; text-align:center;'>
    <th>Cliente</th>
    <th>Tipo</th>
    <th>Descripción</th>
    <th>Capital (S/)</th>
    <th>Interés</th>
    <th>Ganancia (S/)</th>
    <th>Total (S/)</th>
    <th>Pagado (S/)</th>
    <th>Saldo (S/)</th>
</tr>
";

/* ================= TOTALES ================= */
$total_capital  = 0;
$total_ganancia = 0;
$total_total    = 0;
$total_pagado   = 0;
$total_saldo    = 0;

/* ================= DATOS ================= */
while ($row = mysqli_fetch_assoc($result)) {

    if ($row['tipo_venta'] === 'artefacto') {
        $tipo        = 'Artefacto';
        $descripcion = $row['producto'];
        $capital     = (float)$row['precio_compra'];
        $interes     = '';
        $ganancia    = $row['precio_venta'] - $row['precio_compra'];
        $total       = $row['precio_venta'];
    } else {
        $tipo        = 'Efectivo';
        $descripcion = 'Préstamo en efectivo';
        $capital     = (float)$row['monto'];
        $interes     = rtrim(rtrim(number_format($row['interes'],2,'.',''),'0'),'.').' %';
        $ganancia    = $capital * ($row['interes'] / 100);
        $total       = $capital + $ganancia;
    }

    $saldo = $total - $row['total_pagado'];

    // 👉 Mostrar solo deudas
    if ($saldo <= 0) continue;

    echo "
    <tr>
        <td>".htmlspecialchars($row['nombre'])."</td>
        <td align='center'>{$tipo}</td>
        <td>".htmlspecialchars($descripcion)."</td>
        <td align='right'>".number_format($capital,2)."</td>
        <td align='center'>{$interes}</td>
        <td align='right'>".number_format($ganancia,2)."</td>
        <td align='right'>".number_format($total,2)."</td>
        <td align='right'>".number_format($row['total_pagado'],2)."</td>
        <td align='right'><b>".number_format($saldo,2)."</b></td>
    </tr>
    ";

    $total_capital  += $capital;
    $total_ganancia += $ganancia;
    $total_total    += $total;
    $total_pagado   += $row['total_pagado'];
    $total_saldo    += $saldo;
}

/* ================= FILA TOTALES ================= */
echo "
<tr style='background:#f2f2f2; font-weight:bold; text-align:center;'>
    <td colspan='3'>TOTALES</td>
    <td align='right'>".number_format($total_capital,2)."</td>
    <td></td>
    <td align='right'>".number_format($total_ganancia,2)."</td>
    <td align='right'>".number_format($total_total,2)."</td>
    <td align='right'>".number_format($total_pagado,2)."</td>
    <td align='right'>".number_format($total_saldo,2)."</td>
</tr>
";

echo "</table>";
exit;
?>
