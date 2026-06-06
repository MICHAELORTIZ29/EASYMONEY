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
";
$resVentas = mysqli_query($conexion, $sqlVentas);

$ventas = [];

/* ===== TOTALES POR TIPO ===== */
$ef_capital=$ef_interes=$ef_total=$ef_pagado=$ef_mora=0;
$ar_capital=$ar_ganancia=$ar_total=$ar_pagado=$ar_mora=0;

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
        $producto       = 'Préstamo en efectivo';
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
    if ($saldo <= 0) continue;

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
        'producto'=>$producto,
        'tipo'=>$v['tipo_venta'],
        'capital'=>$capital,
        'interes_pct'=>$interes_pct,
        'interes_monto'=>$interes_monto,
        'total'=>$monto_total,
        'pagado'=>$pagado,
        'saldo'=>$saldo,
        'fecha_venc'=>$v['fecha_venc'],
        'dias'=>$dias,
        'pagos'=>$pagos
    ];
}

/* ===== ORDENAR (VENCIDOS PRIMERO) ===== */
usort($ventas, fn($a,$b)=>($a['dias']??99999)<=>($b['dias']??99999));

/* ================= PDF ================= */
$pdf = new TCPDF();
$pdf->SetMargins(15,20,15);
$pdf->AddPage();

/* ================= CABECERA ================= */
$pdf->SetFont('helvetica','B',16);
$pdf->Cell(0,10,'REPORTE DE CLIENTE',0,1,'C');
$pdf->Ln(4);

$pdf->SetFont('helvetica','',11);
$pdf->MultiCell(0,8,"Cliente: {$cliente['nombre']}\nDNI: {$cliente['dni']}",1,'L',1);
$pdf->Ln(5);

/* ===== MORA TOTAL DEL CLIENTE ===== */
$resMoraTotal = mysqli_query($conexion, "SELECT IFNULL(SUM(p.mora),0) AS mora_total FROM pagos p INNER JOIN ventas v ON p.venta_id = v.id WHERE v.cliente_id = $cliente_id");
$moraTotal = mysqli_fetch_assoc($resMoraTotal)['mora_total'] ?? 0;

/* ================= DETALLE ================= */
foreach ($ventas as $v) {

    if ($v['dias'] < 0) {
        $pdf->SetFillColor(255,210,210);
        $estado = "VENCIDO (".abs($v['dias'])." días)";
    } else {
        $pdf->SetFillColor(210,255,210);
        $estado = "Restan {$v['dias']} días";
    }

    $pdf->SetFont('helvetica','B',12);
    $pdf->MultiCell(0,8, strtoupper($v['producto'])." ({$v['tipo']})",1,'L',1);

    $pdf->SetFont('helvetica','',11);
    $pdf->MultiCell(0,7,
        "Capital: S/ ".number_format($v['capital'],2).
        "\nGanancia / Interés: S/ ".number_format($v['interes_monto'],2).
        ($v['interes_pct']!==null ? " ({$v['interes_pct']}%)" : "").
        "\nMonto total: S/ ".number_format($v['total'],2).
        "\nVencimiento: {$v['fecha_venc']} — $estado".
        "\nPagado: S/ ".number_format($v['pagado'],2).
        "\nMora Total Cobrada: S/ ".number_format($moraTotal,2).
        "\nSaldo pendiente: S/ ".number_format($v['saldo'],2)
    ,1,'L');
    $pdf->Ln(2);





    if ($v['pagos']) {
        $pdf->SetFont('helvetica','B',10);
        $pdf->Cell(0,6,'Pagos registrados:',0,1);
        $pdf->SetFont('helvetica','',10);
        foreach ($v['pagos'] as $p) {
            $lineaMora = $p['mora'] > 0 ? " | Mora: S/ ".number_format($p['mora'],2) : "";
            $pdf->Cell(0,6," - {$p['fecha_pago']} | {$p['metodo_pago']} | S/ ".number_format($p['monto'],2).$lineaMora,0,1);
        }
    } else {
        $pdf->SetFont('helvetica','I',10);
        $pdf->Cell(0,6,'No hay pagos registrados.',0,1);
    }
    $pdf->Ln(4);
}

/* ================= RESUMEN GENERAL ================= */
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,9,'Resumen General',0,1);
$pdf->Ln(2);

/* === EFECTIVO === */
if ($ef_total > 0) {
    $pdf->SetFont('helvetica','B',12);
    $pdf->Cell(0,7,'Préstamos en efectivo',0,1);

    $pdf->writeHTML("
    <table border='1' cellpadding='4'>
        <tr bgcolor='#eeeeee'>
            <th>Capital</th><th>Interés</th><th>Total</th><th>Pagado</th><th>Mora</th><th>Saldo</th>
        </tr>
        <tr align='right'>
            <td>S/ ".number_format($ef_capital,2)."</td>
            <td>S/ ".number_format($ef_interes,2)."</td>
            <td>S/ ".number_format($ef_total,2)."</td>
            <td>S/ ".number_format($ef_pagado,2)."</td>
            <td>S/ ".number_format($ef_mora,2)."</td>
            <td>S/ ".number_format($ef_total-$ef_pagado,2)."</td>
        </tr>
    </table>
    ",true,false,true,false,'');
    $pdf->Ln(4);
}

/* === ARTEFACTOS === */
if ($ar_total > 0) {
    $pdf->SetFont('helvetica','B',12);
    $pdf->Cell(0,7,'Préstamos de artefactos',0,1);

    $pdf->writeHTML("
    <table border='1' cellpadding='4'>
        <tr bgcolor='#eeeeee'>
            <th>Capital</th><th>Ganancia</th><th>Total</th><th>Pagado</th><th>Mora</th><th>Saldo</th>
        </tr>
        <tr align='right'>
            <td>S/ ".number_format($ar_capital,2)."</td>
            <td>S/ ".number_format($ar_ganancia,2)."</td>
            <td>S/ ".number_format($ar_total,2)."</td>
            <td>S/ ".number_format($ar_pagado,2)."</td>
            <td>S/ ".number_format($ar_mora,2)."</td>
            <td>S/ ".number_format($ar_total-$ar_pagado,2)."</td>
        </tr>
    </table>
    ",true,false,true,false,'');
}

/* ================= SALIDA ================= */
$pdf->Output('reporte_cliente.pdf','I');