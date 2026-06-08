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

// Si se pasó cliente_id por GET, actualizar sesión
if (isset($_GET['cliente_id']) && $_GET['cliente_id'] > 0) {
    $cliente_id = (int)$_GET['cliente_id'];
    $_SESSION['current_cliente_id'] = $cliente_id;
}

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
$sqlTodosClientes = "SELECT id, nombre, dni FROM clientes ORDER BY nombre";
$resultTodosClientes = mysqli_query($conexion, $sqlTodosClientes);
$todosClientes = mysqli_fetch_all($resultTodosClientes, MYSQLI_ASSOC);

// Obtener todos los usuarios para el select de edición (solo admin)
$usuarios = [];
if ($is_admin) {
    $sqlUsuarios = "SELECT usuario FROM usuarios ORDER BY usuario";
    $resultUsuarios = mysqli_query($conexion, $sqlUsuarios);
    $usuarios = mysqli_fetch_all($resultUsuarios, MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['buscar_cliente'])) {
        $busqueda = trim($_POST['busqueda']);
        if (!empty($busqueda)) {
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
                $mensaje = "Cliente encontrado: " . $cliente['nombre'];
            } else {
                $mensaje = "Cliente no encontrado.";
                unset($_SESSION['current_cliente_id']);
                $cliente_id = null;
            }
        } else {
            $mensaje = "Ingrese un término de búsqueda.";
        }
        
    } elseif (isset($_POST['agregar_pago'])) {
        $venta_id = (int)$_POST['venta_id'];
        $fecha_pago = $_POST['fecha_pago'];
        $metodo_pago = $_POST['metodo_pago'];
        $monto_pago = floatval($_POST['monto_pago']);
        $monto_mora = isset($_POST['monto_mora']) && $_POST['monto_mora'] > 0 ? floatval($_POST['monto_mora']) : 0;
        
        if ($venta_id > 0 && $fecha_pago && $metodo_pago && $monto_pago > 0) {
            
            // Obtener el cliente_id de la venta ANTES de insertar el pago
            $sqlGetCliente = "SELECT cliente_id FROM ventas WHERE id = ?";
            $stmtGet = mysqli_prepare($conexion, $sqlGetCliente);
            mysqli_stmt_bind_param($stmtGet, 'i', $venta_id);
            mysqli_stmt_execute($stmtGet);
            $resGet = mysqli_stmt_get_result($stmtGet);
            $rowGet = mysqli_fetch_assoc($resGet);
            mysqli_stmt_close($stmtGet);
            
            if ($rowGet) {
                $cliente_id_venta = $rowGet['cliente_id'];
                
                // Verificar deuda actual
                $sqlDeuda = "
                    SELECT 
                        CASE 
                            WHEN v.tipo_venta = 'artefacto' THEN v.precio_venta
                            ELSE (v.monto + (v.monto * v.interes/100))
                        END AS monto_total,
                        IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id), 0) AS suma_pagos
                    FROM ventas v
                    WHERE v.id = ?
                ";
                
                $stmtD = mysqli_prepare($conexion, $sqlDeuda);
                mysqli_stmt_bind_param($stmtD, 'i', $venta_id);
                mysqli_stmt_execute($stmtD);
                $resD = mysqli_stmt_get_result($stmtD);
                $rowD = mysqli_fetch_assoc($resD);
                mysqli_stmt_close($stmtD);
                
                if ($rowD) {
                    $deuda_actual = $rowD['monto_total'] - $rowD['suma_pagos'];
                    if ($monto_pago <= $deuda_actual + 0.01) {
                        $usuario_registro = $_SESSION['usuario'];
                        
                        $sqlInsertPago = "INSERT INTO pagos (venta_id, fecha_pago, metodo_pago, monto, mora, usuario_registro) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmtI = mysqli_prepare($conexion, $sqlInsertPago);
                        mysqli_stmt_bind_param($stmtI, 'issdds', $venta_id, $fecha_pago, $metodo_pago, $monto_pago, $monto_mora, $usuario_registro);
                        
                        if (mysqli_stmt_execute($stmtI)) {
                            $mensaje = "Pago registrado correctamente por " . $usuario_registro;
                            // Actualizar el cliente_id en sesión
                            $cliente_id = $cliente_id_venta;
                            $_SESSION['current_cliente_id'] = $cliente_id;
                        } else {
                            $mensaje = "Error al registrar el pago: " . mysqli_error($conexion);
                        }
                        mysqli_stmt_close($stmtI);
                    } else {
                        $mensaje = "El monto del pago (S/ " . number_format($monto_pago, 2) . ") no puede ser mayor que la deuda (S/ " . number_format($deuda_actual, 2) . ").";
                    }
                } else {
                    $mensaje = "Error: No se pudo calcular la deuda.";
                }
            } else {
                $mensaje = "Error: Venta no encontrada.";
            }
        } else {
            $mensaje = "Complete todos los campos para registrar el pago.";
        }
        
        // Redirigir para mostrar el cliente actualizado
        if ($cliente_id) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?cliente_id=' . $cliente_id . '&mensaje=' . urlencode($mensaje));
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?mensaje=' . urlencode($mensaje));
        }
        exit();
        
    } elseif (isset($_POST['editar_pago']) && $is_admin) {
        $pago_id = (int)$_POST['pago_id'];
        $fecha_pago = $_POST['fecha_pago'];
        $metodo_pago = $_POST['metodo_pago'];
        $monto_pago = floatval($_POST['monto_pago']);
        $usuario_registro = $_POST['usuario_registro'];
        
        if ($pago_id > 0 && $fecha_pago && $metodo_pago && $monto_pago > 0 && $usuario_registro) {
            $sqlUpdate = "UPDATE pagos SET fecha_pago = ?, metodo_pago = ?, monto = ?, usuario_registro = ? WHERE id = ?";
            $stmtU = mysqli_prepare($conexion, $sqlUpdate);
            mysqli_stmt_bind_param($stmtU, 'ssdsi', $fecha_pago, $metodo_pago, $monto_pago, $usuario_registro, $pago_id);
            if (mysqli_stmt_execute($stmtU)) {
                $mensaje = "Pago actualizado correctamente. Nuevo usuario: " . $usuario_registro;
                // Obtener cliente_id para redirigir
                $sqlGetCliente = "SELECT v.cliente_id FROM pagos p JOIN ventas v ON p.venta_id = v.id WHERE p.id = ?";
                $stmtG = mysqli_prepare($conexion, $sqlGetCliente);
                mysqli_stmt_bind_param($stmtG, 'i', $pago_id);
                mysqli_stmt_execute($stmtG);
                $resG = mysqli_stmt_get_result($stmtG);
                $rowG = mysqli_fetch_assoc($resG);
                if ($rowG) {
                    $cliente_id = $rowG['cliente_id'];
                    $_SESSION['current_cliente_id'] = $cliente_id;
                }
                mysqli_stmt_close($stmtG);
            } else {
                $mensaje = "Error al actualizar el pago: " . mysqli_error($conexion);
            }
            mysqli_stmt_close($stmtU);
        } else {
            $mensaje = "Complete todos los campos.";
        }
        
        if ($cliente_id) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?cliente_id=' . $cliente_id . '&mensaje=' . urlencode($mensaje));
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?mensaje=' . urlencode($mensaje));
        }
        exit();
        
    } elseif (isset($_POST['eliminar_pago']) && $is_admin) {
        $pago_id = (int)$_POST['pago_id'];
        
        // Obtener cliente_id antes de eliminar
        $sqlGetCliente = "SELECT v.cliente_id FROM pagos p JOIN ventas v ON p.venta_id = v.id WHERE p.id = ?";
        $stmtG = mysqli_prepare($conexion, $sqlGetCliente);
        mysqli_stmt_bind_param($stmtG, 'i', $pago_id);
        mysqli_stmt_execute($stmtG);
        $resG = mysqli_stmt_get_result($stmtG);
        $rowG = mysqli_fetch_assoc($resG);
        if ($rowG) {
            $cliente_id = $rowG['cliente_id'];
        }
        mysqli_stmt_close($stmtG);
        
        $sqlDel = "DELETE FROM pagos WHERE id = ?";
        $stmtDel = mysqli_prepare($conexion, $sqlDel);
        mysqli_stmt_bind_param($stmtDel, 'i', $pago_id);
        if (mysqli_stmt_execute($stmtDel)) {
            $mensaje = "Pago eliminado correctamente.";
            if ($cliente_id) {
                $_SESSION['current_cliente_id'] = $cliente_id;
            }
        } else {
            $mensaje = "Error al eliminar el pago: " . mysqli_error($conexion);
        }
        mysqli_stmt_close($stmtDel);
        
        if ($cliente_id) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?cliente_id=' . $cliente_id . '&mensaje=' . urlencode($mensaje));
        } else {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?mensaje=' . urlencode($mensaje));
        }
        exit();
    }
}

// Capturar mensaje de la URL
if (isset($_GET['mensaje'])) {
    $mensaje = urldecode($_GET['mensaje']);
}

// Cargar datos del cliente si hay un cliente_id válido
if ($cliente_id && $cliente_id > 0) {
    $sqlCliente = "SELECT * FROM clientes WHERE id = ?";
    $stmt = mysqli_prepare($conexion, $sqlCliente);
    mysqli_stmt_bind_param($stmt, 'i', $cliente_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $cliente = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    
    if (!$cliente) {
        unset($_SESSION['current_cliente_id']);
        $cliente_id = null;
    }
}

// Solo ejecutar consultas si hay un cliente válido
if ($cliente_id && $cliente_id > 0) {
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
    
    /* ================= PAGOS ================= */
    $sqlPagos = "
        SELECT 
            p.id,
            p.fecha_pago,
            p.metodo_pago,
            p.monto,
            IFNULL(p.mora, 0) AS mora,
            p.usuario_registro,
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
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gestión de Pagos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .table-responsive { overflow-x: auto; }
        .deuda-pendiente { color: rgb(255, 17, 41); font-weight: bold; }
        .retraso { color: #dc3545; font-weight: bold; }
        .search-container { position: relative; }
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
        .search-option:hover { background-color: #f8f9fa; }
        .search-option.highlight { background-color: #e9ecef; }
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
                <span class="text-white me-3">👤 <?= htmlspecialchars($_SESSION['usuario']) ?> (<?= $user_role ?>)</span>
                <a href="logout.php" class="btn btn-outline-light">Salir</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4" style="max-width: 1200px;">
        <h2 class="mb-4">💰 Gestión de Pagos</h2>

        <?php if ($mensaje): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle"></i> <?= htmlspecialchars($mensaje) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Sección de búsqueda -->
        <div class="form-section">
            <h5 class="mb-3"><i class="bi bi-search"></i> Buscar Cliente</h5>
            <div class="row g-3">
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
                <h4><i class="bi bi-person-circle"></i> Cliente: <?= htmlspecialchars($cliente['nombre']) ?> (DNI: <?= htmlspecialchars($cliente['dni']) ?>)
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

            <!-- Préstamos en Efectivo -->
            <h5 class="mt-4"><i class="bi bi-cash-stack"></i> 💵 Préstamos en Efectivo</h5>
            <?php if (count($ventasEfectivo) === 0): ?>
                <div class="alert alert-success">✅ No hay préstamos en efectivo pendientes.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr><th>Producto</th><th>Fecha</th><th>Días Crédito</th><th>Retraso</th><th>Total</th><th>Pagado</th><th>Deuda</th><th>Acciones</th></tr>
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
                                        <i class="bi bi-cash-coin"></i> Pagar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Préstamos por Artefacto -->
            <h5 class="mt-5"><i class="bi bi-box-seam"></i> 📦 Préstamos por Artefacto</h5>
            <?php if (count($ventasArtefacto) === 0): ?>
                <div class="alert alert-success">✅ No hay artefactos pendientes.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr><th>Producto</th><th>Fecha</th><th>Días Crédito</th><th>Retraso</th><th>Precio Venta</th><th>Pagado</th><th>Deuda</th><th>Acciones</th></tr>
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
                                        <i class="bi bi-cash-coin"></i> Pagar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Historial de pagos -->
            <h4 class="mt-4"><i class="bi bi-clock-history"></i> 📜 Historial de pagos</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr><th>Producto</th><th>Tipo</th><th>Fecha</th><th>Método</th><th>Monto (S/)</th><th>Mora (S/)</th><th>Registrado por</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($pagos) === 0): ?>
                            <tr><td colspan="8" class="text-center text-muted">❌ No hay pagos registrados</td></tr>
                        <?php else: ?>
                            <?php foreach ($pagos as $pago): ?>
                            <tr>
                                <td><?= htmlspecialchars($pago['producto']) ?></td>
                                <td><?= $pago['tipo_venta'] === 'artefacto' ? '📦 Artefacto' : '💵 Efectivo' ?></td>
                                <td><?= htmlspecialchars($pago['fecha_pago']) ?></td>
                                <td><?= htmlspecialchars($pago['metodo_pago']) ?></td>
                                <td><?= number_format($pago['monto'], 2) ?></td>
                                <td><?= $pago['mora'] > 0 ? '<span class="text-danger">S/ ' . number_format($pago['mora'], 2) . '</span>' : '—' ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($pago['usuario_registro']) ?></span></td>
                                <td class="text-nowrap">
                                    <button class="btn btn-secondary btn-sm btnImprimirPago" data-pagoid="<?= $pago['id'] ?>">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                    <?php if ($is_admin): ?>
                                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#modalEditarPago" 
                                            data-pagoid="<?= $pago['id'] ?>"
                                            data-fechapago="<?= $pago['fecha_pago'] ?>"
                                            data-metodopago="<?= $pago['metodo_pago'] ?>" 
                                            data-montopago="<?= $pago['monto'] ?>"
                                            data-usuarioregistro="<?= $pago['usuario_registro'] ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <form method="post" style="display:inline-block" onsubmit="return confirm('¿Eliminar este pago?');">
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

        <?php elseif (!$cliente_id): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Busque un cliente para ver sus pagos.
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Agregar Pago -->
    <div class="modal fade" id="modalAgregarPago" tabindex="-1" aria-labelledby="modalAgregarPagoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" id="formAgregarPago" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Agregar Pago</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="venta_id" id="venta_id_agregar" required>
                    <div class="mb-3">
                        <label class="form-label">📦 Producto</label>
                        <input type="text" class="form-control" id="producto_agregar" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">📅 Fecha de Pago</label>
                        <input type="date" class="form-control" name="fecha_pago" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">💳 Método de Pago</label>
                        <select class="form-select" name="metodo_pago" required>
                            <option value="">--Seleccione método--</option>
                            <option value="yape">Yape</option>
                            <option value="plin">Plin</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">💰 Monto a pagar (S/)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_pago" id="monto_pago" required>
                        <small id="deudaDisponible" class="form-text text-muted"></small>
                    </div>
                    <div class="mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="checkMora" onchange="toggleMora(this)">
                            <label class="form-check-label">⚠️ ¿Agregar mora?</label>
                        </div>
                    </div>
                    <div class="mb-3" id="campoMora" style="display:none;">
                        <label class="form-label">Monto de mora (S/)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_mora" id="monto_mora">
                        <small class="form-text text-warning">⚠️ La mora es un cobro extra y no reduce la deuda principal</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="agregar_pago" class="btn btn-primary" id="btnRegistrarPago">
                        <i class="bi bi-check-circle"></i> Registrar Pago
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Editar Pago (con campo para cambiar usuario) -->
    <div class="modal fade" id="modalEditarPago" tabindex="-1" aria-labelledby="modalEditarPagoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Pago</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="pago_id" id="pago_id_editar" required>
                    <div class="mb-3">
                        <label class="form-label">📅 Fecha de Pago</label>
                        <input type="date" class="form-control" name="fecha_pago" id="fecha_pago_editar" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">💳 Método de Pago</label>
                        <select class="form-select" name="metodo_pago" id="metodo_pago_editar" required>
                            <option value="yape">Yape</option>
                            <option value="plin">Plin</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">💰 Monto (S/)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="monto_pago" id="monto_pago_editar" required>
                    </div>
                    <?php if ($is_admin): ?>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person-badge"></i> 👤 Usuario que registró</label>
                        <select class="form-select" name="usuario_registro" id="usuario_registro_editar" required>
                            <option value="">Seleccione usuario</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?= htmlspecialchars($u['usuario']) ?>"><?= htmlspecialchars($u['usuario']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Puedes cambiar el usuario que registró este pago</small>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" name="editar_pago" class="btn btn-warning">
                        <i class="bi bi-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const clientesData = [
            <?php foreach ($todosClientes as $cliente): ?>
                { id: '<?= $cliente['id'] ?>', nombre: '<?= addslashes($cliente['nombre']) ?>', dni: '<?= $cliente['dni'] ?>' },
            <?php endforeach; ?>
        ];

        let currentHighlight = -1;
        const busquedaInput = document.getElementById('busqueda');
        const opcionesDiv = document.getElementById('opcionesBusqueda');

        if (busquedaInput) {
            busquedaInput.addEventListener('input', function() {
                const busqueda = this.value.toLowerCase();
                opcionesDiv.innerHTML = '';
                currentHighlight = -1;

                if (busqueda.length < 2) {
                    opcionesDiv.style.display = 'none';
                    return;
                }

                const resultados = clientesData.filter(c => 
                    c.nombre.toLowerCase().includes(busqueda) || c.dni.includes(busqueda)
                );

                if (resultados.length > 0) {
                    resultados.forEach((cliente, index) => {
                        const div = document.createElement('div');
                        div.className = 'search-option';
                        div.textContent = `${cliente.nombre} (${cliente.dni})`;
                        div.onclick = () => {
                            busquedaInput.value = cliente.nombre;
                            opcionesDiv.style.display = 'none';
                            document.getElementById('formBusqueda').submit();
                        };
                        opcionesDiv.appendChild(div);
                    });
                    opcionesDiv.style.display = 'block';
                } else {
                    opcionesDiv.style.display = 'none';
                }
            });

            busquedaInput.addEventListener('keydown', function(e) {
                const items = opcionesDiv.getElementsByClassName('search-option');
                if (items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentHighlight = Math.min(currentHighlight + 1, items.length - 1);
                    updateHighlight(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentHighlight = Math.max(currentHighlight - 1, 0);
                    updateHighlight(items);
                } else if (e.key === 'Enter' && currentHighlight >= 0) {
                    e.preventDefault();
                    items[currentHighlight].click();
                }
            });

            function updateHighlight(items) {
                for (let i = 0; i < items.length; i++) {
                    items[i].classList.toggle('highlight', i === currentHighlight);
                }
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container') && opcionesDiv) {
                opcionesDiv.style.display = 'none';
            }
        });

        const modalAgregar = document.getElementById('modalAgregarPago');
        if (modalAgregar) {
            modalAgregar.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('venta_id_agregar').value = button.dataset.ventaid;
                document.getElementById('producto_agregar').value = button.dataset.producto;
                const deuda = parseFloat(button.dataset.deuda);
                document.getElementById('monto_pago').max = deuda;
                document.getElementById('deudaDisponible').textContent = "Deuda disponible: S/ " + deuda.toFixed(2);
                document.getElementById('monto_pago').value = deuda.toFixed(2);
                document.getElementById('checkMora').checked = false;
                document.getElementById('campoMora').style.display = 'none';
                document.getElementById('monto_mora').value = '';
            });
        }

        const modalEditar = document.getElementById('modalEditarPago');
        if (modalEditar) {
            modalEditar.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('pago_id_editar').value = button.dataset.pagoid;
                document.getElementById('fecha_pago_editar').value = button.dataset.fechapago;
                document.getElementById('metodo_pago_editar').value = button.dataset.metodopago;
                document.getElementById('monto_pago_editar').value = button.dataset.montopago;
                <?php if ($is_admin): ?>
                if (document.getElementById('usuario_registro_editar')) {
                    document.getElementById('usuario_registro_editar').value = button.dataset.usuarioregistro;
                }
                <?php endif; ?>
            });
        }

        function toggleMora(checkbox) {
            const campo = document.getElementById('campoMora');
            campo.style.display = checkbox.checked ? 'block' : 'none';
            if (!checkbox.checked) document.getElementById('monto_mora').value = '';
        }

        <?php if ($mensaje): ?>
            Swal.fire({
                icon: '<?= strpos($mensaje, "correctamente") !== false ? "success" : (strpos($mensaje, "Error") !== false ? "error" : "info") ?>',
                title: '<?= strpos($mensaje, "correctamente") !== false ? "Éxito" : (strpos($mensaje, "Error") !== false ? "Error" : "Información") ?>',
                text: '<?= addslashes($mensaje) ?>',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        <?php endif; ?>

        document.querySelectorAll('.btnImprimirPago').forEach(btn => {
            btn.addEventListener('click', function() {
                Swal.fire({
                    title: 'Imprimir Voucher',
                    text: '¿Generar comprobante de pago?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Imprimir',
                    cancelButtonText: 'Cancelar'
                }).then(result => {
                    if (result.isConfirmed) {
                        window.open(`generar_voucher_pago.php?pago_id=${this.dataset.pagoid}`, '_blank');
                    }
                });
            });
        });
    </script>
</body>
</html>