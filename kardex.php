<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

// Solo admin puede ver el kardex
$stmtRol = mysqli_prepare($conexion, "SELECT rol FROM usuarios WHERE usuario = ?");
mysqli_stmt_bind_param($stmtRol, 's', $_SESSION['usuario']);
mysqli_stmt_execute($stmtRol);
$resRol = mysqli_stmt_get_result($stmtRol);
$rowRol = mysqli_fetch_assoc($resRol);
mysqli_stmt_close($stmtRol);

if (!$rowRol || $rowRol['rol'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

/* ================= FILTROS ================= */
// Usamos mysqli_real_escape_string porque MariaDB no admite
// prepared statements con ? dentro de subqueries con UNION ALL
$whereConditions = [];

if (!empty($_GET['usuario_filtro'])) {
    $uf = mysqli_real_escape_string($conexion, $_GET['usuario_filtro']);
    $whereConditions[] = "usuario_registro = '$uf'";
}

if (!empty($_GET['tipo_filtro'])) {
    if ($_GET['tipo_filtro'] === 'mes' && !empty($_GET['mes'])) {
        $mes = mysqli_real_escape_string($conexion, $_GET['mes']);
        $whereConditions[] = "DATE_FORMAT(fecha_mov, '%Y-%m') = '$mes'";
    }
    if ($_GET['tipo_filtro'] === 'rango' && !empty($_GET['desde']) && !empty($_GET['hasta'])) {
        $desde = mysqli_real_escape_string($conexion, $_GET['desde']);
        $hasta  = mysqli_real_escape_string($conexion, $_GET['hasta']);
        $whereConditions[] = "DATE(fecha_mov) BETWEEN '$desde' AND '$hasta'";
    }
}

$whereSQL = count($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

/* ================= OBTENER USUARIOS PARA FILTRO ================= */
$resUsuarios = mysqli_query($conexion, "SELECT usuario FROM usuarios ORDER BY usuario ASC");
$listaUsuarios = mysqli_fetch_all($resUsuarios, MYSQLI_ASSOC);

/* ================= QUERY KARDEX ================= */
// SALIDAS = préstamos registrados (ventas)
/* ================= QUERY KARDEX ================= */
// SALIDAS = préstamos registrados (ventas) + EGRESOS
// ENTRADAS = pagos recibidos de clientes
$sqlKardex = "
SELECT 
    v.fecha_venta                              AS fecha_mov,
    'salida'                                   AS tipo,
    u.usuario                                  AS usuario_registro,
    c.nombre                                   AS cliente_nombre,
    c.dni                                      AS cliente_dni,
    v.producto                                 AS concepto,
    v.tipo_venta                               AS tipo_venta,
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_compra
        ELSE v.monto
    END                                        AS monto,
    0                                          AS mora,
    v.id                                       AS ref_id,
    'venta'                                    AS ref_tipo,
    IFNULL(v.created_at, CONCAT(v.fecha_venta, ' 00:00:00')) AS created_at
FROM ventas v
INNER JOIN clientes c ON v.cliente_id = c.id
LEFT JOIN usuarios u ON u.usuario = IFNULL(v.usuario_registro, 'admin')

UNION ALL

SELECT 
    p.fecha_pago                               AS fecha_mov,
    'entrada'                                  AS tipo,
    u.usuario                                  AS usuario_registro,
    c.nombre                                   AS cliente_nombre,
    c.dni                                      AS cliente_dni,
    CONCAT(v.producto, ' — ', p.metodo_pago)   AS concepto,
    v.tipo_venta                               AS tipo_venta,
    p.monto                                    AS monto,
    IFNULL(p.mora, 0)                          AS mora,
    p.id                                       AS ref_id,
    'pago'                                     AS ref_tipo,
    IFNULL(p.created_at, CONCAT(p.fecha_pago, ' 00:00:01')) AS created_at
FROM pagos p
INNER JOIN ventas v ON p.venta_id = v.id
INNER JOIN clientes c ON v.cliente_id = c.id
LEFT JOIN usuarios u ON u.usuario = IFNULL(p.usuario_registro, 'admin')

UNION ALL

SELECT 
    e.fecha_egreso                             AS fecha_mov,
    'salida'                                   AS tipo,
    u.usuario                                  AS usuario_registro,
    'EGRESO'                                   AS cliente_nombre,
    ''                                         AS cliente_dni,
    CONCAT('EGRESO: ', e.nombre)               AS concepto,
    'egreso'                                   AS tipo_venta,
    e.monto                                    AS monto,
    0                                          AS mora,
    e.id                                       AS ref_id,
    'egreso'                                   AS ref_tipo,
    IFNULL(e.created_at, CONCAT(e.fecha_egreso, ' 00:00:00')) AS created_at
FROM egresos e
LEFT JOIN usuarios u ON u.usuario = IFNULL(e.usuario_registro, 'admin')
";

// Envolver en subquery para aplicar filtros
$sqlFinal = "
SELECT * FROM (
    $sqlKardex
) AS kardex
$whereSQL
ORDER BY created_at ASC, ref_tipo DESC, ref_id ASC
";

$result = mysqli_query($conexion, $sqlFinal);
if (!$result) {
    die("Error en la consulta: " . mysqli_error($conexion));
}
$movimientos = mysqli_fetch_all($result, MYSQLI_ASSOC);

/* ================= CALCULAR SALDO ACUMULADO ================= */
// Saldo inicial = inversión general total
$resInv = mysqli_query($conexion, "SELECT IFNULL(SUM(monto),0) AS total FROM inversion_general");
$saldoInicial = (float)(mysqli_fetch_assoc($resInv)['total'] ?? 0);

// Los datos vienen ASC (más antiguo primero), calculamos saldo correctamente
$saldoActual = $saldoInicial;
foreach ($movimientos as &$mov) {
    if ($mov['tipo'] === 'entrada') {
        $saldoActual += (float)$mov['monto'] + (float)$mov['mora'];
    } else {
        $saldoActual -= (float)$mov['monto'];
    }
    $mov['saldo_acum'] = $saldoActual;
}
unset($mov);

// Un solo reverse: el más reciente queda arriba
$movimientos = array_reverse($movimientos);

/* ================= TOTALES ================= */
$totalEntradas = array_sum(array_map(fn($m) => $m['tipo'] === 'entrada' ? (float)$m['monto'] + (float)$m['mora'] : 0, $movimientos));
$totalSalidas  = array_sum(array_map(fn($m) => $m['tipo'] === 'salida'  ? (float)$m['monto'] : 0, $movimientos));
$totalMoras    = array_sum(array_map(fn($m) => (float)$m['mora'], $movimientos));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Kardex - Control Créditos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; }

        .kardex-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(13,110,253,0.3);
        }

        .filtro-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        .resumen-cards .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .table-kardex thead th {
            background: #212529;
            color: white;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            vertical-align: middle;
            white-space: nowrap;
        }

        .table-kardex tbody tr:hover { background: #f0f4ff; }

        .badge-entrada {
            background: #d1fae5;
            color: #065f46;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        .badge-salida {
            background: #fee2e2;
            color: #991b1b;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
        }

        .monto-entrada { color: #16a34a; font-weight: 700; }
        .monto-salida  { color: #dc2626; font-weight: 700; }
        .monto-saldo   { color: #1d4ed8; font-weight: 700; }
        .monto-mora    { color: #d97706; font-size: 0.8rem; }

        .saldo-inicial-row td {
            background: #e0f2fe !important;
            font-weight: 700;
            font-style: italic;
            color: #0369a1;
        }

        .usuario-badge {
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .kardex-header { background: #0d6efd !important; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark no-print">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-arrow-left me-2"></i>Control Créditos
        </a>
        <button class="btn btn-outline-light btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimir
        </button>
    </div>
</nav>

<div class="container-fluid mt-4" style="max-width:1300px;">

    <!-- HEADER -->
    <div class="kardex-header d-flex justify-content-between align-items-center">
        <div>
            <h3 class="mb-1"><i class="bi bi-journal-text me-2"></i>Kardex de Movimientos</h3>
            <small class="opacity-75">Registro completo de entradas y salidas de capital</small>
        </div>
        <div class="text-end">
            <div class="fs-5 fw-bold">Saldo Base: S/ <?= number_format($saldoInicial, 2) ?></div>
            <small class="opacity-75">Inversión general registrada</small>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="filtro-card no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">👤 Usuario</label>
                <select name="usuario_filtro" class="form-select">
                    <option value="">Todos los usuarios</option>
                    <?php foreach ($listaUsuarios as $u): ?>
                        <option value="<?= htmlspecialchars($u['usuario']) ?>"
                            <?= (isset($_GET['usuario_filtro']) && $_GET['usuario_filtro'] === $u['usuario']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['usuario']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">📅 Tipo de fecha</label>
                <select name="tipo_filtro" class="form-select" id="tipoFiltro" onchange="cambiarFiltro(this.value)">
                    <option value="">Sin filtro</option>
                    <option value="mes" <?= (isset($_GET['tipo_filtro']) && $_GET['tipo_filtro']==='mes') ? 'selected':'' ?>>Por mes</option>
                    <option value="rango" <?= (isset($_GET['tipo_filtro']) && $_GET['tipo_filtro']==='rango') ? 'selected':'' ?>>Por rango</option>
                </select>
            </div>

            <div class="col-md-2" id="campoMes" style="display:none;">
                <label class="form-label fw-semibold">Mes</label>
                <input type="month" name="mes" class="form-control" value="<?= $_GET['mes'] ?? '' ?>">
            </div>

            <div class="col-md-2" id="campoDesde" style="display:none;">
                <label class="form-label fw-semibold">Desde</label>
                <input type="date" name="desde" class="form-control" value="<?= $_GET['desde'] ?? '' ?>">
            </div>

            <div class="col-md-2" id="campoHasta" style="display:none;">
                <label class="form-label fw-semibold">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="<?= $_GET['hasta'] ?? '' ?>">
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filtrar</button>
            </div>
            <div class="col-md-1">
                <a href="kardex.php" class="btn btn-outline-secondary w-100"><i class="bi bi-x"></i> Limpiar</a>
            </div>
        </form>
    </div>

    <!-- TARJETAS RESUMEN -->
    <div class="row g-3 mb-4 resumen-cards">
        <div class="col-md-3">
            <div class="card text-bg-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">Total Entradas</div>
                            <div class="fs-5 fw-bold">S/ <?= number_format($totalEntradas, 2) ?></div>
                            <div class="small opacity-75">Pagos de clientes <?= $totalMoras > 0 ? '(inc. moras)' : '' ?></div>
                        </div>
                        <i class="bi bi-arrow-down-circle fs-2 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-danger h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">Total Salidas</div>
                            <div class="fs-5 fw-bold">S/ <?= number_format($totalSalidas, 2) ?></div>
                            <div class="small opacity-75">Capital prestado</div>
                        </div>
                        <i class="bi bi-arrow-up-circle fs-2 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-warning h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">Total Moras</div>
                            <div class="fs-5 fw-bold">S/ <?= number_format($totalMoras, 2) ?></div>
                            <div class="small opacity-75">Cobros por mora</div>
                        </div>
                        <i class="bi bi-exclamation-circle fs-2 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-bg-primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small opacity-75">Saldo Final</div>
                            <div class="fs-5 fw-bold">S/ <?= number_format($saldoActual, 2) ?></div>
                            <div class="small opacity-75">Capital disponible actual</div>
                        </div>
                        <i class="bi bi-wallet2 fs-2 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLA KARDEX -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm table-kardex mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Cliente</th>
                            <th>DNI</th>
                            <th>Concepto / Producto</th>
                            <th>Tipo</th>
                            <th class="text-end">Entrada (S/)</th>
                            <th class="text-end">Salida (S/)</th>
                            <th class="text-end">Mora (S/)</th>
                            <th class="text-end">Saldo (S/)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- FILA SALDO INICIAL -->
                        <!-- <tr class="saldo-inicial-row">
                            <td colspan="10" class="text-center">
                                <i class="bi bi-bank me-1"></i> Saldo inicial — Inversión General
                            </td>
                            <td class="text-end">S/ <?= number_format($saldoInicial, 2) ?></td>
                        </tr> -->

                        <?php if (empty($movimientos)): ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                                    No hay movimientos en el período seleccionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movimientos as $i => $mov): ?>
                                <tr>
                                    <td class="text-muted" style="font-size:0.75rem;"><?= $i + 1 ?></td>
                                    <td style="white-space:nowrap;">
                                        <?= date('d/m/Y', strtotime($mov['fecha_mov'])) ?>
                                    </td>
                                    <td>
                                        <span class="usuario-badge">
                                            <i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($mov['usuario_registro'] ?? 'admin') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($mov['cliente_nombre']) ?></td>
                                    <td style="font-size:0.8rem;"><?= htmlspecialchars($mov['cliente_dni']) ?></td>
                                    <td><?= htmlspecialchars($mov['concepto']) ?></td>
                                    <td>
                                        <?php if ($mov['tipo'] === 'entrada'): ?>
                                            <span class="badge-entrada"><i class="bi bi-arrow-down-short"></i> Cobro</span>
                                        <?php else: ?>
                                            <span class="badge-salida"><i class="bi bi-arrow-up-short"></i> Préstamo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($mov['tipo'] === 'entrada'): ?>
                                            <span class="monto-entrada">S/ <?= number_format($mov['monto'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($mov['tipo'] === 'salida'): ?>
                                            <span class="monto-salida">S/ <?= number_format($mov['monto'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($mov['mora'] > 0): ?>
                                            <span class="monto-mora fw-bold">S/ <?= number_format($mov['mora'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="monto-saldo">S/ <?= number_format($mov['saldo_acum'], 2) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- FILA TOTALES -->
                            <tr style="background:#f8f9fa; font-weight:700; border-top: 3px solid #dee2e6;">
                                <td colspan="7" class="text-end">TOTALES</td>
                                <td class="text-end text-success">S/ <?= number_format($totalEntradas, 2) ?></td>
                                <td class="text-end text-danger">S/ <?= number_format($totalSalidas, 2) ?></td>
                                <td class="text-end" style="color:#d97706;">S/ <?= number_format($totalMoras, 2) ?></td>
                                <td class="text-end text-primary">S/ <?= number_format($saldoActual, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-muted small mt-3 no-print">
        <i class="bi bi-info-circle"></i>
        <strong>Entradas:</strong> pagos recibidos de clientes &nbsp;|&nbsp;
        <strong>Salidas:</strong> capital prestado &nbsp;|&nbsp;
        <strong>Saldo:</strong> calculado desde la inversión general acumulada
    </div>

</div>

<script>
    function cambiarFiltro(tipo) {
        document.getElementById('campoMes').style.display    = tipo === 'mes'   ? 'block' : 'none';
        document.getElementById('campoDesde').style.display  = tipo === 'rango' ? 'block' : 'none';
        document.getElementById('campoHasta').style.display  = tipo === 'rango' ? 'block' : 'none';
    }

    // Restaurar estado del filtro al cargar
    (function() {
        const tipo = document.getElementById('tipoFiltro').value;
        if (tipo) cambiarFiltro(tipo);
    })();
</script>

</body>
</html>
