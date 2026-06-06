<?php
include 'includes/conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recibir datos del formulario (opcionales)
    $dni = !empty($_POST['dni']) ? trim($_POST['dni']) : null;
    $nombre = !empty($_POST['nombre']) ? trim($_POST['nombre']) : null;
    $latitud = !empty($_POST['latitud']) ? trim($_POST['latitud']) : null;
    $longitud = !empty($_POST['longitud']) ? trim($_POST['longitud']) : null;
    $direccion = !empty($_POST['direccion']) ? trim($_POST['direccion']) : null;
    $referencia = !empty($_POST['referencia']) ? trim($_POST['referencia']) : null;

    // Limpiar entradas (si existen)
    $dni = $dni ? mysqli_real_escape_string($conexion, $dni) : null;
    $nombre = $nombre ? mysqli_real_escape_string($conexion, $nombre) : null;
    $direccion = $direccion ? mysqli_real_escape_string($conexion, $direccion) : null;
    $referencia = $referencia ? mysqli_real_escape_string($conexion, $referencia) : null;

    // === FOTO DNI FRONTAL ===
    $dniFrontalPath = null;
    if (!empty($_FILES['dni_frontal']['name'])) {
        $frontalNombre = time() . "_frontal_" . basename($_FILES['dni_frontal']['name']);
        $dniFrontalPath = "uploads/dni/" . $frontalNombre;

        if (!is_dir("uploads/dni")) {
            mkdir("uploads/dni", 0777, true);
        }
        move_uploaded_file($_FILES['dni_frontal']['tmp_name'], $dniFrontalPath);
    }

    // === FOTO DNI POSTERIOR ===
    $dniPosteriorPath = null;
    if (!empty($_FILES['dni_posterior']['name'])) {
        $posteriorNombre = time() . "_posterior_" . basename($_FILES['dni_posterior']['name']);
        $dniPosteriorPath = "uploads/dni/" . $posteriorNombre;

        if (!is_dir("uploads/dni")) {
            mkdir("uploads/dni", 0777, true);
        }
        move_uploaded_file($_FILES['dni_posterior']['tmp_name'], $dniPosteriorPath);
    }

    // === FOTO CLIENTE ===
    $fotoPath = null;
    if (!empty($_FILES['foto']['name'])) {
        $fotoNombre = time() . "_" . basename($_FILES['foto']['name']);
        $fotoPath = "uploads/fotos/" . $fotoNombre;

        if (!is_dir("uploads/fotos")) {
            mkdir("uploads/fotos", 0777, true);
        }

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $fotoPath)) {
            $fotoPath = null; // si falla la subida
        }
    }

    // === DOCUMENTOS (múltiples) ===
    $documentosPaths = [];
    if (!empty($_FILES['documentos']['name'][0])) {
        if (!is_dir("uploads/documentos")) {
            mkdir("uploads/documentos", 0777, true);
        }

        foreach ($_FILES['documentos']['name'] as $i => $docNombre) {
            if ($_FILES['documentos']['error'][$i] === UPLOAD_ERR_OK) {
                $docNuevo = time() . "_" . basename($docNombre);
                $docPath = "uploads/documentos/" . $docNuevo;

                if (move_uploaded_file($_FILES['documentos']['tmp_name'][$i], $docPath)) {
                    $documentosPaths[] = $docPath;
                }
            }
        }
    }
    $docs = !empty($documentosPaths) ? implode(",", $documentosPaths) : null;

    // === INSERTAR NUEVO CLIENTE ===
    $sqlInsert = "INSERT INTO clientes 
        (dni, nombre, foto, documentos, latitud, longitud, dni_frontal, dni_posterior, direccion, referencia) 
        VALUES (
            " . ($dni ? "'$dni'" : "NULL") . ",
            " . ($nombre ? "'$nombre'" : "NULL") . ",
            " . ($fotoPath ? "'$fotoPath'" : "NULL") . ", 
            " . ($docs ? "'$docs'" : "NULL") . ", 
            " . ($latitud ? "'$latitud'" : "NULL") . ", 
            " . ($longitud ? "'$longitud'" : "NULL") . ", 
            " . ($dniFrontalPath ? "'$dniFrontalPath'" : "NULL") . ", 
            " . ($dniPosteriorPath ? "'$dniPosteriorPath'" : "NULL") . ", 
            " . ($direccion ? "'$direccion'" : "NULL") . ", 
            " . ($referencia ? "'$referencia'" : "NULL") . "
        )";

    if (mysqli_query($conexion, $sqlInsert)) {
        header("Location: clientes.php?mensaje=Cliente agregado correctamente");
        exit;
    } else {
        echo "Error al guardar cliente: " . mysqli_error($conexion);
    }
} else {
    echo "Acceso no permitido.";
}
?>
