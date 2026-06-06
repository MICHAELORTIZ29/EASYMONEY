<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
  header('Location: index.php');
  exit();
}

// Traemos también el rol del usuario desde la sesión
$usuario   = $_SESSION['usuario'];
$rol       = $_SESSION['rol'] ?? 'limitado'; // si no existe, por defecto "limitado"

$sql = "SELECT * FROM clientes";
$result = mysqli_query($conexion, $sql);
?>

<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>

<body>
  <div class="container mt-4">
    <h2 class="mb-4">Listado de Clientes</h2>
    <a href="cliente_agregar.php" class="btn btn-primary mb-3">Agregar Cliente</a>
    <a href="dashboard.php" class="btn btn-secondary mb-3">Volver al Dashboard</a>

    <table class="table table-bordered">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>DNI</th>
          <th>Nombre</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
          <tr>
            <td><?= $row['id'] ?></td>
            <td><?= $row['dni'] ?></td>
            <td><?= $row['nombre'] ?></td>
            <td>
              <a href="cliente_ver.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-info">Ver</a>

              <?php if ($rol === 'admin'): ?>
                <!-- Solo los administradores pueden editar o eliminar -->
                <a href="cliente_editar.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Editar</a>
                <a href="cliente_eliminar.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger"
                  onclick="return confirm('¿Estás seguro de eliminar este cliente?')">Eliminar</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</body>

</html>
