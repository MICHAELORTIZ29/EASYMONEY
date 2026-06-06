<?php
session_start();
include 'conexion.php'; // asegúrate de que la ruta esté bien

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = mysqli_real_escape_string($conexion, $_POST['usuario']);
    $clave = $_POST['clave'];

    $sql = "SELECT * FROM usuarios WHERE usuario = '$usuario'";
    $resultado = mysqli_query($conexion, $sql);

    if (mysqli_num_rows($resultado) === 1) {
        $fila = mysqli_fetch_assoc($resultado);
        if (password_verify($clave, $fila['clave'])) {
            $_SESSION['usuario'] = $usuario;
            header('Location: ../dashboard.php');
            exit();
        } else {
            $_SESSION['error'] = "⚠️ Clave incorrecta.";
        }
    } else {
        $_SESSION['error'] = "⚠️ Usuario no encontrado.";
    }

    header('Location: ../index.php');
    exit();
}
