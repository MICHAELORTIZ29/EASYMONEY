<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

$mensaje = '';

// Clientes
$sqlClientes = "SELECT id, nombre, dni FROM clientes ORDER BY nombre ASC";
$resultClientes = mysqli_query($conexion, $sqlClientes);
$clientes = mysqli_fetch_all($resultClientes, MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cliente_id   = $_POST['cliente_id'] ?? null;
    $producto     = trim($_POST['producto']);
    $dias_credito = $_POST['dias_credito'];
    $fecha_venta  = $_POST['fecha_venta'];
    $tipo_venta   = $_POST['tipo_venta'] ?? 'efectivo';

    if (!$cliente_id || $producto === '' || !$dias_credito || !$fecha_venta) {
        $mensaje = "Complete todos los campos obligatorios.";
    } else {

        if ($tipo_venta === 'efectivo') {

            $monto   = $_POST['monto'];
            $interes = $_POST['interes'];

            if ($monto === '' || $interes === '') {
                $mensaje = "Complete los datos del préstamo.";
            } else {

                $monto_total = $monto + ($monto * ($interes / 100));

                $sql = "INSERT INTO ventas
                (cliente_id, producto, monto, interes, monto_total, dias_credito, fecha_venta, tipo_venta)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'efectivo')";

                $stmt = mysqli_prepare($conexion, $sql);
                mysqli_stmt_bind_param(
                    $stmt,
                    'isdddis',
                    $cliente_id,
                    $producto,
                    $monto,
                    $interes,
                    $monto_total,
                    $dias_credito,
                    $fecha_venta
                );
            }

        } else { // ARTEFACTO

            $precio_compra = $_POST['precio_compra'];
            $precio_venta  = $_POST['precio_venta'];

            if ($precio_compra === '' || $precio_venta === '') {
                $mensaje = "Complete los precios del artefacto.";
            } else {

                $sql = "INSERT INTO ventas
                (cliente_id, producto, precio_compra, precio_venta, dias_credito, fecha_venta, tipo_venta)
                VALUES (?, ?, ?, ?, ?, ?, 'artefacto')";

                $stmt = mysqli_prepare($conexion, $sql);
                mysqli_stmt_bind_param(
                    $stmt,
                    'isddis',
                    $cliente_id,
                    $producto,
                    $precio_compra,
                    $precio_venta,
                    $dias_credito,
                    $fecha_venta
                );
            }
        }

        if (isset($stmt)) {
            if (mysqli_stmt_execute($stmt)) {
                $mensaje = "Venta registrada correctamente.";
            } else {
                $mensaje = "Error: " . mysqli_error($conexion);
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrar Venta</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.select2-container { width:100%!important; }
</style>
</head>

<body>

<nav class="navbar navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">Control Créditos</a>
    <a href="logout.php" class="btn btn-outline-light">Salir</a>
  </div>
</nav>

<div class="container mt-4" style="max-width:700px">
<h3 class="mb-4">Registrar Venta</h3>

<?php if ($mensaje): ?>
<div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<form method="post">

<!-- Cliente -->
<div class="mb-3">
<label class="form-label">Cliente</label>
<select name="cliente_id" id="cliente_id" class="form-select" required>
<option value="">Seleccione</option>
<?php foreach ($clientes as $c): ?>
<option value="<?= $c['id'] ?>" data-dni="<?= $c['dni'] ?>">
<?= htmlspecialchars($c['nombre']) ?> - <?= htmlspecialchars($c['dni']) ?>
</option>
<?php endforeach; ?>
</select>
</div>

<!-- Tipo -->
<div class="mb-3">
<label class="form-label">Tipo de Venta</label>
<div class="form-check">
<input class="form-check-input" type="radio" name="tipo_venta" value="efectivo" checked>
<label class="form-check-label">Efectivo</label>
</div>
<div class="form-check">
<input class="form-check-input" type="radio" name="tipo_venta" value="artefacto">
<label class="form-check-label">Artefacto</label>
</div>
</div>

<!-- Producto -->
<div class="mb-3">
<label class="form-label">Producto</label>
<input type="text" name="producto" class="form-control" required>
</div>

<!-- EFECTIVO -->
<div id="campos-efectivo">
<div class="mb-3">
<label class="form-label">Monto (S/)</label>
<input type="number" name="monto" step="0.01" class="form-control">
</div>

<div class="mb-3">
<label class="form-label">Interés (%)</label>
<input type="number" name="interes" step="0.01" class="form-control">
</div>
</div>

<!-- ARTEFACTO -->
<div id="campos-artefacto" style="display:none">
<div class="mb-3">
<label class="form-label">Precio Compra (S/)</label>
<input type="number" name="precio_compra" step="0.01" class="form-control">
</div>

<div class="mb-3">
<label class="form-label">Precio Venta (S/)</label>
<input type="number" name="precio_venta" step="0.01" class="form-control">
</div>
</div>

<!-- Comunes -->
<div class="mb-3">
<label class="form-label">Días de Crédito</label>
<input type="number" name="dias_credito" class="form-control" required>
</div>

<div class="mb-3">
<label class="form-label">Fecha Venta</label>
<input type="date" name="fecha_venta" class="form-control" value="<?= date('Y-m-d') ?>">
</div>

<button class="btn btn-success">Registrar</button>
<a href="dashboard.php" class="btn btn-secondary">Volver</a>

</form>
</div>

<script>
$(function(){

$('#cliente_id').select2({
placeholder:"Seleccione un cliente",
allowClear:true
});

$('input[name="tipo_venta"]').change(function(){
if(this.value==='efectivo'){
$('#campos-efectivo').show();
$('#campos-artefacto').hide();
}else{
$('#campos-efectivo').hide();
$('#campos-artefacto').show();
}
});

});
</script>

</body>
</html>
