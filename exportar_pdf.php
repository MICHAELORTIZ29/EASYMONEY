<?php
if (ob_get_length()) ob_end_clean();
include 'includes/conexion.php';
require_once('tcpdf/tcpdf.php');

$cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;
if ($cliente_id <= 0) die("ID de cliente inválido.");

/* ================= CLIENTE ================= */
$sqlCliente = "SELECT * FROM clientes WHERE id = $cliente_id";
$resCliente = mysqli_query($conexion, $sqlCliente);
$cliente = mysqli_fetch_assoc($resCliente);
if (!$cliente) die("Cliente no encontrado.");

/* ================= FUNCIONES ================= */
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
ORDER BY v.fecha_venta DESC
";
$resVentas = mysqli_query($conexion, $sqlVentas);

$ventas = [];

/* ===== TOTALES POR TIPO ===== */
$ef_capital = $ef_interes = $ef_total = $ef_pagado = $ef_mora = 0;
$ar_capital = $ar_ganancia = $ar_total = $ar_pagado = $ar_mora = 0;

while ($v = mysqli_fetch_assoc($resVentas)) {

    if ($v['tipo_venta'] === 'artefacto') {
        $producto       = $v['producto'];
        $capital        = (float)$v['precio_compra'];
        $ganancia       = (float)($v['precio_venta'] - $v['precio_compra']);
        $interes_pct    = null;
        $interes_monto  = $ganancia;
        $monto_total    = (float)$v['precio_venta'];

        $ar_capital  += $capital;
        $ar_ganancia += $ganancia;
        $ar_total    += $monto_total;
        $ar_pagado   += $v['total_pagado'];

    } else {
        $producto       = $v['producto'];
        $capital        = (float)$v['monto'];
        $interes_pct    = (float)$v['interes'];
        $interes_monto  = $capital * ($interes_pct / 100);
        $monto_total    = $capital + $interes_monto;

        $ef_capital += $capital;
        $ef_interes += $interes_monto;
        $ef_total   += $monto_total;
        $ef_pagado  += $v['total_pagado'];
    }

    $pagado = (float)$v['total_pagado'];
    $saldo  = max(0, $monto_total - $pagado);
    
    // Determinar estado del crédito
    if ($saldo <= 0) {
        $estado_credito = "CANCELADO";
        $estado_color = "#198754";
    } else {
        $estado_credito = "PENDIENTE";
        $estado_color = "#dc3545";
    }

    $dias = $v['fecha_venc'] ? diasRestantes($v['fecha_venc']) : null;

    /* ===== PAGOS ===== */
    $pagos = [];
    $resPagos = mysqli_query($conexion, "SELECT *, IFNULL(mora,0) AS mora FROM pagos WHERE venta_id={$v['id']} ORDER BY fecha_pago ASC");
    while ($p = mysqli_fetch_assoc($resPagos)) {
        $pagos[] = $p;
        if ($v['tipo_venta'] === 'artefacto') {
            $ar_mora += (float)$p['mora'];
        } else {
            $ef_mora += (float)$p['mora'];
        }
    }

    $ventas[] = [
        'id' => $v['id'],
        'producto' => $producto,
        'tipo' => $v['tipo_venta'],
        'capital' => $capital,
        'interes_pct' => $interes_pct,
        'interes_monto' => $interes_monto,
        'total' => $monto_total,
        'pagado' => $pagado,
        'saldo' => $saldo,
        'estado' => $estado_credito,
        'estado_color' => $estado_color,
        'fecha_venta' => $v['fecha_venta'],
        'fecha_venc' => $v['fecha_venc'],
        'dias_credito' => $v['dias_credito'],
        'dias' => $dias,
        'pagos' => $pagos
    ];
}

/* ===== ORDENAR (PENDIENTES PRIMERO, LUEGO CANCELADOS) ===== */
usort($ventas, function($a, $b) {
    // Primero los pendientes, luego los cancelados
    if ($a['estado'] === 'PENDIENTE' && $b['estado'] === 'CANCELADO') return -1;
    if ($a['estado'] === 'CANCELADO' && $b['estado'] === 'PENDIENTE') return 1;
    // Si mismo estado, ordenar por fecha más reciente
    return strtotime($b['fecha_venta']) - strtotime($a['fecha_venta']);
});

/* ================= PDF ================= */
$pdf = new TCPDF();
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

/* ================= CABECERA ================= */
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'REPORTE DE CLIENTE', 0, 1, 'C');
$pdf->Ln(4);

$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 8, "Cliente: {$cliente['nombre']}\nDNI: {$cliente['dni']}", 1, 'L', 1);
$pdf->Ln(5);

/* ===== MORA TOTAL DEL CLIENTE ===== */
$resMoraTotal = mysqli_query($conexion, "SELECT IFNULL(SUM(p.mora),0) AS mora_total FROM pagos p INNER JOIN ventas v ON p.venta_id = v.id WHERE v.cliente_id = $cliente_id");
$moraTotal = mysqli_fetch_assoc($resMoraTotal)['mora_total'] ?? 0;

/* ================= DETALLE DE CADA CRÉDITO ================= */
foreach ($ventas as $v) {

    // Color de fondo según estado y vencimiento
    if ($v['estado'] === 'CANCELADO') {
        $pdf->SetFillColor(220, 255, 220); // Verde claro para cancelados
        $estado_texto = "CANCELADO";
    } elseif ($v['dias'] < 0 && $v['estado'] !== 'CANCELADO') {
        $pdf->SetFillColor(255, 210, 210); // Rojo claro para vencidos
        $estado_texto = "VENCIDO (" . abs($v['dias']) . " días)";
    } else {
        $pdf->SetFillColor(210, 210, 255); // Azul claro para vigentes
        $estado_texto = "Restan {$v['dias']} días";
    }

    // Título del crédito con estado
    $pdf->SetFont('helvetica', 'B', 12);
    $titulo = strtoupper($v['producto']) . " ({$v['tipo']}) - " . $v['estado'];
    $pdf->MultiCell(0, 8, $titulo, 1, 'L', 1);
    
    // Detalles del crédito
    $pdf->SetFont('helvetica', '', 11);
    
    $detalle = "Capital: S/ " . number_format($v['capital'], 2);
    if ($v['interes_pct'] !== null) {
        $detalle .= "\nInterés: S/ " . number_format($v['interes_monto'], 2) . " ({$v['interes_pct']}%)";
    } else {
        $detalle .= "\nGanancia: S/ " . number_format($v['interes_monto'], 2);
    }
    $detalle .= "\nMonto total: S/ " . number_format($v['total'], 2);
    $detalle .= "\nFecha de crédito: {$v['fecha_venta']} | Días crédito: {$v['dias_credito']}";
    $detalle .= "\nFecha vencimiento: {$v['fecha_venc']} — $estado_texto";
    $detalle .= "\nPagado: S/ " . number_format($v['pagado'], 2);
    $detalle .= "\nSaldo pendiente: S/ " . number_format($v['saldo'], 2);
    
    $pdf->MultiCell(0, 7, $detalle, 1, 'L');
    $pdf->Ln(2);

    // Pagos registrados
    if ($v['pagos']) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Pagos registrados:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        
        // Tabla de pagos
        $html_pagos = '<table border="1" cellpadding="3" style="font-size:9pt;">';
        $html_pagos .= '<tr bgcolor="#eeeeee">
                            <th>#</th>
                            <th>Fecha</th>
                            <th>Método</th>
                            <th>Monto (S/)</th>
                            <th>Mora (S/)</th>
                        </tr>';
        $contador = 1;
        foreach ($v['pagos'] as $p) {
            $html_pagos .= '<tr>
                                <td align="center">' . $contador++ . '</td>
                                <td>' . $p['fecha_pago'] . '</td>
                                <td>' . ucfirst($p['metodo_pago']) . '</td>
                                <td align="right">S/ ' . number_format($p['monto'], 2) . '</td>
                                <td align="right">' . ($p['mora'] > 0 ? 'S/ ' . number_format($p['mora'], 2) : '—') . '</td>
                            </tr>';
        }
        $html_pagos .= '</table>';
        $pdf->writeHTML($html_pagos, true, false, true, false, '');
    } else {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 6, 'No hay pagos registrados para este crédito.', 0, 1);
    }
    $pdf->Ln(6);
}

/* ================= RESUMEN GENERAL ================= */
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 9, 'Resumen General', 0, 1);
$pdf->Ln(2);

// Mostrar mora total del cliente
if ($moraTotal > 0) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, "Total de mora cobrada al cliente: S/ " . number_format($moraTotal, 2), 0, 1);
    $pdf->Ln(2);
}

/* === EFECTIVO === */
if ($ef_total > 0) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'Préstamos en efectivo', 0, 1);
    $pdf->Ln(2);

    $html_efectivo = "
    <table border='1' cellpadding='4' style='font-size:10pt;'>
        <tr bgcolor='#eeeeee'>
            <th>Capital</th>
            <th>Interés</th>
            <th>Total</th>
            <th>Pagado</th>
            <th>Mora</th>
            <th>Saldo</th>
        </tr>
        <tr align='right'>
            <td>S/ " . number_format($ef_capital, 2) . "</td>
            <td>S/ " . number_format($ef_interes, 2) . "</td>
            <td>S/ " . number_format($ef_total, 2) . "</td>
            <td>S/ " . number_format($ef_pagado, 2) . "</td>
            <td>S/ " . number_format($ef_mora, 2) . "</td>
            <td><strong>S/ " . number_format($ef_total - $ef_pagado, 2) . "</strong></td>
        </tr>
    </table>
    ";
    $pdf->writeHTML($html_efectivo, true, false, true, false, '');
    $pdf->Ln(5);
}

/* === ARTEFACTOS === */
if ($ar_total > 0) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'Préstamos de artefactos', 0, 1);
    $pdf->Ln(2);

    $html_artefacto = "
    <table border='1' cellpadding='4' style='font-size:10pt;'>
        <tr bgcolor='#eeeeee'>
            <th>Capital</th>
            <th>Ganancia</th>
            <th>Total</th>
            <th>Pagado</th>
            <th>Mora</th>
            <th>Saldo</th>
        </tr>
        <tr align='right'>
            <td>S/ " . number_format($ar_capital, 2) . "</td>
            <td>S/ " . number_format($ar_ganancia, 2) . "</td>
            <td>S/ " . number_format($ar_total, 2) . "</td>
            <td>S/ " . number_format($ar_pagado, 2) . "</td>
            <td>S/ " . number_format($ar_mora, 2) . "</td>
            <td><strong>S/ " . number_format($ar_total - $ar_pagado, 2) . "</strong></td>
        </tr>
    </table>
    ";
    $pdf->writeHTML($html_artefacto, true, false, true, false, '');
}

/* ================= TOTALES GENERALES ================= */
$total_general_capital = $ef_capital + $ar_capital;
$total_general_interes = $ef_interes + $ar_ganancia;
$total_general_total = $ef_total + $ar_total;
$total_general_pagado = $ef_pagado + $ar_pagado;
$total_general_saldo = ($ef_total + $ar_total) - ($ef_pagado + $ar_pagado);
$total_general_mora = $ef_mora + $ar_mora;

$pdf->Ln(8);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, 'TOTAL GENERAL', 0, 1, 'C');
$pdf->Ln(2);

$html_total = "
<table border='1' cellpadding='5' style='font-size:11pt;'>
    <tr bgcolor='#dddddd'>
        <th>Total Capital</th>
        <th>Total Ganancia</th>
        <th>Total Monto</th>
        <th>Total Pagado</th>
        <th>Total Mora</th>
        <th>Total Saldo</th>
    </tr>
    <tr align='right'>
        <td>S/ " . number_format($total_general_capital, 2) . "</td>
        <td>S/ " . number_format($total_general_interes, 2) . "</td>
        <td>S/ " . number_format($total_general_total, 2) . "</td>
        <td>S/ " . number_format($total_general_pagado, 2) . "</td>
        <td>S/ " . number_format($total_general_mora, 2) . "</td>
        <td><strong>S/ " . number_format($total_general_saldo, 2) . "</strong></td>
    </tr>
</table>
";
$pdf->writeHTML($html_total, true, false, true, false, '');

/* ================= PIE DE PÁGINA ================= */
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Documento generado automáticamente por el sistema de Control de Créditos', 0, 1, 'C');
$pdf->Cell(0, 5, 'Fecha de emisión: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

/* ================= SALIDA ================= */
$pdf->Output('reporte_cliente_' . $cliente['dni'] . '.pdf', 'I');
?>