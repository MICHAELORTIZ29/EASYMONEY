<?php
session_start();
include 'includes/conexion.php';

if (!isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit();
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Iniciar transacción para asegurar la integridad de los datos
    mysqli_begin_transaction($conexion);
    
    try {
        // 1. Eliminar primero los pagos asociados a las ventas del cliente
        $sql_pagos = "DELETE p FROM pagos p
                     INNER JOIN ventas v ON p.venta_id = v.id
                     WHERE v.cliente_id = ?";
        $stmt_pagos = mysqli_prepare($conexion, $sql_pagos);
        mysqli_stmt_bind_param($stmt_pagos, 'i', $id);
        mysqli_stmt_execute($stmt_pagos);
        
        // 2. Luego eliminar las ventas del cliente
        $sql_ventas = "DELETE FROM ventas WHERE cliente_id = ?";
        $stmt_ventas = mysqli_prepare($conexion, $sql_ventas);
        mysqli_stmt_bind_param($stmt_ventas, 'i', $id);
        mysqli_stmt_execute($stmt_ventas);
        
        // 3. Finalmente eliminar el cliente
        $sql_cliente = "DELETE FROM clientes WHERE id = ?";
        $stmt_cliente = mysqli_prepare($conexion, $sql_cliente);
        mysqli_stmt_bind_param($stmt_cliente, 'i', $id);
        mysqli_stmt_execute($stmt_cliente);
        
        // Confirmar todos los cambios
        mysqli_commit($conexion);
        $_SESSION['mensaje'] = "Cliente eliminado correctamente con todos sus registros asociados";
    } catch (Exception $e) {
        // Revertir en caso de error
        mysqli_rollback($conexion);
        $_SESSION['error'] = "Error al eliminar el cliente: " . $e->getMessage();
    }
}

header('Location: clientes.php');
exit();
?>