<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/dashboard.php');
    exit;
}

$usuario = usuarioActual();
if ($usuario['id_rol'] != 1 && $usuario['id_rol'] != 2) {
    header('Location: ../index.php');
    exit;
}

$partesNombre = explode(' ', trim($usuario['nombres']));
$primerNombre = $partesNombre[0] ?? 'Admin';

$empresa = obtenerDatosEmpresa($pdo);

// 2. Contadores para las tarjetas KPI
$totalEquiposDisponibles = $pdo->query("SELECT COUNT(*) FROM equipo WHERE visibilidad = 'Activo' AND estado = 'Disponible'")->fetchColumn();
$totalEquiposActivos     = $pdo->query("SELECT COUNT(*) FROM equipo WHERE visibilidad = 'Activo'")->fetchColumn();
$totalPrestamosActivos   = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Prestado'")->fetchColumn();
$totalServiciosEnCurso   = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas', 'Reparado')")->fetchColumn();

// 3. Consultas de solicitudes pendientes
$sqlPrestamosPend = "SELECT p.*, e.nombre AS equipo_nombre, u.nombres, u.apellidos
                     FROM prestamo p
                     INNER JOIN equipo e ON p.id_equipo = e.id_equipo
                     INNER JOIN usuario u ON p.id_cliente = u.id_usuario
                     WHERE p.estado = 'Pendiente'
                     ORDER BY p.fecha_registro ASC";
$prestamosPendientes = $pdo->query($sqlPrestamosPend)->fetchAll();

$sqlOrdenesPend = "SELECT o.*, s.nombre AS servicio_nombre, u.nombres, u.apellidos
                   FROM orden_servicio o
                   INNER JOIN usuario u ON o.id_cliente = u.id_usuario
                   LEFT JOIN servicio s ON o.id_servicio = s.id_servicio
                   WHERE o.estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')
                   ORDER BY o.fecha_ingreso ASC";
$ordenesPendientes = $pdo->query($sqlOrdenesPend)->fetchAll();

$totalPendientes = count($prestamosPendientes) + count($ordenesPendientes);

// Filtro sencillo por pestaña (?tipo=todas | prestamos | servicios)
$tipo = $_GET['tipo'] ?? 'todas';

// 4. Consultas para las tarjetas inferiores del dashboard
$sqlPrestamosActivos = "SELECT p.*, e.nombre AS equipo_nombre, u.nombres, u.apellidos
                        FROM prestamo p
                        INNER JOIN equipo e ON p.id_equipo = e.id_equipo
                        INNER JOIN usuario u ON p.id_cliente = u.id_usuario
                        WHERE p.estado = 'Prestado'
                        ORDER BY p.fecha_limite ASC
                        LIMIT 4";
$listaDevoluciones = $pdo->query($sqlPrestamosActivos)->fetchAll();

$sqlOrdenesCurso = "SELECT o.*, s.nombre AS servicio_nombre, u.nombres, u.apellidos
                    FROM orden_servicio o
                    INNER JOIN usuario u ON o.id_cliente = u.id_usuario
                    LEFT JOIN servicio s ON o.id_servicio = s.id_servicio
                    WHERE o.estado IN ('Recibido', 'En Revisión', 'Esperando Piezas', 'Reparado')
                    ORDER BY o.fecha_ingreso DESC
                    LIMIT 4";
$listaServiciosCurso = $pdo->query($sqlOrdenesCurso)->fetchAll();

// Fecha actual en español
$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaHoy = $dias[date('w')] . ', ' . date('j') . ' de ' . $meses[date('n') - 1] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="../styles/dashboard.css" />
</head>
<body>

  <!-- Barra superior -->
  <header class="admin-topbar d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <a href="dashboard.php" class="d-flex align-items-center gap-2 text-decoration-none">
        <span class="topbar-brand-icon"><i class="bi bi-grid"></i></span>
        <span class="topbar-title">Administración</span>
      </a>
      <span class="topbar-badge d-none d-sm-inline">Panel de control</span>
    </div>

    <div class="d-flex align-items-center gap-3">
      <a href="../index.php" class="btn-topbar-logout d-none d-md-inline-flex">
        <i class="bi bi-box-arrow-up-right"></i> Ver sitio
      </a>
      <span class="topbar-greeting">Hola, <?= htmlspecialchars($primerNombre) ?></span>
      <a href="../logout.php" class="btn-topbar-logout">
        <i class="bi bi-box-arrow-right"></i> Cerrar sesión
      </a>
    </div>
  </header>

  <div class="admin-shell">

    <!-- Menú lateral -->
    <aside class="admin-sidebar">
      <div>
        <div class="sidebar-section-label">General</div>
        <ul class="sidebar-nav">
          <li>
            <a href="dashboard.php" class="sidebar-link active">
              <span class="sidebar-link-left"><i class="bi bi-grid"></i> Dashboard</span>
            </a>
          </li>
          <li>
            <a href="administrar_solicitudes.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-laptop"></i> Solicitudes</span>
              <?php if ($totalPendientes > 0): ?>
                <span class="sidebar-count-badge"><?= $totalPendientes ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li>
            <a href="administrar_prestamos.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-display"></i> Préstamos</span>
            </a>
          </li>
          <li>
            <a href="administrar_servicios.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-wrench"></i> Servicios</span>
            </a>
          </li>
        </ul>

        <div class="sidebar-section-label">Administrar</div>
        <ul class="sidebar-nav">
          <li>
            <a href="administrar_equipos.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-pc-display"></i> Equipos</span>
            </a>
          </li>
          <li>
            <a href="administrar_catalogo_servicios.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-tools"></i> Catálogo de servicios</span>
            </a>
          </li>
          <li>
            <a href="administrar_empresa.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-building"></i> Empresa</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="sidebar-status-box mt-3">
        <div class="d-flex align-items-center gap-2">
          <span class="status-dot"></span>
          <span class="status-title">Sistema operativo</span>
        </div>
        <div class="status-sub">Última actualización: <?= date('H:i') ?></div>
      </div>
    </aside>

    <!-- Contenido principal -->
    <main class="admin-main">

      <!-- Encabezado -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Resumen general</h1>
          <p class="page-subheading">Revisa las solicitudes y mantén tu operación al día.</p>
        </div>
        <div class="date-pill"><?= htmlspecialchars($fechaHoy) ?></div>
      </div>

      <!-- 4 Tarjetas KPI -->
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card">
            <div>
              <div class="kpi-label">Solicitudes pendientes</div>
              <div class="kpi-value"><?= str_pad($totalPendientes, 2, '0', STR_PAD_LEFT) ?></div>
              <div class="kpi-note kpi-note-amber">
                <?= count($prestamosPendientes) ?> préstamos &middot; <?= count($ordenesPendientes) ?> servicios
              </div>
            </div>
            <div class="kpi-icon-box kpi-icon-amber"><i class="bi bi-inboxes"></i></div>
          </div>
        </div>

        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card">
            <div>
              <div class="kpi-label">Préstamos activos</div>
              <div class="kpi-value"><?= str_pad($totalPrestamosActivos, 2, '0', STR_PAD_LEFT) ?></div>
              <div class="kpi-note kpi-note-green">En manos de estudiantes</div>
            </div>
            <div class="kpi-icon-box kpi-icon-green"><i class="bi bi-laptop"></i></div>
          </div>
        </div>

        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card">
            <div>
              <div class="kpi-label">Servicios en curso</div>
              <div class="kpi-value"><?= str_pad($totalServiciosEnCurso, 2, '0', STR_PAD_LEFT) ?></div>
              <div class="kpi-note kpi-note-blue">Órdenes de soporte activas</div>
            </div>
            <div class="kpi-icon-box kpi-icon-blue"><i class="bi bi-wrench"></i></div>
          </div>
        </div>

        <div class="col-sm-6 col-xl-3">
          <div class="kpi-card">
            <div>
              <div class="kpi-label">Equipos disponibles</div>
              <div class="kpi-value"><?= str_pad($totalEquiposDisponibles, 2, '0', STR_PAD_LEFT) ?></div>
              <div class="kpi-note kpi-note-muted">De <?= $totalEquiposActivos ?> equipos activos</div>
            </div>
            <div class="kpi-icon-box kpi-icon-slate"><i class="bi bi-grid"></i></div>
          </div>
        </div>
      </div>

      <!-- Accesos rápidos -->
      <h2 class="section-block-title">Accesos rápidos</h2>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <a href="equipo_crear.php" class="quick-card">
            <div class="d-flex align-items-center gap-3">
              <div class="quick-icon"><i class="bi bi-plus-lg"></i></div>
              <div>
                <div class="quick-title">Dar de alta equipo</div>
                <p class="quick-desc">Registra un equipo en el inventario.</p>
              </div>
            </div>
            <i class="bi bi-arrow-up-right quick-arrow"></i>
          </a>
        </div>

        <div class="col-md-4">
          <a href="servicio_crear.php" class="quick-card">
            <div class="d-flex align-items-center gap-3">
              <div class="quick-icon"><i class="bi bi-plus-lg"></i></div>
              <div>
                <div class="quick-title">Dar de alta servicio</div>
                <p class="quick-desc">Añade un servicio al catálogo.</p>
              </div>
            </div>
            <i class="bi bi-arrow-up-right quick-arrow"></i>
          </a>
        </div>

        <div class="col-md-4">
          <a href="administrar_empresa.php" class="quick-card">
            <div class="d-flex align-items-center gap-3">
              <div class="quick-icon"><i class="bi bi-building"></i></div>
              <div>
                <div class="quick-title">Información de la empresa</div>
                <p class="quick-desc">Consulta y modifica los datos generales.</p>
              </div>
            </div>
            <i class="bi bi-arrow-up-right quick-arrow"></i>
          </a>
        </div>
      </div>

      <!-- Tabla de Solicitudes pendientes -->
      <div class="panel-card mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <h2 class="panel-title">Solicitudes pendientes</h2>
            <span class="sidebar-count-badge"><?= $totalPendientes ?></span>
          </div>
          <a href="administrar_solicitudes.php" class="panel-link">
            Ver todas las solicitudes <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <div class="d-flex gap-1">
            <a href="dashboard.php?tipo=todas" class="filter-tab-btn <?= $tipo === 'todas' ? 'active' : '' ?>">
              Todas (<?= $totalPendientes ?>)
            </a>
            <a href="dashboard.php?tipo=prestamos" class="filter-tab-btn <?= $tipo === 'prestamos' ? 'active' : '' ?>">
              Préstamos (<?= count($prestamosPendientes) ?>)
            </a>
            <a href="dashboard.php?tipo=servicios" class="filter-tab-btn <?= $tipo === 'servicios' ? 'active' : '' ?>">
              Servicios (<?= count($ordenesPendientes) ?>)
            </a>
          </div>
          <span class="small text-muted">Ordenar: Más antiguas primero &darr;</span>
        </div>

        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Solicitud</th>
                <th>Solicitante</th>
                <th>Tipo / Recurso</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $hayFilas = false;

              if ($tipo === 'todas' || $tipo === 'prestamos') {
                  foreach ($prestamosPendientes as $p) {
                      $hayFilas = true;
              ?>
                <tr>
                  <td class="folio-cell">#<?= htmlspecialchars($p['folio']) ?></td>
                  <td><?= htmlspecialchars($p['nombres'] . ' ' . $p['apellidos']) ?></td>
                  <td>
                    <span class="fw-medium"><?= htmlspecialchars($p['equipo_nombre']) ?></span>
                    <span class="d-block small text-muted">Préstamo de equipo</span>
                  </td>
                  <td class="text-secondary"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></td>
                  <td><span class="badge-pill-status status-slate"><?= htmlspecialchars($p['estado']) ?></span></td>
                  <td class="text-end">
                    <a href="prestamo_revisar.php?id=<?= $p['id_prestamo'] ?>" class="btn-review-link">
                      Revisar <i class="bi bi-arrow-right"></i>
                    </a>
                  </td>
                </tr>
              <?php
                  }
              }

              if ($tipo === 'todas' || $tipo === 'servicios') {
                  foreach ($ordenesPendientes as $o) {
                      $hayFilas = true;
                      $nombreServicio = !empty($o['servicio_nombre']) ? $o['servicio_nombre'] : $o['equipo_receptor'];
              ?>
                <tr>
                  <td class="folio-cell">#<?= htmlspecialchars($o['folio']) ?></td>
                  <td><?= htmlspecialchars($o['nombres'] . ' ' . $o['apellidos']) ?></td>
                  <td>
                    <span class="fw-medium"><?= htmlspecialchars($nombreServicio) ?></span>
                    <span class="d-block small text-muted">Orden de servicio</span>
                  </td>
                  <td class="text-secondary"><?= date('d/m/Y', strtotime($o['fecha_ingreso'])) ?></td>
                  <td><span class="badge-pill-status status-amber"><?= htmlspecialchars($o['estado']) ?></span></td>
                  <td class="text-end">
                    <a href="orden_revisar.php?id=<?= $o['id_orden'] ?>" class="btn-review-link">
                      Revisar <i class="bi bi-arrow-right"></i>
                    </a>
                  </td>
                </tr>
              <?php
                  }
              }

              if (!$hayFilas) {
              ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">
                    No hay solicitudes pendientes en esta categoría.
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Fila inferior: Próximas devoluciones y Servicios en curso -->
      <div class="row g-4">
        <div class="col-lg-6">
          <div class="panel-card h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="panel-title">Próximas devoluciones</h2>
              <span class="small text-muted"><?= $totalPrestamosActivos ?> préstamos activos</span>
            </div>

            <div class="compact-list-header">
              <span>Equipo / Responsable</span>
              <span>Devolución</span>
            </div>

            <div class="flex-grow-1">
              <?php if (count($listaDevoluciones) > 0): ?>
                <?php foreach ($listaDevoluciones as $dev): ?>
                  <div class="compact-list-row">
                    <div>
                      <div class="compact-item-title"><?= htmlspecialchars($dev['equipo_nombre']) ?></div>
                      <div class="compact-item-sub">
                        <?= htmlspecialchars($dev['nombres'] . ' ' . $dev['apellidos']) ?> &middot; #<?= htmlspecialchars($dev['folio']) ?>
                      </div>
                    </div>
                    <span class="badge-pill-status status-slate">
                      <?= date('d/m/Y', strtotime($dev['fecha_limite'])) ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="small text-muted py-4 text-center mb-0">No hay préstamos entregados actualmente.</p>
              <?php endif; ?>
            </div>

            <div class="pt-3 mt-auto">
              <a href="administrar_prestamos.php" class="panel-link">
                Ver todos los préstamos <i class="bi bi-arrow-right ms-1"></i>
              </a>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="panel-card h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="panel-title">Servicios en curso</h2>
              <span class="small text-muted"><?= $totalServiciosEnCurso ?> servicios activos</span>
            </div>

            <div class="compact-list-header">
              <span>Servicio / Responsable</span>
              <span>Estado</span>
            </div>

            <div class="flex-grow-1">
              <?php if (count($listaServiciosCurso) > 0): ?>
                <?php foreach ($listaServiciosCurso as $ord): ?>
                  <?php
                    $tituloOrden = !empty($ord['servicio_nombre']) ? $ord['servicio_nombre'] : $ord['equipo_receptor'];
                    $claseBadge = 'status-amber';
                    if ($ord['estado'] === 'Reparado') {
                        $claseBadge = 'status-green';
                    } elseif ($ord['estado'] === 'En Revisión' || $ord['estado'] === 'Esperando Piezas') {
                        $claseBadge = 'status-blue';
                    }
                  ?>
                  <div class="compact-list-row">
                    <div>
                      <div class="compact-item-title"><?= htmlspecialchars($tituloOrden) ?></div>
                      <div class="compact-item-sub">
                        <?= htmlspecialchars($ord['equipo_receptor']) ?> &middot; <?= htmlspecialchars($ord['nombres'] . ' ' . $ord['apellidos']) ?>
                      </div>
                    </div>
                    <span class="badge-pill-status <?= $claseBadge ?>">
                      <?= htmlspecialchars($ord['estado']) ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="small text-muted py-4 text-center mb-0">No hay servicios activos en taller.</p>
              <?php endif; ?>
            </div>

            <div class="pt-3 mt-auto">
              <a href="administrar_servicios.php" class="panel-link">
                Ver todos los servicios <i class="bi bi-arrow-right ms-1"></i>
              </a>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>