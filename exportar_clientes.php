<?php
require_once("tcpdf/tcpdf.php");
include 'includes/conexion.php';

// Consulta clientes con datos completos
$sql = "SELECT dni, nombre, direccion, referencia, latitud, longitud FROM clientes ORDER BY nombre";
$result = mysqli_query($conexion, $sql);

// Crear PDF
$pdf = new TCPDF();
$pdf->SetMargins(10, 15, 10);
$pdf->AddPage();

// Título
$pdf->SetFont('helvetica','B',14);
$pdf->Cell(0,10," Listado de Clientes",0,1,'C');
$pdf->Ln(3);

// Encabezado de la tabla
$html = '<table border="1" cellpadding="4">
<tr style="background-color:#f2f2f2;" align="center">
    <th width="15%">DNI</th>
    <th width="25%">Nombre</th>
    <th width="25%">Dirección</th>
    <th width="20%">Referencia</th>
    <th width="15%">Ubicación (Lat,Lon)</th>
</tr>';

// Datos de clientes
while($row = mysqli_fetch_assoc($result)){
    $dni = !empty($row['dni']) ? htmlspecialchars($row['dni']) : 'No registrada';
    $nombre = !empty($row['nombre']) ? htmlspecialchars($row['nombre']) : 'No registrada';
    $direccion = !empty($row['direccion']) ? htmlspecialchars($row['direccion']) : 'No registrada';
    $referencia = !empty($row['referencia']) ? htmlspecialchars($row['referencia']) : 'No registrada';
    $ubicacion = (!empty($row['latitud']) && !empty($row['longitud'])) ? $row['latitud'] . ', ' . $row['longitud'] : 'No registrada';

    $html .= "<tr>
        <td align='center'>$dni</td>
        <td>$nombre</td>
        <td>$direccion</td>
        <td>$referencia</td>
        <td align='center'>$ubicacion</td>
    </tr>";
}

$html .= '</table>';

// Mostrar tabla en PDF
$pdf->SetFont('helvetica','',10);
$pdf->writeHTML($html,true,false,true,false,'');

ob_clean();
$pdf->Output('Listado_Clientes.pdf','I');
?>
