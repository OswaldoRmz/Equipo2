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

$partesNombre = explode(' ', trim($usuario['nombres']));
$primerNombre = $partesNombre[0] ?? 'Admin';

$empresa = obtenerDatosEmpresa($pdo);

// Contador para el badge del menú lateral
$pendientesP = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP + $pendientesO;

// Contadores por estado del catálogo
$cntTodos     = $pdo->query("SELECT COUNT(*) FROM servicio")->fetchColumn();
$cntActivos   = $pdo->query("SELECT COUNT(*) FROM servicio WHERE estado = 'Activo'")->fetchColumn();
$cntInactivos = $pdo->query("SELECT COUNT(*) FROM servicio WHERE estado = 'Inactivo'")->fetchColumn();

// 2. Filtros recibidos por GET
$estado = $_GET['estado'] ?? 'todos';
$buscar = trim($_GET['buscar'] ?? '');

$condiciones = [];
$params = [];

if ($estado === 'Activo' || $estado === 'Inactivo') {
    $condiciones[] = "s.estado = ?";
    $params[] = $estado;
}

if ($buscar !== '') {
    $condiciones[] = "(s.nombre LIKE ? OR s.descripcion LIKE ? OR c.nombre LIKE ?)";
    $texto = '%' . $buscar . '%';
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
}

// 3. Consulta principal del catálogo de servicios
$sql = "SELECT s.*, c.nombre AS categoria_nombre
        FROM servicio s
        INNER JOIN categoria c ON s.id_categoria = c.id_categoria";

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(" AND ", $condiciones);
}
$sql .= " ORDER BY s.id_servicio DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listaServicios = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Catálogo de Servicios — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_catalogo_servicios.php" class="sidebar-link active">
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

      <?php if (isset($_GET['msg']) && $_GET['msg'] === 'creado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> El servicio se agregó correctamente al catálogo.
        </div>
      <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> Los datos del servicio se actualizaron correctamente.
        </div>
      <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> El servicio fue eliminado del catálogo.
        </div>
      <?php elseif (isset($_GET['err']) && $_GET['err'] === 'tiene_ordenes'): ?>
        <div class="alert alert-warning py-2 px-3 small mb-4">
          <i class="bi bi-exclamation-triangle me-1"></i> No se puede eliminar este servicio porque está vinculado a órdenes de servicio registradas. Puedes editarlo y cambiar su estado a <strong>Inactivo</strong>.
        </div>
      <?php endif; ?>

      <!-- Encabezado y botón de alta -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Catálogo de servicios</h1>
          <p class="page-subheading">Administra los servicios técnicos, precios estimados y disponibilidad en el sitio.</p>
        </div>
        <a href="servicio_crear.php" class="btn-admin-primary">
          <i class="bi bi-plus-lg me-1"></i> Agregar nuevo servicio
        </a>
      </div>

      <!-- Tarjeta principal -->
      <div class="panel-card">

        <!-- Filtros por estado y buscador -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div class="d-flex flex-wrap gap-1">
            <a href="administrar_catalogo_servicios.php?estado=todos&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'todos' ? 'active' : '' ?>">
              Todos (<?= $cntTodos ?>)
            </a>
            <a href="administrar_catalogo_servicios.php?estado=Activo&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Activo' ? 'active' : '' ?>">
              Activos (<?= $cntActivos ?>)
            </a>
            <a href="administrar_catalogo_servicios.php?estado=Inactivo&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $estado === 'Inactivo' ? 'active' : '' ?>">
              Inactivos (<?= $cntInactivos ?>)
            </a>
          </div>

          <form method="GET" action="administrar_catalogo_servicios.php" class="d-flex gap-2">
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>" />
            <input type="text" name="buscar" class="form-control form-control-sm"
                   placeholder="Nombre, descripción o categoría..."
                   value="<?= htmlspecialchars($buscar) ?>" />
            <button type="submit" class="btn-admin-primary btn-sm">Buscar</button>
            <?php if ($buscar !== ''): ?>
              <a href="administrar_catalogo_servicios.php?estado=<?= urlencode($estado) ?>"
                 class="btn-admin-outline btn-sm">Limpiar</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tabla de servicios -->
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Servicio</th>
                <th>Categoría</th>
                <th>Descripción</th>
                <th>Precio estimado</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($listaServicios) > 0): ?>
                <?php foreach ($listaServicios as $serv): ?>
                  <tr>
                    <td class="folio-cell">#<?= $serv['id_servicio'] ?></td>
                    <td class="fw-medium"><?= htmlspecialchars($serv['nombre']) ?></td>
                    <td class="small text-secondary"><?= htmlspecialchars($serv['categoria_nombre']) ?></td>
                    <td class="small text-secondary">
                      <?= htmlspecialchars(!empty($serv['descripcion']) ? mb_strimwidth($serv['descripcion'], 0, 55, '...') : 'Sin descripción') ?>
                    </td>
                    <td class="fw-medium">$<?= number_format((float)$serv['precio_estimado'], 2) ?></td>
                    <td>
                      <?php if ($serv['estado'] === 'Activo'): ?>
                        <span class="badge-pill-status status-green">Activo</span>
                      <?php else: ?>
                        <span class="badge-pill-status status-slate">Inactivo</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a href="servicio_editar.php?id=<?= $serv['id_servicio'] ?>" class="btn-review-link me-2">
                        Editar
                      </a>
                      <a href="servicio_eliminar.php?id=<?= $serv['id_servicio'] ?>"
                         class="small text-danger text-decoration-none fw-medium"
                         onclick="return confirm('¿Seguro que deseas eliminar el servicio <?= htmlspecialchars($serv['nombre'], ENT_QUOTES) ?>?');">
                        Eliminar
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    No se encontraron servicios registrados en el catálogo.
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