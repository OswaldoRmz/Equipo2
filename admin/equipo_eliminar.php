<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/administrar_equipos.php');
    exit;
}

$usuario = usuarioActual();
if ($usuario['id_rol'] != 1 && $usuario['id_rol'] != 2) {
    header('Location: ../index.php');
    exit;
}

$id = $_GET['id'] ?? null;

if ($id && is_numeric($id)) {
    // 1. Verificar que no tenga préstamos asociados en la tabla prestamo
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM prestamo WHERE id_equipo = ?");
    $stmtCheck->execute([$id]);

    if ($stmtCheck->fetchColumn() > 0) {
        header('Location: administrar_equipos.php?err=tiene_prestamos');
        exit;
    }

    // 2. Buscar el nombre de la imagen antes de borrar el registro
    $stmtBusq = $pdo->prepare("SELECT imagen_url FROM equipo WHERE id_equipo = ?");
    $stmtBusq->execute([$id]);
    $equipo = $stmtBusq->fetch();

    if ($equipo) {
        if (!empty($equipo['imagen_url'])) {
            $rutaArchivo = __DIR__ . '/../uploads/equipos/' . $equipo['imagen_url'];
            if (file_exists($rutaArchivo)) {
                unlink($rutaArchivo);
            }
        }

        // 3. Eliminar el registro de la base de datos
        $stmtDel = $pdo->prepare("DELETE FROM equipo WHERE id_equipo = ?");
        $stmtDel->execute([$id]);
    }
}

header('Location: administrar_equipos.php?msg=eliminado');
exit;