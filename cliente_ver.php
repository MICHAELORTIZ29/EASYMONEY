<?php
include 'includes/conexion.php';

if (!isset($_GET['id'])) {
    echo "Cliente no especificado.";
    exit;
}

$id = intval($_GET['id']);

/* ================= CLIENTE ================= */
$sqlCliente = "SELECT * FROM clientes WHERE id = $id";
$resultCliente = mysqli_query($conexion, $sqlCliente);

if (mysqli_num_rows($resultCliente) === 0) {
    echo "Cliente no encontrado.";
    exit;
}

$cliente = mysqli_fetch_assoc($resultCliente);

/* ================= VENTAS EFECTIVO ================= */
$sqlVentasEfectivo = "
SELECT 
    v.id,
    v.producto,
    v.monto_total,
    v.fecha_venta,
    v.dias_credito,
    IFNULL(SUM(p.monto),0) AS total_pagado,
    (v.monto_total - IFNULL(SUM(p.monto),0)) AS deuda
FROM ventas v
LEFT JOIN pagos p ON p.venta_id = v.id
WHERE v.cliente_id = $id
AND v.tipo_venta = 'efectivo'
GROUP BY v.id
HAVING deuda > 0
";
$ventasEfectivo = mysqli_fetch_all(mysqli_query($conexion, $sqlVentasEfectivo), MYSQLI_ASSOC);

foreach ($ventasEfectivo as &$v) {
    $fecha_limite = date('Y-m-d', strtotime($v['fecha_venta'].' + '.$v['dias_credito'].' days'));
    $v['dias_retraso'] = max(0, floor((strtotime(date('Y-m-d')) - strtotime($fecha_limite)) / 86400));
}
unset($v);

/* ================= VENTAS ARTEFACTO ================= */
$sqlVentasArtefacto = "
SELECT 
    v.id,
    v.producto,
    v.precio_venta,
    v.fecha_venta,
    v.dias_credito,
    IFNULL(SUM(p.monto),0) AS total_pagado,
    (v.precio_venta - IFNULL(SUM(p.monto),0)) AS deuda
FROM ventas v
LEFT JOIN pagos p ON p.venta_id = v.id
WHERE v.cliente_id = $id
AND v.tipo_venta = 'artefacto'
GROUP BY v.id
HAVING deuda > 0
";
$ventasArtefacto = mysqli_fetch_all(mysqli_query($conexion, $sqlVentasArtefacto), MYSQLI_ASSOC);

foreach ($ventasArtefacto as &$v) {
    $fecha_limite = date('Y-m-d', strtotime($v['fecha_venta'].' + '.$v['dias_credito'].' days'));
    $v['dias_retraso'] = max(0, floor((strtotime(date('Y-m-d')) - strtotime($fecha_limite)) / 86400));
}
unset($v);

/* ================= PAGOS ================= */
$sqlPagos = "
SELECT p.fecha_pago, p.metodo_pago, p.monto, v.producto
FROM pagos p
INNER JOIN ventas v ON p.venta_id = v.id
WHERE v.cliente_id = $id
ORDER BY p.fecha_pago DESC
";
$pagos = mysqli_fetch_all(mysqli_query($conexion, $sqlPagos), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Detalle Cliente</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#f9f9f9; }

.img-thumbnail{
    cursor:pointer;
    border-radius:6px;
    transition: transform .2s;
}
.img-thumbnail:hover{ transform:scale(1.03); }

.deuda{ color:#dc3545; font-weight:bold; }
.retraso{ color:#dc3545; font-weight:bold; }

/* DESKTOP */
@media (min-width: 992px){
    .img-thumbnail{ max-width:220px; }
    .mapa-contenedor iframe{ height:220px; }
    table th{ width:220px; }
}

/* TABLET */
@media (min-width:576px) and (max-width:991px){
    .img-thumbnail{ max-width:160px; }
    .mapa-contenedor iframe{ height:260px; }
}

/* CELULAR */
@media (max-width:575px){
    .img-thumbnail{ max-width:100%; }
}
</style>
</head>

<body class="container py-4">

<h3 class="mb-3">📌 Datos del Cliente</h3>

<table class="table table-bordered bg-white shadow-sm">
<tr><th>DNI</th><td><?= htmlspecialchars($cliente['dni']) ?></td></tr>
<tr><th>Nombre</th><td><?= htmlspecialchars($cliente['nombre']) ?></td></tr>
<tr><th>Dirección</th><td><?= htmlspecialchars($cliente['direccion'] ?? '') ?></td></tr>
<tr><th>Referencia</th><td><?= htmlspecialchars($cliente['referencia'] ?? '') ?></td></tr>



<tr>
<th>DNI Frontal</th>
<td>
<?php if($cliente['dni_frontal']): ?>
<img src="<?= $cliente['dni_frontal'] ?>" class="img-thumbnail mb-2" onclick="verImagen(this.src)">
<?php else: ?><em>No registrado</em><?php endif; ?>
</td>
</tr>

<tr>
<th>DNI Posterior</th>
<td>
<?php if($cliente['dni_posterior']): ?>
<img src="<?= $cliente['dni_posterior'] ?>" class="img-thumbnail mb-2" onclick="verImagen(this.src)">
<?php else: ?><em>No registrado</em><?php endif; ?>
</td>
</tr>

<tr>
<th>Documentos</th>
<td>
<?php if($cliente['documentos']):
$docs = explode(',', $cliente['documentos']); ?>
<ul class="mb-0">
<?php foreach($docs as $d): ?>
<li><a href="<?= $d ?>" target="_blank">📄 <?= basename($d) ?></a></li>
<?php endforeach; ?>
</ul>
<?php else: ?><em>No hay documentos</em><?php endif; ?>
</td>
</tr>

<tr>
<th>Ubicación</th>
<td>
<?php if($cliente['latitud'] && $cliente['longitud']): ?>
<div class="mapa-contenedor">
<iframe src="https://www.google.com/maps?q=<?= $cliente['latitud'] ?>,<?= $cliente['longitud'] ?>&output=embed"
style="width:100%; border:0;" loading="lazy"></iframe>
</div>
<?php else: ?><em>No registrada</em><?php endif; ?>
</td>
</tr>
</table>

<hr>

<h4>💵 Préstamos en Efectivo</h4>
<?php if(!$ventasEfectivo): ?>
<div class="alert alert-success">No hay préstamos pendientes</div>
<?php else: ?>
<table class="table table-bordered">
<tr class="table-dark">
<th>Producto</th><th>Fecha</th><th>Días</th><th>Retraso</th><th>Total</th><th>Pagado</th><th>Deuda</th>
</tr>
<?php foreach($ventasEfectivo as $v): ?>
<tr>
<td><?= $v['producto'] ?></td>
<td><?= $v['fecha_venta'] ?></td>
<td><?= $v['dias_credito'] ?></td>
<td class="<?= $v['dias_retraso']>0?'retraso':'' ?>"><?= $v['dias_retraso'] ?></td>
<td><?= number_format($v['monto_total'],2) ?></td>
<td><?= number_format($v['total_pagado'],2) ?></td>
<td class="deuda"><?= number_format($v['deuda'],2) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h4 class="mt-4">📦 Préstamos por Artefacto</h4>
<?php if(!$ventasArtefacto): ?>
<div class="alert alert-success">No hay artefactos pendientes</div>
<?php else: ?>
<table class="table table-bordered">
<tr class="table-dark">
<th>Producto</th><th>Fecha</th><th>Días</th><th>Retraso</th><th>Total</th><th>Pagado</th><th>Deuda</th>
</tr>
<?php foreach($ventasArtefacto as $v): ?>
<tr>
<td><?= $v['producto'] ?></td>
<td><?= $v['fecha_venta'] ?></td>
<td><?= $v['dias_credito'] ?></td>
<td class="<?= $v['dias_retraso']>0?'retraso':'' ?>"><?= $v['dias_retraso'] ?></td>
<td><?= number_format($v['precio_venta'],2) ?></td>
<td><?= number_format($v['total_pagado'],2) ?></td>
<td class="deuda"><?= number_format($v['deuda'],2) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h4 class="mt-4">📜 Historial de Pagos</h4>
<?php if(!$pagos): ?>
<div class="alert alert-info">No hay pagos</div>
<?php else: ?>
<table class="table table-striped table-bordered">
<tr class="table-dark">
<th>Producto</th><th>Fecha</th><th>Método</th><th>Monto</th>
</tr>
<?php foreach($pagos as $p): ?>
<tr>
<td><?= $p['producto'] ?></td>
<td><?= $p['fecha_pago'] ?></td>
<td><?= ucfirst($p['metodo_pago']) ?></td>
<td><?= number_format($p['monto'],2) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<a href="clientes.php" class="btn btn-secondary mt-3">⬅️ Volver</a>

<!-- MODAL -->
<div class="modal fade" id="modalImg">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content bg-dark">
<div class="modal-body text-center">
<img id="imgModal" class="img-fluid">
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function verImagen(src){
document.getElementById('imgModal').src = src;
new bootstrap.Modal(document.getElementById('modalImg')).show();
}
</script>

</body>
</html>
