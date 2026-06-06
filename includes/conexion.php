<?php
$host = "localhost";
$usuario = "miorpaco_admin";
$clave = "P7XQ5mdav9";
$bd = "miorpaco_control_creditos";

$conexion = new mysqli($host, $usuario, $clave, $bd);

if ($conexion->connect_error) {
    die("Conexión fallida: " . $conexion->connect_error);
}
?>
