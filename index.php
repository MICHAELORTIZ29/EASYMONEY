<?php
// index.php
session_start();
include 'includes/conexion.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = mysqli_real_escape_string($conexion, $_POST['usuario']);
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    $sql = "SELECT * FROM usuarios WHERE usuario = '$usuario'";
    $resultado = mysqli_query($conexion, $sql);

    if ($resultado && mysqli_num_rows($resultado) === 1) {
        $fila = mysqli_fetch_assoc($resultado);

        if ($password === $fila['password']) { // texto plano
            $_SESSION['usuario'] = $usuario;
            $_SESSION['rol'] = $fila['rol']; // 🔹 Guardar el rol en la sesión
            header('Location: dashboard.php');
            exit();
        } else {
            $error = "⚠️ Contraseña incorrecta.";
        }
    } else {
        $error = "⚠️ Usuario no encontrado.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - Control de Créditos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: url('img/fondo1.jpg') no-repeat center center fixed;
            background-size: cover;
        }
        .login-card {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            max-width: 400px;
            margin: auto;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        .logo { display: block; margin: 0 auto 20px auto; width: 100px; }
    </style>
</head>
<body>
    <div class="d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="login-card">
            <img src="img/logo-shor.jpeg" alt="Logo de la empresa" class="logo">
            <h4 class="text-center mb-4">Iniciar Sesión</h4>
            
            <?php if ($error): ?>
                <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="mb-3">
                    <label for="usuario" class="form-label">Usuario</label>
                    <input type="text" name="usuario" id="usuario" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Ingresar</button>
            </form>
        </div>
    </div>
</body>
</html>
