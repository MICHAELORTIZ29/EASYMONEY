<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

$mensaje = '';
$usuario_actual = $_SESSION['usuario'];

// Verificar si es admin
$is_admin = false;
$stmtRol = mysqli_prepare($conexion, "SELECT rol FROM usuarios WHERE usuario = ?");
mysqli_stmt_bind_param($stmtRol, 's', $usuario_actual);
mysqli_stmt_execute($stmtRol);
$resRol = mysqli_stmt_get_result($stmtRol);
$rowRol = mysqli_fetch_assoc($resRol);
mysqli_stmt_close($stmtRol);
$is_admin = ($rowRol && $rowRol['rol'] === 'admin');

// Obtener lista de usuarios para el select de edición (solo admin)
$usuarios = [];
if ($is_admin) {
    $resUsuarios = mysqli_query($conexion, "SELECT usuario FROM usuarios ORDER BY usuario");
    $usuarios = mysqli_fetch_all($resUsuarios, MYSQLI_ASSOC);
}

// Procesar formulario de nuevo egreso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_egreso'])) {
    $nombre = trim($_POST['nombre']);
    $fecha_egreso = $_POST['fecha_egreso'];
    $metodo_pago = $_POST['metodo_pago'];
    $monto = floatval($_POST['monto']);
    $descripcion = trim($_POST['descripcion']);

    if ($nombre && $fecha_egreso && $metodo_pago && $monto > 0) {
        $sqlInsert = "INSERT INTO egresos (nombre, fecha_egreso, metodo_pago, monto, descripcion, usuario_registro) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sqlInsert);
        mysqli_stmt_bind_param($stmt, 'sssdss', $nombre, $fecha_egreso, $metodo_pago, $monto, $descripcion, $usuario_actual);
        
        if (mysqli_stmt_execute($stmt)) {
            $mensaje = "Egreso registrado correctamente.";
        } else {
            $mensaje = "Error al registrar egreso: " . mysqli_error($conexion);
        }
        mysqli_stmt_close($stmt);
    } else {
        $mensaje = "Complete todos los campos obligatorios.";
    }
}

// Procesar edición de egreso (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_egreso']) && $is_admin) {
    $egreso_id = (int)$_POST['egreso_id'];
    $nombre = trim($_POST['nombre']);
    $fecha_egreso = $_POST['fecha_egreso'];
    $metodo_pago = $_POST['metodo_pago'];
    $monto = floatval($_POST['monto']);
    $descripcion = trim($_POST['descripcion']);
    $usuario_registro = $_POST['usuario_registro'];

    if ($egreso_id > 0 && $nombre && $fecha_egreso && $metodo_pago && $monto > 0 && $usuario_registro) {
        $sqlUpdate = "UPDATE egresos SET nombre = ?, fecha_egreso = ?, metodo_pago = ?, monto = ?, descripcion = ?, usuario_registro = ? WHERE id = ?";
        $stmt = mysqli_prepare($conexion, $sqlUpdate);
        mysqli_stmt_bind_param($stmt, 'sssdssi', $nombre, $fecha_egreso, $metodo_pago, $monto, $descripcion, $usuario_registro, $egreso_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $mensaje = "Egreso actualizado correctamente.";
        } else {
            $mensaje = "Error al actualizar egreso: " . mysqli_error($conexion);
        }
        mysqli_stmt_close($stmt);
    } else {
        $mensaje = "Complete todos los campos obligatorios.";
    }
}

// Procesar eliminación (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_egreso']) && $is_admin) {
    $egreso_id = (int)$_POST['egreso_id'];
    $sqlDel = "DELETE FROM egresos WHERE id = ?";
    $stmtDel = mysqli_prepare($conexion, $sqlDel);
    mysqli_stmt_bind_param($stmtDel, 'i', $egreso_id);
    if (mysqli_stmt_execute($stmtDel)) {
        $mensaje = "Egreso eliminado correctamente.";
    } else {
        $mensaje = "Error al eliminar egreso.";
    }
    mysqli_stmt_close($stmtDel);
}

// Construir consulta con filtros
$whereConditions = [];
$params = [];
$types = "";

if (!empty($_GET['fecha_inicio']) && !empty($_GET['fecha_fin'])) {
    $whereConditions[] = "fecha_egreso BETWEEN ? AND ?";
    $params[] = $_GET['fecha_inicio'];
    $params[] = $_GET['fecha_fin'];
    $types .= "ss";
} elseif (!empty($_GET['fecha_inicio'])) {
    $whereConditions[] = "fecha_egreso >= ?";
    $params[] = $_GET['fecha_inicio'];
    $types .= "s";
} elseif (!empty($_GET['fecha_fin'])) {
    $whereConditions[] = "fecha_egreso <= ?";
    $params[] = $_GET['fecha_fin'];
    $types .= "s";
}

if (!empty($_GET['mes'])) {
    $whereConditions[] = "DATE_FORMAT(fecha_egreso, '%Y-%m') = ?";
    $params[] = $_GET['mes'];
    $types .= "s";
}

$whereSQL = count($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$sql = "SELECT * FROM egresos $whereSQL ORDER BY fecha_egreso DESC, id DESC";
$stmt = mysqli_prepare($conexion, $sql);

if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$egresos = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Calcular total de egresos mostrados
$totalEgresos = array_sum(array_column($egresos, 'monto'));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Egresos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .table-responsive { overflow-x: auto; }
        .modal-header.bg-danger { background: #dc3545 !important; }
        .modal-header.bg-warning-custom { background: #ffc107 !important; }
        .card-egreso { border-left: 4px solid #dc3545; }
        .filtro-card { background: #f8f9fa; border-radius: 10px; padding: 1rem; margin-bottom: 1.5rem; }
        .btn-edit { background: #ffc107; color: #000; }
        .btn-edit:hover { background: #e0a800; color: #000; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><i class="bi bi-arrow-left me-2"></i>Control Créditos</a>
        <div class="d-flex">
            <span class="text-white me-3">👤 <?= htmlspecialchars($usuario_actual) ?></span>
            <a href="logout.php" class="btn btn-outline-light">Salir</a>
        </div>
    </div>
</nav>

<div class="container mt-4" style="max-width: 1300px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-cash"></i> Gestionar Egresos</h2>
        <button class="btn btn-danger btn-lg" data-bs-toggle="modal" data-bs-target="#modalNuevoEgreso">
            <i class="bi bi-plus-circle"></i> Nuevo Egreso
        </button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle"></i> <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="filtro-card">
        <h6 class="mb-3"><i class="bi bi-funnel"></i> Filtrar Egresos</h6>
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Tipo de filtro</label>
                <select class="form-select" id="tipoFiltro" onchange="cambiarFiltro(this.value)">
                    <option value="">Sin filtro</option>
                    <option value="rango" <?= isset($_GET['fecha_inicio']) && isset($_GET['fecha_fin']) ? 'selected' : '' ?>>Por rango de fechas</option>
                    <option value="mes" <?= isset($_GET['mes']) ? 'selected' : '' ?>>Por mes</option>
                </select>
            </div>

            <div class="col-md-3" id="campoRango" style="display: none;">
                <label class="form-label">Fecha desde</label>
                <input type="date" name="fecha_inicio" class="form-control" value="<?= $_GET['fecha_inicio'] ?? '' ?>">
            </div>

            <div class="col-md-3" id="campoRangoHasta" style="display: none;">
                <label class="form-label">Fecha hasta</label>
                <input type="date" name="fecha_fin" class="form-control" value="<?= $_GET['fecha_fin'] ?? '' ?>">
            </div>

            <div class="col-md-3" id="campoMes" style="display: none;">
                <label class="form-label">Mes</label>
                <input type="month" name="mes" class="form-control" value="<?= $_GET['mes'] ?? '' ?>">
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
            </div>
            <div class="col-md-1">
                <a href="gestionar_egresos.php" class="btn btn-outline-secondary w-100"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>

    <!-- Resumen -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-bg-danger">
                <div class="card-body">
                    <h6 class="card-title">Total Egresos (filtrados)</h6>
                    <p class="card-text fs-4 fw-bold">S/ <?= number_format($totalEgresos, 2) ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card bg-light">
                <div class="card-body">
                    <h6 class="card-title">📌 Información</h6>
                    <p class="card-text small mb-0">Los egresos registrados afectan directamente el <strong>Capital Disponible</strong> del dashboard y aparecen en el <strong>Kardex</strong> como salidas de capital.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de egresos -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Nombre</th>
                            <th>Método</th>
                            <th class="text-end">Monto (S/)</th>
                            <th>Descripción</th>
                            <th>Registrado por</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($egresos)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                    No hay egresos registrados
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($egresos as $egreso): ?>
                                <tr>
                                    <td><?= $egreso['id'] ?></td>
                                    <td><?= date('d/m/Y', strtotime($egreso['fecha_egreso'])) ?></td>
                                    <td><?= htmlspecialchars($egreso['nombre']) ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?= ucfirst(htmlspecialchars($egreso['metodo_pago'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-danger fw-bold">S/ <?= number_format($egreso['monto'], 2) ?></td>
                                    <td><?= htmlspecialchars($egreso['descripcion'] ?? '—') ?></td>
                                    <td><small><?= htmlspecialchars($egreso['usuario_registro'] ?? '—') ?></small></td>
                                    <td class="text-nowrap">
                                        <?php if ($is_admin): ?>
                                            <button class="btn btn-warning btn-sm btn-edit" data-bs-toggle="modal"
                                                data-bs-target="#modalEditarEgreso"
                                                data-id="<?= $egreso['id'] ?>"
                                                data-nombre="<?= htmlspecialchars($egreso['nombre']) ?>"
                                                data-fecha="<?= $egreso['fecha_egreso'] ?>"
                                                data-metodo="<?= $egreso['metodo_pago'] ?>"
                                                data-monto="<?= $egreso['monto'] ?>"
                                                data-descripcion="<?= htmlspecialchars($egreso['descripcion'] ?? '') ?>"
                                                data-usuario="<?= htmlspecialchars($egreso['usuario_registro'] ?? '') ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <form method="post" style="display:inline-block" onsubmit="return confirm('¿Eliminar este egreso? Esta acción no se puede deshacer.');">
                                                <input type="hidden" name="egreso_id" value="<?= $egreso['id'] ?>">
                                                <button type="submit" name="eliminar_egreso" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">Solo admin</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($egresos)): ?>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="4" class="text-end">TOTAL:</th>
                            <th class="text-end">S/ <?= number_format($totalEgresos, 2) ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nuevo Egreso -->
<div class="modal fade" id="modalNuevoEgreso" tabindex="-1" aria-labelledby="modalNuevoEgresoLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="modalNuevoEgresoLabel">
                    <i class="bi bi-cash"></i> Registrar Nuevo Egreso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nombre del egreso *</label>
                    <input type="text" class="form-control" name="nombre" placeholder="Ej: Pago de luz, Alquiler, etc." required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Fecha del egreso *</label>
                    <input type="date" class="form-control" name="fecha_egreso" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Método de pago *</label>
                    <select class="form-select" name="metodo_pago" required>
                        <option value="">Seleccione</option>
                        <option value="yape">Yape</option>
                        <option value="plin">Plin</option>
                        <option value="efectivo">Efectivo</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Monto (S/) *</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="monto" placeholder="0.00" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" name="descripcion" rows="3" placeholder="Detalle adicional del egreso..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="registrar_egreso" class="btn btn-danger">
                    <i class="bi bi-save"></i> Registrar Egreso
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Egreso (solo admin) -->
<?php if ($is_admin): ?>
<div class="modal fade" id="modalEditarEgreso" tabindex="-1" aria-labelledby="modalEditarEgresoLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header" style="background: #ffc107; color: #000;">
                <h5 class="modal-title" id="modalEditarEgresoLabel">
                    <i class="bi bi-pencil-square"></i> Editar Egreso
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="egreso_id" id="edit_egreso_id">
                
                <div class="mb-3">
                    <label class="form-label">Nombre del egreso *</label>
                    <input type="text" class="form-control" name="nombre" id="edit_nombre" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Fecha del egreso *</label>
                    <input type="date" class="form-control" name="fecha_egreso" id="edit_fecha" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Método de pago *</label>
                    <select class="form-select" name="metodo_pago" id="edit_metodo" required>
                        <option value="yape">Yape</option>
                        <option value="plin">Plin</option>
                        <option value="efectivo">Efectivo</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Monto (S/) *</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" name="monto" id="edit_monto" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" name="descripcion" id="edit_descripcion" rows="3" placeholder="Detalle adicional del egreso..."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label"><i class="bi bi-person-badge"></i> Usuario que registró</label>
                    <select class="form-select" name="usuario_registro" id="edit_usuario" required>
                        <option value="">Seleccione usuario</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= htmlspecialchars($u['usuario']) ?>"><?= htmlspecialchars($u['usuario']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Puedes cambiar el usuario que registró este egreso</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="editar_egreso" class="btn btn-warning">
                    <i class="bi bi-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function cambiarFiltro(tipo) {
        document.getElementById('campoRango').style.display = tipo === 'rango' ? 'block' : 'none';
        document.getElementById('campoRangoHasta').style.display = tipo === 'rango' ? 'block' : 'none';
        document.getElementById('campoMes').style.display = tipo === 'mes' ? 'block' : 'none';
    }

    // Restaurar estado del filtro al cargar
    (function() {
        const tipoSelect = document.getElementById('tipoFiltro');
        if (tipoSelect && tipoSelect.value) {
            cambiarFiltro(tipoSelect.value);
        }
    })();

    // Modal editar egreso - cargar datos
    <?php if ($is_admin): ?>
    const modalEditar = document.getElementById('modalEditarEgreso');
    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('edit_egreso_id').value = button.dataset.id;
            document.getElementById('edit_nombre').value = button.dataset.nombre;
            document.getElementById('edit_fecha').value = button.dataset.fecha;
            document.getElementById('edit_metodo').value = button.dataset.metodo;
            document.getElementById('edit_monto').value = button.dataset.monto;
            document.getElementById('edit_descripcion').value = button.dataset.descripcion || '';
            document.getElementById('edit_usuario').value = button.dataset.usuario;
        });
    }
    <?php endif; ?>

    <?php if ($mensaje): ?>
        Swal.fire({
            icon: '<?= strpos($mensaje, "correctamente") !== false ? "success" : (strpos($mensaje, "Error") !== false ? "error" : "info") ?>',
            title: '<?= strpos($mensaje, "correctamente") !== false ? "Éxito" : (strpos($mensaje, "Error") !== false ? "Error" : "Información") ?>',
            text: '<?= addslashes($mensaje) ?>',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    <?php endif; ?>
</script>
</body>
</html>