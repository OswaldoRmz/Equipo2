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

$partesNombre = explode(' ', trim($usuario['nombres']));
$primerNombre = $partesNombre[0] ?? 'Admin';

$empresa = obtenerDatosEmpresa($pdo);

// Contador para el badge del menú lateral
$pendientesP = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP + $pendientesO;

// Contadores por estado/visibilidad
$cntTodos         = $pdo->query("SELECT COUNT(*) FROM equipo")->fetchColumn();
$cntDisponibles   = $pdo->query("SELECT COUNT(*) FROM equipo WHERE visibilidad = 'Activo' AND estado = 'Disponible'")->fetchColumn();
$cntPrestados     = $pdo->query("SELECT COUNT(*) FROM equipo WHERE visibilidad = 'Activo' AND estado = 'Prestado'")->fetchColumn();
$cntDeshabilitados = $pdo->query("SELECT COUNT(*) FROM equipo WHERE visibilidad = 'Deshabilitado'")->fetchColumn();

// 2. Filtros recibidos por GET
$filtro = $_GET['filtro'] ?? 'todos';
$buscar = trim($_GET['buscar'] ?? '');

$condiciones = [];
$params = [];

if ($filtro === 'Disponible') {
    $condiciones[] = "e.visibilidad = 'Activo' AND e.estado = 'Disponible'";
} elseif ($filtro === 'Prestado') {
    $condiciones[] = "e.visibilidad = 'Activo' AND e.estado = 'Prestado'";
} elseif ($filtro === 'Deshabilitado') {
    $condiciones[] = "e.visibilidad = 'Deshabilitado'";
}

if ($buscar !== '') {
    $condiciones[] = "(e.codigo LIKE ? OR e.nombre LIKE ? OR m.nombre LIKE ? OR c.nombre LIKE ? OR e.ubicacion LIKE ?)";
    $texto = '%' . $buscar . '%';
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
    $params[] = $texto;
}

// 3. Consulta principal de equipos
$sql = "SELECT e.*, m.nombre AS marca_nombre, c.nombre AS categoria_nombre
        FROM equipo e
        INNER JOIN marca m ON e.id_marca = m.id_marca
        INNER JOIN categoria c ON e.id_categoria = c.id_categoria";

if (count($condiciones) > 0) {
    $sql .= " WHERE " . implode(" AND ", $condiciones);
}
$sql .= " ORDER BY e.id_equipo DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$listaEquipos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Administrar Equipos — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_equipos.php" class="sidebar-link active">
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

      <?php if (isset($_GET['msg']) && $_GET['msg'] === 'creado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> El equipo se registró correctamente en el inventario.
        </div>
      <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> Los datos del equipo se actualizaron correctamente.
        </div>
      <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
        <div class="alert alert-success py-2 px-3 small mb-4">
          <i class="bi bi-check-circle me-1"></i> El equipo fue eliminado del inventario.
        </div>
      <?php elseif (isset($_GET['err']) && $_GET['err'] === 'tiene_prestamos'): ?>
        <div class="alert alert-warning py-2 px-3 small mb-4">
          <i class="bi bi-exclamation-triangle me-1"></i> No se puede eliminar este equipo porque tiene préstamos registrados en el historial. Puedes editarlo y cambiar su visibilidad a <strong>Deshabilitado</strong>.
        </div>
      <?php endif; ?>

      <!-- Encabezado y botón de alta -->
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <h1 class="page-heading">Inventario de equipos</h1>
          <p class="page-subheading">Administra el catálogo de equipos disponibles para préstamo a la comunidad.</p>
        </div>
        <a href="equipo_crear.php" class="btn-admin-primary">
          <i class="bi bi-plus-lg me-1"></i> Agregar nuevo equipo
        </a>
      </div>

      <!-- Tarjeta principal -->
      <div class="panel-card">

        <!-- Filtros y buscador -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
          <div class="d-flex flex-wrap gap-1">
            <a href="administrar_equipos.php?filtro=todos&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $filtro === 'todos' ? 'active' : '' ?>">
              Todos (<?= $cntTodos ?>)
            </a>
            <a href="administrar_equipos.php?filtro=Disponible&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $filtro === 'Disponible' ? 'active' : '' ?>">
              Disponibles (<?= $cntDisponibles ?>)
            </a>
            <a href="administrar_equipos.php?filtro=Prestado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $filtro === 'Prestado' ? 'active' : '' ?>">
              Prestados (<?= $cntPrestados ?>)
            </a>
            <a href="administrar_equipos.php?filtro=Deshabilitado&buscar=<?= urlencode($buscar) ?>"
               class="filter-tab-btn <?= $filtro === 'Deshabilitado' ? 'active' : '' ?>">
              Deshabilitados (<?= $cntDeshabilitados ?>)
            </a>
          </div>

          <form method="GET" action="administrar_equipos.php" class="d-flex gap-2">
            <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>" />
            <input type="text" name="buscar" class="form-control form-control-sm"
                   placeholder="Código, nombre, marca..."
                   value="<?= htmlspecialchars($buscar) ?>" />
            <button type="submit" class="btn-admin-primary btn-sm">Buscar</button>
            <?php if ($buscar !== ''): ?>
              <a href="administrar_equipos.php?filtro=<?= urlencode($filtro) ?>"
                 class="btn-admin-outline btn-sm">Limpiar</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Tabla de equipos -->
        <div class="table-responsive">
          <table class="admin-table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Imagen</th>
                <th>Equipo / Marca</th>
                <th>Categoría</th>
                <th>Ubicación</th>
                <th>Estado</th>
                <th>Visibilidad</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($listaEquipos) > 0): ?>
                <?php foreach ($listaEquipos as $eq): ?>
                  <tr>
                    <td class="folio-cell"><?= htmlspecialchars($eq['codigo']) ?></td>
                    <td>
                      <?php if (!empty($eq['imagen_url'])): ?>
                        <img src="../uploads/equipos/<?= htmlspecialchars($eq['imagen_url']) ?>"
                             alt="<?= htmlspecialchars($eq['nombre']) ?>"
                             width="52" height="40"
                             style="object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0;" />
                      <?php else: ?>
                        <span class="badge-pill-status status-slate">Sin foto</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="fw-medium"><?= htmlspecialchars($eq['nombre']) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars($eq['marca_nombre']) ?></div>
                    </td>
                    <td class="small text-secondary"><?= htmlspecialchars($eq['categoria_nombre']) ?></td>
                    <td class="small text-secondary"><?= htmlspecialchars($eq['ubicacion'] ?? 'Sin ubicación') ?></td>
                    <td>
                      <?php if ($eq['estado'] === 'Disponible'): ?>
                        <span class="badge-pill-status status-green">Disponible</span>
                      <?php else: ?>
                        <span class="badge-pill-status status-amber">Prestado</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($eq['visibilidad'] === 'Activo'): ?>
                        <span class="badge-pill-status status-blue">Activo</span>
                      <?php else: ?>
                        <span class="badge-pill-status status-slate">Deshabilitado</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a href="equipo_editar.php?id=<?= $eq['id_equipo'] ?>" class="btn-review-link me-2">
                        Editar
                      </a>
                      <a href="equipo_eliminar.php?id=<?= $eq['id_equipo'] ?>"
                         class="small text-danger text-decoration-none fw-medium"
                         onclick="return confirm('¿Seguro que deseas eliminar el equipo <?= htmlspecialchars($eq['nombre'], ENT_QUOTES) ?>?');">
                        Eliminar
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">
                    No se encontraron equipos registrados.
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