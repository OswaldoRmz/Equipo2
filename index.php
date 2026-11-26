<?php
require_once 'config/conexion.php';

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

if (!defined('ROL_ADMINISTRADOR')) {
    define('ROL_ADMINISTRADOR', 1);
}
// Rol real de la cuenta: el administrador conserva sus permisos y, además del menú normal,
// ve el acceso a su panel (admin/dashboard.php) en el menú de su nombre.
$esAdministrador = $usuario !== null && (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;

// 1. Cargar los 3 equipos activos más prestados desde la base de datos
$sqlEquipos = "
    SELECT
        e.id_equipo,
        e.codigo,
        e.nombre,
        e.descripcion,
        e.ubicacion,
        e.estado,
        m.nombre AS marca,
        c.nombre AS categoria,
        COUNT(p.id_prestamo) AS total_prestamos
    FROM equipo e
    INNER JOIN marca m ON e.id_marca = m.id_marca
    INNER JOIN categoria c ON e.id_categoria = c.id_categoria
    LEFT JOIN prestamo p ON e.id_equipo = p.id_equipo
    WHERE e.visibilidad = 'Activo'
    GROUP BY e.id_equipo
    ORDER BY total_prestamos DESC, e.id_equipo ASC
    LIMIT 3
";
$equiposDestacados = $pdo->query($sqlEquipos)->fetchAll();

// 2. Cargar los servicios populares activos desde la base de datos
$sqlServicios = "
    SELECT
        s.id_servicio,
        s.nombre,
        s.descripcion,
        s.precio_estimado,
        c.nombre AS categoria
    FROM servicio s
    INNER JOIN categoria c ON s.id_categoria = c.id_categoria
    WHERE s.estado = 'Activo'
    ORDER BY s.id_servicio ASC
    LIMIT 4
";
$serviciosPopulares = $pdo->query($sqlServicios)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inicio — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos personalizados del Index -->
  <link rel="stylesheet" href="styles/styleIndex.css" />
</head>
<body>

  <!-- ================= NAVBAR SUPERIOR ================= -->
  <header class="main-navbar sticky-top">
    <nav class="navbar navbar-expand-lg py-3">
      <div class="container">
        <!-- Logo oficial y Marca -->
        <a class="navbar-brand d-flex align-items-center gap-3 m-0" href="index.php">
          <img
            src="images/logo.jpeg"
            alt="Logo oficial Capsule Tech"
            width="46"
            height="46"
            style="border-radius: 12px; object-fit: cover;"
          />
          <div>
            <div class="brand-title">Capsule Tech</div>
            <div class="brand-subtitle">Servicios Tecnológicos</div>
          </div>
        </a>

        <!-- Botón móvil -->
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Abrir menú">
          <i class="bi bi-list fs-2 text-navy"></i>
        </button>

        <!-- Enlaces de navegación -->
        <div class="collapse navbar-collapse justify-content-end mt-3 mt-lg-0" id="mainNav">
          <ul class="navbar-nav align-items-lg-center gap-1 gap-lg-2">
            <li class="nav-item">
              <a class="nav-link nav-pill-link active" aria-current="page" href="index.php">Inicio</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="servicios.php">Servicios</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="inventario.php">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="acerca_de.php">Acerca de</a>
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
                  <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person me-2"></i>Perfil</a></li>
                  <li><a class="dropdown-item" href="mis_solicitudes.php"><i class="bi bi-clipboard-check me-2"></i>Mis solicitudes</a></li>
                  <?php if ($esAdministrador): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="admin/dashboard.php"><i class="bi bi-grid me-2"></i>Panel de administración</a></li>
                  <?php endif; ?>
                  <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
                </ul>
              </li>
            <?php else: ?>
              <li class="nav-item ms-lg-2">
                <a class="btn btn-capsule-outline py-2 px-3" href="login.php">Iniciar sesión</a>
              </li>
              <li class="nav-item">
                <a class="btn btn-capsule-primary py-2 px-3" href="crear_cuenta.php">Crear cuenta</a>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </nav>
  </header>

  <main>
    <!-- ================= HERO BANNER ================= -->
    <section class="pt-4 pb-5">
      <div class="container">
        <div class="hero-card">
          <div class="row align-items-center g-4 g-lg-5">
            <!-- Columna izquierda -->
            <div class="col-lg-6">
              <div class="eyebrow-tag">Tu espacio tecnológico</div>
              <h1 class="hero-title">
                Todo lo que necesitas<br class="d-none d-md-inline" />
                en un solo lugar
              </h1>
              <p class="hero-description">
                Encuentra equipos, solicita servicios y sigue tus soluciones desde un mismo espacio.
              </p>

              <div class="d-flex flex-wrap gap-3 mt-4">
                <a href="#seccion-servicios" class="btn btn-capsule-primary">
                  Explorar servicios
                </a>
                <a href="inventario.php" class="btn btn-capsule-outline">
                  Consultar inventario
                </a>
              </div>
            </div>

            <!-- Columna derecha: Buscador rápido hacia Inventario -->
            <div class="col-lg-5 offset-lg-1">
              <div class="search-widget-card">
                <h2 class="search-widget-title">¿Qué necesitas hoy?</h2>

                <form action="inventario.php" method="GET" role="search">
                  <div class="search-input-wrapper">
                    <i class="bi bi-search search-icon"></i>
                    <input
                      type="search"
                      id="buscador-principal"
                      name="q"
                      class="form-control search-input"
                      placeholder="Busca servicios o equipos"
                      aria-label="Busca servicios o equipos"
                    />
                  </div>
                </form>

                <div class="d-flex flex-wrap gap-2 mt-3">
                  <a href="inventario.php" class="quick-filter-chip text-decoration-none">Préstamos</a>
                  <a href="#seccion-servicios" class="quick-filter-chip text-decoration-none">Soporte</a>
                  <a href="#seccion-servicios" class="quick-filter-chip text-decoration-none">Licencias</a>
                </div>

                <p class="search-widget-note">Una búsqueda. Muchas posibilidades.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= SECCIÓN: PRÉSTAMOS DE EQUIPOS (CARGADO DESDE BD) ================= -->
    <section id="seccion-prestamos" class="py-5 bg-white">
      <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
          <div>
            <h2 class="section-title">Préstamos de equipos</h2>
            <p class="section-subtitle mb-0">Los más prestados, listos para tu próximo proyecto.</p>
          </div>
          <a href="inventario.php" class="section-action-link">
            Ver inventario completo <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>

        <div class="row g-4" id="contenedor-prestamos-equipos">
          <?php if (count($equiposDestacados) > 0): ?>
            <?php foreach ($equiposDestacados as $index => $eq): ?>
              <?php
                $ranking = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
                $disponible = ($eq['estado'] === 'Disponible');
                $icono = iconoCategoria($eq['categoria']);
                $detalle = trim($eq['marca'] . ($eq['descripcion'] ? ' · ' . $eq['descripcion'] : ' · ' . $eq['codigo']));
              ?>
              <div class="col-md-6 col-lg-4">
                <article class="equipment-card h-100">
                  <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="card-icon-box">
                      <i class="bi <?= htmlspecialchars($icono) ?>"></i>
                    </div>
                    <span class="rank-pill"><?= $ranking ?> &middot; Más prestado</span>
                  </div>

                  <h3 class="equipment-title"><?= htmlspecialchars($eq['nombre']) ?></h3>
                  <p class="equipment-specs"><?= htmlspecialchars($detalle) ?></p>

                  <div class="mt-auto pt-2">
                    <?php if ($disponible): ?>
                      <div class="availability-label text-available mb-2">
                        Disponible &middot; <?= htmlspecialchars($eq['ubicacion'] ?: 'Almacén') ?>
                      </div>
                      <div class="availability-track mb-4">
                        <div class="availability-fill" style="width: 100%;"></div>
                      </div>

                      <?php if ($usuario): ?>
                        <a href="inventario.php?solicitar=<?= (int)$eq['id_equipo'] ?>" class="btn btn-card-loan w-100">
                          Solicitar préstamo
                        </a>
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
                        Prestado actualmente
                      </div>
                      <div class="availability-track mb-4">
                        <div class="availability-fill" style="width: 0%;"></div>
                      </div>
                      <a href="inventario.php" class="btn btn-card-alt w-100">
                        Ver alternativas
                      </a>
                    <?php endif; ?>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="alert alert-light border text-center py-4">
                No hay equipos activos registrados en este momento.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <p class="section-footnote mt-3 mb-0">
          Consulta el catálogo completo en <a href="inventario.php" class="text-decoration-none">Inventario</a> para ver todos los equipos.
        </p>
      </div>
    </section>

    <!-- ================= SECCIÓN: SERVICIOS POPULARES (CARGADO DESDE BD) ================= -->
    <section id="seccion-servicios" class="py-5 section-tinted">
      <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
          <div>
            <h2 class="section-title">Servicios populares</h2>
            <p class="section-subtitle mb-0">Soluciones para que nada detenga tus ideas.</p>
          </div>
          <a href="servicios.php" class="section-action-link">
            Ver todos los servicios <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>

        <div class="row g-4" id="contenedor-servicios-populares">
          <?php if (count($serviciosPopulares) > 0): ?>
            <?php foreach ($serviciosPopulares as $srv): ?>
              <?php $iconoSrv = iconoCategoria($srv['categoria']); ?>
              <div class="col-sm-6 col-lg-3">
                <article class="service-card h-100">
                  <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="card-icon-box">
                      <i class="bi <?= htmlspecialchars($iconoSrv) ?>"></i>
                    </div>
                    <span class="rank-pill">$<?= number_format((float)$srv['precio_estimado'], 2) ?></span>
                  </div>

                  <h3 class="service-title"><?= htmlspecialchars($srv['nombre']) ?></h3>
                  <p class="service-description">
                    <?= htmlspecialchars($srv['descripcion'] ?: 'Servicio tecnológico especializado.') ?>
                  </p>

                  <?php if ($usuario): ?>
                    <a href="servicios.php?servicio=<?= (int)$srv['id_servicio'] ?>" class="service-link mt-auto">
                      Solicitar servicio <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                  <?php else: ?>
                    <a
                      href="#"
                      class="service-link mt-auto"
                      data-bs-toggle="modal"
                      data-bs-target="#modalRequiereSesion"
                      data-accion="solicitar el servicio «<?= htmlspecialchars($srv['nombre']) ?>»"
                    >
                      Solicitar servicio <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                  <?php endif; ?>
                </article>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="alert alert-light border text-center py-4">
                No hay servicios disponibles por el momento.
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- ================= BANNER CTA SOPORTE ================= -->
    <section class="py-5 bg-white">
      <div class="container">
        <div class="cta-banner d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
          <div>
            <h2 class="cta-title">¿No encuentras lo que necesitas?</h2>
            <p class="cta-subtitle mb-0">Cuéntanos qué sucede. Te ayudamos a dar el siguiente paso.</p>
          </div>
          <div class="flex-shrink-0">
            <a href="mailto:<?= htmlspecialchars($empresa['correo_contacto'] ?? 'contacto@techsolutions.com') ?>" class="btn btn-capsule-primary px-4">
              Contactar soporte
            </a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- ================= FOOTER (MISIÓN Y VISIÓN DESDE BD) ================= -->
  <footer id="footer-institucional" class="main-footer pt-5 pb-4">
    <div class="container">
      <div class="row g-4 pb-4">
        <!-- Columna 1: Identidad de marca -->
        <div class="col-md-6 col-lg-3">
          <div class="d-flex align-items-center gap-2 mb-3">
            <img
              src="images/logo.jpeg"
              alt="Logo oficial Capsule Tech"
              width="38"
              height="38"
              style="border-radius: 10px; object-fit: cover;"
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

        <!-- Columna 2: Nuestra misión (BD: datos_empresa.mision) -->
        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra misión</h3>
          <p id="contenedor-mision" class="footer-text mb-0">
            <?= htmlspecialchars($empresa['mision'] ?? '') ?>
          </p>
        </div>

        <!-- Columna 3: Nuestra visión (BD: datos_empresa.vision) -->
        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra visión</h3>
          <p id="contenedor-vision" class="footer-text mb-0">
            <?= htmlspecialchars($empresa['vision'] ?? '') ?>
          </p>
        </div>

        <!-- Columna 4: Contáctanos -->
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
            <a href="inventario.php#titulo-reglas-prestamo" class="footer-action-link">
              Centro de ayuda <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>

      <!-- Barra legal inferior -->
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

  <!-- ================= MODAL: INICIO DE SESIÓN REQUERIDO ================= -->
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
                Inicia sesión para continuar
              </h5>
              <span class="small text-muted">Acceso exclusivo para usuarios registrados</span>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-3">
          <p class="mb-0" style="color: #3d5d80; font-size: 0.94rem;" id="mensajeModalSesion">
            Para solicitar préstamos de equipos o servicios tecnológicos necesitas iniciar sesión con tu cuenta. Si aún no tienes una, puedes crearla en segundos.
          </p>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <a href="registro.php" class="btn btn-capsule-outline flex-fill text-center">
            Crear una cuenta
          </a>
          <a href="login.php?redirect=index.php" class="btn btn-capsule-primary flex-fill text-center">
            Iniciar sesión
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    const modalSesion = document.getElementById('modalRequiereSesion');
    if (modalSesion) {
      modalSesion.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        const accion = trigger ? trigger.getAttribute('data-accion') : null;
        const texto = document.getElementById('mensajeModalSesion');
        if (accion && texto) {
          texto.textContent = `Para ${accion} necesitas iniciar sesión con tu cuenta. Si aún no tienes una, puedes registrarte ahora mismo.`;
        }
      });
    }
  </script>
</body>
</html>