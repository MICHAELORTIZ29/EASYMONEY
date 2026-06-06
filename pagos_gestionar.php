<?php
session_start();
require_once('tcpdf/tcpdf.php');
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

$mensaje = '';
$cliente_id = isset($_SESSION['current_cliente_id']) ? $_SESSION['current_cliente_id'] : null;
$cliente = null;
$ventas = [];
$pagos = [];
$ventasEfectivo = [];
$ventasArtefacto = [];


// === Obtener rol del usuario actual ===
$user_role = 'limitado';
if (isset($_SESSION['usuario'])) {
    $stmtR = mysqli_prepare($conexion, "SELECT rol FROM usuarios WHERE usuario = ?");
    mysqli_stmt_bind_param($stmtR, 's', $_SESSION['usuario']);
    mysqli_stmt_execute($stmtR);
    $resR = mysqli_stmt_get_result($stmtR);
    $rowR = mysqli_fetch_assoc($resR);
    if ($rowR && isset($rowR['rol'])) {
        $user_role = $rowR['rol'];
    }
    mysqli_stmt_close($stmtR);
}
$is_admin = ($user_role === 'admin');

// Obtener todos los clientes para la búsqueda
$sqlTodosClientes = "SELECT id, nombre, dni FROM clientes";
$resultTodosClientes = mysqli_query($conexion, $sqlTodosClientes);
$todosClientes = mysqli_fetch_all($resultTodosClientes, MYSQLI_ASSOC);

// Obtener clientes con deuda para el select
$sqlClientesDeuda = "SELECT c.id, c.nombre, c.dni 
                     FROM clientes c
                     INNER JOIN ventas v ON v.cliente_id = c.id
                     WHERE ( (v.monto + (v.monto * v.interes / 100)) - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id), 0)) > 0
                     GROUP BY c.id";
$resultClientesDeuda = mysqli_query($conexion, $sqlClientesDeuda);
$clientesDeuda = mysqli_fetch_all($resultClientesDeuda, MYSQLI_ASSOC);




if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['seleccionar_cliente'])) {
        $cliente_id = $_POST['cliente_id'];
        $_SESSION['current_cliente_id'] = $cliente_id;

        $sqlCliente = "SELECT * FROM clientes WHERE id = ?";
        $stmt = mysqli_prepare($conexion, $sqlCliente);
        mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $cliente = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($cliente) {
            /*cargarDatosCliente($conexion, $cliente_id, $ventas, $pagos);*/
        } else {
            $mensaje = "Cliente no encontrado.";
            unset($_SESSION['current_cliente_id']);
        }
    } elseif (isset($_POST['buscar_cliente'])) {
        $busqueda = trim($_POST['busqueda']);
        $sqlCliente = "SELECT * FROM clientes WHERE dni = ? OR nombre LIKE ?";
        $stmt = mysqli_prepare($conexion, $sqlCliente);
        $likeBusqueda = "%$busqueda%";
        mysqli_stmt_bind_param($stmt, 'ss', $busqueda, $likeBusqueda);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $cliente = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($cliente) {
            $cliente_id = $cliente['id'];
            $_SESSION['current_cliente_id'] = $cliente_id;
            /*cargarDatosCliente($conexion, $cliente_id, $ventas, $pagos);*/
        } else {
            $mensaje = "Cliente no encontrado.";
            unset($_SESSION['current_cliente_id']);
        }
    } elseif (isset($_POST['agregar_pago'])) {
        // Permitir a todos los roles agregar pagos
        $venta_id = $_POST['venta_id'];
        $fecha_pago = $_POST['fecha_pago'];
        $metodo_pago = $_POST['metodo_pago'];
        $monto_pago = $_POST['monto_pago'];
        $monto_mora = isset($_POST['monto_mora']) && $_POST['monto_mora'] > 0 ? floatval($_POST['monto_mora']) : 0;

        if ($venta_id && $fecha_pago && $metodo_pago && $monto_pago > 0) {
            $sqlDeuda = "
SELECT 
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
        ELSE (v.monto + (v.monto * v.interes/100))
    END
    - IFNULL((SELECT SUM(monto) FROM pagos WHERE venta_id = v.id),0) AS deuda
FROM ventas v
WHERE v.id = ?
";

            $stmtD = mysqli_prepare($conexion, $sqlDeuda);
            mysqli_stmt_bind_param($stmtD, 'i', $venta_id);
            mysqli_stmt_execute($stmtD);
            $resD = mysqli_stmt_get_result($stmtD);
            $rowD = mysqli_fetch_assoc($resD);
            mysqli_stmt_close($stmtD);

            if ($rowD && $monto_pago <= $rowD['deuda']) {
                $usuario_registro = $_SESSION['usuario'];
                $sqlInsertPago = "INSERT INTO pagos (venta_id, fecha_pago, metodo_pago, monto, mora, usuario_registro) VALUES (?, ?, ?, ?, ?, ?)";
                $stmtI = mysqli_prepare($conexion, $sqlInsertPago);
                mysqli_stmt_bind_param($stmtI, 'issdds', $venta_id, $fecha_pago, $metodo_pago, $monto_pago, $monto_mora, $usuario_registro);


                if (mysqli_stmt_execute($stmtI)) {
                    $mensaje = "Pago registrado correctamente.";

                    $sqlClienteVenta = "SELECT cliente_id FROM ventas WHERE id = ?";
                    $stmtCV = mysqli_prepare($conexion, $sqlClienteVenta);
                    mysqli_stmt_bind_param($stmtCV, 'i', $venta_id);
                    mysqli_stmt_execute($stmtCV);
                    $resCV = mysqli_stmt_get_result($stmtCV);
                    $rowCV = mysqli_fetch_assoc($resCV);
                    mysqli_stmt_close($stmtCV);

                    if ($rowCV) {
                        $cliente_id = $rowCV['cliente_id'];
                        $_SESSION['current_cliente_id'] = $cliente_id;
                        /*cargarDatosCliente($conexion, $cliente_id, $ventas, $pagos);*/
                    }
                } else {
                    $mensaje = "Error al registrar el pago: " . mysqli_error($conexion);
                }
                mysqli_stmt_close($stmtI);
            } else {
                $mensaje = "El monto del pago no puede ser mayor que la deuda.";
            }
        } else {
            $mensaje = "Complete todos los campos para registrar el pago.";
        }
    } elseif (isset($_POST['editar_pago'])) {
        // Sólo admin puede editar pagos
        if (!$is_admin) {
            $mensaje = "No tienes permisos para editar pagos.";
        } else {
            $pago_id = $_POST['pago_id'];
            $fecha_pago = $_POST['fecha_pago'];
            $metodo_pago = $_POST['metodo_pago'];
            $monto_pago = $_POST['monto_pago'];

            if ($pago_id && $fecha_pago && $metodo_pago && $monto_pago > 0) {
                $sqlVentaPago = "SELECT venta_id FROM pagos WHERE id = ?";
                $stmtVP = mysqli_prepare($conexion, $sqlVentaPago);
                mysqli_stmt_bind_param($stmtVP, 'i', $pago_id);
                mysqli_stmt_execute($stmtVP);
                $resVP = mysqli_stmt_get_result($stmtVP);
                $rowVP = mysqli_fetch_assoc($resVP);
                mysqli_stmt_close($stmtVP);

                if ($rowVP) {
                    $venta_id = $rowVP['venta_id'];

                    $sqlDeuda = "
SELECT 
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
        ELSE (v.monto + (v.monto * v.interes/100))
    END AS monto_total,
    (SELECT IFNULL(SUM(monto),0) FROM pagos WHERE venta_id = ? AND id != ?) AS suma_pagos
FROM ventas v
WHERE v.id = ?

";

                    $stmtD = mysqli_prepare($conexion, $sqlDeuda);
                    mysqli_stmt_bind_param($stmtD, 'iii', $venta_id, $pago_id, $venta_id);
                    mysqli_stmt_execute($stmtD);
                    $resD = mysqli_stmt_get_result($stmtD);
                    $rowD = mysqli_fetch_assoc($resD);
                    mysqli_stmt_close($stmtD);

                    if ($rowD) {
                        $deuda_actual = $rowD['monto_total'] - $rowD['suma_pagos'];
                        if ($monto_pago <= $deuda_actual) {
                            $sqlUpdate = "UPDATE pagos SET fecha_pago = ?, metodo_pago = ?, monto = ? WHERE id = ?";
                            $stmtU = mysqli_prepare($conexion, $sqlUpdate);
                            mysqli_stmt_bind_param($stmtU, 'ssdi', $fecha_pago, $metodo_pago, $monto_pago, $pago_id);
                            if (mysqli_stmt_execute($stmtU)) {
                                $mensaje = "Pago actualizado correctamente.";

                                $sqlClienteVenta = "SELECT cliente_id FROM ventas WHERE id = ?";
                                $stmtCV = mysqli_prepare($conexion, $sqlClienteVenta);
                                mysqli_stmt_bind_param($stmtCV, 'i', $venta_id);
                                mysqli_stmt_execute($stmtCV);
                                $resCV = mysqli_stmt_get_result($stmtCV);
                                $rowCV = mysqli_fetch_assoc($resCV);
                                mysqli_stmt_close($stmtCV);

                                if ($rowCV) {
                                    $cliente_id = $rowCV['cliente_id'];
                                    $_SESSION['current_cliente_id'] = $cliente_id;
                                    /*  cargarDatosCliente($conexion, $cliente_id, $ventas, $pagos);*/
                                }
                            } else {
                                $mensaje = "Error al actualizar el pago: " . mysqli_error($conexion);
                            }
                            mysqli_stmt_close($stmtU);
                        } else {
                            $mensaje = "El monto del pago no puede ser mayor que la deuda actual.";
                        }
                    } else {
                        $mensaje = "Pago o venta no encontrados.";
                    }
                } else {
                    $mensaje = "Pago no encontrado.";
                }
            } else {
                $mensaje = "Complete todos los campos para actualizar el pago.";
            }
        }
    } elseif (isset($_POST['eliminar_pago'])) {
        // Sólo admin puede eliminar pagos
        if (!$is_admin) {
            $mensaje = "No tienes permisos para eliminar pagos.";
        } else {
            $pago_id = $_POST['pago_id'];
            $sqlDel = "DELETE FROM pagos WHERE id = ?";
            $stmtDel = mysqli_prepare($conexion, $sqlDel);
            mysqli_stmt_bind_param($stmtDel, 'i', $pago_id);
            if (mysqli_stmt_execute($stmtDel)) {
                $mensaje = "Pago eliminado correctamente.";
            } else {
                $mensaje = "Error al eliminar el pago: " . mysqli_error($conexion);
            }
            mysqli_stmt_close($stmtDel);
        }
    }
} elseif ($cliente_id) {
    $sqlCliente = "SELECT * FROM clientes WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sqlCliente);
    mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $cliente = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($cliente) {
        /*cargarDatosCliente($conexion, $cliente_id, $ventas, $pagos);*/
    } else {
        unset($_SESSION['current_cliente_id']);
    }
}








/* ================= ARTEFACTO ================= */
$sqlArtefacto = "
    SELECT v.id, v.producto, v.precio_venta, v.fecha_venta, v.dias_credito,
        IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0) AS total_pagado,
        (v.precio_venta - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) AS deuda
    FROM ventas v
    WHERE v.cliente_id = ?
    AND v.tipo_venta = 'artefacto'
    HAVING deuda > 0
    ";
$stmt = mysqli_prepare($conexion, $sqlArtefacto);
mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$ventasArtefacto = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

foreach ($ventasArtefacto as &$v) {
    $limite = date('Y-m-d', strtotime($v['fecha_venta'] . ' + ' . $v['dias_credito'] . ' days'));
    $v['dias_retraso'] = max(0, floor((strtotime(date('Y-m-d')) - strtotime($limite)) / 86400));
}
unset($v);


/* ================= EFECTIVO ================= */
$sqlEfectivo = "
    SELECT 
        v.id,
        v.producto,
        v.monto,
        v.interes,
        v.fecha_venta,
        v.dias_credito,
        (v.monto + (v.monto * v.interes / 100)) AS monto_total,
        IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0) AS total_pagado,
        ((v.monto + (v.monto * v.interes / 100)) - 
         IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) AS deuda
    FROM ventas v
    WHERE v.cliente_id = ?
      AND v.tipo_venta = 'efectivo'
    HAVING deuda > 0
";

$stmt = mysqli_prepare($conexion, $sqlEfectivo);
mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$ventasEfectivo = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

foreach ($ventasEfectivo as &$v) {
    $limite = date('Y-m-d', strtotime($v['fecha_venta'] . ' + ' . $v['dias_credito'] . ' days'));
    $v['dias_retraso'] = max(0, floor((strtotime(date('Y-m-d')) - strtotime($limite)) / 86400));
}
unset($v);


/* ================= PAGOS ================= */
$sqlPagos = "
SELECT 
    p.id,
    p.fecha_pago,
    p.metodo_pago,
    p.monto,
    IFNULL(p.mora, 0) AS mora,
    v.producto,
    v.tipo_venta,
    CASE 
        WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
        ELSE (v.monto + (v.monto * v.interes / 100))
    END AS monto_total
FROM pagos p
INNER JOIN ventas v ON p.venta_id = v.id
WHERE v.cliente_id = ?
ORDER BY p.fecha_pago DESC
";

$stmt = mysqli_prepare($conexion, $sqlPagos);
mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
mysqli_stmt_execute($stmt);
$pagos = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);



?>

<!-- AQUI SIGUE EL HTML TAL COMO LO TENÍAS, SOLO CAMBIAR LA PARTE DE LA TABLA DE VENTAS -->



<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gestión de Pagos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .table-responsive {
            overflow-x: auto;
        }

        .deuda-pendiente {
            color: rgb(255, 17, 41);
            font-weight: bold;
        }

        .retraso {
            color: #dc3545;
            font-weight: bold;
        }

        .search-container {
            position: relative;
        }

        .search-options {
            position: absolute;
            z-index: 1000;
            width: 100%;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid #ddd;
            border-radius: 0 0 5px 5px;
            display: none;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .search-option {
            padding: 8px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .search-option:hover {
            background-color: #f8f9fa;
        }

        .search-option.highlight {
            background-color: #e9ecef;
        }

        .form-section {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
    </style>
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

    <div class="container mt-4" style="max-width: 1200px;">
        <h2 class="mb-4">Gestión de Pagos</h2>

        <?php if ($mensaje): ?>
            <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <!-- Sección de búsqueda -->
        <div class="form-section">
            <h5 class="mb-3">Buscar Cliente</h5>

            <!-- Búsqueda por texto -->
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <div class="search-container">
                        <form method="post" id="formBusqueda">
                            <label for="busqueda" class="form-label">Buscar por DNI o Nombre</label>
                            <input type="text" id="busqueda" name="busqueda" class="form-control"
                                placeholder="Escriba DNI o nombre del cliente"
                                value="<?= isset($_POST['busqueda']) ? htmlspecialchars($_POST['busqueda']) : '' ?>"
                                autocomplete="off">
                            <div class="search-options" id="opcionesBusqueda"></div>
                        </form>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" form="formBusqueda" name="buscar_cliente" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Buscar
                    </button>
                </div>
            </div>


        </div>

        <?php if ($cliente): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Cliente: <?= htmlspecialchars($cliente['nombre']) ?> (DNI: <?= htmlspecialchars($cliente['dni']) ?>)
                </h4>
                <div>
                    <a href="exportar_pdf.php?cliente_id=<?= $cliente_id ?>" target="_blank" class="btn btn-danger btn-sm">
                        <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
                    </a>
                    <a href="exportar_excel.php?cliente_id=<?= $cliente_id ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-excel"></i> Exportar Excel
                    </a>

                </div>
            </div>





            <h5 class="mt-4">💵 Préstamos en Efectivo</h5>

            <?php if (count($ventasEfectivo) === 0): ?>
                <div class="alert alert-success">No hay préstamos en efectivo pendientes.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Fecha</th>
                                <th>Días Crédito</th>
                                <th>Retraso</th>
                                <th>Total</th>
                                <th>Pagado</th>
                                <th>Deuda</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasEfectivo as $v): ?>
                                <tr>
                                    <td><?= htmlspecialchars($v['producto']) ?></td>
                                    <td><?= $v['fecha_venta'] ?></td>
                                    <td><?= $v['dias_credito'] ?></td>
                                    <td class="<?= $v['dias_retraso'] > 0 ? 'retraso' : '' ?>"><?= $v['dias_retraso'] ?></td>
                                    <td><?= number_format($v['monto_total'], 2) ?></td>
                                    <td><?= number_format($v['total_pagado'], 2) ?></td>
                                    <td class="deuda-pendiente"><?= number_format($v['deuda'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-success btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#modalAgregarPago" data-ventaid="<?= $v['id'] ?>"
                                            data-producto="<?= htmlspecialchars($v['producto']) ?>" data-deuda="<?= $v['deuda'] ?>">
                                            <i class="bi bi-cash-coin"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>




            <h5 class="mt-5">📦 Préstamos por Artefacto</h5>

            <?php if (count($ventasArtefacto) === 0): ?>
                <div class="alert alert-success">No hay artefactos pendientes.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Producto</th>
                                <th>Fecha</th>
                                <th>Días Crédito</th>
                                <th>Retraso</th>
                                <th>Precio Venta</th>
                                <th>Pagado</th>
                                <th>Deuda</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasArtefacto as $v): ?>
                                <tr>
                                    <td><?= htmlspecialchars($v['producto']) ?></td>
                                    <td><?= $v['fecha_venta'] ?></td>
                                    <td><?= $v['dias_credito'] ?></td>
                                    <td class="<?= $v['dias_retraso'] > 0 ? 'retraso' : '' ?>"><?= $v['dias_retraso'] ?></td>
                                    <td><?= number_format($v['precio_venta'], 2) ?></td>
                                    <td><?= number_format($v['total_pagado'], 2) ?></td>
                                    <td class="deuda-pendiente"><?= number_format($v['deuda'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-success btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#modalAgregarPago" data-ventaid="<?= $v['id'] ?>"
                                            data-producto="<?= htmlspecialchars($v['producto']) ?>" data-deuda="<?= $v['deuda'] ?>">
                                            <i class="bi bi-cash-coin"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>












            <h4 class="mt-4">📜 Historial de pagos</h4>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Producto</th>
                            <th>Tipo</th>
                            <th>Fecha</th>
                            <th>Método</th>
                            <th>Monto (S/)</th>
                            <th>Mora (S/)</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($pagos) === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">
                                    No hay pagos registrados
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagos as $pago): ?>
                                <tr>
                                    <td><?= htmlspecialchars($pago['producto']) ?></td>
                                    <td>
                                        <?= $pago['tipo_venta'] === 'artefacto' ? '📦 Artefacto' : '💵 Efectivo' ?>
                                    </td>
                                    <td><?= htmlspecialchars($pago['fecha_pago']) ?></td>
                                    <td><?= htmlspecialchars($pago['metodo_pago']) ?></td>
                                    <td><?= number_format($pago['monto'], 2) ?></td>
                                    <td><?= $pago['mora'] > 0 ? '<span class="text-danger fw-bold">S/ ' . number_format($pago['mora'], 2) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-nowrap">

                                        <!-- IMPRIMIR (TODOS) -->
                                        <button class="btn btn-secondary btn-sm btnImprimirPago" data-pagoid="<?= $pago['id'] ?>">
                                            <i class="bi bi-printer"></i>
                                        </button>

                                        <?php if ($is_admin): ?>
                                            <!-- EDITAR -->
                                            <button class="btn btn-warning btn-sm" data-bs-toggle="modal"
                                                data-bs-target="#modalEditarPago" data-pagoid="<?= $pago['id'] ?>"
                                                data-fechapago="<?= $pago['fecha_pago'] ?>"
                                                data-metodopago="<?= $pago['metodo_pago'] ?>" data-montopago="<?= $pago['monto'] ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <!-- ELIMINAR -->
                                            <form method="post" style="display:inline-block"
                                                onsubmit="return confirm('¿Eliminar este pago?');">
                                                <input type="hidden" name="pago_id" value="<?= $pago['id'] ?>">
                                                <button type="submit" name="eliminar_pago" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>

        <?php endif; ?>
    </div>

    <!-- Modal Agregar Pago -->
    <div class="modal fade" id="modalAgregarPago" tabindex="-1" aria-labelledby="modalAgregarPagoLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" id="formAgregarPago" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalAgregarPagoLabel">Agregar Pago</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="venta_id" id="venta_id_agregar" required>

                    <div class="mb-3">
                        <label for="producto_agregar" class="form-label">Producto</label>
                        <input type="text" class="form-control" id="producto_agregar" disabled>
                    </div>

                    <div class="mb-3">
                        <label for="fecha_pago" class="form-label">Fecha de Pago</label>
                        <input type="date" class="form-control" name="fecha_pago" required value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="metodo_pago" class="form-label">Método de Pago</label>
                        <select class="form-select" name="metodo_pago" required>
                            <option value="">--Seleccione método--</option>
                            <option value="yape">Yape</option>
                            <option value="plin">Plin</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="monto_pago" class="form-label">Monto a pagar (S/)</label>

                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_pago"
                            id="monto_pago" required inputmode="decimal">

                        <small id="deudaDisponible" class="form-text text-muted"></small>
                    </div>

                    <!-- MORA -->
                    <div class="mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="checkMora" onchange="toggleMora(this)">
                            <label class="form-check-label" for="checkMora">¿Agregas mora?</label>
                        </div>
                    </div>
                    <div class="mb-3" id="campoMora" style="display:none;">
                        <label for="monto_mora" class="form-label">Monto de mora (S/)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_mora"
                            id="monto_mora" inputmode="decimal" placeholder="0.00">
                        <small class="form-text text-warning">⚠️ La mora es un cobro extra y no reduce la deuda principal.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="agregar_pago" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Registrar Pago
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Editar Pago -->
    <div class="modal fade" id="modalEditarPago" tabindex="-1" aria-labelledby="modalEditarPagoLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" id="formEditarPago" class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="modalEditarPagoLabel">Editar Pago</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="pago_id" id="pago_id_editar" required>

                    <div class="mb-3">
                        <label for="fecha_pago_editar" class="form-label">Fecha de Pago</label>
                        <input type="date" class="form-control" name="fecha_pago" id="fecha_pago_editar" required>
                    </div>

                    <div class="mb-3">
                        <label for="metodo_pago_editar" class="form-label">Método de Pago</label>
                        <select class="form-select" name="metodo_pago" id="metodo_pago_editar" required>
                            <option value="">--Seleccione método--</option>
                            <option value="yape">Yape</option>
                            <option value="plin">Plin</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="monto_pago_editar" class="form-label">Monto a pagar (S/)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_pago"
                            id="monto_pago_editar" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="editar_pago" class="btn btn-warning">
                        <i class="bi bi-save"></i> Guardar Cambios
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>





    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Variables para el control del dropdown
        let currentHighlight = -1;
        const clientesData = [
            <?php foreach ($todosClientes as $cliente): ?>
                                                                            { id: '<?= $cliente['id'] ?>', nombre: '<?= addslashes($cliente['nombre']) ?>', dni: '<?= $cliente['dni'] ?>' },
            <?php endforeach; ?>
        ];

        // Filtrado local de clientes mientras escribe
        document.getElementById('busqueda').addEventListener('input', function () {
            const busqueda = this.value.toLowerCase();
            const opciones = document.getElementById('opcionesBusqueda');
            opciones.innerHTML = '';
            currentHighlight = -1;

            if (busqueda.length < 2) {
                opciones.style.display = 'none';
                return;
            }

            // Filtrar localmente
            const resultados = clientesData.filter(cliente =>
                cliente.nombre.toLowerCase().includes(busqueda) ||
                cliente.dni.includes(busqueda)
            );

            // Mostrar resultados
            if (resultados.length > 0) {
                resultados.forEach((cliente, index) => {
                    const opcion = document.createElement('div');
                    opcion.className = 'search-option';
                    opcion.textContent = `${cliente.nombre} (${cliente.dni})`;
                    opcion.dataset.id = cliente.id;
                    opcion.dataset.nombre = cliente.nombre;
                    opcion.dataset.dni = cliente.dni;

                    opcion.addEventListener('click', function () {
                        document.getElementById('busqueda').value = cliente.nombre;
                        opciones.style.display = 'none';
                    });

                    opciones.appendChild(opcion);
                });
                opciones.style.display = 'block';
            } else {
                opciones.style.display = 'none';
            }
        });

        // Navegación con teclado en el dropdown
        document.getElementById('busqueda').addEventListener('keydown', function (e) {
            const opciones = document.getElementById('opcionesBusqueda');
            const items = opciones.getElementsByClassName('search-option');

            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (currentHighlight < items.length - 1) {
                    currentHighlight++;
                    updateHighlight(items);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (currentHighlight > 0) {
                    currentHighlight--;
                    updateHighlight(items);
                }
            } else if (e.key === 'Enter' && currentHighlight >= 0) {
                e.preventDefault();
                items[currentHighlight].click();
            }
        });

        function updateHighlight(items) {
            for (let i = 0; i < items.length; i++) {
                items[i].classList.toggle('highlight', i === currentHighlight);
                if (i === currentHighlight) {
                    items[i].scrollIntoView({ block: 'nearest' });
                }
            }
        }

        // Cerrar el dropdown al hacer clic fuera
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.search-container')) {
                document.getElementById('opcionesBusqueda').style.display = 'none';
            }
        });

        // Llenar modal agregar pago con datos de la venta
        const modalAgregarPago = document.getElementById('modalAgregarPago');
        modalAgregarPago.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const ventaId = button.getAttribute('data-ventaid');
            const producto = button.getAttribute('data-producto');
            const deuda = button.getAttribute('data-deuda');

            document.getElementById('venta_id_agregar').value = ventaId;
            document.getElementById('producto_agregar').value = producto;
            document.getElementById('monto_pago').max = deuda;
            document.getElementById('deudaDisponible').textContent = "Deuda disponible: S/ " + parseFloat(deuda).toFixed(2);
            document.getElementById('monto_pago').value = parseFloat(deuda).toFixed(2);

            // Resetear mora
            document.getElementById('checkMora').checked = false;
            document.getElementById('campoMora').style.display = 'none';
            document.getElementById('monto_mora').value = '';
        });

        // Llenar modal editar pago con datos
        const modalEditarPago = document.getElementById('modalEditarPago');
        modalEditarPago.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const pagoId = button.getAttribute('data-pagoid');
            const fechaPago = button.getAttribute('data-fechapago');
            const metodoPago = button.getAttribute('data-metodopago');
            const montoPago = button.getAttribute('data-montopago');

            document.getElementById('pago_id_editar').value = pagoId;
            document.getElementById('fecha_pago_editar').value = fechaPago;
            document.getElementById('metodo_pago_editar').value = metodoPago;
            document.getElementById('monto_pago_editar').value = montoPago;
        });

        // Mostrar mensajes con SweetAlert
        <?php if ($mensaje): ?>
            Swal.fire({
                icon: '<?= strpos($mensaje, "Error") !== false ? "error" : (strpos($mensaje, "eliminado") !== false ? "warning" : "success") ?>',
                title: '<?= strpos($mensaje, "Error") !== false ? "Error" : "Éxito" ?>',
                text: '<?= addslashes($mensaje) ?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        <?php endif; ?>

        // Modal para imprimir
        document.querySelectorAll('.btnImprimirPago').forEach(button => {
            button.addEventListener('click', function () {
                const pagoId = this.getAttribute('data-pagoid');

                Swal.fire({
                    title: 'Imprimir Voucher',
                    text: '¿Generar comprobante de pago?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Imprimir',
                    cancelButtonText: 'Cancelar',
                    customClass: {
                        confirmButton: 'btn btn-success',
                        cancelButton: 'btn btn-danger'
                    },
                    buttonsStyling: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.open(`generar_voucher_pago.php?pago_id=${pagoId}`, '_blank');
                    }
                });
            });
        });
    </script>
    <script>
const inputMonto = document.getElementById('monto_pago');

inputMonto.addEventListener('blur', () => {
    if (inputMonto.value !== '') {
        let valor = parseFloat(inputMonto.value);
        if (!isNaN(valor)) {
            // Elimina ceros innecesarios
            inputMonto.value = valor.toString();
        }
    }
});

function toggleMora(checkbox) {
    const campo = document.getElementById('campoMora');
    campo.style.display = checkbox.checked ? 'block' : 'none';
    if (!checkbox.checked) {
        document.getElementById('monto_mora').value = '';
    }
}
</script>
</body>

</html>