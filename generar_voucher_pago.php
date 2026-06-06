<?php
require_once('tcpdf/tcpdf.php');
include 'includes/conexion.php';

if (!isset($_GET['pago_id'])) {
    die('Parámetro inválido');
}

$pago_id = intval($_GET['pago_id']);

/* ================= CONSULTA ================= */
$sql = "
SELECT 
    p.*,
    IFNULL(p.mora, 0) AS mora,
    c.nombre AS cliente_nombre,
    c.dni,
    v.producto,
    v.tipo_venta,
    CASE 
    WHEN v.tipo_venta = 'artefacto' 
        THEN v.precio_venta
    ELSE 
        (v.monto + (v.monto * v.interes / 100))
END AS total_credito,

CASE 
    WHEN v.tipo_venta = 'artefacto' 
        THEN v.precio_venta - IFNULL((SELECT SUM(monto) FROM pagos WHERE venta_id = v.id), 0)
    ELSE 
        (v.monto + (v.monto * v.interes / 100)) 
        - IFNULL((SELECT SUM(monto) FROM pagos WHERE venta_id = v.id), 0)
END AS deuda_actual
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

/* ================= PDF ================= */
$pdf = new TCPDF('P', 'mm', array(54, 100), true, 'UTF-8', false);
$pdf->SetMargins(3, 5, 3);
$pdf->SetAutoPageBreak(true, 5);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

/* ================= LOGO ================= */
$logo = 'img/logo-shor.jpeg';
$pdf->Image($logo, 17, 5, 20);
$pdf->SetY(27);

/* ================= EMPRESA ================= */
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 4, 'EASY MONEY', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 5);
$pdf->Cell(0, 3, 'PRESTAMOS AL INSTANTE', 0, 1, 'C');

$pdf->Ln(2);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, 'VOUCHER DE PAGO', 0, 1, 'C');

$pdf->Ln(1);
$pdf->Cell(0, 0, str_repeat('-', 60), 0, 1, 'C');
$pdf->Ln(2);

/* ================= DATOS ================= */
$pdf->SetFont('helvetica', '', 7);

$pdf->Cell(14, 4, 'Fecha:', 0, 0);
$pdf->Cell(0, 4, date('d/m/Y', strtotime($pago['fecha_pago'])), 0, 1);

$pdf->Cell(14, 4, 'DNI:', 0, 0);
$pdf->Cell(0, 4, $pago['dni'], 0, 1);

$pdf->Cell(14, 4, 'Método:', 0, 0);
$pdf->Cell(0, 4, strtoupper($pago['metodo_pago']), 0, 1);

/* ================= CLIENTE ================= */
$pdf->Ln(1);
$pdf->Cell(14, 4, 'Cliente:', 0, 0);

$pdf->MultiCell(0, 4, $pago['cliente_nombre'], 0, 'L');

/* ================= PRODUCTO ================= */
$pdf->Cell(14, 4, 'Concepto:', 0, 0);
$pdf->MultiCell(0, 4, $pago['producto'], 0, 'L');

/* ================= LINEA ================= */
$pdf->Ln(1);
$pdf->Cell(0, 0, str_repeat('-', 60), 0, 1, 'C');
$pdf->Ln(1);

/* ================= MONTOS ================= */
$pdf->SetFont('helvetica', 'B', 8);
$total_pagado = $pago['monto'] + $pago['mora'];

$pdf->Cell(22, 4, 'Monto Pagado:', 0, 0);
$pdf->Cell(0, 4, 'S/ ' . number_format($total_pagado, 2), 0, 1);

$pdf->Cell(22, 4, 'Deuda Actual:', 0, 0);
$pdf->Cell(0, 4, 'S/ ' . number_format(max(0, $pago['deuda_actual']), 2), 0, 1);

/* ================= MORA ================= */
if ($pago['mora'] > 0) {
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', 'I', 6);
    $pdf->Cell(22, 4, 'Mora cobrada:', 0, 0);
    $pdf->Cell(0, 4, 'S/ ' . number_format($pago['mora'], 2), 0, 1);
}

/* ================= PIE ================= */
$pdf->Ln(1);
$pdf->SetFont('helvetica', 'I', 6);
$pdf->Cell(0, 4, 'Gracias por su pago', 0, 1, 'C');


$pdf->Output('voucher_pago_'.$pago_id.'.pdf', 'I');