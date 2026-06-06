<?php
include 'includes/conexion.php';

// Encabezados para exportar como Excel
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=Deudas_Vencimiento.xls");
header("Pragma: no-cache");
header("Expires: 0");

// Consulta con fechas de vencimiento
$sql = "
SELECT 
    c.dni,
    c.nombre,
    v.monto,
    v.fecha_venta,
    v.dias_credito,
    IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0) AS total_pagado,
    (v.monto - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) AS deuda,
    DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) AS fecha_limite
FROM clientes c
JOIN ventas v ON c.id = v.cliente_id
WHERE (v.monto - IFNULL((SELECT SUM(p.monto) FROM pagos p WHERE p.venta_id = v.id),0)) > 0
ORDER BY 
    (DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) < CURDATE()) DESC,
    DATE_ADD(v.fecha_venta, INTERVAL v.dias_credito DAY) ASC
";

$result = mysqli_query($conexion, $sql);

if (!$result) {
    die("Error en consulta: " . mysqli_error($conexion));
}

// Encabezados de tabla
echo "<table border='1'>";
echo "<tr style='background-color:#d9d9d9;'>
        <th>DNI</th>
        <th>Nombre</th>
        <th>Monto</th>
        <th>Pagado</th>
        <th>Deuda</th>
        <th>Fecha Venta</th>
        <th>Fecha Límite</th>
        <th>Estado</th>
      </tr>";

// Filas de resultados
while ($row = mysqli_fetch_assoc($result)) {
    $estado = (strtotime($row['fecha_limite']) < strtotime(date("Y-m-d"))) 
                ? 'VENCIDO' 
                : 'Vigente';

    echo "<tr>
            <td>{$row['dni']}</td>
            <td>{$row['nombre']}</td>
            <td>".number_format($row['monto'],2)."</td>
            <td>".number_format($row['total_pagado'],2)."</td>
            <td>".number_format($row['deuda'],2)."</td>
            <td>{$row['fecha_venta']}</td>
            <td>{$row['fecha_limite']}</td>
            <td>{$estado}</td>
          </tr>";
}

echo "</table>";
