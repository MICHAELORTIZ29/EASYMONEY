<?php
$host = "localhost";
$usuario = "root";
$clave = "";
$bd = "miorpaco_control_creditos";

$conexion = new mysqli($host, $usuario, $clave, $bd);

if ($conexion->connect_error) {
    die("Conexión fallida: " . $conexion->connect_error);
}
?>
