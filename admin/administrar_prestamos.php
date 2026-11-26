<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/administrar_prestamos.php');
    exit;
}

$usuario = usuarioActual();
if ($usuario['id_rol'] != 1 &&$usuario['id_rol'] != 2) {
    header('Location: ../index.php');
    exit;
}

$partesNombre = explode(' ', trim($usuario['nombres']));
$primerNombre =$partesNombre[0] ?? 'Admin';

$empresa = obtenerDatosEmpresa($pdo);

// Contador para el badge del menú lateral
$pendientesP =$pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO =$pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP +$pendientesO;

// Contadores rápidos por estado de préstamo
$cntTodos     =$pdo->query("SELECT COUNT(*) FROM prestamo")->fetchColumn();
$cntPendiente =$pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$cntPrestado  =$pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Prestado'")->fetchColumn();
$cntDevuelto  =$pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Devuelto'")->fetchColumn();
$cntCancelado =$pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Cancelado'")->fetchColumn();

// 2. Filtros recibidos por GET
$estado =$_GET['estado'] ?? 'todos';
$buscar = trim($_GET['buscar'] ?? '');

$condiciones = [];$params = [];

$estadosValidos = ['Pendiente', 'Prestado', 'Devuelto', 'Cancelado'];
if (in_array($estado, $estadosValidos)) {$condiciones[] = "p.estado = ?";
    $params[] =$estado;
}

if ($buscar !== '') {$condiciones[] = "(p.folio LIKE ? OR u.nombres LIKE ? OR u.apellidos LIKE ? OR e.codigo LIKE ? OR e.nombre LIKE ?)";
    $texto = '\%' .$buscar . '%';
    $params[] =$texto;
    $params[] =$texto;
    $params[] =$texto;
    $params[] =$texto;
    $params[] =$texto;
}

// 3. Consulta de préstamos
$sql = "SELECT p.*, e.codigo AS equipo_codigo, e.nombre AS equipo_nombre,
               u.nombres, u.apellidos, u.correo, u.telefono
        FROM prestamo p
        INNER JOIN equipo e ON p.id_equipo = e.id_equipo
        INNER JOIN usuario u ON p.id_cliente = u.id_usuario";

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(" AND ", $condiciones);
}
$sql .= " ORDER BY p.fecha_registro DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listaPrestamos =$stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Administrar Préstamos — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_prestamos.php" class="sidebar-link active">
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

      <?php if (isset($_GET['msg']) &&$_GET['msg'] === 'actualizado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> El estado del préstamo se actualizó correctamente.
        </div>
      <?php endif; ?>

      <!-- Encabezado -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Control de préstamos</h1>
          <p class="page-subheading">Administra entregas, devoluciones y fechas límite de los equipos prestados.</p>
        </div>
      </div>

      <!-- Tarjeta principal -->
      <div class="panel-card">

        <!-- Filtros por estado y buscador -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div class="d-flex flex-wrap gap-1">
            <a href="administrar_prestamos.php?estado=todos&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'todos' ? 'active' : '' ?>">
              Todos (<?= $cntTodos ?>)
            </a>
            <a href="administrar_prestamos.php?estado=Pendiente&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Pendiente' ? 'active' : '' ?>">
              Pendientes (<?= $cntPendiente ?>)
            </a>
            <a href="administrar_prestamos.php?estado=Prestado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Prestado' ? 'active' : '' ?>">
              Activos / Prestados (<?= $cntPrestado ?>)
            </a>
            <a href="administrar_prestamos.php?estado=Devuelto&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Devuelto' ? 'active' : '' ?>">
              Devueltos (<?= $cntDevuelto ?>)
            </a>
            <a href="administrar_prestamos.php?estado=Cancelado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Cancelado' ? 'active' : '' ?>">
              Cancelados (<?= $cntCancelado ?>)
            </a>
          </div>

          <form method="GET" action="administrar_prestamos.php" class="d-flex gap-2">
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>" />
            <input type="text" name="buscar" class="form-control form-control-sm"
                   placeholder="Folio, solicitante o equipo..."
                   value="<?= htmlspecialchars($buscar) ?>" />
            <button type="submit" class="btn-admin-primary btn-sm">Buscar</button>
            <?php if ($buscar !== ''): ?>
              <a href="administrar_prestamos.php?estado=<?= urlencode($estado) ?>"
                 class="btn-admin-outline btn-sm">Limpiar</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tabla de préstamos -->
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Solicitante</th>
                <th>Equipo</th>
                <th>Inicio</th>
                <th>Fecha límite</th>
                <th>Estado</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($listaPrestamos) > 0): ?>
                <?php foreach ($listaPrestamos as$p): ?>
                  <?php
                    $claseBadge = 'status-slate';
                    if ($p['estado'] === 'Pendiente') {$claseBadge = 'status-amber';
                    } elseif ($p['estado'] === 'Prestado') {$claseBadge = 'status-blue';
                    } elseif ($p['estado'] === 'Devuelto') {$claseBadge = 'status-green';
                    }

                    $estaVencido = ($p['estado'] === 'Prestado' && strtotime($p['fecha_limite']) < time());
                  ?>
                  <tr>
                    <td class="folio-cell">#<?= htmlspecialchars($p['folio']) ?></td>
                    <td>
                      <div class="fw-medium"><?= htmlspecialchars($p['nombres'] . ' ' .$p['apellidos']) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars($p['telefono']) ?></div>
                    </td>
                    <td>
                      <div class="fw-medium"><?= htmlspecialchars($p['equipo_nombre']) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars($p['equipo_codigo']) ?></div>
                    </td>
                    <td class="text-secondary small">
                      <?= date('d/m/Y H:i', strtotime($p['fecha_inicio'])) ?>
                    </td>
                    <td class="small <?= $estaVencido ? 'text-danger fw-semibold' : 'text-secondary' ?>">
                      <?= date('d/m/Y H:i', strtotime($p['fecha_limite'])) ?>
                      <?php if ($estaVencido): ?>
                        <span class="d-block small">Vencido</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge-pill-status <?= $claseBadge ?>">
                        <?= htmlspecialchars($p['estado']) ?>
                      </span>
                    </td>
                    <td class="text-end">
                      <a href="prestamo_revisar.php?id=<?= $p['id_prestamo'] ?>&origen=prestamos" class="btn-review-link">
                        Gestionar <i class="bi bi-arrow-right"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    No se encontraron préstamos con los filtros seleccionados.
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