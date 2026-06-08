<?php
require_once('tcpdf/tcpdf.php');
include 'includes/conexion.php';

if (!isset($_GET['pago_id'])) {
    die('Parámetro inválido');
}

$pago_id = intval($_GET['pago_id']);

/* ================= CONSULTA CORREGIDA ================= */
$sql = "
SELECT 
    p.id,
    p.fecha_pago,
    p.metodo_pago,
    p.monto,
    IFNULL(p.mora, 0) AS mora,
    c.nombre AS cliente_nombre,
    c.dni,
    v.producto,
    v.tipo_venta,
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
        ELSE (v.monto + (v.monto * v.interes / 100))
    END AS total_credito,
    (
        SELECT IFNULL(SUM(pagos_ant.monto), 0)
        FROM pagos pagos_ant
        WHERE pagos_ant.venta_id = v.id AND pagos_ant.id < p.id
    ) AS total_pagado_antes,
    (
        SELECT IFNULL(SUM(pagos_desp.monto), 0)
        FROM pagos pagos_desp
        WHERE pagos_desp.venta_id = v.id AND pagos_desp.id <= p.id
    ) AS total_pagado_despues
FROM pagos p
INNER JOIN ventas v ON p.venta_id = v.id
INNER JOIN clientes c ON v.cliente_id = c.id
WHERE p.id = ?
";

$stmt = mysqli_prepare($conexion, $sql);
mysqli_stmt_bind_param($stmt, 'i', $pago_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$pago = mysqli_fetch_assoc($result);

if (!$pago) {
    die('Pago no encontrado');
}

// Calcular deudas correctamente
$total_credito = floatval($pago['total_credito']);
$pagado_antes = floatval($pago['total_pagado_antes']);
$pagado_despues = floatval($pago['total_pagado_despues']);
$monto_pagado = floatval($pago['monto']);
$mora_pagada = floatval($pago['mora']);

// Deuda ANTES de este pago
$deuda_anterior = max(0, $total_credito - $pagado_antes);
// Deuda DESPUÉS de este pago (la mora NO reduce la deuda)
$deuda_actual = max(0, $total_credito - $pagado_despues);

// Limitar el nombre del cliente para que no ocupe mucho espacio
$nombre_cliente = $pago['cliente_nombre'];
if (strlen($nombre_cliente) > 30) {
    $nombre_cliente = substr($nombre_cliente, 0, 28) . '...';
}

/* ================= PDF - TAMAÑO MEJORADO ================= */
// Usar un tamaño más grande y orientación vertical
$pdf = new TCPDF('P', 'mm', array(60, 120), true, 'UTF-8', false);
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 4);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

/* ================= LOGO ================= */
$logo = 'img/logo-shor.jpeg';
if (file_exists($logo)) {
    $pdf->Image($logo, 20, 3, 20);
    $pdf->SetY(22);
} else {
    $pdf->SetY(8);
}

/* ================= HEADER ================= */
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'EASY MONEY', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 6);
$pdf->Cell(0, 3, 'PRESTAMOS AL INSTANTE', 0, 1, 'C');

$pdf->Ln(2);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, 'VOUCHER DE PAGO', 0, 1, 'C');

$pdf->Ln(1);
$pdf->Cell(0, 0, str_repeat('=', 52), 0, 1, 'C');
$pdf->Ln(1);

/* ================= DATOS DEL CLIENTE ================= */
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, 'DATOS DEL CLIENTE', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 7);

$pdf->Cell(15, 4, 'Fecha:', 0, 0);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, date('d/m/Y', strtotime($pago['fecha_pago'])), 0, 1);
$pdf->SetFont('helvetica', '', 7);

$pdf->Cell(15, 4, 'DNI:', 0, 0);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, $pago['dni'], 0, 1);
$pdf->SetFont('helvetica', '', 7);

$pdf->Cell(15, 4, 'Cliente:', 0, 0);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->MultiCell(0, 4, $nombre_cliente, 0, 'L');
$pdf->SetFont('helvetica', '', 7);

/* ================= DATOS DEL PRESTAMO ================= */
$pdf->Ln(1);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, 'DATOS DEL PRESTAMO', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 7);


$pdf->Cell(15, 4, 'Metodo:', 0, 0);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, strtoupper($pago['metodo_pago']), 0, 1);
$pdf->SetFont('helvetica', '', 7);

/* ================= LINEA ================= */
$pdf->Ln(1);
$pdf->Cell(0, 0, str_repeat('=', 52), 0, 1, 'C');
$pdf->Ln(1);

/* ================= DETALLE DEL PAGO ================= */
$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(0, 4, 'DETALLE DEL PAGO', 0, 1, 'L');

// Tabla de pagos
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(30, 5, 'Concepto', 0, 0);
$pdf->Cell(0, 5, 'Monto (S/)', 0, 1, 'R');

$pdf->Cell(30, 5, 'Abono Realizado', 0, 0);
$pdf->Cell(0, 5, number_format($monto_pagado, 2), 0, 1, 'R');

if ($mora_pagada > 0) {
    $pdf->Cell(30, 5, 'Mora cobrada', 0, 0);
    $pdf->Cell(0, 5, number_format($mora_pagada, 2), 0, 1, 'R');
}

$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(30, 5, 'TOTAL PAGADO', 0, 0);
$pdf->Cell(0, 5, number_format($monto_pagado + $mora_pagada, 2), 0, 1, 'R');


$pdf->SetFont('helvetica', 'B', 7);
$pdf->Cell(25, 5, 'Deuda actual:', 0, 0);
$pdf->Cell(0, 5, 'S/ ' . number_format($deuda_actual, 2), 0, 1, 'R');
$pdf->SetFont('helvetica', '', 7);

/* ================= LINEA ================= */
$pdf->Ln(1);
$pdf->Cell(0, 0, str_repeat('=', 52), 0, 1, 'C');

/* ================= MENSAJE DE CANCELACION ================= */
if ($deuda_actual <= 0) {
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(0, 128, 0);
    $pdf->Cell(0, 5, '*** CREDITO CANCELADO ***', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
} elseif ($deuda_actual < $deuda_anterior) {
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', 'I', 6);
    $pdf->SetTextColor(0, 0, 255);
    $pdf->Cell(0, 4, '¡Gracias por su pago!', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
}


$pdf->Output('voucher_pago_' . $pago_id . '.pdf', 'I');
?>