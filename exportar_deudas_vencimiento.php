<?php
require_once("tcpdf/tcpdf.php");
include 'includes/conexion.php';

/* ================= CONSULTA ================= */
$sql = "
SELECT 
    c.nombre,
    v.tipo_venta,
    v.monto,
    v.interes,
    v.precio_venta,
    v.fecha_venta,
    v.dias_credito,
    IFNULL(pag.total_pagado,0) AS total_pagado,
    DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) AS fecha_limite,
    DATEDIFF(DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY), CURDATE()) AS dias_diff
FROM clientes c
INNER JOIN ventas v ON c.id = v.cliente_id
LEFT JOIN (
    SELECT venta_id, SUM(monto) AS total_pagado
    FROM pagos
    GROUP BY venta_id
) pag ON v.id = pag.venta_id
ORDER BY dias_diff ASC, c.nombre ASC
";

$result = mysqli_query($conexion, $sql);

/* ================= PDF ================= */
$pdf = new TCPDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();

/* ================= TITULO ================= */
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,8,'LISTADO DE DEUDAS POR VENCIMIENTO',0,1,'C');
$pdf->Ln(3);

$pdf->SetFont('helvetica','',9);

/* ================= TABLA ================= */
$html = '
<table border="1" cellpadding="6" cellspacing="0" width="100%">
<tr align="center" bgcolor="#eeeeee">
    <th width="50mm"><b>Cliente</b></th>
    <th width="22mm"><b>Tipo</b></th>
    <th width="28mm"><b>Monto Total</b></th>
    <th width="28mm"><b>Pagado</b></th>
    <th width="28mm"><b>Pendiente</b></th>
    <th width="30mm"><b>Fecha Préstamo</b></th>
    <th width="30mm"><b>Fecha Vencimiento</b></th>
    <th width="36mm"><b>Días</b></th>
    <th width="20mm"><b>Estado</b></th>
</tr>
';

while ($row = mysqli_fetch_assoc($result)) {

    if ($row['tipo_venta'] === 'artefacto') {
        $tipo = 'Artefacto';
        $monto_total = (float)$row['precio_venta'];
    } else {
        $tipo = 'Efectivo';
        $monto_total = $row['monto'] + ($row['monto'] * ($row['interes'] / 100));
    }

    $pendiente = $monto_total - $row['total_pagado'];
    if ($pendiente <= 0) continue;

    if ($row['dias_diff'] < 0) {
        $dias = '<font color="red"><b>Vencido hace '.abs($row['dias_diff']).' días</b></font>';
        $estado = '<font color="red"><b>Vencido</b></font>';
    } else {
        $dias = '<font color="green">Faltan '.$row['dias_diff'].' días</font>';
        $estado = '<font color="green"><b>Vigente</b></font>';
    }

    $html .= "
    <tr align='center'>
        <td>{$row['nombre']}</td>
        <td>{$tipo}</td>
        <td align='right'>S/ ".number_format($monto_total,2)."</td>
        <td align='right'>S/ ".number_format($row['total_pagado'],2)."</td>
        <td align='right'><b>S/ ".number_format($pendiente,2)."</b></td>
        <td>{$row['fecha_venta']}</td>
        <td>{$row['fecha_limite']}</td>
        <td>{$dias}</td>
        <td>{$estado}</td>
    </tr>
    ";
}

$html .= '</table>';

/* ================= RENDER ================= */
$pdf->writeHTML($html, true, false, true, false, '');

ob_clean();
$pdf->Output('Deudas_Por_Vencimiento.pdf','I');
