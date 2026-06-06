<?php
include 'includes/conexion.php';

$dni = $_GET['dni'] ?? '';

$response = ['existe' => false];

if ($dni) {
    $stmt = $conexion->prepare("SELECT nombre FROM clientes WHERE dni = ?");
    $stmt->bind_param("s", $dni);
    $stmt->execute();
    $stmt->bind_result($nombre);
    if ($stmt->fetch()) {
        $response = [
            'existe' => true,
            'nombre' => $nombre
        ];
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($response);
