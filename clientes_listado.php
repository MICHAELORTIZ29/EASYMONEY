<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

// Obtener todos los clientes
$sqlClientes = "SELECT * FROM clientes ORDER BY id";
$result = mysqli_query($conexion, $sqlClientes);

// Guardamos resultados en array
$clientes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $clientes[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Listado de Clientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>

<body>
    <div class="container mt-4">
        <h2 class="mb-4">Listado de Clientes</h2>

        <div class="mb-3">
            <a href="exportar_clientes.php" class="btn btn-primary">📄 Exportar Listado Clientes</a>
            <a href="exportar_deudas.php" class="btn btn-danger">📄 Exportar Deudas Clientes (PDF)</a>
            <a href="exportar_deuda_excel.php" class="btn btn-success">📊 Exportar Deudas Clientes (Excel)</a>
            <a href="exportar_deudas_vencimiento.php" class="btn btn-warning">📄 Exportar Deudas con Vencimiento
                (PDF)</a>
            <a href="dashboard.php" class="btn btn-secondary">⬅️ Volver</a>
        </div>

        <table class="table table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th>DNI</th>
                    <th>Nombre</th>
                    <th>Préstamos</th>
                    <th>Capital (S/)</th>
                    <th>Interés (S/)</th>
                    <th>Monto Total (S/)</th>
                    <th>Saldo Pendiente (S/)</th>
                    <th>Estado de Pago</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Totales generales
                $total_capital = 0;
                $total_interes = 0;
                $total_monto = 0;
                $total_saldo = 0;

                foreach ($clientes as $c):
                    // Variables acumuladoras por cliente
                    $capital_total = 0;
                    $interes_total = 0;
                    $monto_total = 0;
                    $saldo_total = 0;
                    $num_prestamos = 0;
                    $estado_pago = "Vigente"; // Por defecto vigente
                
                    // Obtener todos los préstamos del cliente
                    $sqlPrestamos = "
SELECT 
    id,
    tipo_venta,
    monto,
    interes,
    precio_compra,
    precio_venta,
    fecha_venta,
    dias_credito
FROM ventas
WHERE cliente_id = " . intval($c['id']);


                    $resPrestamos = mysqli_query($conexion, $sqlPrestamos);

                    while ($p = mysqli_fetch_assoc($resPrestamos)) {

                        $num_prestamos++;

                        $capital = 0;
                        $ganancia = 0;
                        $monto_total_prestamo = 0;

                        if ($p['tipo_venta'] === 'efectivo') {

                            // 💵 PRÉSTAMO EN EFECTIVO
                            $capital = (float) $p['monto'];
                            $ganancia = $capital * ((float) $p['interes'] / 100);
                            $monto_total_prestamo = $capital + $ganancia;

                        } else {

                            // 📦 ARTEFACTO
                            $capital = (float) $p['precio_compra'];
                            $ganancia = (float) $p['precio_venta'] - (float) $p['precio_compra'];
                            $monto_total_prestamo = (float) $p['precio_venta'];
                        }

                        // Pagos realizados
                        $sqlPagos = "SELECT COALESCE(SUM(monto),0) AS pagado 
                 FROM pagos 
                 WHERE venta_id = " . intval($p['id']);
                        $resPagos = mysqli_query($conexion, $sqlPagos);
                        $pagadoRow = mysqli_fetch_assoc($resPagos);
                        $pagado = (float) $pagadoRow['pagado'];

                        $saldo = max(0, $monto_total_prestamo - $pagado);

                        // Acumular por cliente
                        $capital_total += $capital;
                        $interes_total += $ganancia;
                        $monto_total += $monto_total_prestamo;
                        $saldo_total += $saldo;

                        // Verificar vencimiento
                        $fecha_limite = date('Y-m-d', strtotime($p['fecha_venta'] . ' +' . $p['dias_credito'] . ' days'));
                        if (strtotime($fecha_limite) < time() && $saldo > 0) {
                            $estado_pago = "Vencido";
                        }
                    }


                    // Acumular totales generales
                    $total_capital += $capital_total;
                    $total_interes += $interes_total;
                    $total_monto += $monto_total;
                    $total_saldo += $saldo_total;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($c['dni']) ?></td>
                        <td><?= htmlspecialchars($c['nombre']) ?></td>
                        <td><?= $num_prestamos ?></td>
                        <td>S/ <?= number_format($capital_total, 2) ?></td>
                        <td>S/ <?= number_format($interes_total, 2) ?></td>
                        <td>S/ <?= number_format($monto_total, 2) ?></td>
                        <td>S/ <?= number_format($saldo_total, 2) ?></td>
                        <td>
                            <?php if ($estado_pago == "Vencido"): ?>
                                <span class="badge bg-danger">Vencido</span>
                            <?php else: ?>
                                <span class="badge bg-success">Vigente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- Totales generales -->
                <tr class="table-secondary fw-bold">
                    <td colspan="3" class="text-end">Totales:</td>
                    <td>S/ <?= number_format($total_capital, 2) ?></td>
                    <td>S/ <?= number_format($total_interes, 2) ?></td>
                    <td>S/ <?= number_format($total_monto, 2) ?></td>
                    <td>S/ <?= number_format($total_saldo, 2) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
</body>

</html>