<?php
// conexion.php — Conexión PDO y utilidades de sesión para servicios_tecnologicos_db
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = '127.0.0.1';
$db   = 'servicios_tecnologicos_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Error de conexión con la base de datos: ' . htmlspecialchars($e->getMessage()));
}

/**
 * Verifica si existe un usuario autenticado en la sesión actual.
 */
function estaLogueado(): bool {
    return isset($_SESSION['id_usuario']) && !empty($_SESSION['id_usuario']);
}

/**
 * Devuelve los datos básicos del usuario autenticado o null si es visitante.
 */
function usuarioActual(): ?array {
    if (!estaLogueado()) {
        return null;
    }
    return [
        'id_usuario' => $_SESSION['id_usuario'],
        'nombres'    => $_SESSION['nombres'] ?? '',
        'apellidos'  => $_SESSION['apellidos'] ?? '',
        'correo'     => $_SESSION['correo'] ?? '',
        'id_rol'     => $_SESSION['id_rol'] ?? 3,
    ];
}

/**
 * Obtiene la configuración institucional (Misión, Visión, contacto) desde datos_empresa.
 */
function obtenerDatosEmpresa(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM datos_empresa WHERE id_configuracion = 1 LIMIT 1");
    $empresa = $stmt->fetch();
    return $empresa ?: [
        'nombre_empresa'  => 'Capsule Tech',
        'mision'          => 'Facilitar el acceso a recursos y servicios tecnológicos.',
        'vision'          => 'Ser un espacio confiable donde la tecnología esté al alcance de nuestra comunidad.',
        'correo_contacto' => 'contacto@techsolutions.com',
        'direccion'       => 'Huejutla de Reyes, Hidalgo, México',
    ];
}

/**
 * Mapea el nombre de una categoría a una clase de Bootstrap Icons.
 */
function iconoCategoria(string $categoria): string {
    $cat = mb_strtolower(trim($categoria), 'UTF-8');
    return match (true) {
        str_contains($cat, 'cómputo') || str_contains($cat, 'computo') => 'bi-laptop',
        str_contains($cat, 'audio') || str_contains($cat, 'video')     => 'bi-projector',
        str_contains($cat, 'electrónica') || str_contains($cat, 'electronica') => 'bi-cpu',
        str_contains($cat, 'redes')                                    => 'bi-router',
        str_contains($cat, 'consumibles')                              => 'bi-box-seam',
        str_contains($cat, 'mantenimiento')                            => 'bi-wrench',
        str_contains($cat, 'reparación') || str_contains($cat, 'reparacion') => 'bi-tools',
        str_contains($cat, 'licencia')                                 => 'bi-key',
        default                                                        => 'bi-headset',
    };
}