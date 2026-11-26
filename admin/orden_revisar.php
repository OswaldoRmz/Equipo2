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

// 2. Consultar los datos actuales de la orden de servicio
$sql = "SELECT o.*, s.nombre AS servicio_nombre, s.precio_estimado,
               u.nombres, u.apellidos, u.correo, u.telefono
        FROM orden_servicio o
        INNER JOIN usuario u ON o.id_cliente = u.id_usuario
        LEFT JOIN servicio s ON o.id_servicio = s.id_servicio
        WHERE o.id_orden = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$orden = $stmt->fetch();

if (!$orden) {
    header('Location: administrar_solicitudes.php');
    exit;
}

// Catálogo de servicios activos para poder asignar o cambiar el servicio
$listaServicios = $pdo->query("SELECT id_servicio, nombre, precio_estimado FROM servicio WHERE estado = 'Activo' ORDER BY nombre ASC")->fetchAll();

// 3. Si el formulario se envió por POST, procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estado      = $_POST['estado'] ?? '';
    $prioridad   = $_POST['prioridad'] ?? 'Media';
    $idServicio  = (int)($_POST['id_servicio'] ?? 0);
    $diagnostico = trim($_POST['diagnostico_tecnico'] ?? '');
    $manoObra    = trim($_POST['costo_mano_obra'] ?? '0');
    $costoTotal  = trim($_POST['costo_total'] ?? '0');

    $estadosPermitidos     = ['Recibido', 'En Revisión', 'Esperando Piezas', 'Reparado', 'Entregado', 'Cancelado'];
    $prioridadesPermitidas = ['Baja', 'Media', 'Alta'];

    if (!in_array($estado, $estadosPermitidos)) {
        $error = 'Selecciona un estado válido para la orden.';
    } elseif (!in_array($prioridad, $prioridadesPermitidas)) {
        $error = 'Selecciona una prioridad válida.';
    } elseif (!is_numeric($manoObra) || (float)$manoObra < 0 || !is_numeric($costoTotal) || (float)$costoTotal < 0) {
        $error = 'Los costos deben ser números mayores o iguales a 0.';
    } else {
        $manoObraFloat   = (float)$manoObra;
        $costoTotalFloat = (float)$costoTotal;
        $servicioValor   = ($idServicio > 0) ? $idServicio : null;
        $diagValor       = ($diagnostico !== '') ? $diagnostico : null;

        if ($estado === 'Entregado') {
            $sqlUpd = "UPDATE orden_servicio
                       SET estado = ?,
                           prioridad = ?,
                           id_servicio = ?,
                           diagnostico_tecnico = ?,
                           costo_mano_obra = ?,
                           costo_total = ?,
                           fecha_entrega = COALESCE(fecha_entrega, NOW())
                       WHERE id_orden = ?";
        } else {
            $sqlUpd = "UPDATE orden_servicio
                       SET estado = ?,
                           prioridad = ?,
                           id_servicio = ?,
                           diagnostico_tecnico = ?,
                           costo_mano_obra = ?,
                           costo_total = ?
                       WHERE id_orden = ?";
        }

        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute([
            $estado,
            $prioridad,
            $servicioValor,
            $diagValor,
            $manoObraFloat,
            $costoTotalFloat,
            $id
        ]);

        if ($origen === 'servicios') {
            header('Location: administrar_servicios.php?msg=actualizado');
        } else {
            header('Location: administrar_solicitudes.php?msg=actualizado');
        }
        exit;
    }
}

$urlVolver = ($origen === 'servicios') ? 'administrar_servicios.php' : 'administrar_solicitudes.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Atender Orden #<?= htmlspecialchars($orden['folio']) ?> — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
            <a href="administrar_solicitudes.php" class="sidebar-link <?= $origen !== 'servicios' ? 'active' : '' ?>">
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
            <a href="administrar_servicios.php" class="sidebar-link <?= $origen === 'servicios' ? 'active' : '' ?>">
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
          <h1 class="page-heading">Atender orden #<?= htmlspecialchars($orden['folio']) ?></h1>
          <p class="page-subheading">Registra el diagnóstico técnico, los costos y el avance del servicio.</p>
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
        <!-- Columna izquierda: Datos del cliente y equipo recibido -->
        <div class="col-lg-5">
          <div class="panel-card h-100">
            <h2 class="panel-title mb-3">Información del cliente y equipo</h2>

            <div class="mb-3 pb-3 border-bottom">
              <div class="small text-muted">Cliente</div>
              <div class="fw-semibold"><?= htmlspecialchars($orden['nombres'] . ' ' . $orden['apellidos']) ?></div>
              <div class="small text-secondary">
                <?= htmlspecialchars($orden['correo']) ?> &middot; Tel: <?= htmlspecialchars($orden['telefono']) ?>
              </div>
            </div>

            <div class="mb-3 pb-3 border-bottom">
              <div class="small text-muted">Equipo recibido</div>
              <div class="fw-semibold"><?= htmlspecialchars($orden['equipo_receptor']) ?></div>
              <div class="small text-secondary">
                Fecha de ingreso: <?= date('d/m/Y H:i', strtotime($orden['fecha_ingreso'])) ?>
              </div>
              <?php if (!empty($orden['fecha_entrega'])): ?>
                <div class="small text-secondary">
                  Fecha de entrega: <?= date('d/m/Y H:i', strtotime($orden['fecha_entrega'])) ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <div class="small text-muted mb-1">Falla reportada por el cliente</div>
              <div class="p-3 bg-light rounded border small">
                <?= nl2br(htmlspecialchars($orden['falla_reportada'])) ?>
              </div>
            </div>

            <?php if (!empty($orden['archivo_adjunto'])): ?>
              <div>
                <div class="small text-muted mb-1">Archivo adjunto</div>
                <a href="../uploads/ordenes/<?= htmlspecialchars($orden['archivo_adjunto']) ?>" target="_blank" class="btn-admin-outline btn-sm">
                  <i class="bi bi-paperclip me-1"></i> Ver archivo adjunto
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Columna derecha: Formulario técnico -->
        <div class="col-lg-7">
          <div class="panel-card h-100">
            <h2 class="panel-title mb-3">Diagnóstico y seguimiento técnico</h2>

            <form method="POST" action="orden_revisar.php">
              <input type="hidden" name="id" value="<?= $orden['id_orden'] ?>" />
              <input type="hidden" name="origen" value="<?= htmlspecialchars($origen) ?>" />

              <div class="row g-3 mb-3">
                <div class="col-sm-6">
                  <label for="estado" class="form-label small fw-semibold">Estado de la orden</label>
                  <select name="estado" id="estado" class="form-select" required>
                    <option value="Recibido" <?= $orden['estado'] === 'Recibido' ? 'selected' : '' ?>>Recibido</option>
                    <option value="En Revisión" <?= $orden['estado'] === 'En Revisión' ? 'selected' : '' ?>>En Revisión</option>
                    <option value="Esperando Piezas" <?= $orden['estado'] === 'Esperando Piezas' ? 'selected' : '' ?>>Esperando Piezas</option>
                    <option value="Reparado" <?= $orden['estado'] === 'Reparado' ? 'selected' : '' ?>>Reparado</option>
                    <option value="Entregado" <?= $orden['estado'] === 'Entregado' ? 'selected' : '' ?>>Entregado</option>
                    <option value="Cancelado" <?= $orden['estado'] === 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                  </select>
                </div>

                <div class="col-sm-6">
                  <label for="prioridad" class="form-label small fw-semibold">Prioridad</label>
                  <select name="prioridad" id="prioridad" class="form-select" required>
                    <option value="Baja" <?= $orden['prioridad'] === 'Baja' ? 'selected' : '' ?>>Baja</option>
                    <option value="Media" <?= $orden['prioridad'] === 'Media' ? 'selected' : '' ?>>Media</option>
                    <option value="Alta" <?= $orden['prioridad'] === 'Alta' ? 'selected' : '' ?>>Alta</option>
                  </select>
                </div>
              </div>

              <div class="mb-3">
                <label for="id_servicio" class="form-label small fw-semibold">Servicio del catálogo asociado</label>
                <select name="id_servicio" id="id_servicio" class="form-select">
                  <option value="0">-- Sin servicio específico asignado --</option>
                  <?php foreach ($listaServicios as $serv): ?>
                    <option value="<?= $serv['id_servicio'] ?>" <?= (int)$orden['id_servicio'] === (int)$serv['id_servicio'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($serv['nombre']) ?> ($<?= number_format($serv['precio_estimado'], 2) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label for="diagnostico_tecnico" class="form-label small fw-semibold">Diagnóstico técnico y trabajos realizados</label>
                <textarea name="diagnostico_tecnico" id="diagnostico_tecnico" rows="4" class="form-control"
                          placeholder="Describe el diagnóstico encontrado y las acciones realizadas..."><?= htmlspecialchars($_POST['diagnostico_tecnico'] ?? ($orden['diagnostico_tecnico'] ?? '')) ?></textarea>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-sm-6">
                  <label for="costo_mano_obra" class="form-label small fw-semibold">Costo mano de obra ($)</label>
                  <input type="number" step="0.01" min="0" name="costo_mano_obra" id="costo_mano_obra" class="form-control"
                         value="<?= htmlspecialchars($_POST['costo_mano_obra'] ?? $orden['costo_mano_obra']) ?>" required />
                </div>
                <div class="col-sm-6">
                  <label for="costo_total" class="form-label small fw-semibold">Costo total final ($)</label>
                  <input type="number" step="0.01" min="0" name="costo_total" id="costo_total" class="form-control"
                         value="<?= htmlspecialchars($_POST['costo_total'] ?? $orden['costo_total']) ?>" required />
                </div>
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