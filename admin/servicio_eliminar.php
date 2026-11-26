<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/administrar_catalogo_servicios.php');
    exit;
}

$usuario = usuarioActual();
if ($usuario['id_rol'] != 1 && $usuario['id_rol'] != 2) {
    header('Location: ../index.php');
    exit;
}

$id = $_GET['id'] ?? null;

if ($id && is_numeric($id)) {
    // Verificar que el servicio no esté asociado a órdenes de servicio existentes
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM orden_servicio WHERE id_servicio = ?");
    $stmtCheck->execute([$id]);

    if ($stmtCheck->fetchColumn() > 0) {
        header('Location: administrar_catalogo_servicios.php?err=tiene_ordenes');
        exit;
    }

    // Eliminar el servicio del catálogo
    $stmtDel = $pdo->prepare("DELETE FROM servicio WHERE id_servicio = ?");
    $stmtDel->execute([$id]);
}

header('Location: administrar_catalogo_servicios.php?msg=eliminado');
exit;