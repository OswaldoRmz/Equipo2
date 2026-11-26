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

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header('Location: administrar_equipos.php');
    exit;
}

// 2. Consultar datos actuales del equipo
$stmtEq = $pdo->prepare("SELECT * FROM equipo WHERE id_equipo = ?");
$stmtEq->execute([$id]);
$equipo = $stmtEq->fetch();

if (!$equipo) {
    header('Location: administrar_equipos.php');
    exit;
}

$marcas = $pdo->query("SELECT id_marca, nombre FROM marca ORDER BY nombre ASC")->fetchAll();
$categorias = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Equipo' ORDER BY id_categoria ASC")->fetchAll();

$error = '';

// 3. Procesar actualización si es POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo       = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre       = trim($_POST['nombre'] ?? '');
    $descripcion  = trim($_POST['descripcion'] ?? '');
    $ubicacion    = trim($_POST['ubicacion'] ?? '');
    $estado       = $_POST['estado'] ?? 'Disponible';
    $visibilidad  = $_POST['visibilidad'] ?? 'Activo';
    $idMarca      = (int)($_POST['id_marca'] ?? 0);
    $nuevaMarca   = trim($_POST['nueva_marca'] ?? '');
    $idCategoria  = (int)($_POST['id_categoria'] ?? 0);
    $eliminarFoto = isset($_POST['eliminar_foto']) ? 1 : 0;
    $imagen       = $_FILES['imagen'] ?? null;

    if ($codigo === '' || $nombre === '' || $idCategoria <= 0) {
        $error = 'El código, el nombre y la categoría son obligatorios.';
    } elseif (!in_array($estado, ['Disponible', 'Prestado'])) {
        $error = 'Estado no válido.';
    } elseif (!in_array($visibilidad, ['Activo', 'Deshabilitado'])) {
        $error = 'Visibilidad no válida.';
    } else {
        // Verificar que otro equipo no tenga ese mismo código
        $stmtCod = $pdo->prepare("SELECT COUNT(*) FROM equipo WHERE codigo = ? AND id_equipo != ?");
        $stmtCod->execute([$codigo, $id]);
        if ($stmtCod->fetchColumn() > 0) {
            $error = 'Ya existe otro equipo con el código ' . $codigo . '.';
        }
    }

    // Resolver marca
    if ($error === '') {
        if ($nuevaMarca !== '') {
            $stmtM = $pdo->prepare("SELECT id_marca FROM marca WHERE nombre = ? LIMIT 1");
            $stmtM->execute([$nuevaMarca]);
            $marcaEncontrada = $stmtM->fetchColumn();

            if ($marcaEncontrada) {
                $idMarca = (int)$marcaEncontrada;
            } else {
                $stmtInsM = $pdo->prepare("INSERT INTO marca (nombre) VALUES (?)");
                $stmtInsM->execute([$nuevaMarca]);
                $idMarca = (int)$pdo->lastInsertId();
            }
        }

        if ($idMarca <= 0) {
            $error = 'Selecciona una marca existente o escribe una nueva.';
        }
    }

    // Gestión de imagen
    $nombreImagenFinal = $equipo['imagen_url'];
    $dirDestino = __DIR__ . '/../uploads/equipos/';

    if ($error === '' && $eliminarFoto === 1 && !empty($nombreImagenFinal)) {
        $rutaAnterior = $dirDestino . $nombreImagenFinal;
        if (file_exists($rutaAnterior)) {
            unlink($rutaAnterior);
        }
        $nombreImagenFinal = null;
    }

    if ($error === '' && $imagen && $imagen['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($imagen['error'] !== UPLOAD_ERR_OK) {
            $error = 'Ocurrió un error al subir la nueva imagen.';
        } else {
            $maxSize = 4 * 1024 * 1024;
            if ($imagen['size'] > $maxSize) {
                $error = 'La imagen supera el peso máximo de 4MB.';
            }

            $extPermitidas = ['jpg', 'jpeg', 'png', 'webp'];
            $mimePermitidos = ['image/jpeg', 'image/png', 'image/webp'];
            $ext = strtolower(pathinfo($imagen['name'], PATHINFO_EXTENSION));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeReal = finfo_file($finfo, $imagen['tmp_name']);
            finfo_close($finfo);

            if (!in_array($ext, $extPermitidas) || !in_array($mimeReal, $mimePermitidos)) {
                $error = 'Formato de imagen no válido. Solo se admiten JPG, PNG y WEBP.';
            }

            if ($error === '') {
                $nombreUnico = uniqid('equipo_', true) . '.' . $ext;
                if (!file_exists($dirDestino)) {
                    mkdir($dirDestino, 0777, true);
                }

                if (move_uploaded_file($imagen['tmp_name'], $dirDestino . $nombreUnico)) {
                    // Borrar foto anterior si existía
                    if (!empty($nombreImagenFinal) && file_exists($dirDestino . $nombreImagenFinal)) {
                        unlink($dirDestino . $nombreImagenFinal);
                    }
                    $nombreImagenFinal = $nombreUnico;
                } else {
                    $error = 'No se pudo guardar la nueva imagen en el servidor.';
                }
            }
        }
    }

    if ($error === '') {
        $sqlUpd = "UPDATE equipo
                   SET codigo = ?,
                       nombre = ?,
                       descripcion = ?,
                       imagen_url = ?,
                       ubicacion = ?,
                       estado = ?,
                       visibilidad = ?,
                       id_marca = ?,
                       id_categoria = ?
                   WHERE id_equipo = ?";
        $stmtUpd = $pdo->prepare($sqlUpd);
        $stmtUpd->execute([
            $codigo,
            $nombre,
            $descripcion !== '' ? $descripcion : null,
            $nombreImagenFinal,
            $ubicacion !== '' ? $ubicacion : null,
            $estado,
            $visibilidad,
            $idMarca,
            $idCategoria,
            $id
        ]);

        header('Location: administrar_equipos.php?msg=actualizado');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Editar Equipo — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <h1 class="page-heading">Editar equipo (<?= htmlspecialchars($equipo['codigo']) ?>)</h1>
          <p class="page-subheading">Actualiza la información, disponibilidad o fotografía del equipo.</p>
        </div>
        <a href="administrar_equipos.php" class="btn-admin-outline">
          <i class="bi bi-arrow-left me-1"></i> Volver al listado
        </a>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2 px-3 small mb-4">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="panel-card" style="max-width: 780px;">
        <form method="POST" action="equipo_editar.php" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?= $equipo['id_equipo'] ?>" />

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="codigo" class="form-label small fw-semibold">Código de inventario *</label>
              <input type="text" name="codigo" id="codigo" class="form-control"
                     value="<?= htmlspecialchars($_POST['codigo'] ?? $equipo['codigo']) ?>" required />
            </div>

            <div class="col-sm-8">
              <label for="nombre" class="form-label small fw-semibold">Nombre del equipo *</label>
              <input type="text" name="nombre" id="nombre" class="form-control"
                     value="<?= htmlspecialchars($_POST['nombre'] ?? $equipo['nombre']) ?>" required />
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="id_categoria" class="form-label small fw-semibold">Categoría *</label>
              <select name="id_categoria" id="id_categoria" class="form-select" required>
                <?php $catActual = $_POST['id_categoria'] ?? $equipo['id_categoria']; ?>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= $cat['id_categoria'] ?>" <?= (int)$catActual === (int)$cat['id_categoria'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="id_marca" class="form-label small fw-semibold">Marca existente</label>
              <select name="id_marca" id="id_marca" class="form-select">
                <?php $marcaActual = $_POST['id_marca'] ?? $equipo['id_marca']; ?>
                <?php foreach ($marcas as $m): ?>
                  <option value="<?= $m['id_marca'] ?>" <?= (int)$marcaActual === (int)$m['id_marca'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="nueva_marca" class="form-label small fw-semibold">O escribir nueva marca</label>
              <input type="text" name="nueva_marca" id="nueva_marca" class="form-control"
                     placeholder="Opcional"
                     value="<?= htmlspecialchars($_POST['nueva_marca'] ?? '') ?>" />
            </div>
          </div>

          <div class="mb-3">
            <label for="descripcion" class="form-label small fw-semibold">Descripción / Accesorios incluidos</label>
            <textarea name="descripcion" id="descripcion" rows="3" class="form-control"><?= htmlspecialchars($_POST['descripcion'] ?? ($equipo['descripcion'] ?? '')) ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="ubicacion" class="form-label small fw-semibold">Ubicación física</label>
              <input type="text" name="ubicacion" id="ubicacion" class="form-control"
                     value="<?= htmlspecialchars($_POST['ubicacion'] ?? ($equipo['ubicacion'] ?? '')) ?>" />
            </div>

            <div class="col-sm-4">
              <label for="estado" class="form-label small fw-semibold">Estado</label>
              <?php $estActual = $_POST['estado'] ?? $equipo['estado']; ?>
              <select name="estado" id="estado" class="form-select">
                <option value="Disponible" <?= $estActual === 'Disponible' ? 'selected' : '' ?>>Disponible</option>
                <option value="Prestado" <?= $estActual === 'Prestado' ? 'selected' : '' ?>>Prestado</option>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="visibilidad" class="form-label small fw-semibold">Visibilidad</label>
              <?php $visActual = $_POST['visibilidad'] ?? $equipo['visibilidad']; ?>
              <select name="visibilidad" id="visibilidad" class="form-select">
                <option value="Activo" <?= $visActual === 'Activo' ? 'selected' : '' ?>>Activo</option>
                <option value="Deshabilitado" <?= $visActual === 'Deshabilitado' ? 'selected' : '' ?>>Deshabilitado</option>
              </select>
            </div>
          </div>

          <!-- Imagen actual y reemplazo -->
          <div class="mb-4">
            <label class="form-label small fw-semibold">Fotografía del equipo</label>

            <?php if (!empty($equipo['imagen_url'])): ?>
              <div class="d-flex align-items-center gap-3 p-2 mb-2 bg-light rounded border">
                <img src="../uploads/equipos/<?= htmlspecialchars($equipo['imagen_url']) ?>"
                     alt="Vista previa" width="90" height="65"
                     style="object-fit: cover; border-radius: 6px;" />
                <div>
                  <div class="small fw-medium"><?= htmlspecialchars($equipo['imagen_url']) ?></div>
                  <div class="form-check mt-1">
                    <input type="checkbox" name="eliminar_foto" id="eliminar_foto" value="1" class="form-check-input" />
                    <label for="eliminar_foto" class="form-check-label small text-danger">Eliminar fotografía actual</label>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <input type="file" name="imagen" id="imagen" class="form-control" accept="image/jpeg, image/png, image/webp" />
            <div class="form-text small">Sube una nueva imagen solo si deseas agregar o reemplazar la actual (JPG, PNG o WEBP, máx. 4MB).</div>
          </div>

          <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="administrar_equipos.php" class="btn-admin-outline">Cancelar</a>
            <button type="submit" class="btn-admin-primary">Actualizar equipo</button>
          </div>

        </form>
      </div>

    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>