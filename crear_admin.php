<?php
// crear_admin.php (ejecutar una vez desde el navegador)
include 'includes/conexion.php';

$usuario = 'admin';
$pwd_plano = '123456'; // contraseña que usarás
$hash = password_hash($pwd_plano, PASSWORD_DEFAULT);

// Si quieres sobreescribir o insertar:
$sql_check = "SELECT * FROM usuarios WHERE usuario = '$usuario'";
$res = mysqli_query($conexion, $sql_check);

if ($res && mysqli_num_rows($res) > 0) {
    $sql = "UPDATE usuarios SET password = '".mysqli_real_escape_string($conexion, $hash)."' WHERE usuario = '$usuario'";
} else {
    $sql = "INSERT INTO usuarios (usuario, password) VALUES ('".mysqli_real_escape_string($conexion, $usuario)."', '".mysqli_real_escape_string($conexion, $hash)."')";
}

if (mysqli_query($conexion, $sql)) {
    echo "Admin creado/actualizado. Usuario: $usuario | Clave: $pwd_plano";
} else {
    echo "Error: " . mysqli_error($conexion);
}
?>
