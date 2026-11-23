<?php
require_once 'config/conexion.php';

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

$mensajeExito = '';
$mensajeError = '';

// Procesar solicitud de préstamo SOLO si el usuario está logueado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'solicitar_prestamo') {
    if (!$usuario) {
        header('Location: login.php?redirect=inventario.php');
        exit;
    }

    $idEquipo    = (int)($_POST['id_equipo'] ?? 0);
    $fechaInicio = $_POST['fecha_inicio'] ?? '';
    $fechaLimite = $_POST['fecha_limite'] ?? '';
    $motivo      = trim($_POST['motivo'] ?? '');

    if ($idEquipo <= 0 || $fechaInicio === '' || $fechaLimite === '' || $motivo === '') {
        $mensajeError = 'Completa todos los campos para registrar la solicitud de préstamo.';
    } elseif (strtotime($fechaLimite) <= strtotime($fechaInicio)) {
        $mensajeError = 'La fecha de devolución debe ser posterior a la fecha de inicio.';
    } else {
        // Verificar que el equipo exista, esté activo y disponible
        $stmtEq = $pdo->prepare("SELECT id_equipo, estado FROM equipo WHERE id_equipo = :id AND visibilidad = 'Activo' LIMIT 1");
        $stmtEq->execute([':id' => $idEquipo]);
        $eqRow = $stmtEq->fetch();

        if (!$eqRow || $eqRow['estado'] !== 'Disponible') {
            $mensajeError = 'El equipo seleccionado ya no se encuentra disponible.';
        } else {
            // Generar folio consecutivo PRE-YYYY-XXX
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id_prestamo), 0) + 1 FROM prestamo")->fetchColumn();
            $folio = 'PRE-' . date('Y') . '-' . str_pad((string)$nextId, 3, '0', STR_PAD_LEFT);

            $stmtInsert = $pdo->prepare("
                INSERT INTO prestamo (folio, id_cliente, id_equipo, fecha_inicio, fecha_limite, motivo, estado)
                VALUES (:folio, :id_cliente, :id_equipo, :fecha_inicio, :fecha_limite, :motivo, 'Pendiente')
            ");
            $stmtInsert->execute([
                ':folio'        => $folio,
                ':id_cliente'   => $usuario['id_usuario'],
                ':id_equipo'    => $idEquipo,
                ':fecha_inicio' => str_replace('T', ' ', $fechaInicio) . ':00',
                ':fecha_limite' => str_replace('T', ' ', $fechaLimite) . ':00',
                ':motivo'       => $motivo,
            ]);

            $mensajeExito = "Solicitud registrada con el folio {$folio}. Puedes revisarla en «Mis préstamos activos».";
        }
    }
}

// Parámetros de búsqueda y filtro
$busqueda       = trim($_GET['q'] ?? '');
$catSeleccion   = (int)($_GET['categoria'] ?? 0);
$dispSeleccion  = $_GET['disponibilidad'] ?? 'todos';
$ordenSeleccion = $_GET['orden'] ?? 'mas-prestados';

// Categorías de tipo 'Equipo' desde BD
$categoriasEquipo = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Equipo' ORDER BY id_categoria ASC")->fetchAll();

// Construir consulta dinámica de equipos activos
$where = ["e.visibilidad = 'Activo'"];
$params = [];

if ($busqueda !== '') {
    $where[] = "(e.nombre LIKE :q1 OR e.descripcion LIKE :q2 OR m.nombre LIKE :q3 OR e.codigo LIKE :q4)";
    $like = '%' . $busqueda . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

if ($catSeleccion > 0) {
    $where[] = "e.id_categoria = :cat";
    $params[':cat'] = $catSeleccion;
}

if ($dispSeleccion === 'disponible') {
    $where[] = "e.estado = 'Disponible'";
} elseif ($dispSeleccion === 'agotado') {
    $where[] = "e.estado = 'Prestado'";
}

$orderBy = ($ordenSeleccion === 'az')
    ? "e.nombre ASC"
    : "total_prestamos DESC, e.id_equipo ASC";

$sqlInventario = "
    SELECT
        e.id_equipo,
        e.codigo,
        e.nombre,
        e.descripcion,
        e.ubicacion,
        e.estado,
        m.nombre AS marca,
        c.id_categoria,
        c.nombre AS categoria,
        COUNT(p.id_prestamo) AS total_prestamos
    FROM equipo e
    INNER JOIN marca m ON e.id_marca = m.id_marca
    INNER JOIN categoria c ON e.id_categoria = c.id_categoria
    LEFT JOIN prestamo p ON e.id_equipo = p.id_equipo
    WHERE " . implode(' AND ', $where) . "
    GROUP BY e.id_equipo
    ORDER BY $orderBy
";

$stmtInv = $pdo->prepare($sqlInventario);
$stmtInv->execute($params);
$equipos = $stmtInv->fetchAll();

// Si está logueado, consultar sus préstamos activos/pendientes
$prestamosActivos = [];
if ($usuario) {
    $stmtPrestamos = $pdo->prepare("
        SELECT
            p.id_prestamo,
            p.folio,
            p.fecha_inicio,
            p.fecha_limite,
            p.estado,
            e.nombre AS equipo_nombre,
            c.nombre AS categoria
        FROM prestamo p
        INNER JOIN equipo e ON p.id_equipo = e.id_equipo
        INNER JOIN categoria c ON e.id_categoria = c.id_categoria
        WHERE p.id_cliente = :id_cliente
          AND p.estado IN ('Pendiente', 'Prestado')
        ORDER BY p.fecha_limite ASC
    ");
    $stmtPrestamos->execute([':id_cliente' => $usuario['id_usuario']]);
    $prestamosActivos = $stmtPrestamos->fetchAll();
}

// Helper para construir URLs conservando filtros actuales
function urlFiltro(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    unset($query['solicitar']);
    return 'inventario.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inventario de equipos — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos personalizados del Inventario -->
  <link rel="stylesheet" href="styles/inventario.css" />
</head>
<body>

  <!-- ================= NAVBAR SUPERIOR ================= -->
  <header class="main-navbar sticky-top">
    <nav class="navbar navbar-expand-lg py-3">
      <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-3 m-0" href="index.php">
          <img
            src="images/logo.jpeg"
            alt="Logo oficial Capsule Tech"
            width="46"
            height="46"
            class="brand-logo"
          />
          <div>
            <div class="brand-title">Capsule Tech</div>
            <div class="brand-subtitle">Servicios Tecnológicos</div>
          </div>
        </a>

        <button
          class="navbar-toggler border-0 shadow-none"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#mainNav"
          aria-controls="mainNav"
          aria-expanded="false"
          aria-label="Abrir menú"
        >
          <i class="bi bi-list fs-2 text-navy"></i>
        </button>

        <div class="collapse navbar-collapse justify-content-end mt-3 mt-lg-0" id="mainNav">
          <ul class="navbar-nav align-items-lg-center gap-1 gap-lg-2">
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="index.php">Inicio</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="index.php#seccion-servicios">Servicios</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link active" aria-current="page" href="inventario.php">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="#footer-institucional">Acerca de</a>
            </li>

            <?php if ($usuario): ?>
              <li class="nav-item dropdown ms-lg-2">
                <a class="nav-link nav-pill-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="bi bi-person-circle"></i>
                  <span><?= htmlspecialchars(explode(' ', $usuario['nombres'])[0]) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm">
                  <li><span class="dropdown-item-text small text-muted"><?= htmlspecialchars($usuario['correo']) ?></span></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="#titulo-prestamos-activos"><i class="bi bi-laptop me-2"></i>Mis préstamos</a></li>
                  <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
                </ul>
              </li>
            <?php else: ?>
              <li class="nav-item ms-lg-2">
                <a class="btn btn-card-alt px-3" style="height: 40px;" href="login.php?redirect=inventario.php">Iniciar sesión</a>
              </li>
              <li class="nav-item">
                <a class="btn btn-card-loan px-3" style="height: 40px;" href="registro.php">Crear cuenta</a>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </nav>
  </header>

  <main>
    <!-- ================= ENCABEZADO, BUSCADOR Y FILTROS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-inventario">
      <div class="container">
        <?php if ($mensajeExito !== ''): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($mensajeExito) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
          </div>
        <?php endif; ?>

        <?php if ($mensajeError !== ''): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i> <?= htmlspecialchars($mensajeError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
          </div>
        <?php endif; ?>

        <h1 id="titulo-inventario" class="page-title">Inventario de equipos</h1>
        <p class="page-subtitle mb-4">Consulta la disponibilidad y solicita tu préstamo</p>

        <!-- Barra de búsqueda -->
        <form action="inventario.php" method="GET" role="search" class="mb-4">
          <?php if ($catSeleccion > 0): ?>
            <input type="hidden" name="categoria" value="<?= $catSeleccion ?>" />
          <?php endif; ?>
          <?php if ($dispSeleccion !== 'todos'): ?>
            <input type="hidden" name="disponibilidad" value="<?= htmlspecialchars($dispSeleccion) ?>" />
          <?php endif; ?>
          <input type="hidden" name="orden" value="<?= htmlspecialchars($ordenSeleccion) ?>" />

          <div class="search-input-wrapper">
            <i class="bi bi-search search-icon"></i>
            <label for="buscador-equipos" class="visually-hidden">Busca un equipo</label>
            <input
              type="search"
              id="buscador-equipos"
              name="q"
              value="<?= htmlspecialchars($busqueda) ?>"
              class="form-control search-input"
              placeholder="Busca un equipo por nombre, marca o código"
            />
          </div>
        </form>

        <!-- Filtro de Categoría desde BD -->
        <div class="mb-3">
          <span class="filter-group-label d-block mb-2">Categoría</span>
          <div class="d-flex flex-wrap gap-2" role="group" aria-label="Categoría">
            <a href="<?= htmlspecialchars(urlFiltro(['categoria' => 0])) ?>" class="filter-pill text-decoration-none <?= $catSeleccion === 0 ? 'active' : '' ?>">
              Todas
            </a>
            <?php foreach ($categoriasEquipo as $cat): ?>
              <a
                href="<?= htmlspecialchars(urlFiltro(['categoria' => (int)$cat['id_categoria']])) ?>"
                class="filter-pill text-decoration-none <?= $catSeleccion === (int)$cat['id_categoria'] ? 'active' : '' ?>"
              >
                <?= htmlspecialchars($cat['nombre']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Filtros de Disponibilidad y Ordenar por -->
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 pt-1">
          <div>
            <span class="filter-group-label d-block mb-2">Disponibilidad</span>
            <div class="d-flex flex-wrap gap-2" role="group" aria-label="Disponibilidad">
              <a href="<?= htmlspecialchars(urlFiltro(['disponibilidad' => 'todos'])) ?>" class="filter-pill text-decoration-none <?= $dispSeleccion === 'todos' ? 'active' : '' ?>">Todos</a>
              <a href="<?= htmlspecialchars(urlFiltro(['disponibilidad' => 'disponible'])) ?>" class="filter-pill text-decoration-none <?= $dispSeleccion === 'disponible' ? 'active' : '' ?>">Disponible</a>
              <a href="<?= htmlspecialchars(urlFiltro(['disponibilidad' => 'agotado'])) ?>" class="filter-pill text-decoration-none <?= $dispSeleccion === 'agotado' ? 'active' : '' ?>">Prestado</a>
            </div>
          </div>

          <div>
            <span class="filter-group-label d-block mb-2">Ordenar por</span>
            <div class="d-flex flex-wrap gap-2" role="group" aria-label="Ordenar por">
              <a href="<?= htmlspecialchars(urlFiltro(['orden' => 'mas-prestados'])) ?>" class="filter-pill text-decoration-none <?= $ordenSeleccion === 'mas-prestados' ? 'active' : '' ?>">Más prestados</a>
              <a href="<?= htmlspecialchars(urlFiltro(['orden' => 'az'])) ?>" class="filter-pill text-decoration-none <?= $ordenSeleccion === 'az' ? 'active' : '' ?>">A–Z</a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= CATÁLOGO DE EQUIPOS (DESDE BASE DE DATOS) ================= -->
    <section class="py-4 bg-white" aria-label="Catálogo de equipos">
      <div class="container">
        <div class="row g-4" id="contenedor-inventario-equipos">
          <?php if (count($equipos) > 0): ?>
            <?php foreach ($equipos as $eq): ?>
              <?php
                $disponible = ($eq['estado'] === 'Disponible');
                $icono = iconoCategoria($eq['categoria']);
                $detalle = trim($eq['marca'] . ($eq['descripcion'] ? ' · ' . $eq['descripcion'] : ' · ' . $eq['codigo']));
              ?>
              <div class="col-sm-6 col-lg-3">
                <article class="equipment-card h-100">
                  <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="card-icon-box">
                      <i class="bi <?= htmlspecialchars($icono) ?>"></i>
                    </div>
                    <span class="category-pill"><?= htmlspecialchars($eq['categoria']) ?></span>
                  </div>

                  <h3 class="equipment-title"><?= htmlspecialchars($eq['nombre']) ?></h3>
                  <p class="equipment-specs"><?= htmlspecialchars($detalle) ?></p>

                  <div class="mt-auto pt-2">
                    <?php if ($disponible): ?>
                      <div class="availability-label text-available mb-2">
                        Disponible &middot; <?= htmlspecialchars($eq['codigo']) ?>
                      </div>
                      <div class="availability-track mb-4">
                        <div class="availability-fill" style="width: 100%;"></div>
                      </div>

                      <?php if ($usuario): ?>
                        <button
                          type="button"
                          class="btn btn-card-loan w-100"
                          data-bs-toggle="modal"
                          data-bs-target="#modalSolicitarPrestamo"
                          data-equipo-id="<?= (int)$eq['id_equipo'] ?>"
                          data-equipo-nombre="<?= htmlspecialchars($eq['nombre']) ?>"
                          data-equipo-codigo="<?= htmlspecialchars($eq['codigo']) ?>"
                        >
                          Solicitar préstamo
                        </button>
                      <?php else: ?>
                        <button
                          type="button"
                          class="btn btn-card-loan w-100"
                          data-bs-toggle="modal"
                          data-bs-target="#modalRequiereSesion"
                          data-accion="solicitar el préstamo de <?= htmlspecialchars($eq['nombre']) ?>"
                        >
                          Solicitar préstamo
                        </button>
                      <?php endif; ?>

                    <?php else: ?>
                      <div class="availability-label text-unavailable mb-2">
                        Prestado &middot; <?= htmlspecialchars($eq['codigo']) ?>
                      </div>
                      <div class="availability-track mb-4">
                        <div class="availability-fill" style="width: 0%;"></div>
                      </div>
                      <a href="<?= htmlspecialchars(urlFiltro(['disponibilidad' => 'disponible'])) ?>" class="btn btn-card-alt w-100">
                        Ver disponibles
                      </a>
                    <?php endif; ?>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="alert alert-light border text-center py-4">
                No se encontraron equipos con los filtros seleccionados.
                <a href="inventario.php" class="ms-2">Limpiar filtros</a>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Resumen de conteo -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4 pt-2">
          <p class="pagination-summary mb-0">
            Mostrando <?= count($equipos) ?> equipo(s) activo(s)
          </p>
        </div>
      </div>
    </section>

    <!-- ================= REGLAS: ANTES DE PEDIR UN PRÉSTAMO ================= -->
    <section class="py-5 section-tinted" aria-labelledby="titulo-reglas-prestamo">
      <div class="container">
        <h2 id="titulo-reglas-prestamo" class="section-title mb-4">Antes de pedir un préstamo</h2>

        <div class="row g-4">
          <div class="col-md-4">
            <article class="d-flex align-items-start gap-3">
              <div class="rule-icon-box">
                <i class="bi bi-clock"></i>
              </div>
              <div>
                <h3 class="rule-title">Duración</h3>
                <p class="rule-text mb-0">El préstamo dura 3 días como máximo.</p>
              </div>
            </article>
          </div>

          <div class="col-md-4">
            <article class="d-flex align-items-start gap-3">
              <div class="rule-icon-box">
                <i class="bi bi-person-vcard"></i>
              </div>
              <div>
                <h3 class="rule-title">Matrícula</h3>
                <p class="rule-text mb-0">Presenta tu matrícula.</p>
              </div>
            </article>
          </div>

          <div class="col-md-4">
            <article class="d-flex align-items-start gap-3">
              <div class="rule-icon-box">
                <i class="bi bi-clipboard-check"></i>
              </div>
              <div>
                <h3 class="rule-title">Revisión</h3>
                <p class="rule-text mb-0">Revisa el equipo al recibirlo.</p>
              </div>
            </article>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= MIS PRÉSTAMOS ACTIVOS ================= -->
    <section class="py-5 bg-white" aria-labelledby="titulo-prestamos-activos">
      <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
          <h2 id="titulo-prestamos-activos" class="section-title mb-0">Mis préstamos activos</h2>
          <span class="cutoff-date-badge">
            Al <?= date('d M Y') ?>
          </span>
        </div>

        <?php if (!$usuario): ?>
          <div class="db-loan-placeholder flex-column gap-2 py-4">
            <p class="mb-2 text-dark fw-semibold">Inicia sesión para consultar tus préstamos activos y solicitudes pendientes.</p>
            <div class="d-flex gap-2">
              <a href="login.php?redirect=inventario.php" class="btn btn-card-loan px-4" style="height: 40px;">Iniciar sesión</a>
              <a href="registro.php" class="btn btn-card-alt px-4" style="height: 40px;">Crear cuenta</a>
            </div>
          </div>
        <?php elseif (count($prestamosActivos) > 0): ?>
          <div class="row g-4">
            <?php foreach ($prestamosActivos as $pr): ?>
              <?php
                $iconoPr = iconoCategoria($pr['categoria']);
                $fechaLimiteTs = strtotime($pr['fecha_limite']);
                $diasRestantes = (int)ceil(($fechaLimiteTs - time()) / 86400);
                $esUrgente = ($diasRestantes <= 1);
              ?>
              <div class="col-lg-6">
                <article class="active-loan-card h-100">
                  <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="card-icon-box">
                      <i class="bi <?= htmlspecialchars($iconoPr) ?>"></i>
                    </div>
                    <div>
                      <h3 class="loan-equipment-title"><?= htmlspecialchars($pr['equipo_nombre']) ?></h3>
                      <p class="loan-folio mb-0">
                        Préstamo <?= htmlspecialchars($pr['folio']) ?> &middot; Estado: <strong><?= htmlspecialchars($pr['estado']) ?></strong>
                      </p>
                    </div>
                  </div>

                  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-auto">
                    <div>
                      <span class="loan-date-label d-block">Fecha límite de devolución</span>
                      <strong class="loan-date-value"><?= date('d M Y, H:i', $fechaLimiteTs) ?></strong>
                    </div>
                    <span class="days-pill <?= $esUrgente ? 'days-pill-warning' : 'days-pill-normal' ?>">
                      <?php if ($diasRestantes < 0): ?>
                        Vencido
                      <?php elseif ($diasRestantes === 0): ?>
                        Vence hoy
                      <?php elseif ($diasRestantes === 1): ?>
                        1 día restante
                      <?php else: ?>
                        <?= $diasRestantes ?> días restantes
                      <?php endif; ?>
                    </span>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="db-loan-placeholder py-4">
            <span>Aún no tienes préstamos activos o pendientes registrados en tu cuenta.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <!-- ================= FOOTER INSTITUCIONAL (MISIÓN Y VISIÓN DESDE BD) ================= -->
  <footer id="footer-institucional" class="main-footer pt-5 pb-4">
    <div class="container">
      <div class="row g-4 pb-4">
        <div class="col-md-6 col-lg-3">
          <div class="d-flex align-items-center gap-2 mb-3">
            <img
              src="images/logo.jpeg"
              alt="Logo oficial Capsule Tech"
              width="38"
              height="38"
              class="brand-logo brand-logo-sm"
            />
            <span class="footer-brand-name">Capsule Tech</span>
          </div>

          <p class="footer-lead mb-2">
            Todo lo que necesitas<br />
            en un solo lugar
          </p>
          <p class="footer-text mb-0">
            Tecnología que conecta a nuestra comunidad.
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra misión</h3>
          <p class="footer-text mb-0">
            <?= htmlspecialchars($empresa['mision'] ?? '') ?>
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra visión</h3>
          <p class="footer-text mb-0">
            <?= htmlspecialchars($empresa['vision'] ?? '') ?>
          </p>
        </div>

        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Contáctanos</h3>
          <p class="footer-text mb-3">
            ¿Tienes una duda?<br />
            Nuestro equipo te acompaña.
          </p>
          <div class="d-flex flex-column gap-2">
            <a href="mailto:<?= htmlspecialchars($empresa['correo_contacto'] ?? 'contacto@techsolutions.com') ?>" class="footer-action-link">
              Enviar una consulta <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="#titulo-reglas-prestamo" class="footer-action-link">
              Centro de ayuda <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>

      <div class="footer-bottom pt-3 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
        <span>&copy; 2026 Capsule Tech. Todos los derechos reservados.</span>
        <div class="d-flex align-items-center gap-2">
          <a href="#">Privacidad</a>
          <span>&middot;</span>
          <a href="#">Términos de uso</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- ================= MODAL 1: REQUIERE INICIAR SESIÓN (VISITANTE) ================= -->
  <div class="modal fade" id="modalRequiereSesion" tabindex="-1" aria-labelledby="modalRequiereSesionLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow" style="border-radius: 16px;">
        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
          <div class="d-flex align-items-center gap-3">
            <div class="card-icon-box">
              <i class="bi bi-shield-lock"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold" id="modalRequiereSesionLabel" style="color: #153352;">
                Inicia sesión para solicitar
              </h5>
              <span class="small text-muted">Se requiere una cuenta activa</span>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-3">
          <p class="mb-0" style="color: #3d5d80; font-size: 0.94rem;" id="mensajeModalSesion">
            Para solicitar préstamos de equipos debes iniciar sesión. Si aún no tienes cuenta, créala en un momento.
          </p>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <a href="registro.php" class="btn btn-card-alt flex-fill">
            Crear cuenta
          </a>
          <a href="login.php?redirect=inventario.php" class="btn btn-card-loan flex-fill">
            Iniciar sesión
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= MODAL 2: SOLICITAR PRÉSTAMO (USUARIO LOGUEADO) ================= -->
  <?php if ($usuario): ?>
  <div class="modal fade" id="modalSolicitarPrestamo" tabindex="-1" aria-labelledby="modalSolicitarPrestamoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form action="inventario.php" method="POST" class="modal-content border-0 shadow" style="border-radius: 16px;">
        <input type="hidden" name="accion" value="solicitar_prestamo" />
        <input type="hidden" name="id_equipo" id="inputEquipoId" value="" />

        <div class="modal-header border-bottom-0 pt-4 px-4">
          <div>
            <h5 class="modal-title fw-bold" id="modalSolicitarPrestamoLabel" style="color: #153352;">
              Solicitar préstamo de equipo
            </h5>
            <span class="small text-muted" id="subtituloEquipoModal">Equipo seleccionado</span>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-2">
          <div class="mb-3">
            <label for="fecha_inicio" class="form-label small fw-semibold">Fecha y hora de inicio</label>
            <input
              type="datetime-local"
              class="form-control"
              id="fecha_inicio"
              name="fecha_inicio"
              value="<?= date('Y-m-d\TH:i') ?>"
              required
            />
          </div>

          <div class="mb-3">
            <label for="fecha_limite" class="form-label small fw-semibold">Fecha y hora de devolución (máx. 3 días)</label>
            <input
              type="datetime-local"
              class="form-control"
              id="fecha_limite"
              name="fecha_limite"
              value="<?= date('Y-m-d\TH:i', strtotime('+2 days')) ?>"
              required
            />
          </div>

          <div class="mb-2">
            <label for="motivo" class="form-label small fw-semibold">Motivo del préstamo</label>
            <textarea
              class="form-control"
              id="motivo"
              name="motivo"
              rows="2"
              placeholder="Ej. Proyecto integrador o exposición en clase"
              required
            ></textarea>
          </div>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2">
          <button type="button" class="btn btn-card-alt px-3" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-card-loan px-4">Confirmar solicitud</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Modal para visitantes
    const modalSesion = document.getElementById('modalRequiereSesion');
    if (modalSesion) {
      modalSesion.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        const accion = trigger ? trigger.getAttribute('data-accion') : null;
        const texto = document.getElementById('mensajeModalSesion');
        if (accion && texto) {
          texto.textContent = `Para ${accion} debes iniciar sesión con tu cuenta. Si aún no tienes cuenta, créala en segundos.`;
        }
      });
    }

    // Modal para usuarios logueados
    const modalPrestamo = document.getElementById('modalSolicitarPrestamo');
    if (modalPrestamo) {
      modalPrestamo.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        const id = trigger.getAttribute('data-equipo-id');
        const nombre = trigger.getAttribute('data-equipo-nombre');
        const codigo = trigger.getAttribute('data-equipo-codigo');

        document.getElementById('inputEquipoId').value = id;
        document.getElementById('subtituloEquipoModal').textContent = `${nombre} (${codigo})`;
      });
    }
  </script>
</body>
</html>