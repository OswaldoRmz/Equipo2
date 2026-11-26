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

// Contador para el badge del menú lateral
$pendientesP = $pdo->query("SELECT COUNT(*) FROM prestamo WHERE estado = 'Pendiente'")->fetchColumn();
$pendientesO = $pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ('Recibido', 'En Revisión', 'Esperando Piezas')")->fetchColumn();
$totalPendientes = $pendientesP + $pendientesO;

$error = '';
$id = $_GET['id'] ?? $_POST['id'] ?? null;
$origen = $_GET['origen'] ?? $_POST['origen'] ?? 'solicitudes';

if (!$id || !is_numeric($id)) {
    header('Location: administrar_solicitudes.php');
    exit;
}

// 2. Consultar los datos actuales del préstamo
$sql = "SELECT p.*, e.codigo AS equipo_codigo, e.nombre AS equipo_nombre,
               e.descripcion AS equipo_descripcion, e.ubicacion AS equipo_ubicacion,
               e.estado AS equipo_estado, m.nombre AS marca_nombre,
               u.nombres, u.apellidos, u.correo, u.telefono
        FROM prestamo p
        INNER JOIN equipo e ON p.id_equipo = e.id_equipo
        INNER JOIN marca m ON e.id_marca = m.id_marca
        INNER JOIN usuario u ON p.id_cliente = u.id_usuario
        WHERE p.id_prestamo = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$prestamo = $stmt->fetch();

if (!$prestamo) {
    header('Location: administrar_solicitudes.php');
    exit;
}

// 3. Si el formulario se envió por POST, procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estado = $_POST['estado'] ?? '';
    $observaciones = trim($_POST['observaciones_devolucion'] ?? '');
    $estadosPermitidos = ['Pendiente', 'Prestado', 'Devuelto', 'Cancelado'];

    if (!in_array($estado, $estadosPermitidos)) {
        $error = 'Selecciona un estado válido para el préstamo.';
    } else {
        $idEquipo = $prestamo['id_equipo'];

        if ($estado === 'Prestado') {
            // Se aprueba y entrega el equipo al estudiante
            $sqlUpd = "UPDATE prestamo
                       SET estado = 'Prestado',
                           fecha_entrega = COALESCE(fecha_entrega, NOW()),
                           observaciones_devolucion = ?
                       WHERE id_prestamo = ?";
            $stmtUpd = $pdo->prepare($sqlUpd);
            $stmtUpd->execute([$observaciones !== '' ? $observaciones : null, $id]);

            $stmtEq = $pdo->prepare("UPDATE equipo SET estado = 'Prestado' WHERE id_equipo = ?");
            $stmtEq->execute([$idEquipo]);

        } elseif ($estado === 'Devuelto') {
            // El estudiante regresa el equipo y vuelve a estar disponible
            $sqlUpd = "UPDATE prestamo
                       SET estado = 'Devuelto',
                           fecha_devolucion = NOW(),
                           observaciones_devolucion = ?
                       WHERE id_prestamo = ?";
            $stmtUpd = $pdo->prepare($sqlUpd);
            $stmtUpd->execute([$observaciones !== '' ? $observaciones : null, $id]);

            $stmtEq = $pdo->prepare("UPDATE equipo SET estado = 'Disponible' WHERE id_equipo = ?");
            $stmtEq->execute([$idEquipo]);

        } elseif ($estado === 'Cancelado') {
            // Se rechaza o cancela el préstamo y se libera el equipo
            $sqlUpd = "UPDATE prestamo
                       SET estado = 'Cancelado',
                           observaciones_devolucion = ?
                       WHERE id_prestamo = ?";
            $stmtUpd = $pdo->prepare($sqlUpd);
            $stmtUpd->execute([$observaciones !== '' ? $observaciones : null, $id]);

            $stmtEq = $pdo->prepare("UPDATE equipo SET estado = 'Disponible' WHERE id_equipo = ?");
            $stmtEq->execute([$idEquipo]);

        } else {
            // Regresa a Pendiente
            $sqlUpd = "UPDATE prestamo
                       SET estado = 'Pendiente',
                           observaciones_devolucion = ?
                       WHERE id_prestamo = ?";
            $stmtUpd = $pdo->prepare($sqlUpd);
            $stmtUpd->execute([$observaciones !== '' ? $observaciones : null, $id]);
        }

        // Redirección según de dónde venía el administrador
        if ($origen === 'prestamos') {
            header('Location: administrar_prestamos.php?msg=actualizado');
        } else {
            header('Location: administrar_solicitudes.php?msg=actualizado');
        }
        exit;
    }
}

$urlVolver = ($origen === 'prestamos') ? 'administrar_prestamos.php' : 'administrar_solicitudes.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Revisar Préstamo #<?= htmlspecialchars($prestamo['folio']) ?> — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_solicitudes.php" class="sidebar-link <?= $origen !== 'prestamos' ? 'active' : '' ?>">
              <span class="sidebar-link-left"><i class="bi bi-laptop"></i> Solicitudes</span>
              <?php if ($totalPendientes > 0): ?>
                <span class="sidebar-count-badge"><?= $totalPendientes ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li>
            <a href="administrar_prestamos.php" class="sidebar-link <?= $origen === 'prestamos' ? 'active' : '' ?>">
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

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <h1 class="page-heading">Revisar préstamo #<?= htmlspecialchars($prestamo['folio']) ?></h1>
          <p class="page-subheading">Consulta los datos de la solicitud y actualiza el estado de entrega o devolución.</p>
        </div>
        <a href="<?= $urlVolver ?>" class="btn-admin-outline">
          <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2 px-3 small mb-4">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="row g-4">
        <!-- Columna izquierda: Información de la solicitud -->
        <div class="col-lg-6">
          <div class="panel-card h-100">
            <h2 class="panel-title mb-3">Datos de la solicitud</h2>

            <div class="mb-3 pb-3 border-bottom">
              <div class="small text-muted">Solicitante</div>
              <div class="fw-semibold"><?= htmlspecialchars($prestamo['nombres'] . ' ' . $prestamo['apellidos']) ?></div>
              <div class="small text-secondary">
                <?= htmlspecialchars($prestamo['correo']) ?> &middot; Tel: <?= htmlspecialchars($prestamo['telefono']) ?>
              </div>
            </div>

            <div class="mb-3 pb-3 border-bottom">
              <div class="small text-muted">Equipo solicitado</div>
              <div class="fw-semibold">
                <?= htmlspecialchars($prestamo['equipo_nombre']) ?>
                <span class="text-muted fw-normal">(<?= htmlspecialchars($prestamo['equipo_codigo']) ?>)</span>
              </div>
              <div class="small text-secondary">
                Marca: <?= htmlspecialchars($prestamo['marca_nombre']) ?> &middot;
                Ubicación: <?= htmlspecialchars($prestamo['equipo_ubicacion'] ?? 'No especificada') ?>
              </div>
              <?php if (!empty($prestamo['equipo_descripcion'])): ?>
                <div class="small text-muted mt-1"><?= htmlspecialchars($prestamo['equipo_descripcion']) ?></div>
              <?php endif; ?>
            </div>

            <div class="row g-3 mb-3 pb-3 border-bottom">
              <div class="col-6">
                <div class="small text-muted">Fecha de inicio</div>
                <div class="fw-medium"><?= date('d/m/Y H:i', strtotime($prestamo['fecha_inicio'])) ?></div>
              </div>
              <div class="col-6">
                <div class="small text-muted">Fecha límite de devolución</div>
                <div class="fw-medium"><?= date('d/m/Y H:i', strtotime($prestamo['fecha_limite'])) ?></div>
              </div>
              <?php if (!empty($prestamo['fecha_entrega'])): ?>
                <div class="col-6">
                  <div class="small text-muted">Entregado el</div>
                  <div class="small fw-medium"><?= date('d/m/Y H:i', strtotime($prestamo['fecha_entrega'])) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($prestamo['fecha_devolucion'])): ?>
                <div class="col-6">
                  <div class="small text-muted">Devuelto el</div>
                  <div class="small fw-medium"><?= date('d/m/Y H:i', strtotime($prestamo['fecha_devolucion'])) ?></div>
                </div>
              <?php endif; ?>
            </div>

            <div>
              <div class="small text-muted mb-1">Motivo del préstamo</div>
              <div class="p-3 bg-light rounded border small">
                <?= nl2br(htmlspecialchars(!empty($prestamo['motivo']) ? $prestamo['motivo'] : 'Sin motivo especificado.')) ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Columna derecha: Formulario de resolución -->
        <div class="col-lg-6">
          <div class="panel-card h-100">
            <h2 class="panel-title mb-3">Resolución y estado</h2>

            <form method="POST" action="prestamo_revisar.php">
              <input type="hidden" name="id" value="<?= $prestamo['id_prestamo'] ?>" />
              <input type="hidden" name="origen" value="<?= htmlspecialchars($origen) ?>" />

              <div class="mb-3">
                <label for="estado" class="form-label small fw-semibold">Estado del préstamo</label>
                <select name="estado" id="estado" class="form-select" required>
                  <option value="Pendiente" <?= $prestamo['estado'] === 'Pendiente' ? 'selected' : '' ?>>
                    Pendiente (en espera de revisión)
                  </option>
                  <option value="Prestado" <?= $prestamo['estado'] === 'Prestado' ? 'selected' : '' ?>>
                    Prestado (aprobar y entregar equipo)
                  </option>
                  <option value="Devuelto" <?= $prestamo['estado'] === 'Devuelto' ? 'selected' : '' ?>>
                    Devuelto (recibir equipo y ponerlo disponible)
                  </option>
                  <option value="Cancelado" <?= $prestamo['estado'] === 'Cancelado' ? 'selected' : '' ?>>
                    Cancelado (rechazar o cancelar solicitud)
                  </option>
                </select>
                <div class="form-text small">
                  Al marcar como <strong>Prestado</strong> o <strong>Devuelto</strong>, el estado del equipo en el inventario se actualiza automáticamente.
                </div>
              </div>

              <div class="mb-4">
                <label for="observaciones_devolucion" class="form-label small fw-semibold">
                  Observaciones de entrega o devolución
                </label>
                <textarea name="observaciones_devolucion" id="observaciones_devolucion" rows="4"
                          class="form-control"
                          placeholder="Ej. Se entrega con cargador original y maletín sin daños..."><?= htmlspecialchars($_POST['observaciones_devolucion'] ?? ($prestamo['observaciones_devolucion'] ?? '')) ?></textarea>
              </div>

              <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                <a href="<?= $urlVolver ?>" class="btn-admin-outline">Cancelar</a>
                <button type="submit" class="btn-admin-primary">Guardar cambios</button>
              </div>
            </form>
          </div>
        </div>
      </div>

    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>