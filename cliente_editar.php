<?php
include 'includes/conexion.php';

// Función para cargar datos del cliente
function cargarDatosCliente($conexion, $cliente_id, &$ventas, &$pagos)
{
    $sqlVentas = "SELECT v.id, v.producto, v.monto, v.interes, v.fecha_venta, v.dias_credito,
        (v.monto + (v.monto * v.interes/100)) AS monto_total,
        IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0) AS total_pagado,
        ((v.monto + (v.monto * v.interes/100)) - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) AS deuda
        FROM ventas v WHERE v.cliente_id = ? 
        AND ((v.monto + (v.monto * v.interes/100)) - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) > 0";
    $stmtV = mysqli_prepare($conexion, $sqlVentas);
    mysqli_stmt_bind_param($stmtV, 'i', $cliente_id);
    mysqli_stmt_execute($stmtV);
    $resV = mysqli_stmt_get_result($stmtV);
    $ventas = mysqli_fetch_all($resV, MYSQLI_ASSOC);
    mysqli_stmt_close($stmtV);

    foreach ($ventas as &$venta) {
        $fecha_limite = date('Y-m-d', strtotime($venta['fecha_venta'] . ' + ' . $venta['dias_credito'] . ' days'));
        $hoy = date('Y-m-d');
        $venta['dias_retraso'] = max(0, (strtotime($hoy) - strtotime($fecha_limite)) / (60 * 60 * 24));
    }
    unset($venta);

    $sqlPagos = "SELECT p.id, p.venta_id, p.fecha_pago, p.metodo_pago, p.monto, v.producto
                 FROM pagos p
                 INNER JOIN ventas v ON p.venta_id = v.id
                 WHERE v.cliente_id = ?
                 ORDER BY p.fecha_pago DESC";
    $stmtP = mysqli_prepare($conexion, $sqlPagos);
    mysqli_stmt_bind_param($stmtP, 'i', $cliente_id);
    mysqli_stmt_execute($stmtP);
    $resP = mysqli_stmt_get_result($stmtP);
    $pagos = mysqli_fetch_all($resP, MYSQLI_ASSOC);
    mysqli_stmt_close($stmtP);
}

$id = $_GET['id'] ?? null;
if (!$id) {
    die("ID de cliente no proporcionado");
}

// Obtener cliente actual
$sql = "SELECT * FROM clientes WHERE id = ?";
$stmt = mysqli_prepare($conexion, $sql);
if (!$stmt) die("Error en prepare: " . mysqli_error($conexion));
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$cliente = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$cliente) {
    die("Cliente no encontrado");
}

$mensaje = "";

// Manejo de POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === ACTUALIZAR CLIENTE ===
    if (isset($_POST['actualizar_cliente'])) {

        $dni = trim($_POST['dni']);
        $nombre = trim($_POST['nombre']);
        $direccion = trim($_POST['direccion']);
        $referencia = trim($_POST['referencia']);
        $latitud = trim($_POST['latitud']);
        $longitud = trim($_POST['longitud']);

        // === FOTO ===
        $fotoPath = $cliente['foto'];
        if (!empty($_FILES['foto']['name'])) {
            $fotoNombre = time() . "_" . basename($_FILES['foto']['name']);
            $fotoPath = "uploads/fotos/" . $fotoNombre;
            if (!is_dir("uploads/fotos")) mkdir("uploads/fotos", 0777, true);
            move_uploaded_file($_FILES['foto']['tmp_name'], $fotoPath);
        }

        // === DNI FRONTAL ===
        $dniFrontalPath = $cliente['dni_frontal'];
        if (!empty($_FILES['dni_frontal']['name'])) {
            $frontalNombre = time() . "_frontal_" . basename($_FILES['dni_frontal']['name']);
            $dniFrontalPath = "uploads/dni/" . $frontalNombre;
            if (!is_dir("uploads/dni")) mkdir("uploads/dni", 0777, true);
            move_uploaded_file($_FILES['dni_frontal']['tmp_name'], $dniFrontalPath);
        }

        // === DNI POSTERIOR ===
        $dniPosteriorPath = $cliente['dni_posterior'];
        if (!empty($_FILES['dni_posterior']['name'])) {
            $posteriorNombre = time() . "_posterior_" . basename($_FILES['dni_posterior']['name']);
            $dniPosteriorPath = "uploads/dni/" . $posteriorNombre;
            if (!is_dir("uploads/dni")) mkdir("uploads/dni", 0777, true);
            move_uploaded_file($_FILES['dni_posterior']['tmp_name'], $dniPosteriorPath);
        }

        // === DOCUMENTOS ===
        $docs = $cliente['documentos'];
        if (!empty($_FILES['documentos']['name'][0])) {
            if (!is_dir("uploads/documentos")) mkdir("uploads/documentos", 0777, true);
            $documentosPaths = [];
            foreach ($_FILES['documentos']['name'] as $i => $docNombre) {
                if ($_FILES['documentos']['error'][$i] === UPLOAD_ERR_OK) {
                    $docNuevo = time() . "_" . basename($docNombre);
                    $docPath = "uploads/documentos/" . $docNuevo;
                    if (move_uploaded_file($_FILES['documentos']['tmp_name'][$i], $docPath)) {
                        $documentosPaths[] = $docPath;
                    }
                }
            }
            $docs = !empty($documentosPaths) ? implode(",", $documentosPaths) : $cliente['documentos'];
        }

        $update = "UPDATE clientes 
                   SET dni=?, nombre=?, direccion=?, referencia=?, latitud=?, longitud=?, 
                       foto=?, documentos=?, dni_frontal=?, dni_posterior=? 
                   WHERE id=?";
        $stmtUpdate = mysqli_prepare($conexion, $update);
        mysqli_stmt_bind_param(
            $stmtUpdate,
            'ssssssssssi',
            $dni, $nombre, $direccion, $referencia, $latitud, $longitud,
            $fotoPath, $docs, $dniFrontalPath, $dniPosteriorPath, $id
        );
        if (mysqli_stmt_execute($stmtUpdate)) {
            $mensaje = "Cliente actualizado correctamente.";
        } else {
            $mensaje = "Error al actualizar el cliente: " . mysqli_error($conexion);
        }
        mysqli_stmt_close($stmtUpdate);

        // recargar datos del cliente
        $sql = "SELECT * FROM clientes WHERE id=?";
        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $cliente = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }

    // === EDITAR PRESTAMO ===
    if (isset($_POST['editar_venta'])) {
        $venta_id = $_POST['venta_id'];
        $producto = trim($_POST['producto']);
        $monto = floatval($_POST['monto']);
        $interes = floatval($_POST['interes']);
        $dias_credito = intval($_POST['dias_credito']);
        $fecha_venta = $_POST['fecha_venta'];

        $updateVenta = "UPDATE ventas SET producto=?, monto=?, interes=?, dias_credito=?, fecha_venta=? WHERE id=?";
        $stmtVenta = mysqli_prepare($conexion, $updateVenta);
        mysqli_stmt_bind_param($stmtVenta, "sddisi", $producto, $monto, $interes, $dias_credito, $fecha_venta, $venta_id);
        if (mysqli_stmt_execute($stmtVenta)) {
            $mensaje = "Préstamo actualizado correctamente.";
        } else {
            $mensaje = "Error al actualizar el préstamo: " . mysqli_error($conexion);
        }
        mysqli_stmt_close($stmtVenta);

        // recargar datos del cliente
        cargarDatosCliente($conexion, $cliente['id'], $ventas, $pagos);
    }

    // === ELIMINAR VENTA/PRÉSTAMO ===
    if (isset($_POST['eliminar_venta'])) {
        $venta_id = $_POST['venta_id'];
        $delete = "DELETE FROM ventas WHERE id=?";
        $stmtDel = mysqli_prepare($conexion, $delete);
        mysqli_stmt_bind_param($stmtDel, 'i', $venta_id);
        mysqli_stmt_execute($stmtDel);
        mysqli_stmt_close($stmtDel);

        header("Location: cliente_editar.php?id=$id");
        exit();
    }
}

// Cargar ventas del cliente si no se editaron
if (!isset($ventas)) {
    $sqlVentas = "SELECT * FROM ventas WHERE cliente_id=?";
    $stmtV = mysqli_prepare($conexion, $sqlVentas);
    mysqli_stmt_bind_param($stmtV, 'i', $id);
    mysqli_stmt_execute($stmtV);
    $resultV = mysqli_stmt_get_result($stmtV);
    $ventas = mysqli_fetch_all($resultV, MYSQLI_ASSOC);
    mysqli_stmt_close($stmtV);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editar Cliente</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-4">
<h2>Editar Cliente</h2>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="actualizar_cliente">
    <div class="mb-3">
        <label>DNI</label>
        <input type="text" name="dni" class="form-control" value="<?= htmlspecialchars($cliente['dni']) ?>" required>
    </div>
    <div class="mb-3">
        <label>Nombre</label>
        <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($cliente['nombre']) ?>" required>
    </div>
    <div class="mb-3">
        <label>Dirección</label>
        <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($cliente['direccion']) ?>">
    </div>
    <div class="mb-3">
        <label>Referencia</label>
        <input type="text" name="referencia" class="form-control" value="<?= htmlspecialchars($cliente['referencia']) ?>">
    </div>
    <div class="mb-3">
        <label>Latitud</label>
        <input type="text" name="latitud" class="form-control" value="<?= htmlspecialchars($cliente['latitud']) ?>">
    </div>
    <div class="mb-3">
        <label>Longitud</label>
        <input type="text" name="longitud" class="form-control" value="<?= htmlspecialchars($cliente['longitud']) ?>">
    </div>

    <div class="mb-3">
        <label>DNI Frontal</label><br>
        <?php if ($cliente['dni_frontal']): ?><img src="<?= $cliente['dni_frontal'] ?>" width="100"><br><?php endif; ?>
        <input type="file" name="dni_frontal">
    </div>
    <div class="mb-3">
        <label>DNI Posterior</label><br>
        <?php if ($cliente['dni_posterior']): ?><img src="<?= $cliente['dni_posterior'] ?>" width="100"><br><?php endif; ?>
        <input type="file" name="dni_posterior">
    </div>
    <div class="mb-3">
        <label>Documentos</label><br>
        <?php if ($cliente['documentos']): 
            foreach (explode(",", $cliente['documentos']) as $doc): ?>
                <a href="<?= $doc ?>" target="_blank">📄 Ver documento</a><br>
        <?php endforeach; endif; ?>
        <input type="file" name="documentos[]" multiple>
    </div>
    <button type="submit" class="btn btn-primary">Actualizar Cliente</button>
    <a href="clientes.php" class="btn btn-secondary">Cancelar</a>
</form>

<h3 class="mt-5">Préstamos/Ventas</h3>
<?php if ($ventas): ?>
<table class="table table-bordered">
<tr>
    <th>Producto</th>
    <th>Monto</th>
    <th>Interés (%)</th>
    <th>Días Crédito</th>
    <th>Fecha Venta</th>
    <th>Acciones</th>
</tr>
<?php foreach ($ventas as $v): ?>
<tr>
    <form method="POST">
        <input type="hidden" name="editar_venta">
        <input type="hidden" name="venta_id" value="<?= $v['id'] ?>">
        <td><input type="text" name="producto" class="form-control" value="<?= htmlspecialchars($v['producto']) ?>"></td>
        <td><input type="number" step="0.01" name="monto" class="form-control" value="<?= $v['monto'] ?>"></td>
        <td><input type="number" step="0.01" name="interes" class="form-control" value="<?= $v['interes'] ?>"></td>
        <td><input type="number" name="dias_credito" class="form-control" value="<?= $v['dias_credito'] ?>"></td>
        <td><input type="date" name="fecha_venta" class="form-control" value="<?= $v['fecha_venta'] ?>"></td>
        <td class="d-flex gap-1">
            <button type="submit" class="btn btn-success btn-sm">Guardar</button>
    </form>
    <form method="POST" style="margin:0;">
        <input type="hidden" name="eliminar_venta">
        <input type="hidden" name="venta_id" value="<?= $v['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Eliminar este préstamo?');">Eliminar</button>
    </form>
        </td>
</tr>
<?php endforeach; ?>
</table>
<?php else: ?>
<p>No hay préstamos registrados.</p>
<?php endif; ?>

</div>

<?php if (!empty($mensaje)): ?>
<script>
Swal.fire({
    icon: '<?= strpos($mensaje, "Error") !== false ? "error" : "success" ?>',
    title: '<?= strpos($mensaje, "Error") !== false ? "Error" : "Éxito" ?>',
    text: '<?= addslashes($mensaje) ?>',
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3000
});
</script>
<?php endif; ?>
</body>
</html>
