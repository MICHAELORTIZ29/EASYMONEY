<?php
require_once("tcpdf/tcpdf.php");
include 'includes/conexion.php';

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
    IFNULL(pag.total_pagado,0) AS total_pagado,
    v.fecha_venta,
    v.dias_credito,
    DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) AS fecha_limite
FROM clientes c
INNER JOIN ventas v ON c.id = v.cliente_id
LEFT JOIN (
    SELECT venta_id, SUM(monto) AS total_pagado
    FROM pagos
    GROUP BY venta_id
) pag ON v.id = pag.venta_id
ORDER BY c.nombre, v.fecha_venta ASC
";

$result = mysqli_query($conexion, $sql);

/* ================= PDF ================= */
$pdf = new TCPDF('L', 'mm', 'A4');
$pdf->SetMargins(6, 8, 6); // márgenes equilibrados
$pdf->AddPage();

/* ================= TITULO ================= */
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,8,'REPORTE GENERAL DE DEUDAS',0,1,'C');
$pdf->Ln(3);

/* ================= TABLA ================= */
$pdf->SetFont('helvetica','',9);

$html = '
<style>
table {
    font-size:9.2px;
    line-height:1.35;
}
th {
    background-color:#eaeaea;
    font-weight:bold;
}
td {
    vertical-align:middle;
}
</style>

<table border="1" cellpadding="5" cellspacing="0" width="290mm">
<tr align="center">
    <th width="40mm">Cliente</th>
    <th width="22mm">Tipo</th>
    <th width="65mm">Descripción</th>
    <th width="25mm">Capital</th>
    <th width="20mm">Interés</th>
    <th width="26mm">Ganancia</th>
    <th width="26mm">Total</th>
    <th width="26mm">Pagado</th>
    <th width="30mm">Saldo</th>
</tr>
';

/* ================= TOTALES ================= */
$total_capital  = 0;
$total_ganancia = 0;
$total_total    = 0;
$total_pagado   = 0;
$total_saldo    = 0;

while ($row = mysqli_fetch_assoc($result)) {

    if ($row['tipo_venta'] === 'artefacto') {
        $tipo        = 'Artefacto';
        $descripcion = $row['producto'];
        $capital     = (float)$row['precio_compra'];
        $interes     = '';
        $ganancia    = (float)($row['precio_venta'] - $row['precio_compra']);
        $total       = (float)$row['precio_venta'];
    } else {
        $tipo        = 'Efectivo';
        $descripcion = 'Préstamo en efectivo';
        $capital     = (float)$row['monto'];
        $interes     = rtrim(rtrim(number_format($row['interes'],2,'.',''),'0'),'.').' %';
        $ganancia    = $capital * ($row['interes'] / 100);
        $total       = $capital + $ganancia;
    }

    $saldo = $total - $row['total_pagado'];
    if ($saldo <= 0) continue;

    $html .= '
    <tr>
        <td width="40mm">'.$row['nombre'].'</td>
        <td width="22mm" align="center">'.$tipo.'</td>
        <td width="65mm">'.$descripcion.'</td>
        <td width="25mm" align="right">S/ '.number_format($capital,2).'</td>
        <td width="20mm" align="center">'.$interes.'</td>
        <td width="26mm" align="right">S/ '.number_format($ganancia,2).'</td>
        <td width="26mm" align="right">S/ '.number_format($total,2).'</td>
        <td width="26mm" align="right">S/ '.number_format($row['total_pagado'],2).'</td>
        <td width="30mm" align="right"><b>S/ '.number_format($saldo,2).'</b></td>
    </tr>
    ';

    $total_capital  += $capital;
    $total_ganancia += $ganancia;
    $total_total    += $total;
    $total_pagado   += $row['total_pagado'];
    $total_saldo    += $saldo;
}

/* ================= FILA TOTALES ================= */
$html .= '
<tr bgcolor="#f2f2f2">
    <td colspan="3" align="center"><b>TOTALES</b></td>
    <td align="right"><b>S/ '.number_format($total_capital,2).'</b></td>
    <td></td>
    <td align="right"><b>S/ '.number_format($total_ganancia,2).'</b></td>
    <td align="right"><b>S/ '.number_format($total_total,2).'</b></td>
    <td align="right"><b>S/ '.number_format($total_pagado,2).'</b></td>
    <td align="right"><b>S/ '.number_format($total_saldo,2).'</b></td>
</tr>
</table>
';

$pdf->writeHTML($html, true, false, true, false, '');
ob_clean();
$pdf->Output('Reporte_Deudas.pdf','I');
?>
