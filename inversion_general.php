<?php
session_start();
$isAdmin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

$mensaje = "";
/* ================= ELIMINAR ================= */
if (isset($_GET['eliminar']) && $isAdmin) {
    $id = (int) $_GET['eliminar'];

    mysqli_query($conexion, "DELETE FROM inversion_general WHERE id = $id");

    header("Location: inversion_general.php");
    exit();
}
/* ================= EDITAR ================= */
if (isset($_POST['editar']) && $isAdmin) {
    $id = (int) $_POST['id'];
    $monto = $_POST['monto'];
    $fecha = $_POST['fecha'];
    $descripcion = $_POST['descripcion'];

    $sql = "UPDATE inversion_general 
            SET monto=?, fecha=?, descripcion=? 
            WHERE id=?";

    $stmt = mysqli_prepare($conexion, $sql);
    mysqli_stmt_bind_param($stmt, 'dssi', $monto, $fecha, $descripcion, $id);
    mysqli_stmt_execute($stmt);

    header("Location: inversion_general.php");
    exit();
}
/* ================= INSERTAR ================= */
if (isset($_POST['agregar'])) {
    $monto = $_POST['monto'];
    $fecha = $_POST['fecha'];
    $descripcion = $_POST['descripcion'];

    if ($monto > 0 && $fecha) {
        $sql = "INSERT INTO inversion_general (monto, fecha, descripcion) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);
        mysqli_stmt_bind_param($stmt, 'dss', $monto, $fecha, $descripcion);
        mysqli_stmt_execute($stmt);

        $mensaje = "Inversión agregada correctamente";
    }
}

/* ================= FILTRO ================= */
$filtro = "";

if (isset($_GET['tipo_filtro'])) {

    if ($_GET['tipo_filtro'] === 'mes' && !empty($_GET['mes'])) {
        $mes = $_GET['mes'];
        $filtro = "WHERE DATE_FORMAT(fecha,'%Y-%m') = '$mes'";
    }

    if ($_GET['tipo_filtro'] === 'rango' && !empty($_GET['desde']) && !empty($_GET['hasta'])) {
        $desde = $_GET['desde'];
        $hasta = $_GET['hasta'];
        $filtro = "WHERE fecha BETWEEN '$desde' AND '$hasta'";
    }
}

/* ================= TOTAL ================= */
$total = mysqli_fetch_assoc(mysqli_query(
    $conexion,
    "SELECT SUM(monto) as total FROM inversion_general $filtro"
))['total'] ?? 0;

/* ================= HISTORIAL ================= */
$historial = mysqli_query(
    $conexion,
    "SELECT * FROM inversion_general $filtro ORDER BY fecha DESC"
);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Inversión General</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f5f6fa;
        }

        .card-total {
            border-radius: 12px;
        }
    </style>
</head>

<body>

    <!-- NAV -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">Control Créditos</span>
            <a href="dashboard.php" class="btn btn-outline-light">Volver</a>
        </div>
    </nav>

    <div class="container mt-4">

        <h3 class="text-center mb-4">💰 Inversión General Easy Money</h3>

        <!-- MENSAJE -->
        <?php if ($mensaje): ?>
            <div class="alert alert-success text-center"><?= $mensaje ?></div>
        <?php endif; ?>

        <!-- TOTAL -->
        <div class="card text-bg-dark mb-4 shadow card-total">
            <div class="card-body text-center">
                <h5>Total Invertido</h5>
                <h2>S/ <?= number_format($total, 2) ?></h2>
            </div>
        </div>

        <!-- BOTONES -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregar">
                ➕ Agregar Inversión
            </button>

            <!-- <a href="exportar_inversion_pdf.php" class="btn btn-danger">
                📄 Exportar PDF
            </a>

            <a href="exportar_inversion_excel.php" class="btn btn-success">
                📊 Exportar Excel
            </a> -->
        </div>

        <!-- FILTRO -->
        <div class="card mb-3 shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-2">

                    <div class="col-md-3">
                        <select name="tipo_filtro" class="form-select">
                            <option value="">Tipo filtro</option>
                            <option value="mes">Por mes</option>
                            <option value="rango">Por rango</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <input type="month" name="mes" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <input type="date" name="desde" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <input type="date" name="hasta" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <button class="btn btn-dark w-100">Filtrar</button>
                    </div>

                </form>
            </div>
        </div>
        <!-- TABLA -->
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="table-dark text-center">
                    <tr>
                        <th>Fecha</th>
                        <th>Monto</th>
                        <th>Descripción</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (mysqli_num_rows($historial) === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center">No hay registros</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($historial)): ?>
                            <tr>
                                <td><?= $row['fecha'] ?></td>
                                <td class="text-success fw-bold">S/ <?= number_format($row['monto'], 2) ?></td>
                                <td><?= htmlspecialchars($row['descripcion']) ?></td>

                                <td class="text-center">
                                    <?php if ($isAdmin): ?>
                                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal"
                                            data-bs-target="#edit<?= $row['id'] ?>">✏️</button>
                                        <a href="?eliminar=<?= $row['id'] ?>" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Eliminar?')">🗑️</a>
                                    <?php else: ?>
                                        <span class="text-muted">Sin permisos</span>
                                    <?php endif; ?>
                                </td>

                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- MODALES EDITAR (FUERA DE LA TABLA) -->
    <?php
    mysqli_data_seek($historial, 0);
    while ($row = mysqli_fetch_assoc($historial)):
        if ($isAdmin):
            ?>
            <div class="modal fade" id="edit<?= $row['id'] ?>">
                <div class="modal-dialog">
                    <form method="post" class="modal-content">

                        <div class="modal-header bg-warning">
                            <h5>Editar</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">

                            <input type="number" step="0.01" name="monto" value="<?= $row['monto'] ?>" class="form-control mb-2"
                                required>

                            <input type="date" name="fecha" value="<?= $row['fecha'] ?>" class="form-control mb-2" required>

                            <textarea name="descripcion" class="form-control"><?= $row['descripcion'] ?></textarea>
                        </div>

                        <div class="modal-footer">
                            <button name="editar" class="btn btn-warning w-100">Guardar</button>
                        </div>

                    </form>
                </div>
            </div>
            <?php
        endif;
    endwhile;
    ?>

    <!-- MODAL AGREGAR -->
    <div class="modal fade" id="modalAgregar">
        <div class="modal-dialog">
            <form method="post" class="modal-content">

                <div class="modal-header bg-primary text-white">
                    <h5>Agregar</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="number" step="0.01" name="monto" class="form-control mb-2" placeholder="Monto"
                        required>
                    <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="form-control mb-2" required>
                    <textarea name="descripcion" class="form-control" placeholder="Descripción"></textarea>
                </div>

                <div class="modal-footer">
                    <button name="agregar" class="btn btn-success w-100">Guardar</button>
                </div>

            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>