<?php
session_start();
include 'includes/conexion.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario'])) {
    echo json_encode([]);
    exit();
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit();
}

$sql = "SELECT id, nombre, dni FROM clientes WHERE dni LIKE ? OR nombre LIKE ? LIMIT 10";
$stmt = mysqli_prepare($conexion, $sql);
$likeQ = "%$q%";
mysqli_stmt_bind_param($stmt, 'ss', $likeQ, $likeQ);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$clientes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $clientes[] = $row;
}

echo json_encode($clientes);
?>