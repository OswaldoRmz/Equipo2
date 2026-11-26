<?php
require_once __DIR__ . '/../config/conexion.php';

// 1. Candado de seguridad: solo Administrador (1) o Técnico (2)
if (!estaLogueado()) {
    header('Location: ../login.php?redirect=admin/equipo_crear.php');
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

// Catálogos de marcas y categorías de equipo
$marcas = $pdo->query("SELECT id_marca, nombre FROM marca ORDER BY nombre ASC")->fetchAll();
$categorias = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Equipo' ORDER BY id_categoria ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo      = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre      = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $ubicacion   = trim($_POST['ubicacion'] ?? '');
    $estado      = $_POST['estado'] ?? 'Disponible';
    $visibilidad = $_POST['visibilidad'] ?? 'Activo';
    $idMarca     = (int)($_POST['id_marca'] ?? 0);
    $nuevaMarca  = trim($_POST['nueva_marca'] ?? '');
    $idCategoria = (int)($_POST['id_categoria'] ?? 0);
    $imagen      = $_FILES['imagen'] ?? null;

    // 1. Validaciones básicas de campos
    if ($codigo === '' || $nombre === '' || $idCategoria <= 0) {
        $error = 'El código, el nombre y la categoría son obligatorios.';
    } elseif (!in_array($estado, ['Disponible', 'Prestado'])) {
        $error = 'Estado inicial no válido.';
    } elseif (!in_array($visibilidad, ['Activo', 'Deshabilitado'])) {
        $error = 'Visibilidad no válida.';
    } else {
        // Verificar que el código no esté repetido
        $stmtCod = $pdo->prepare("SELECT COUNT(*) FROM equipo WHERE codigo = ?");
        $stmtCod->execute([$codigo]);
        if ($stmtCod->fetchColumn() > 0) {
            $error = 'Ya existe un equipo registrado con el código ' . $codigo . '.';
        }
    }

    // 2. Resolver marca (existente o nueva)
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

    // 3. Procesar imagen si el usuario seleccionó un archivo
    $nombreImagen = null;
    if ($error === '' && $imagen && $imagen['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($imagen['error'] !== UPLOAD_ERR_OK) {
            $error = 'Ocurrió un error al subir la imagen.';
        } else {
            // Validar peso máximo (4MB)
            $maxSize = 4 * 1024 * 1024;
            if ($imagen['size'] > $maxSize) {
                $error = 'La imagen supera el peso máximo permitido de 4MB.';
            }

            // Validar extensión y tipo MIME real
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
                $dirDestino = __DIR__ . '/../uploads/equipos/';

                if (!file_exists($dirDestino)) {
                    mkdir($dirDestino, 0777, true);
                }

                $rutaFinal = $dirDestino . $nombreUnico;
                if (move_uploaded_file($imagen['tmp_name'], $rutaFinal)) {
                    $nombreImagen = $nombreUnico;
                } else {
                    $error = 'No se pudo guardar la imagen en el servidor.';
                }
            }
        }
    }

    // 4. Insertar registro en la base de datos
    if ($error === '') {
        $sql = "INSERT INTO equipo (codigo, nombre, descripcion, imagen_url, ubicacion, estado, visibilidad, id_marca, id_categoria)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $codigo,
            $nombre,
            $descripcion !== '' ? $descripcion : null,
            $nombreImagen,
            $ubicacion !== '' ? $ubicacion : null,
            $estado,
            $visibilidad,
            $idMarca,
            $idCategoria
        ]);

        header('Location: administrar_equipos.php?msg=creado');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Agregar Equipo — <?= htmlspecialchars($empresa['nombre_empresa'] ?? 'Capsule Tech') ?></title>

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
          <h1 class="page-heading">Agregar nuevo equipo</h1>
          <p class="page-subheading">Registra un equipo tecnológico en el inventario de préstamos.</p>
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
        <form id="formEquipo" method="POST" action="equipo_crear.php" enctype="multipart/form-data" novalidate>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="codigo" class="form-label small fw-semibold">Código de inventario *</label>
              <input type="text" name="codigo" id="codigo" class="form-control"
                     placeholder="Ej. EQ-006"
                     value="<?= htmlspecialchars($_POST['codigo'] ?? '') ?>" />
              <div id="feedback-codigo" class="invalid-feedback"></div>
            </div>

            <div class="col-sm-8">
              <label for="nombre" class="form-label small fw-semibold">Nombre del equipo *</label>
              <input type="text" name="nombre" id="nombre" class="form-control"
                     placeholder="Ej. Laptop Dell Latitude 5420"
                     value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" />
              <div id="feedback-nombre" class="invalid-feedback"></div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="id_categoria" class="form-label small fw-semibold">Categoría *</label>
              <select name="id_categoria" id="id_categoria" class="form-select">
                <option value="">Selecciona...</option>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= $cat['id_categoria'] ?>" <?= (isset($_POST['id_categoria']) && (int)$_POST['id_categoria'] === (int)$cat['id_categoria']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div id="feedback-categoria" class="invalid-feedback"></div>
            </div>

            <div class="col-sm-4">
              <label for="id_marca" class="form-label small fw-semibold">Marca existente</label>
              <select name="id_marca" id="id_marca" class="form-select">
                <option value="0">-- Seleccionar marca --</option>
                <?php foreach ($marcas as $m): ?>
                  <option value="<?= $m['id_marca'] ?>" <?= (isset($_POST['id_marca']) && (int)$_POST['id_marca'] === (int)$m['id_marca']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="nueva_marca" class="form-label small fw-semibold">O escribir nueva marca</label>
              <input type="text" name="nueva_marca" id="nueva_marca" class="form-control"
                     placeholder="Ej. Lenovo, Dell..."
                     value="<?= htmlspecialchars($_POST['nueva_marca'] ?? '') ?>" />
            </div>
          </div>

          <div class="mb-3">
            <label for="descripcion" class="form-label small fw-semibold">Descripción / Accesorios incluidos</label>
            <textarea name="descripcion" id="descripcion" rows="3" class="form-control"
                      placeholder="Ej. Procesador Core i5, 16GB RAM, incluye cargador original y maletín"><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label for="ubicacion" class="form-label small fw-semibold">Ubicación física</label>
              <input type="text" name="ubicacion" id="ubicacion" class="form-control"
                     value="<?= htmlspecialchars($_POST['ubicacion'] ?? 'Almacén de Tecnología') ?>" />
            </div>

            <div class="col-sm-4">
              <label for="estado" class="form-label small fw-semibold">Estado inicial</label>
              <select name="estado" id="estado" class="form-select">
                <option value="Disponible" <?= (($_POST['estado'] ?? 'Disponible') === 'Disponible') ? 'selected' : '' ?>>Disponible</option>
                <option value="Prestado" <?= (($_POST['estado'] ?? '') === 'Prestado') ? 'selected' : '' ?>>Prestado</option>
              </select>
            </div>

            <div class="col-sm-4">
              <label for="visibilidad" class="form-label small fw-semibold">Visibilidad en catálogo</label>
              <select name="visibilidad" id="visibilidad" class="form-select">
                <option value="Activo" <?= (($_POST['visibilidad'] ?? 'Activo') === 'Activo') ? 'selected' : '' ?>>Activo</option>
                <option value="Deshabilitado" <?= (($_POST['visibilidad'] ?? '') === 'Deshabilitado') ? 'selected' : '' ?>>Deshabilitado</option>
              </select>
            </div>
          </div>

          <div class="mb-4">
            <label for="imagen" class="form-label small fw-semibold">Fotografía del equipo (opcional, JPG/PNG/WEBP, máx. 4MB)</label>
            <input type="file" name="imagen" id="imagen" class="form-control" accept="image/jpeg, image/png, image/webp" />
          </div>

          <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="administrar_equipos.php" class="btn-admin-outline">Cancelar</a>
            <button type="submit" class="btn-admin-primary">Guardar equipo</button>
          </div>

        </form>
      </div>

    </main>
  </div>

  <script>
    document.getElementById('formEquipo').addEventListener('submit', function(e) {
      let esValido = true;

      const codigo = document.getElementById('codigo');
      const nombre = document.getElementById('nombre');
      const categoria = document.getElementById('id_categoria');

      [codigo, nombre, categoria].forEach(input => input.classList.remove('is-invalid'));

      if (codigo.value.trim() === '') {
        marcarError(codigo, 'feedback-codigo', 'Ingresa el código del equipo.');
        esValido = false;
      }

      if (nombre.value.trim() === '') {
        marcarError(nombre, 'feedback-nombre', 'Ingresa el nombre del equipo.');
        esValido = false;
      }

      if (categoria.value === '') {
        marcarError(categoria, 'feedback-categoria', 'Selecciona una categoría.');
        esValido = false;
      }

      if (!esValido) {
        e.preventDefault();
      }
    });

    function marcarError(input, idFeedback, mensaje) {
      input.classList.add('is-invalid');
      document.getElementById(idFeedback).textContent = mensaje;
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>