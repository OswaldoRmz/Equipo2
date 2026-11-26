<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/administrar_servicios.php');
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

// Contador para el badge del menú lateral
$pendientesP = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP + $pendientesO;

// Contadores rápidos por estado de orden
$cntTodas     = $pdo->query("SELECT COUNT(*) FROM orden_servicio")->fetchColumn();
$cntRecibido  = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado = 'Recibido'")->fetchColumn();
$cntProceso   = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('En Revisión', 'Esperando Piezas')")->fetchColumn();
$cntReparado  = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado = 'Reparado'")->fetchColumn();
$cntEntregado = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado = 'Entregado'")->fetchColumn();

// 2. Filtros recibidos por GET
$estado = $_GET['estado'] ?? 'todas';
$buscar = trim($_GET['buscar'] ?? '');

$condiciones = [];
$params = [];

if ($estado === 'Recibido') {
    $condiciones[] = "o.estado = 'Recibido'";
} elseif ($estado === 'proceso') {
    $condiciones[] = "o.estado IN ('En Revisión', 'Esperando Piezas')";
} elseif ($estado === 'Reparado') {
    $condiciones[] = "o.estado = 'Reparado'";
} elseif ($estado === 'Entregado') {
    $condiciones[] = "o.estado = 'Entregado'";
} elseif ($estado === 'Cancelado') {
    $condiciones[] = "o.estado = 'Cancelado'";
}

if ($buscar !== '') {
    $condiciones[] = "(o.folio LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR o.equipo_receptor LIKE ? OR s.nombre LIKE ?)";
    $texto = '%' . $buscar . '%';
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
}

// 3. Consulta de órdenes de servicio
$sql = "SELECT o.*, s.nombre AS servicio_nombre,
               u.nombres, u.apellidos, u.telefono
        FROM orden_servicio o
        INNER JOIN usuario u ON o.id_cliente = u.id_usuario
        LEFT JOIN servicio s ON o.id_servicio = s.id_servicio";

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(" AND ", $condiciones);
}
$sql .= " ORDER BY o.fecha_ingreso DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listaOrdenes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Administrar Servicios — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_servicios.php" class="sidebar-link active">
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
          <i class="bi bi-check-circle me-1"></i> La orden de servicio se actualizó correctamente.
        </div>
      <?php endif; ?>

      <!-- Encabezado -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Órdenes de servicio</h1>
          <p class="page-subheading">Seguimiento técnico de diagnósticos, reparaciones y entregas a clientes.</p>
        </div>
      </div>

      <!-- Tarjeta principal -->
      <div class="panel-card">

        <!-- Filtros por estado y buscador -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div class="d-flex flex-wrap gap-1">
            <a href="administrar_servicios.php?estado=todas&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'todas' ? 'active' : '' ?>">
              Todas (<?= $cntTodas ?>)
            </a>
            <a href="administrar_servicios.php?estado=Recibido&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Recibido' ? 'active' : '' ?>">
              Recibidas (<?= $cntRecibido ?>)
            </a>
            <a href="administrar_servicios.php?estado=proceso&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'proceso' ? 'active' : '' ?>">
              En taller (<?= $cntProceso ?>)
            </a>
            <a href="administrar_servicios.php?estado=Reparado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Reparado' ? 'active' : '' ?>">
              Reparadas (<?= $cntReparado ?>)
            </a>
            <a href="administrar_servicios.php?estado=Entregado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Entregado' ? 'active' : '' ?>">
              Entregadas (<?= $cntEntregado ?>)
            </a>
          </div>

          <form method="GET" action="administrar_servicios.php" class="d-flex gap-2">
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>" />
            <input type="text" name="buscar" class="form-control form-control-sm"
                   placeholder="Folio, cliente o equipo..."
                   value="<?= htmlspecialchars($buscar) ?>" />
            <button type="submit" class="btn-admin-primary btn-sm">Buscar</button>
            <?php if ($buscar !== ''): ?>
              <a href="administrar_servicios.php?estado=<?= urlencode($estado) ?>"
                 class="btn-admin-outline btn-sm">Limpiar</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tabla de órdenes -->
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Cliente</th>
                <th>Equipo / Servicio</th>
                <th>Prioridad</th>
                <th>Costo total</th>
                <th>Estado</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($listaOrdenes) > 0): ?>
                <?php foreach ($listaOrdenes as $o): ?>
                  <?php
                    $claseBadge = 'status-amber';
                    if ($o['estado'] === 'En Revisión' || $o['estado'] === 'Esperando Piezas') {
                        $claseBadge = 'status-blue';
                    } elseif ($o['estado'] === 'Reparado' || $o['estado'] === 'Entregado') {
                        $claseBadge = 'status-green';
                    } elseif ($o['estado'] === 'Cancelado') {
                        $claseBadge = 'status-slate';
                    }
                  ?>
                  <tr>
                    <td class="folio-cell">#<?= htmlspecialchars($o['folio']) ?></td>
                    <td>
                      <div class="fw-medium"><?= htmlspecialchars($o['nombres'] . ' ' . $o['apellidos']) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars($o['telefono']) ?></div>
                    </td>
                    <td>
                      <div class="fw-medium"><?= htmlspecialchars($o['equipo_receptor']) ?></div>
                      <div class="small text-muted">
                        <?= htmlspecialchars(!empty($o['servicio_nombre']) ? $o['servicio_nombre'] : 'Diagnóstico general') ?>
                      </div>
                    </td>
                    <td class="small">
                      <?php if ($o['prioridad'] === 'Alta'): ?>
                        <span class="text-danger fw-semibold">Alta</span>
                      <?php elseif ($o['prioridad'] === 'Media'): ?>
                        <span class="text-secondary">Media</span>
                      <?php else: ?>
                        <span class="text-muted">Baja</span>
                      <?php endif; ?>
                    </td>
                    <td class="fw-medium">
                      $<?= number_format((float)$o['costo_total'], 2) ?>
                    </td>
                    <td>
                      <span class="badge-pill-status <?= $claseBadge ?>">
                        <?= htmlspecialchars($o['estado']) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <a href="orden_revisar.php?id=<?= $o['id_orden'] ?>&origen=servicios" class="btn-review-link">
                        Atender <i class="bi bi-arrow-right"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    No se encontraron órdenes de servicio con los filtros seleccionados.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>