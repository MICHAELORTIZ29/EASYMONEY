<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

// Verificar rol del usuario actual
$stmtRolD = mysqli_prepare($conexion, "SELECT rol FROM usuarios WHERE usuario = ?");
mysqli_stmt_bind_param($stmtRolD, 's', $_SESSION['usuario']);
mysqli_stmt_execute($stmtRolD);
$resRolD = mysqli_stmt_get_result($stmtRolD);
$rowRolD = mysqli_fetch_assoc($resRolD);
mysqli_stmt_close($stmtRolD);
$is_admin_dash = ($rowRolD && $rowRolD['rol'] === 'admin');

/* ================= FILTRO ================= */
$filtroSQL = "";

if (isset($_GET['tipo_filtro'])) {

    if ($_GET['tipo_filtro'] === 'mes' && !empty($_GET['mes'])) {
        $mes = $_GET['mes']; // YYYY-MM
        $filtroSQL = "WHERE DATE_FORMAT(v.fecha_venta,'%Y-%m') = '$mes'";
    }

    if ($_GET['tipo_filtro'] === 'rango' && !empty($_GET['desde']) && !empty($_GET['hasta'])) {
        $desde = $_GET['desde'];
        $hasta = $_GET['hasta'];
        $filtroSQL = "WHERE v.fecha_venta BETWEEN '$desde' AND '$hasta'";
    }
}

/* ================= CAPITAL INVERTIDO ================= */
$sqlCapital = "
SELECT SUM(
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_compra
        ELSE v.monto
    END
) AS total_capital
FROM ventas v
$filtroSQL
";

/* ================= GANANCIA TOTAL ================= */
$sqlGanancia = "
SELECT SUM(
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN (v.precio_venta - v.precio_compra)
        ELSE (v.monto * (v.interes / 100))
    END
) AS total_ganancia
FROM ventas v
$filtroSQL
";

/* ================= TOTAL PAGADO ================= */
$sqlPagado = "
SELECT SUM(p.monto) AS total_pagado
FROM pagos p
INNER JOIN ventas v ON v.id = p.venta_id
$filtroSQL
";

/* ================= TOTAL PENDIENTE ================= */
$sqlPendiente = "
SELECT SUM(
    (
        CASE 
            WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
            ELSE (v.monto + (v.monto * (v.interes/100)))
        END
    ) - IFNULL(pag.total_pagado,0)
) AS total_pendiente
FROM ventas v
LEFT JOIN (
    SELECT venta_id, SUM(monto) AS total_pagado
    FROM pagos
    GROUP BY venta_id
) pag ON v.id = pag.venta_id
$filtroSQL
";
/* ================= TOTAL MORA ================= */
$sqlMora = "
SELECT SUM(p.mora) AS total_mora
FROM pagos p
INNER JOIN ventas v ON v.id = p.venta_id
$filtroSQL
";
$totalMora = mysqli_fetch_assoc(mysqli_query($conexion, $sqlMora))['total_mora'] ?? 0;

/* ================= EJECUTAR ================= */
$totalCapital = mysqli_fetch_assoc(mysqli_query($conexion, $sqlCapital))['total_capital'] ?? 0;
$totalGanancia = mysqli_fetch_assoc(mysqli_query($conexion, $sqlGanancia))['total_ganancia'] ?? 0;
$totalGanancia += $totalMora; // La mora suma a ganancia
$totalPagado = mysqli_fetch_assoc(mysqli_query($conexion, $sqlPagado))['total_pagado'] ?? 0;
$totalPagado += $totalMora; // La mora suma al monto cobrado
$totalPendiente = mysqli_fetch_assoc(mysqli_query($conexion, $sqlPendiente))['total_pendiente'] ?? 0;

/* ================= INVERSION GENERAL ================= */
$sqlInversionGeneral = "SELECT SUM(monto) AS total_inversion FROM inversion_general";
$totalInversionGeneral = mysqli_fetch_assoc(mysqli_query($conexion, $sqlInversionGeneral))['total_inversion'] ?? 0;

/* ================= CAPITAL DISPONIBLE ================= */
$capitalDisponible = ($totalInversionGeneral - $totalCapital) + $totalPagado;
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Dashboard - Control Créditos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Control Créditos</span>
            <a href="logout.php" class="btn btn-outline-light">Salir</a>
        </div>
    </nav>

    <div class="container mt-4">

        <h3 class="text-center mb-3">Resumen General</h3>

        <!-- BOTÓN FILTRO -->
        <div class="text-center mb-3">
            <button class="btn btn-outline-secondary" onclick="toggleFiltro()">¿Quieres aplicar filtro?</button>
        </div>

        <!-- FILTRO -->
        <div id="filtroBox" class="card mb-4 shadow-sm" style="display:none;">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Tipo de filtro</label>
                        <select name="tipo_filtro" class="form-select" onchange="cambiarFiltro(this.value)" required>
                            <option value="">Seleccione</option>
                            <option value="mes">Por mes</option>
                            <option value="rango">Por fechas</option>
                        </select>
                    </div>

                    <div class="col-md-3" id="filtroMes" style="display:none;">
                        <label class="form-label">Mes</label>
                        <input type="month" name="mes" class="form-control">
                    </div>

                    <div class="col-md-3" id="filtroDesde" style="display:none;">
                        <label class="form-label">Desde</label>
                        <input type="date" name="desde" class="form-control">
                    </div>

                    <div class="col-md-3" id="filtroHasta" style="display:none;">
                        <label class="form-label">Hasta</label>
                        <input type="date" name="hasta" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <button class="btn btn-primary w-100">Aplicar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- TARJETAS -->
        <!-- TARJETAS -->
        <div class="row g-4">

            <div class="col-md-3">
                <?php if ($is_admin_dash): ?>
                <a href="kardex.php" class="text-decoration-none" title="Ver Kardex de movimientos">
                <?php endif; ?>
                <div class="card text-bg-info shadow h-100"
                     <?php if ($is_admin_dash): ?>
                     style="cursor:pointer; transition: transform 0.15s, box-shadow 0.15s;"
                     onmouseover="this.style.transform='scale(1.03)';this.style.boxShadow='0 6px 20px rgba(0,0,0,0.18)';"
                     onmouseout="this.style.transform='scale(1)';this.style.boxShadow='';"
                     <?php endif; ?>>
                    <div class="card-body">
                        <h6><i class="bi bi-journal-text me-1"></i>Capital Disponible</h6>
                        <p class="fs-4 mb-0">S/ <?= number_format($capitalDisponible, 2) ?></p>
                        <?php if ($is_admin_dash): ?>
                        <small class="opacity-75" style="font-size:0.72rem;">📋 Click para ver Kardex</small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($is_admin_dash): ?>
                </a>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <div class="card text-bg-primary shadow h-100">
                    <div class="card-body">
                        <h6>Capital Invertido</h6>
                        <p class="fs-4">S/ <?= number_format($totalCapital, 2) ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-bg-success shadow h-100">
                    <div class="card-body">
                        <h6>Ganancia Total</h6>
                        <p class="fs-4">S/ <?= number_format($totalGanancia, 2) ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-bg-warning shadow h-100">
                    <div class="card-body">
                        <h6>Monto Cobrado</h6>
                        <p class="fs-4">S/ <?= number_format($totalPagado, 2) ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-bg-danger shadow h-100">
                    <div class="card-body">
                        <h6>Monto Pendiente</h6>
                        <p class="fs-4">S/ <?= number_format($totalPendiente, 2) ?></p>
                    </div>
                </div>
            </div>

        </div>

        <div class="quick-actions">
            <h3 class="mb-3 text-center">Acciones Rápidas</h3>
            <div class="row justify-content-center g-3">
                <div class="col-md-3 d-grid"><a href="cliente_agregar.php" class="btn btn-primary btn-lg">Agregar
                        Cliente</a></div>
                <div class="col-md-3 d-grid"><a href="ventas_agregar.php" class="btn btn-success btn-lg">Registrar
                        Crédito</a></div>
                <div class="col-md-3 d-grid"><a href="pagos_gestionar.php" class="btn btn-warning btn-lg">Gestionar
                        Pagos</a></div>
                <div class="col-md-3 d-grid"><a href="clientes_listado.php" class="btn btn-info btn-lg">Listado
                        Clientes</a></div>
                <div class="col-md-3 d-grid"><a href="reporte_pagos.php" class="btn btn-dark btn-lg">Ver Reporte de
                        Pagos</a></div>
                <div class="col-md-3 d-grid"><a href="inversion_general.php" class="btn btn-secondary btn-lg"> Inversión
                        General
                    </a>
                </div>
            </div>
        </div>
    </div>


    </div>

    <script>
        function toggleFiltro() {
            const box = document.getElementById('filtroBox');
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
        }

        function cambiarFiltro(tipo) {
            document.getElementById('filtroMes').style.display = tipo === 'mes' ? 'block' : 'none';
            document.getElementById('filtroDesde').style.display = tipo === 'rango' ? 'block' : 'none';
            document.getElementById('filtroHasta').style.display = tipo === 'rango' ? 'block' : 'none';
        }
    </script>

</body>

</html>