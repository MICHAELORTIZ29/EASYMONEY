<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

// Inicializar variables
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin    = $_GET['fecha_fin'] ?? '';

$where = '';
$params = [];

// Construir el WHERE dinámico
if ($fecha_inicio && $fecha_fin) {
    $where = "WHERE p.fecha_pago BETWEEN ? AND ?";
    $params = [$fecha_inicio, $fecha_fin];
} elseif ($fecha_inicio) {
    $where = "WHERE p.fecha_pago >= ?";
    $params = [$fecha_inicio];
} elseif ($fecha_fin) {
    $where = "WHERE p.fecha_pago <= ?";
    $params = [$fecha_fin];
}

// Consulta con joins
$sql = "
    SELECT p.id, p.fecha_pago, p.monto, c.nombre AS cliente, v.producto
    FROM pagos p
    INNER JOIN ventas v ON p.venta_id = v.id
    INNER JOIN clientes c ON v.cliente_id = c.id
    $where
    ORDER BY p.fecha_pago DESC
";

$stmt = mysqli_prepare($conexion, $sql);

// Vincular parámetros dinámicamente
if ($params) {
    if (count($params) === 2) {
        mysqli_stmt_bind_param($stmt, "ss", $params[0], $params[1]);
    } else {
        mysqli_stmt_bind_param($stmt, "s", $params[0]);
    }
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$pagos = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de Pagos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">Control Créditos</a>
        <div class="d-flex">
            <a href="logout.php" class="btn btn-outline-light">Salir</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h2 class="mb-4 text-center">Reporte de Pagos</h2>

    <!-- Formulario de filtros -->
    <form method="get" class="row g-3 mb-4">
        <div class="col-md-4">
            <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" class="form-control">
        </div>
        <div class="col-md-4">
            <label for="fecha_fin" class="form-label">Fecha Fin</label>
            <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" class="form-control">
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>

    <!-- Resultados -->
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Producto</th>
                    <th>Fecha de Pago</th>
                    <th>Monto (S/)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totalGeneral = 0;
                if ($pagos): 
                    foreach ($pagos as $pago): 
                        $totalGeneral += $pago['monto'];
                ?>
                        <tr>
                            <td><?= $pago['id'] ?></td>
                            <td><?= htmlspecialchars($pago['cliente']) ?></td>
                            <td><?= htmlspecialchars($pago['producto']) ?></td>
                            <td><?= htmlspecialchars($pago['fecha_pago']) ?></td>
                            <td><?= number_format($pago['monto'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">No se encontraron pagos en este rango de fechas.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if ($pagos): ?>
            <tfoot>
                <tr class="table-secondary">
                    <th colspan="4" class="text-end">TOTAL:</th>
                    <th>S/ <?= number_format($totalGeneral, 2) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <a href="dashboard.php" class="btn btn-secondary mt-3">Volver al Dashboard</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
