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

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header('Location: administrar_catalogo_servicios.php');
    exit;
}

// 2. Consultar datos actuales del servicio
$stmtServ = $pdo->prepare("SELECT * FROM servicio WHERE id_servicio = ?");
$stmtServ->execute([$id]);
$servicio = $stmtServ->fetch();

if (!$servicio) {
    header('Location: administrar_catalogo_servicios.php');
    exit;
}

$categorias = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Servicio' ORDER BY id_categoria ASC")->fetchAll();

$error = '';

// 3. Procesar actualización si es POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre      = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio      = trim($_POST['precio_estimado'] ?? '');
    $idCategoria = (int)($_POST['id_categoria'] ?? 0);
    $estado      = $_POST['estado'] ?? 'Activo';

    if ($nombre === '' || $idCategoria <= 0 || $precio === '') {
        $error = 'El nombre, la categoría y el precio estimado son obligatorios.';
    } elseif (!is_numeric($precio) || (float)$precio < 0) {
        $error = 'El precio estimado debe ser un número mayor o igual a 0.';
    } elseif (!in_array($estado, ['Activo', 'Inactivo'])) {
        $error = 'Selecciona un estado válido.';
    } else {
        $precioFloat = (float)$precio;

        $sqlUpd = "UPDATE servicio
                   SET nombre = ?,
                       descripcion = ?,
                       precio_estimado = ?,
                       id_categoria = ?,
                       estado = ?
                   WHERE id_servicio = ?";
        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute([
            $nombre,
            $descripcion !== '' ? $descripcion : null,
            $precioFloat,
            $idCategoria,
            $estado,
            $id
        ]);

        header('Location: administrar_catalogo_servicios.php?msg=actualizado');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Editar Servicio — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <h1 class="page-heading">Editar servicio (#<?= $servicio['id_servicio'] ?>)</h1>
          <p class="page-subheading">Actualiza la información, precio estimado o disponibilidad del servicio.</p>
        </div>
        <a href="administrar_catalogo_servicios.php" class="btn-admin-outline">
          <i class="bi bi-arrow-left me-1"></i> Volver al catálogo
        </a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2 px-3 small mb-4">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="panel-card" style="max-width: 720px;">
        <form method="POST" action="servicio_editar.php">
          <input type="hidden" name="id" value="<?= $servicio['id_servicio'] ?>" />

          <div class="mb-3">
            <label for="nombre" class="form-label small fw-semibold">Nombre del servicio *</label>
            <input type="text" name="nombre" id="nombre" class="form-control"
                   value="<?= htmlspecialchars($_POST['nombre'] ?? $servicio['nombre']) ?>" required />
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-5">
              <label for="id_categoria" class="form-label small fw-semibold">Categoría *</label>
              <select name="id_categoria" id="id_categoria" class="form-select" required>
                <?php $catActual = $_POST['id_categoria'] ?? $servicio['id_categoria']; ?>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= $cat['id_categoria'] ?>" <?= (int)$catActual === (int)$cat['id_categoria'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="precio_estimado" class="form-label small fw-semibold">Precio estimado ($) *</label>
              <input type="number" step="0.01" min="0" name="precio_estimado" id="precio_estimado" class="form-control"
                     value="<?= htmlspecialchars($_POST['precio_estimado'] ?? $servicio['precio_estimado']) ?>" required />
            </div>

            <div class="col-sm-3">
              <label for="estado" class="form-label small fw-semibold">Estado</label>
              <?php $estActual = $_POST['estado'] ?? $servicio['estado']; ?>
              <select name="estado" id="estado" class="form-select">
                <option value="Activo" <?= $estActual === 'Activo' ? 'selected' : '' ?>>Activo</option>
                <option value="Inactivo" <?= $estActual === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
              </select>
            </div>
          </div>

          <div class="mb-4">
            <label for="descripcion" class="form-label small fw-semibold">Descripción del servicio</label>
            <textarea name="descripcion" id="descripcion" rows="4" class="form-control"><?= htmlspecialchars($_POST['descripcion'] ?? ($servicio['descripcion'] ?? '')) ?></textarea>
          </div>

          <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="administrar_catalogo_servicios.php" class="btn-admin-outline">Cancelar</a>
            <button type="submit" class="btn-admin-primary">Actualizar servicio</button>
          </div>

        </form>
      </div>

    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>