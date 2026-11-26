<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/administrar_solicitudes.php');
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

// 2. Contador general para el badge del menú lateral
$pendientesP = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP + $pendientesO;

// 3. Filtros recibidos por GET
$tipo   = $_GET['tipo'] ?? 'todas';        // todas | prestamos | servicios
$vista  = $_GET['vista'] ?? 'pendientes';  // pendientes | historial
$buscar = trim($_GET['buscar'] ?? '');

// 4. Consulta de solicitudes de préstamo
$condicionesP = [];
$paramsP = [];

if ($vista === 'pendientes') {
    $condicionesP[] = "p.estado = 'Pendiente'";
}

if ($buscar !== '') {
    $condicionesP[] = "(p.folio LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR e.nombre LIKE ?)";
    $textoBusqueda = '%' . $buscar . '%';
    $paramsP[] = $textoBusqueda;
    $paramsP[] = $textoBusqueda;
    $paramsP[] = $textoBusqueda;
    $paramsP[] = $textoBusqueda;
}

$sqlPrestamos = "SELECT p.*, e.codigo AS equipo_codigo, e.nombre AS equipo_nombre,
                        u.nombres, u.apellidos, u.correo
                 FROM prestamo p
                 INNER JOIN equipo e ON p.id_equipo = e.id_equipo
                 INNER JOIN usuario u ON p.id_cliente = u.id_usuario";

if (count($condicionesP) > 0) {
    $sqlPrestamos .= " WHERE " . implode(" AND ", $condicionesP);
}
$sqlPrestamos .= " ORDER BY p.fecha_registro DESC";

$stmtP = $pdo->prepare($sqlPrestamos);
$stmtP->execute($paramsP);
$listaPrestamos = $stmtP->fetchAll();

// 5. Consulta de solicitudes / órdenes de servicio
$condicionesO = [];
$paramsO = [];

if ($vista === 'pendientes') {
    $condicionesO[] = "o.estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')";
}

if ($buscar !== '') {
    $condicionesO[] = "(o.folio LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR o.equipo_receptor LIKE ? OR s.nombre LIKE ?)";
    $textoBusqueda = '%' . $buscar . '%';
    $paramsO[] = $textoBusqueda;
    $paramsO[] = $textoBusqueda;
    $paramsO[] = $textoBusqueda;
    $paramsO[] = $textoBusqueda;
    $paramsO[] = $textoBusqueda;
}

$sqlOrdenes = "SELECT o.*, s.nombre AS servicio_nombre,
                      u.nombres, u.apellidos, u.correo
               FROM orden_servicio o
               INNER JOIN usuario u ON o.id_cliente = u.id_usuario
               LEFT JOIN servicio s ON o.id_servicio = s.id_servicio";

if (count($condicionesO) > 0) {
    $sqlOrdenes .= " WHERE " . implode(" AND ", $condicionesO);
}
$sqlOrdenes .= " ORDER BY o.fecha_ingreso DESC";

$stmtO = $pdo->prepare($sqlOrdenes);
$stmtO->execute($paramsO);
$listaOrdenes = $stmtO->fetchAll();

$totalMostradas = count($listaPrestamos) + count($listaOrdenes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Administrar Solicitudes — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="dashboard.php" class="sidebar-link">
              <span class="sidebar-link-left"><i class="bi bi-grid"></i> Dashboard</span>
            </a>
          </li>
          <li>
            <a href="administrar_solicitudes.php" class="sidebar-link active">
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

      <?php if (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> La solicitud se actualizó correctamente.
        </div>
      <?php endif; ?>

      <!-- Encabezado -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Solicitudes</h1>
          <p class="page-subheading">Bandeja unificada de solicitudes de préstamo y órdenes de servicio técnico.</p>
        </div>

        <div class="d-flex gap-2">
          <a href="administrar_solicitudes.php?vista=pendientes&tipo=<?= urlencode($tipo) ?>"
             class="filter-tab-btn <?= $vista === 'pendientes' ? 'active' : '' ?>">
            Por atender (<?= $totalPendientes ?>)
          </a>
          <a href="administrar_solicitudes.php?vista=historial&tipo=<?= urlencode($tipo) ?>"
             class="filter-tab-btn <?= $vista === 'historial' ? 'active' : '' ?>">
            Ver historial completo
          </a>
        </div>
      </div>

      <!-- Tarjeta principal con filtros y tabla -->
      <div class="panel-card">

        <!-- Barra de pestañas y buscador -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div class="d-flex flex-wrap gap-1">
            <a href="administrar_solicitudes.php?vista=<?= urlencode($vista) ?>&tipo=todas&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $tipo === 'todas' ? 'active' : '' ?>">
              Todas (<?= $totalMostradas ?>)
            </a>
            <a href="administrar_solicitudes.php?vista=<?= urlencode($vista) ?>&tipo=prestamos&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $tipo === 'prestamos' ? 'active' : '' ?>">
              Préstamos (<?= count($listaPrestamos) ?>)
            </a>
            <a href="administrar_solicitudes.php?vista=<?= urlencode($vista) ?>&tipo=servicios&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $tipo === 'servicios' ? 'active' : '' ?>">
              Servicios (<?= count($listaOrdenes) ?>)
            </a>
          </div>

          <form method="GET" action="administrar_solicitudes.php" class="d-flex gap-2">
            <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>" />
            <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>" />
            <input type="text" name="buscar" class="form-control form-control-sm"
                   placeholder="Buscar folio, cliente o equipo..."
                   value="<?= htmlspecialchars($buscar) ?>" />
            <button type="submit" class="btn-admin-primary btn-sm">Buscar</button>
            <?php if ($buscar !== ''): ?>
              <a href="administrar_solicitudes.php?vista=<?= urlencode($vista) ?>&tipo=<?= urlencode($tipo) ?>"
                 class="btn-admin-outline btn-sm">Limpiar</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tabla de resultados -->
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Solicitante</th>
                <th>Tipo / Recurso</th>
                <th>Detalle / Motivo</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $hayFilas = false;

              // Filas de Préstamos
              if ($tipo === 'todas' || $tipo === 'prestamos') {
                  foreach ($listaPrestamos as $p) {
                      $hayFilas = true;
                      $claseEstado = 'status-slate';
                      if ($p['estado'] === 'Pendiente') {
                          $claseEstado = 'status-amber';
                      } elseif ($p['estado'] === 'Prestado') {
                          $claseEstado = 'status-blue';
                      } elseif ($p['estado'] === 'Devuelto') {
                          $claseEstado = 'status-green';
                      }
              ?>
                <tr>
                  <td class="folio-cell">#<?= htmlspecialchars($p['folio']) ?></td>
                  <td>
                    <div class="fw-medium"><?= htmlspecialchars($p['nombres'] . ' ' . $p['apellidos']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($p['correo']) ?></div>
                  </td>
                  <td>
                    <span class="fw-medium"><?= htmlspecialchars($p['equipo_nombre']) ?></span>
                    <span class="d-block small text-muted">Préstamo &middot; <?= htmlspecialchars($p['equipo_codigo']) ?></span>
                  </td>
                  <td class="small text-secondary">
                    <?= htmlspecialchars(!empty($p['motivo']) ? mb_strimwidth($p['motivo'], 0, 45, '...') : 'Sin motivo registrado') ?>
                  </td>
                  <td class="text-secondary"><?= date('d/m/Y', strtotime($p['fecha_registro'])) ?></td>
                  <td>
                    <span class="badge-pill-status <?= $claseEstado ?>">
                      <?= htmlspecialchars($p['estado']) ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <a href="prestamo_revisar.php?id=<?= $p['id_prestamo'] ?>" class="btn-review-link">
                      Revisar <i class="bi bi-arrow-right"></i>
                    </a>
                  </td>
                </tr>
              <?php
                  }
              }

              // Filas de Órdenes de Servicio
              if ($tipo === 'todas' || $tipo === 'servicios') {
                  foreach ($listaOrdenes as $o) {
                      $hayFilas = true;
                      $nombreServicio = !empty($o['servicio_nombre']) ? $o['servicio_nombre'] : $o['equipo_receptor'];
                      $claseEstado = 'status-amber';
                      if ($o['estado'] === 'En Revisión' || $o['estado'] === 'Esperando Piezas') {
                          $claseEstado = 'status-blue';
                      } elseif ($o['estado'] === 'Reparado' || $o['estado'] === 'Entregado') {
                          $claseEstado = 'status-green';
                      } elseif ($o['estado'] === 'Cancelado') {
                          $claseEstado = 'status-slate';
                      }
              ?>
                <tr>
                  <td class="folio-cell">#<?= htmlspecialchars($o['folio']) ?></td>
                  <td>
                    <div class="fw-medium"><?= htmlspecialchars($o['nombres'] . ' ' . $o['apellidos']) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($o['correo']) ?></div>
                  </td>
                  <td>
                    <span class="fw-medium"><?= htmlspecialchars($nombreServicio) ?></span>
                    <span class="d-block small text-muted">Servicio &middot; <?= htmlspecialchars($o['equipo_receptor']) ?></span>
                  </td>
                  <td class="small text-secondary">
                    <?= htmlspecialchars(mb_strimwidth($o['falla_reportada'], 0, 45, '...')) ?>
                  </td>
                  <td class="text-secondary"><?= date('d/m/Y', strtotime($o['fecha_ingreso'])) ?></td>
                  <td>
                    <span class="badge-pill-status <?= $claseEstado ?>">
                      <?= htmlspecialchars($o['estado']) ?>
                    </span>
                  </td>
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
                  <td colspan="7" class="text-center text-muted py-4">
                    No se encontraron solicitudes con los filtros seleccionados.
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>