<?php
require_once 'config/conexion.php';

// Utilidades de la barra de administrador (las mismas de servicios.php e inventario.php).
// defined() y function_exists() evitan un error si algún día se mueven a config/conexion.php.
if (!defined('ROL_ADMINISTRADOR')) {
    define('ROL_ADMINISTRADOR', 1);
}

if (!function_exists('inicialesUsuario')) {
    /**
     * Iniciales del usuario para el avatar (ej. "Admin Principal" → "AP").
     */
    function inicialesUsuario(array $usuario): string {
        $iniciales = mb_substr(trim($usuario['nombres']), 0, 1, 'UTF-8') . mb_substr(trim($usuario['apellidos']), 0, 1, 'UTF-8');
        return $iniciales !== '' ? mb_strtoupper($iniciales, 'UTF-8') : 'U';
    }
}

/**
 * Fecha y hora de un movimiento: "17 nov 2026 · 09:42".
 */
function fechaActividad(string $fecha): string {
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ts = strtotime($fecha);
    return date('j', $ts) . ' ' . $meses[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) . ' · ' . date('H:i', $ts);
}

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

// Página privada: solo usuarios con sesión iniciada
if (!$usuario) {
    header('Location: login.php?redirect=perfil.php');
    exit;
}

// Rol real de la cuenta: el administrador usa su perfil igual que cualquier usuario y conserva
// sus permisos; su panel (admin/dashboard.php) se abre desde el menú de su nombre.
$esAdministrador = (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;
$seccionActiva = (($_GET['seccion'] ?? '') === 'seguridad') ? 'seguridad' : 'datos';

// Mensajes guardados antes de redirigir (evita repetir una acción al recargar)
$mensajeExito = $_SESSION['flash_perfil'] ?? '';
unset($_SESSION['flash_perfil']);
$mensajeError = '';

// Datos completos del usuario desde la BD (la sesión solo guarda nombre, apellidos, correo y rol)
$stmtUsuario = $pdo->prepare("
    SELECT u.id_usuario, u.nombres, u.apellidos, u.telefono, u.correo, u.password_hash, u.direccion, r.tipo_rol
    FROM usuario u
    LEFT JOIN rol r ON r.id_rol = u.id_rol
    WHERE u.id_usuario = :id
    LIMIT 1
");
$stmtUsuario->execute([':id' => $usuario['id_usuario']]);
$cuenta = $stmtUsuario->fetch();

if (!$cuenta) {
    // La cuenta ya no existe: se cierra la sesión
    header('Location: logout.php');
    exit;
}

$erroresDatos = [];
$valoresDatos = [
    'nombres'   => $cuenta['nombres'],
    'apellidos' => $cuenta['apellidos'],
    'telefono'  => $cuenta['telefono'],
    'correo'    => $cuenta['correo'],
    'direccion' => $cuenta['direccion'] ?? '',
];
$erroresContrasena = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ---------- Mis datos ----------
    if ($accion === 'guardar_datos') {
        $valoresDatos = [
            'nombres'   => trim($_POST['nombres'] ?? ''),
            'apellidos' => trim($_POST['apellidos'] ?? ''),
            'telefono'  => trim($_POST['telefono'] ?? ''),
            'correo'    => trim($_POST['correo'] ?? ''),
            'direccion' => trim($_POST['direccion'] ?? ''),
        ];

        $largoNombres = mb_strlen($valoresDatos['nombres'], 'UTF-8');
        if ($largoNombres === 0) {
            $erroresDatos['nombres'] = 'Escribe tu nombre.';
        } elseif ($largoNombres > 50) {
            $erroresDatos['nombres'] = 'El nombre no puede superar los 50 caracteres.';
        }

        $largoApellidos = mb_strlen($valoresDatos['apellidos'], 'UTF-8');
        if ($largoApellidos === 0) {
            $erroresDatos['apellidos'] = 'Escribe al menos tu apellido paterno.';
        } elseif ($largoApellidos > 100) {
            $erroresDatos['apellidos'] = 'Los apellidos no pueden superar los 100 caracteres.';
        }

        // Mismas reglas que en Crear cuenta
        if ($valoresDatos['telefono'] === '' || strlen($valoresDatos['telefono']) > 15) {
            $erroresDatos['telefono'] = 'Ingresa un número de teléfono válido (máximo 15 caracteres).';
        }

        if (!filter_var($valoresDatos['correo'], FILTER_VALIDATE_EMAIL) || strlen($valoresDatos['correo']) > 100) {
            $erroresDatos['correo'] = 'Ingresa un correo electrónico válido.';
        } else {
            $stmtCorreo = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = :correo AND id_usuario <> :id LIMIT 1");
            $stmtCorreo->execute([':correo' => $valoresDatos['correo'], ':id' => $cuenta['id_usuario']]);
            if ($stmtCorreo->fetch()) {
                $erroresDatos['correo'] = 'Este correo ya está registrado en otra cuenta.';
            }
        }

        if (mb_strlen($valoresDatos['direccion'], 'UTF-8') > 150) {
            $erroresDatos['direccion'] = 'La dirección no puede superar los 150 caracteres.';
        }

        if (empty($erroresDatos)) {
            $pdo->prepare("
                UPDATE usuario
                SET nombres = :nombres, apellidos = :apellidos, telefono = :telefono, correo = :correo, direccion = :direccion
                WHERE id_usuario = :id
            ")->execute([
                ':nombres'   => $valoresDatos['nombres'],
                ':apellidos' => $valoresDatos['apellidos'],
                ':telefono'  => $valoresDatos['telefono'],
                ':correo'    => $valoresDatos['correo'],
                ':direccion' => $valoresDatos['direccion'] === '' ? null : $valoresDatos['direccion'],
                ':id'        => $cuenta['id_usuario'],
            ]);

            // La barra de navegación lee estos datos de la sesión
            $_SESSION['nombres']   = $valoresDatos['nombres'];
            $_SESSION['apellidos'] = $valoresDatos['apellidos'];
            $_SESSION['correo']    = $valoresDatos['correo'];

            $_SESSION['flash_perfil'] = 'Se guardaron tus datos.';
            header('Location: perfil.php');
            exit;
        }
        $seccionActiva = 'datos';
    }

    // ---------- Seguridad: cambiar contraseña ----------
    if ($accion === 'cambiar_contrasena') {
        $actual    = $_POST['password_actual'] ?? '';
        $nueva     = $_POST['password_nueva'] ?? '';
        $confirmar = $_POST['password_confirmar'] ?? '';

        if ($actual === '') {
            $erroresContrasena['password_actual'] = 'Escribe tu contraseña actual.';
        } elseif (!password_verify($actual, $cuenta['password_hash'])) {
            $erroresContrasena['password_actual'] = 'La contraseña actual no es correcta.';
        }

        // Misma regla que en Crear cuenta: al menos 6 caracteres
        if (strlen($nueva) < 6) {
            $erroresContrasena['password_nueva'] = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif (!isset($erroresContrasena['password_actual']) && $nueva === $actual) {
            $erroresContrasena['password_nueva'] = 'La nueva contraseña debe ser distinta de la actual.';
        }

        if ($confirmar !== $nueva) {
            $erroresContrasena['password_confirmar'] = 'Las contraseñas no coinciden.';
        }

        if (empty($erroresContrasena)) {
            $pdo->prepare("UPDATE usuario SET password_hash = :hash WHERE id_usuario = :id")
                ->execute([':hash' => password_hash($nueva, PASSWORD_DEFAULT), ':id' => $cuenta['id_usuario']]);
            session_regenerate_id(true);

            $_SESSION['flash_perfil'] = 'Tu contraseña se actualizó. Úsala la próxima vez que inicies sesión.';
            header('Location: perfil.php?seccion=seguridad');
            exit;
        }
        $seccionActiva = 'seguridad';
    }
}

// ---------- Resumen y actividad reciente (solo usuarios; el administrador no solicita) ----------
$totalPrestamosActivos = 0;
$totalSolicitudesEnCurso = 0;
$totalServiciosCompletados = 0;
$actividad = [];

$stmtPrestamos = $pdo->prepare("
    SELECT p.folio, p.estado, p.fecha_registro, p.fecha_entrega, p.fecha_devolucion, e.nombre AS equipo
    FROM prestamo p
    INNER JOIN equipo e ON e.id_equipo = p.id_equipo
    WHERE p.id_cliente = :id
");
$stmtPrestamos->execute([':id' => $cuenta['id_usuario']]);
$prestamos = $stmtPrestamos->fetchAll();

$stmtServicios = $pdo->prepare("
    SELECT o.folio, o.estado, o.fecha_ingreso, o.fecha_entrega, o.equipo_receptor, s.nombre AS servicio
    FROM orden_servicio o
    LEFT JOIN servicio s ON s.id_servicio = o.id_servicio
    WHERE o.id_cliente = :id
");
$stmtServicios->execute([':id' => $cuenta['id_usuario']]);
$servicios = $stmtServicios->fetchAll();

foreach ($prestamos as $pr) {
    if ($pr['estado'] === 'Prestado') {
        $totalPrestamosActivos++;
    } elseif ($pr['estado'] === 'Pendiente') {
        $totalSolicitudesEnCurso++;
    }

    // Cada préstamo puede generar varios movimientos: solicitado, entregado, devuelto o rechazado
    $actividad[] = ['fecha' => $pr['fecha_registro'], 'icono' => 'bi-box-arrow-up-right', 'titulo' => 'Préstamo solicitado',
                    'detalle' => $pr['equipo'] . ' · ' . ($pr['estado'] === 'Pendiente' ? 'Pendiente de confirmación' : 'Folio ' . $pr['folio'])];
    if (!empty($pr['fecha_entrega'])) {
        $actividad[] = ['fecha' => $pr['fecha_entrega'], 'icono' => 'bi-laptop', 'titulo' => 'Equipo entregado',
                        'detalle' => $pr['equipo'] . ' · Préstamo ' . $pr['folio']];
    }
    if (!empty($pr['fecha_devolucion'])) {
        $actividad[] = ['fecha' => $pr['fecha_devolucion'], 'icono' => 'bi-arrow-return-left', 'titulo' => 'Equipo devuelto',
                        'detalle' => $pr['equipo'] . ' · Devolución completada'];
    }
    if ($pr['estado'] === 'Cancelado') {
        $actividad[] = ['fecha' => $pr['fecha_registro'], 'icono' => 'bi-x-circle', 'titulo' => 'Solicitud de préstamo rechazada',
                        'detalle' => $pr['equipo'] . ' · Folio ' . $pr['folio']];
    }
}

foreach ($servicios as $srv) {
    $nombreServicio = $srv['servicio'] ?? 'Servicio general';
    if (in_array($srv['estado'], ['Reparado', 'Entregado'], true)) {
        $totalServiciosCompletados++;
    } elseif ($srv['estado'] !== 'Cancelado') {
        $totalSolicitudesEnCurso++;
    }

    $actividad[] = ['fecha' => $srv['fecha_ingreso'], 'icono' => 'bi-tools', 'titulo' => 'Servicio solicitado',
                    'detalle' => $nombreServicio . ' · ' . $srv['equipo_receptor']];
    if (!empty($srv['fecha_entrega']) && in_array($srv['estado'], ['Reparado', 'Entregado'], true)) {
        $actividad[] = ['fecha' => $srv['fecha_entrega'], 'icono' => 'bi-check2-circle', 'titulo' => 'Servicio resuelto',
                        'detalle' => $nombreServicio . ' · ' . $srv['estado']];
    }
}

// Los 5 movimientos más recientes
usort($actividad, fn($a, $b) => strtotime($b['fecha']) <=> strtotime($a['fecha']));
$actividad = array_slice($actividad, 0, 5);

$nombreCompleto = trim($cuenta['nombres'] . ' ' . $cuenta['apellidos']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Perfil — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos compartidos: navbar, botones y footer (inventario.css);
       pestañas, tarjetas de resumen, campos e insignia de rol (servicios.css) -->
  <link rel="stylesheet" href="styles/inventario.css" />
  <link rel="stylesheet" href="styles/servicios.css" />
  <!-- Estilos propios del Perfil -->
  <link rel="stylesheet" href="styles/perfil.css" />
</head>
<body>

  <!-- ================= NAVBAR SUPERIOR ================= -->
  <header class="main-navbar sticky-top">
    <nav class="navbar navbar-expand-lg py-3">
      <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-3 m-0" href="index.php">
          <img
            src="images/logo.jpeg"
            alt="Logo oficial Capsule Tech"
            width="46"
            height="46"
            class="brand-logo"
          />
          <div>
            <div class="brand-title">Capsule Tech</div>
            <div class="brand-subtitle">Servicios Tecnológicos</div>
          </div>
        </a>

        <button
          class="navbar-toggler border-0 shadow-none"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#mainNav"
          aria-controls="mainNav"
          aria-expanded="false"
          aria-label="Abrir menú"
        >
          <i class="bi bi-list fs-2 text-navy"></i>
        </button>

        <div class="collapse navbar-collapse justify-content-end mt-3 mt-lg-0" id="mainNav">
          <ul class="navbar-nav align-items-lg-center gap-1 gap-lg-2">
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="index.php">Inicio</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="servicios.php">Servicios</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="inventario.php">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="acerca_de.php">Acerca de</a>
            </li>

            <li class="nav-item dropdown ms-lg-2">
              <a class="nav-link nav-pill-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars(explode(' ', $usuario['nombres'])[0]) ?></span>
              </a>
              <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm">
                <li><span class="dropdown-item-text small text-muted"><?= htmlspecialchars($usuario['correo']) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item fw-semibold" aria-current="page" href="perfil.php"><i class="bi bi-person me-2"></i>Perfil</a></li>
                <li><a class="dropdown-item" href="mis_solicitudes.php"><i class="bi bi-clipboard-check me-2"></i>Mis solicitudes</a></li>
                <?php if ($esAdministrador): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="admin/dashboard.php"><i class="bi bi-grid me-2"></i>Panel de administración</a></li>
                <?php endif; ?>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
              </ul>
            </li>
          </ul>
        </div>
      </div>
    </nav>
  </header>

  <main>
    <!-- ================= IDENTIDAD, RESUMEN Y PESTAÑAS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-perfil">
      <div class="container">
        <?php if ($mensajeExito !== ''): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($mensajeExito) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
          </div>
        <?php endif; ?>

        <h1 id="titulo-perfil" class="page-title mb-4">Perfil</h1>

        <!-- Tarjeta del usuario (BD: usuario y rol) -->
        <div class="profile-card d-flex flex-column flex-md-row align-items-md-center gap-4">
          <div class="profile-avatar" aria-hidden="true"><?= htmlspecialchars(inicialesUsuario($cuenta)) ?></div>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3 mb-2">
              <h2 class="profile-name mb-0"><?= htmlspecialchars($nombreCompleto) ?></h2>
              <span class="role-pill role-pill-sm"><?= $esAdministrador ? 'Administrador' : 'Usuario' ?></span>
            </div>
            <p class="profile-meta mb-1"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars($cuenta['correo']) ?></p>
            <p class="profile-meta mb-0"><i class="bi bi-telephone me-2"></i><?= htmlspecialchars($cuenta['telefono']) ?></p>
          </div>
          <a href="perfil.php#mis-datos" class="btn btn-card-loan profile-edit-btn flex-shrink-0" id="botonEditarPerfil">Editar perfil</a>
        </div>

        <!-- Resumen de actividad (BD: prestamo y orden_servicio) -->
        <div class="row g-3 mt-2">
          <div class="col-md-4">
            <div class="admin-stat-card is-highlighted h-100">
              <span class="admin-stat-label">Préstamos activos</span>
              <strong class="admin-stat-value"><?= $totalPrestamosActivos ?></strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Solicitudes en curso</span>
              <strong class="admin-stat-value"><?= $totalSolicitudesEnCurso ?></strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Servicios completados</span>
              <strong class="admin-stat-value"><?= $totalServiciosCompletados ?></strong>
            </div>
          </div>
        </div>

        <!-- Pestañas: Mis préstamos y Mis solicitudes abren mis_solicitudes.php -->
        <nav class="admin-tabs profile-tabs mt-4" aria-label="Secciones del perfil">
          <a href="perfil.php" class="admin-tab <?= $seccionActiva === 'datos' ? 'active' : '' ?>" <?= $seccionActiva === 'datos' ? 'aria-current="page"' : '' ?>>Mis datos</a>
          <a href="mis_solicitudes.php?seccion=prestamos" class="admin-tab">Mis préstamos</a>
          <a href="mis_solicitudes.php" class="admin-tab">Mis solicitudes</a>
          <a href="perfil.php?seccion=seguridad" class="admin-tab <?= $seccionActiva === 'seguridad' ? 'active' : '' ?>" <?= $seccionActiva === 'seguridad' ? 'aria-current="page"' : '' ?>>Seguridad</a>
        </nav>
      </div>
    </section>

    <section class="pb-5 bg-white">
      <div class="container">
        <?php if ($seccionActiva === 'datos'): ?>
          <!-- ================= MIS DATOS (BD: usuario) ================= -->
          <form action="perfil.php" method="POST" class="profile-form-card mt-3" id="mis-datos" novalidate>
            <input type="hidden" name="accion" value="guardar_datos" />

            <h2 class="profile-section-title">Mis datos</h2>
            <p class="profile-section-text">Revisa y actualiza tu información personal.</p>

            <div class="row g-3">
              <div class="col-12">
                <label for="perfilNombres" class="form-label service-label">Nombre(s)</label>
                <input
                  type="text"
                  id="perfilNombres"
                  name="nombres"
                  class="form-control service-input<?= isset($erroresDatos['nombres']) ? ' is-invalid' : '' ?>"
                  maxlength="50"
                  value="<?= htmlspecialchars($valoresDatos['nombres']) ?>"
                  autocomplete="given-name"
                  required
                />
                <div class="invalid-feedback" id="error-perfil-nombres"><?= htmlspecialchars($erroresDatos['nombres'] ?? 'Escribe tu nombre.') ?></div>
              </div>
              <div class="col-md-6">
                <label for="perfilApellidos" class="form-label service-label">Apellidos</label>
                <input
                  type="text"
                  id="perfilApellidos"
                  name="apellidos"
                  class="form-control service-input<?= isset($erroresDatos['apellidos']) ? ' is-invalid' : '' ?>"
                  maxlength="100"
                  value="<?= htmlspecialchars($valoresDatos['apellidos']) ?>"
                  placeholder="Apellido paterno y materno"
                  autocomplete="family-name"
                  required
                />
                <div class="invalid-feedback" id="error-perfil-apellidos"><?= htmlspecialchars($erroresDatos['apellidos'] ?? 'Escribe al menos tu apellido paterno.') ?></div>
              </div>
              <div class="col-md-6">
                <label for="perfilTelefono" class="form-label service-label">Teléfono</label>
                <input
                  type="tel"
                  id="perfilTelefono"
                  name="telefono"
                  class="form-control service-input<?= isset($erroresDatos['telefono']) ? ' is-invalid' : '' ?>"
                  maxlength="15"
                  value="<?= htmlspecialchars($valoresDatos['telefono']) ?>"
                  placeholder="Ej. 7712345678"
                  autocomplete="tel"
                  required
                />
                <div class="invalid-feedback" id="error-perfil-telefono"><?= htmlspecialchars($erroresDatos['telefono'] ?? 'Ingresa un número de teléfono válido (máximo 15 caracteres).') ?></div>
              </div>
              <div class="col-md-6">
                <label for="perfilCorreo" class="form-label service-label">Correo electrónico</label>
                <input
                  type="email"
                  id="perfilCorreo"
                  name="correo"
                  class="form-control service-input<?= isset($erroresDatos['correo']) ? ' is-invalid' : '' ?>"
                  maxlength="100"
                  value="<?= htmlspecialchars($valoresDatos['correo']) ?>"
                  aria-describedby="ayudaCorreo"
                  autocomplete="email"
                  required
                />
                <div class="invalid-feedback" id="error-perfil-correo"><?= htmlspecialchars($erroresDatos['correo'] ?? 'Ingresa un correo electrónico válido.') ?></div>
                <div class="form-text service-help" id="ayudaCorreo">Con este correo inicias sesión.</div>
              </div>
              <div class="col-md-6">
                <label for="perfilDireccion" class="form-label service-label">Dirección <span class="fw-normal text-muted">(opcional)</span></label>
                <input
                  type="text"
                  id="perfilDireccion"
                  name="direccion"
                  class="form-control service-input<?= isset($erroresDatos['direccion']) ? ' is-invalid' : '' ?>"
                  maxlength="150"
                  value="<?= htmlspecialchars($valoresDatos['direccion']) ?>"
                  placeholder="Ej. Calle, número y colonia"
                  autocomplete="street-address"
                />
                <div class="invalid-feedback" id="error-perfil-direccion"><?= htmlspecialchars($erroresDatos['direccion'] ?? 'La dirección no puede superar los 150 caracteres.') ?></div>
              </div>
            </div>

            <div class="d-flex flex-column flex-md-row justify-content-end gap-3 mt-4">
              <button type="submit" class="btn btn-card-loan profile-save-btn" id="botonGuardarDatos">Guardar cambios</button>
            </div>
          </form>

          <!-- ================= ACTIVIDAD RECIENTE (BD: prestamo y orden_servicio) ================= -->
          <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-5 mb-3">
            <h2 class="profile-section-title mb-0">Actividad reciente</h2>
            <a href="mis_solicitudes.php" class="footer-action-link">Ver todas mis solicitudes <i class="bi bi-arrow-right ms-1"></i></a>
          </div>

          <?php if (count($actividad) === 0): ?>
            <div class="db-loan-placeholder flex-column gap-2 py-4">
              <span>Todavía no tienes movimientos.</span>
              <a href="servicios.php" class="footer-action-link">Solicitar un servicio <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
          <?php else: ?>
            <ul class="activity-list list-unstyled mb-0">
              <?php foreach ($actividad as $mov): ?>
                <li class="activity-item">
                  <span class="activity-icon" aria-hidden="true"><i class="bi <?= $mov['icono'] ?>"></i></span>
                  <div class="flex-grow-1 min-w-0">
                    <p class="activity-title mb-0"><?= htmlspecialchars($mov['titulo']) ?></p>
                    <p class="activity-detail mb-0"><?= htmlspecialchars($mov['detalle']) ?></p>
                  </div>
                  <span class="activity-date"><?= fechaActividad($mov['fecha']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

        <?php else: ?>
          <!-- ================= SEGURIDAD Y SESIÓN ================= -->
          <div class="row g-4 mt-0">
            <div class="col-lg-7">
              <form action="perfil.php?seccion=seguridad" method="POST" class="profile-form-card h-100" id="formContrasena" novalidate>
                <input type="hidden" name="accion" value="cambiar_contrasena" />

                <h2 class="profile-section-title">Seguridad</h2>
                <p class="profile-section-text">Actualiza tu contraseña para proteger tu cuenta.</p>

                <?php
                  $camposContrasena = [
                      'password_actual'    => ['Contraseña actual', 'current-password', 'Escribe tu contraseña actual.'],
                      'password_nueva'     => ['Nueva contraseña', 'new-password', 'La nueva contraseña debe tener al menos 6 caracteres.'],
                      'password_confirmar' => ['Confirmar contraseña', 'new-password', 'Las contraseñas no coinciden.'],
                  ];
                ?>
                <?php foreach ($camposContrasena as $campo => [$etiqueta, $autocompletar, $mensajeDefecto]): ?>
                  <div class="mb-3">
                    <label for="<?= $campo ?>" class="form-label service-label"><?= $etiqueta ?></label>
                    <div class="password-field">
                      <i class="bi bi-lock password-icon" aria-hidden="true"></i>
                      <input
                        type="password"
                        id="<?= $campo ?>"
                        name="<?= $campo ?>"
                        class="form-control service-input<?= isset($erroresContrasena[$campo]) ? ' is-invalid' : '' ?>"
                        autocomplete="<?= $autocompletar ?>"
                        required
                      />
                      <button type="button" class="password-toggle" data-target="<?= $campo ?>" aria-label="Mostrar u ocultar <?= mb_strtolower($etiqueta, 'UTF-8') ?>">
                        <i class="bi bi-eye"></i>
                      </button>
                      <div class="invalid-feedback" id="error-<?= $campo ?>"><?= htmlspecialchars($erroresContrasena[$campo] ?? $mensajeDefecto) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>

                <p class="service-help mb-4">Elige una contraseña de al menos 6 caracteres que no uses en otros sitios.</p>
                <button type="submit" class="btn btn-card-loan w-100 profile-save-btn" id="botonContrasena">Actualizar contraseña</button>
              </form>
            </div>

            <div class="col-lg-5 d-flex flex-column gap-4">
              <div class="profile-form-card">
                <h2 class="profile-section-title profile-section-title-sm">Tu sesión</h2>
                <p class="profile-section-text mb-4">Al cerrar sesión, tendrás que volver a ingresar para acceder a tus servicios.</p>
                <a href="logout.php" class="btn btn-danger-soft w-100 d-flex align-items-center justify-content-center profile-logout-btn">Cerrar sesión</a>
              </div>

              <div class="profile-tip flex-grow-1">
                <div class="profile-tip-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
                <h3 class="profile-tip-title">Cuida tu cuenta</h3>
                <p class="profile-tip-text mb-0">No compartas tu contraseña y cierra sesión si utilizas un equipo compartido.</p>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <!-- ================= FOOTER INSTITUCIONAL (MISIÓN Y VISIÓN DESDE BD) ================= -->
  <footer id="footer-institucional" class="main-footer pt-5 pb-4">
    <div class="container">
      <div class="row g-4 pb-4">
        <div class="col-md-6 col-lg-3">
          <div class="d-flex align-items-center gap-2 mb-3">
            <img
              src="images/logo.jpeg"
              alt="Logo oficial Capsule Tech"
              width="38"
              height="38"
              class="brand-logo brand-logo-sm"
            />
            <span class="footer-brand-name">Capsule Tech</span>
          </div>

          <p class="footer-lead mb-2">
            Todo lo que necesitas<br />
            en un solo lugar
          </p>
          <p class="footer-text mb-0">
            Tecnología que conecta a nuestra comunidad.
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra misión</h3>
          <p class="footer-text mb-0">
            <?= htmlspecialchars($empresa['mision'] ?? '') ?>
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra visión</h3>
          <p class="footer-text mb-0">
            <?= htmlspecialchars($empresa['vision'] ?? '') ?>
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Contáctanos</h3>
          <p class="footer-text mb-3">
            ¿Tienes una duda?<br />
            Nuestro equipo te acompaña.
          </p>
          <div class="d-flex flex-column gap-2">
            <a href="acerca_de.php#contacto" class="footer-action-link">
              Enviar una consulta <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="acerca_de.php#preguntas-frecuentes" class="footer-action-link">
              Centro de ayuda <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>

      <div class="footer-bottom pt-3 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <span>&copy; 2026 Capsule Tech. Todos los derechos reservados.</span>
        <div class="d-flex align-items-center gap-2">
          <a href="#">Privacidad</a>
          <span>&middot;</span>
          <a href="#">Términos de uso</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Validación en vivo: marca el campo con el mensaje de error o lo deja válido.
    // marcarValidos = false: solo se marcan errores (en contraseñas el navegador no sabe si la actual es correcta).
    function conectarValidacion(form, reglas, prefijoError, marcarValidos = true) {
      const campos = Object.keys(reglas).map(nombre => form.elements[nombre]);

      function validarCampo(campo) {
        const mensaje = reglas[campo.name](campo);
        campo.setCustomValidity(mensaje);
        const feedback = document.getElementById(prefijoError + campo.name);
        if (feedback && mensaje) feedback.textContent = mensaje;
        campo.classList.toggle('is-invalid', mensaje !== '');
        campo.classList.toggle('is-valid', marcarValidos && mensaje === '' && campo.value.trim() !== '');
        return mensaje === '';
      }

      campos.forEach(campo => {
        campo.addEventListener('input', () => {
          if (campo.dataset.tocado === '1' || campo.classList.contains('is-invalid')) validarCampo(campo);
        });
        campo.addEventListener('blur', () => {
          campo.dataset.tocado = '1';
          validarCampo(campo);
        });
      });

      // Mantener el foco en el campo al hacer clic en el botón (evita que se mueva y el clic se pierda)
      form.querySelector('button[type="submit"]').addEventListener('mousedown', event => event.preventDefault());

      form.addEventListener('submit', event => {
        let primerInvalido = null;
        campos.forEach(campo => {
          campo.dataset.tocado = '1';
          if (!validarCampo(campo) && !primerInvalido) primerInvalido = campo;
        });
        if (primerInvalido) {
          event.preventDefault();
          primerInvalido.focus();
          return;
        }
        const boton = form.querySelector('button[type="submit"]');
        boton.disabled = true;
        boton.textContent = 'Guardando…';
      });
    }

    // ---------- Mis datos ----------
    const formDatos = document.getElementById('mis-datos');
    if (formDatos) {
      conectarValidacion(formDatos, {
        nombres: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe tu nombre.';
          if (largo > 50) return 'El nombre no puede superar los 50 caracteres.';
          return '';
        },
        apellidos: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe al menos tu apellido paterno.';
          if (largo > 100) return 'Los apellidos no pueden superar los 100 caracteres.';
          return '';
        },
        telefono: campo => {
          const valor = campo.value.trim();
          return (valor === '' || valor.length > 15) ? 'Ingresa un número de teléfono válido (máximo 15 caracteres).' : '';
        },
        correo: campo => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(campo.value.trim()) ? '' : 'Ingresa un correo electrónico válido.',
        direccion: campo => campo.value.trim().length > 150 ? 'La dirección no puede superar los 150 caracteres.' : ''
      }, 'error-perfil-');

      // "Editar perfil" lleva al formulario y pone el cursor en el primer campo
      const enfocarDatos = () => {
        if (location.hash === '#mis-datos') {
          formDatos.scrollIntoView({ behavior: 'smooth', block: 'start' });
          document.getElementById('perfilNombres').focus({ preventScroll: true });
        }
      };
      window.addEventListener('hashchange', enfocarDatos);
      enfocarDatos();
    }

    // ---------- Seguridad: cambiar contraseña ----------
    const formContrasena = document.getElementById('formContrasena');
    if (formContrasena) {
      const nueva = document.getElementById('password_nueva');
      const confirmar = document.getElementById('password_confirmar');

      conectarValidacion(formContrasena, {
        password_actual: campo => campo.value === '' ? 'Escribe tu contraseña actual.' : '',
        password_nueva: campo => {
          if (campo.value.length < 6) return 'La nueva contraseña debe tener al menos 6 caracteres.';
          if (campo.value === document.getElementById('password_actual').value) return 'La nueva contraseña debe ser distinta de la actual.';
          return '';
        },
        password_confirmar: campo => campo.value === '' ? 'Confirma tu nueva contraseña.' : (campo.value !== nueva.value ? 'Las contraseñas no coinciden.' : '')
      }, 'error-', false);

      // Si cambia la nueva contraseña, revisar otra vez la confirmación
      nueva.addEventListener('input', () => {
        if (confirmar.dataset.tocado === '1') confirmar.dispatchEvent(new Event('input'));
      });

      // Mostrar u ocultar cada contraseña (mismo comportamiento que Iniciar sesión)
      formContrasena.querySelectorAll('.password-toggle').forEach(boton => {
        boton.addEventListener('click', () => {
          const campo = document.getElementById(boton.dataset.target);
          const icono = boton.querySelector('i');
          const oculto = campo.type === 'password';
          campo.type = oculto ? 'text' : 'password';
          icono.classList.toggle('bi-eye', !oculto);
          icono.classList.toggle('bi-eye-slash', oculto);
        });
      });
    }
  </script>
</body>
</html>
