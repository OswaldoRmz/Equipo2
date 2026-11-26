<?php
require_once 'config/conexion.php';

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

if (!defined('ROL_ADMINISTRADOR')) {
    define('ROL_ADMINISTRADOR', 1);
}
// Rol real de la cuenta: el administrador conserva sus permisos y, además del menú normal,
// ve el acceso a su panel (admin/dashboard.php) en el menú de su nombre.
$esAdministrador = $usuario !== null && (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;

// Página privada: solo usuarios con sesión iniciada
if (!$usuario) {
    header('Location: login.php?redirect=mis_solicitudes.php');
    exit;
}

/**
 * Estado que ve el usuario en "Mis solicitudes", según el estado del préstamo en la BD:
 * Pendiente → Pendiente · Prestado / Devuelto → Aprobada · Cancelado → Rechazada
 */
function estadoSolicitud(string $estadoPrestamo): array {
    return match ($estadoPrestamo) {
        'Pendiente'            => ['texto' => 'Pendiente', 'clase' => 'status-pill-proceso'],
        'Prestado', 'Devuelto' => ['texto' => 'Aprobada',  'clase' => 'status-pill-listo'],
        default                => ['texto' => 'Rechazada', 'clase' => 'status-pill-cancelado'],
    };
}

/**
 * Clase de color para el estado de una solicitud de servicio (orden_servicio).
 */
function claseEstadoServicio(string $estado): string {
    return match ($estado) {
        'Recibido'                        => 'status-pill-recibido',
        'En Revisión', 'Esperando Piezas' => 'status-pill-proceso',
        'Reparado', 'Entregado'           => 'status-pill-listo',
        default                           => 'status-pill-cancelado',
    };
}

// Mensaje después de registrar una solicitud de servicio (viene de servicios.php)
$mensajeExito = $_SESSION['flash_mis_solicitudes'] ?? '';
unset($_SESSION['flash_mis_solicitudes']);

// Solicitudes de servicio del usuario (antes se mostraban en servicios.php)
$stmtServicios = $pdo->prepare("
    SELECT
        o.id_orden,
        o.folio,
        o.equipo_receptor,
        o.fecha_ingreso,
        o.estado,
        o.prioridad,
        o.archivo_adjunto,
        s.nombre AS servicio
    FROM orden_servicio o
    LEFT JOIN servicio s ON o.id_servicio = s.id_servicio
    WHERE o.id_cliente = :id_cliente
    ORDER BY o.fecha_ingreso DESC, o.id_orden DESC
");
$stmtServicios->execute([':id_cliente' => $usuario['id_usuario']]);
$solicitudesServicio = $stmtServicios->fetchAll();

// Todas las solicitudes de préstamo del usuario (una sola consulta para ambas pestañas)
$stmtSolicitudes = $pdo->prepare("
    SELECT
        p.id_prestamo,
        p.folio,
        p.fecha_inicio,
        p.fecha_limite,
        p.motivo,
        p.estado,
        p.fecha_entrega,
        p.fecha_devolucion,
        p.fecha_registro,
        e.nombre AS equipo_nombre,
        e.codigo AS equipo_codigo,
        c.nombre AS categoria
    FROM prestamo p
    INNER JOIN equipo e ON p.id_equipo = e.id_equipo
    INNER JOIN categoria c ON e.id_categoria = c.id_categoria
    WHERE p.id_cliente = :id_cliente
    ORDER BY p.fecha_registro DESC, p.id_prestamo DESC
");
$stmtSolicitudes->execute([':id_cliente' => $usuario['id_usuario']]);
$solicitudes = $stmtSolicitudes->fetchAll();

// Mis préstamos: equipos que el usuario tiene actualmente en su poder
$prestamosActivos = array_values(array_filter($solicitudes, fn($sol) => $sol['estado'] === 'Prestado'));
usort($prestamosActivos, fn($a, $b) => strtotime($a['fecha_limite']) <=> strtotime($b['fecha_limite']));

// Solicitudes que todavía no se revisan: préstamos "Pendiente" y servicios "Recibido"
$totalPendientes = count(array_filter($solicitudes, fn($sol) => $sol['estado'] === 'Pendiente'))
    + count(array_filter($solicitudesServicio, fn($sol) => $sol['estado'] === 'Recibido'));
$totalSolicitudes = count($solicitudes) + count($solicitudesServicio);

// Pestaña inicial: mis_solicitudes.php (solicitudes) o mis_solicitudes.php?seccion=prestamos
$seccionActiva = (($_GET['seccion'] ?? '') === 'prestamos') ? 'prestamos' : 'solicitudes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Mis solicitudes — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos compartidos: navbar, tarjetas de préstamo y footer (inventario.css);
       pestañas, filas de solicitud y etiquetas de estado (servicios.css) -->
  <link rel="stylesheet" href="styles/inventario.css" />
  <link rel="stylesheet" href="styles/servicios.css" />
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
                <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person me-2"></i>Perfil</a></li>
                <li><a class="dropdown-item fw-semibold" aria-current="page" href="mis_solicitudes.php"><i class="bi bi-clipboard-check me-2"></i>Mis solicitudes</a></li>
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
    <!-- ================= ENCABEZADO Y PESTAÑAS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-mis-solicitudes">
      <div class="container">
        <?php if ($mensajeExito !== ''): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($mensajeExito) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
          </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
          <div>
            <h1 id="titulo-mis-solicitudes" class="page-title">Mis solicitudes</h1>
            <p class="page-subtitle mb-0">Consulta el estado de tus solicitudes y los equipos que tienes en préstamo.</p>
          </div>
          <span class="cutoff-date-badge">
            Al <?= date('d M Y') ?>
          </span>
        </div>

        <div class="admin-tabs" role="tablist" aria-label="Secciones de Mis solicitudes">
          <button
            type="button"
            class="admin-tab <?= $seccionActiva === 'solicitudes' ? 'active' : '' ?>"
            id="tab-solicitudes"
            role="tab"
            data-bs-toggle="tab"
            data-bs-target="#panel-solicitudes"
            data-seccion="solicitudes"
            aria-controls="panel-solicitudes"
            aria-selected="<?= $seccionActiva === 'solicitudes' ? 'true' : 'false' ?>"
          >
            Mis solicitudes
            <span class="tab-count"><?= $totalSolicitudes ?></span>
          </button>
          <button
            type="button"
            class="admin-tab <?= $seccionActiva === 'prestamos' ? 'active' : '' ?>"
            id="tab-prestamos"
            role="tab"
            data-bs-toggle="tab"
            data-bs-target="#panel-prestamos"
            data-seccion="prestamos"
            aria-controls="panel-prestamos"
            aria-selected="<?= $seccionActiva === 'prestamos' ? 'true' : 'false' ?>"
          >
            Mis préstamos
            <span class="tab-count"><?= count($prestamosActivos) ?></span>
          </button>
        </div>
      </div>
    </section>

    <section class="pb-5 bg-white">
      <div class="container tab-content">

        <!-- ================= PESTAÑA A: MIS SOLICITUDES ================= -->
        <div
          class="tab-pane fade <?= $seccionActiva === 'solicitudes' ? 'show active' : '' ?>"
          id="panel-solicitudes"
          role="tabpanel"
          aria-labelledby="tab-solicitudes"
          tabindex="0"
        >
          <p class="tab-intro">
            Solicitudes de servicios, equipos, accesorios y artículos que has realizado.
            <?php if ($totalPendientes > 0): ?>
              Tienes <strong><?= $totalPendientes ?> pendiente<?= $totalPendientes === 1 ? '' : 's' ?></strong> de revisión.
            <?php endif; ?>
          </p>

          <!-- ---------- Solicitudes de servicio (orden_servicio) ---------- -->
          <h2 class="tab-subtitle">
            Servicios
            <span class="tab-count"><?= count($solicitudesServicio) ?></span>
          </h2>

          <?php if (count($solicitudesServicio) === 0): ?>
            <div class="db-loan-placeholder flex-column gap-2 py-4">
              <span>Aún no has solicitado servicios.</span>
              <a href="servicios.php" class="footer-action-link">Ver servicios <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
          <?php else: ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($solicitudesServicio as $srv): ?>
                <article class="request-row">
                  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 gap-md-4">
                    <div class="min-w-0">
                      <h3 class="request-title"><?= htmlspecialchars($srv['servicio'] ?? 'Servicio general') ?></h3>
                      <p class="request-meta mb-0">
                        <?= htmlspecialchars($srv['folio']) ?>
                        &middot; <?= htmlspecialchars($srv['equipo_receptor']) ?>
                        &middot; <?= date('d M Y', strtotime($srv['fecha_ingreso'])) ?>
                        &middot; Prioridad <?= htmlspecialchars(mb_strtolower($srv['prioridad'] ?? 'Media', 'UTF-8')) ?>
                        <?php if (!empty($srv['archivo_adjunto'])): ?>
                          &middot; <a href="<?= htmlspecialchars($srv['archivo_adjunto']) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-paperclip"></i>Ver archivo
                          </a>
                        <?php endif; ?>
                      </p>
                    </div>
                    <span class="status-pill <?= claseEstadoServicio($srv['estado']) ?>">
                      <?= htmlspecialchars($srv['estado']) ?>
                    </span>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- ---------- Solicitudes de equipos, accesorios y artículos (prestamo) ---------- -->
          <h2 class="tab-subtitle mt-5">
            Equipos y accesorios
            <span class="tab-count"><?= count($solicitudes) ?></span>
          </h2>

          <?php if (count($solicitudes) === 0): ?>
            <div class="db-loan-placeholder flex-column gap-2 py-4">
              <span>Aún no has solicitado equipos, accesorios ni artículos.</span>
              <a href="inventario.php" class="footer-action-link">Consultar el inventario <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
          <?php else: ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($solicitudes as $sol): ?>
                <?php $estado = estadoSolicitud($sol['estado']); ?>
                <article class="request-row">
                  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 gap-md-4">
                    <div class="d-flex align-items-start gap-3 min-w-0">
                      <div class="card-icon-box">
                        <i class="bi <?= htmlspecialchars(iconoCategoria($sol['categoria'])) ?>"></i>
                      </div>
                      <div class="min-w-0">
                        <h3 class="request-title"><?= htmlspecialchars($sol['equipo_nombre']) ?></h3>
                        <p class="request-meta mb-1">
                          Solicitud <?= htmlspecialchars($sol['folio']) ?>
                          &middot; <?= htmlspecialchars($sol['equipo_codigo']) ?>
                          &middot; Realizada el <?= date('d M Y', strtotime($sol['fecha_registro'])) ?>
                        </p>
                        <p class="request-meta mb-0">
                          Del <?= date('d M Y, H:i', strtotime($sol['fecha_inicio'])) ?>
                          al <?= date('d M Y, H:i', strtotime($sol['fecha_limite'])) ?>
                          <?php if (!empty($sol['motivo'])): ?>
                            &middot; Motivo: <?= htmlspecialchars($sol['motivo']) ?>
                          <?php endif; ?>
                        </p>
                        <?php if ($sol['estado'] === 'Prestado'): ?>
                          <p class="request-meta mb-0 mt-1">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Ya tienes este equipo.
                            <a href="mis_solicitudes.php?seccion=prestamos" class="js-ir-prestamos">Ver en Mis préstamos</a>
                          </p>
                        <?php elseif ($sol['estado'] === 'Devuelto' && !empty($sol['fecha_devolucion'])): ?>
                          <p class="request-meta mb-0 mt-1">
                            <i class="bi bi-check2-circle me-1"></i>Devuelto el <?= date('d M Y, H:i', strtotime($sol['fecha_devolucion'])) ?>
                          </p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <span class="status-pill <?= $estado['clase'] ?>"><?= $estado['texto'] ?></span>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ================= PESTAÑA B: MIS PRÉSTAMOS (antes "Mis préstamos activos") ================= -->
        <div
          class="tab-pane fade <?= $seccionActiva === 'prestamos' ? 'show active' : '' ?>"
          id="panel-prestamos"
          role="tabpanel"
          aria-labelledby="tab-prestamos"
          tabindex="0"
        >
          <p class="tab-intro">Equipos, accesorios y artículos que tienes actualmente en préstamo.</p>

          <?php if (count($prestamosActivos) > 0): ?>
            <div class="row g-4">
              <?php foreach ($prestamosActivos as $pr): ?>
                <?php
                  $iconoPr = iconoCategoria($pr['categoria']);
                  $fechaLimiteTs = strtotime($pr['fecha_limite']);
                  $diasRestantes = (int)ceil(($fechaLimiteTs - time()) / 86400);
                  $esUrgente = ($diasRestantes <= 1);
                ?>
                <div class="col-lg-6">
                  <article class="active-loan-card h-100">
                    <div class="d-flex align-items-center gap-3 mb-4">
                      <div class="card-icon-box">
                        <i class="bi <?= htmlspecialchars($iconoPr) ?>"></i>
                      </div>
                      <div>
                        <h3 class="loan-equipment-title"><?= htmlspecialchars($pr['equipo_nombre']) ?></h3>
                        <p class="loan-folio mb-0">
                          Préstamo <?= htmlspecialchars($pr['folio']) ?> &middot; Estado: <strong><?= htmlspecialchars($pr['estado']) ?></strong>
                        </p>
                      </div>
                    </div>

                    <!-- Detalles del préstamo -->
                    <dl class="loan-details mb-4">
                      <dt>Código</dt>
                      <dd><?= htmlspecialchars($pr['equipo_codigo']) ?></dd>
                      <dt>Recibido</dt>
                      <dd><?= date('d M Y, H:i', strtotime($pr['fecha_entrega'] ?: $pr['fecha_inicio'])) ?></dd>
                      <?php if (!empty($pr['motivo'])): ?>
                        <dt>Motivo</dt>
                        <dd><?= htmlspecialchars($pr['motivo']) ?></dd>
                      <?php endif; ?>
                    </dl>

                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-auto">
                      <div>
                        <span class="loan-date-label d-block">Fecha límite de devolución</span>
                        <strong class="loan-date-value"><?= date('d M Y, H:i', $fechaLimiteTs) ?></strong>
                      </div>
                      <span class="days-pill <?= $esUrgente ? 'days-pill-warning' : 'days-pill-normal' ?>">
                        <?php if ($diasRestantes < 0): ?>
                          Vencido
                        <?php elseif ($diasRestantes === 0): ?>
                          Vence hoy
                        <?php elseif ($diasRestantes === 1): ?>
                          1 día restante
                        <?php else: ?>
                          <?= $diasRestantes ?> días restantes
                        <?php endif; ?>
                      </span>
                    </div>
                  </article>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="db-loan-placeholder py-4">
              <span>No tienes equipos, accesorios ni artículos en préstamo en este momento.</span>
            </div>
          <?php endif; ?>
        </div>
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
            <a href="mailto:<?= htmlspecialchars($empresa['correo_contacto'] ?? 'contacto@techsolutions.com') ?>" class="footer-action-link">
              Enviar una consulta <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="inventario.php#titulo-reglas-prestamo" class="footer-action-link">
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
    // Al cambiar de pestaña, actualizar la URL (?seccion=...) para que al recargar se quede en la misma
    document.querySelectorAll('[data-bs-toggle="tab"][data-seccion]').forEach(pestana => {
      pestana.addEventListener('shown.bs.tab', () => {
        const url = new URL(window.location.href);
        if (pestana.dataset.seccion === 'prestamos') {
          url.searchParams.set('seccion', 'prestamos');
        } else {
          url.searchParams.delete('seccion');
        }
        history.replaceState(null, '', url);
      });
    });

    // "Ver en Mis préstamos" cambia de pestaña sin recargar la página
    document.querySelectorAll('.js-ir-prestamos').forEach(enlace => {
      enlace.addEventListener('click', event => {
        event.preventDefault();
        bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-prestamos')).show();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  </script>
</body>
</html>
