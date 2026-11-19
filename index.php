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
        <!-- Logo y Marca -->
        <a class="navbar-brand d-flex align-items-center gap-3 m-0" href="index.html">
          <svg width="46" height="46" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Capsule Tech">
            <rect width="64" height="64" rx="14" fill="#090C10" />
            <circle cx="32" cy="31" r="24" stroke="#FFFFFF" stroke-width="2" />
            <path d="M43.5 23.5C41.2 19.6 36.9 17 32 17C24.3 17 18 23.3 18 31C18 38.7 24.3 45 32 45C36.9 45 41.2 42.4 43.5 38.5L37.5 35C36.3 36.8 34.3 38 32 38C28.1 38 25 34.9 25 31C25 27.1 28.1 24 32 24C34.3 24 36.3 25.2 37.5 27L43.5 23.5Z" fill="#FFFFFF" />
            <circle cx="32" cy="31" r="4.5" stroke="#FFFFFF" stroke-width="2.2" />
            <rect x="11" y="47" width="42" height="8" rx="4" fill="#090C10" stroke="#FFFFFF" stroke-width="1.5" />
            <text x="32" y="52.6" fill="#FFFFFF" font-family="Inter, sans-serif" font-size="4.6" font-weight="700" text-anchor="middle" letter-spacing="0.4">CAPSULE CORP</text>
          </svg>
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
              <a class="nav-link nav-pill-link active" aria-current="page" href="#">Inicio</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="#seccion-servicios">Servicios</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="#seccion-prestamos">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="#">Perfil</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="#footer-institucional">Acerca de</a>
            </li>
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
                <a href="#seccion-prestamos" class="btn btn-capsule-outline">
                  Consultar inventario
                </a>
              </div>
            </div>

            <!-- Columna derecha: Buscador rápido -->
            <div class="col-lg-5 offset-lg-1">
              <div class="search-widget-card">
                <h2 class="search-widget-title">¿Qué necesitas hoy?</h2>

                <form action="#" method="GET" role="search">
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
                  <button type="button" class="quick-filter-chip" data-filter="prestamos">Préstamos</button>
                  <button type="button" class="quick-filter-chip" data-filter="soporte">Soporte</button>
                  <button type="button" class="quick-filter-chip" data-filter="licencias">Licencias</button>
                </div>

                <p class="search-widget-note">Una búsqueda. Muchas posibilidades.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= SECCIÓN: PRÉSTAMOS DE EQUIPOS (BD) ================= -->
    <section id="seccion-prestamos" class="py-5 bg-white">
      <div class="container">
        <!-- Encabezado de sección -->
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
          <div>
            <h2 class="section-title">Préstamos de equipos</h2>
            <p class="section-subtitle mb-0">Los más prestados, listos para tu próximo proyecto.</p>
          </div>
          <a href="#" class="section-action-link">
            Ver inventario completo <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>

        <!-- CONTENEDOR PREPARADO PARA INYECTAR DESDE BASE DE DATOS -->
        <div class="row g-4" id="contenedor-prestamos-equipos" data-source="db-prestamos">
          <!-- Espacio reservado 1 -->
          <div class="col-md-6 col-lg-4">
            <div class="db-card-placeholder">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Equipo #01 (BD) -->
            </div>
          </div>
          <!-- Espacio reservado 2 -->
          <div class="col-md-6 col-lg-4">
            <div class="db-card-placeholder">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Equipo #02 (BD) -->
            </div>
          </div>
          <!-- Espacio reservado 3 -->
          <div class="col-md-6 col-lg-4">
            <div class="db-card-placeholder">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Equipo #03 (BD) -->
            </div>
          </div>
        </div>

        <p class="section-footnote mt-3 mb-0">Disponibilidad de ejemplo para el diseño.</p>
      </div>
    </section>

    <!-- ================= SECCIÓN: SERVICIOS POPULARES (BD) ================= -->
    <section id="seccion-servicios" class="py-5 section-tinted">
      <div class="container">
        <!-- Encabezado de sección -->
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
          <div>
            <h2 class="section-title">Servicios populares</h2>
            <p class="section-subtitle mb-0">Soluciones para que nada detenga tus ideas.</p>
          </div>
          <a href="#" class="section-action-link">
            Ver todos los servicios <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>

        <!-- CONTENEDOR PREPARADO PARA INYECTAR DESDE BASE DE DATOS -->
        <div class="row g-4" id="contenedor-servicios-populares" data-source="db-servicios">
          <!-- Espacio reservado 1 -->
          <div class="col-sm-6 col-lg-3">
            <div class="db-card-placeholder db-card-placeholder-sm">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Servicio #01 (BD) -->
            </div>
          </div>
          <!-- Espacio reservado 2 -->
          <div class="col-sm-6 col-lg-3">
            <div class="db-card-placeholder db-card-placeholder-sm">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->        
              <!-- Espacio preparado: Servicio #02 (BD) -->
            </div>
          </div>
          <!-- Espacio reservado 3 -->
          <div class="col-sm-6 col-lg-3">
            <div class="db-card-placeholder db-card-placeholder-sm">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Servicio #03 (BD) -->
            </div>
          </div>
          <!-- Espacio reservado 4 -->
          <div class="col-sm-6 col-lg-3">
            <div class="db-card-placeholder db-card-placeholder-sm">
              <!-- <i class="bi bi-database-dash fs-4 mb-2"></i> -->
              <!-- Espacio preparado: Servicio #04 (BD) -->
            </div>
          </div>
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
            <a href="#" class="btn btn-capsule-primary px-4">
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
            <svg width="38" height="38" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Capsule Tech">
              <rect width="64" height="64" rx="14" fill="#090C10" />
              <circle cx="32" cy="31" r="24" stroke="#FFFFFF" stroke-width="2" />
              <path d="M43.5 23.5C41.2 19.6 36.9 17 32 17C24.3 17 18 23.3 18 31C18 38.7 24.3 45 32 45C36.9 45 41.2 42.4 43.5 38.5L37.5 35C36.3 36.8 34.3 38 32 38C28.1 38 25 34.9 25 31C25 27.1 28.1 24 32 24C34.3 24 36.3 25.2 37.5 27L43.5 23.5Z" fill="#FFFFFF" />
              <circle cx="32" cy="31" r="4.5" stroke="#FFFFFF" stroke-width="2.2" />
              <rect x="11" y="47" width="42" height="8" rx="4" fill="#090C10" stroke="#FFFFFF" stroke-width="1.5" />
              <text x="32" y="52.6" fill="#FFFFFF" font-family="Inter, sans-serif" font-size="4.6" font-weight="700" text-anchor="middle" letter-spacing="0.4">CAPSULE CORP</text>
            </svg>
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

        <!-- Columna 2: Nuestra misión (Preparado para BD) -->
        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra misión</h3>
          <div id="contenedor-mision" class="footer-db-placeholder" data-source="db-mision">
            <!-- Espacio preparado para Misión (BD) -->
          </div>
        </div>

        <!-- Columna 3: Nuestra visión (Preparado para BD) -->
        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Nuestra visión</h3>
          <div id="contenedor-vision" class="footer-db-placeholder" data-source="db-vision">
            <!-- Espacio preparado para Visión (BD) -->
          </div>
        </div>

        <!-- Columna 4: Contáctanos -->
        <div class="col-md-6 col-lg-3">
          <h3 class="footer-heading">Contáctanos</h3>
          <p class="footer-text mb-3">
            ¿Tienes una duda?<br />
            Nuestro equipo te acompaña.
          </p>
          <div class="d-flex flex-column gap-2">
            <a href="#" class="footer-action-link">
              Enviar una consulta <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="#" class="footer-action-link">
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

  <!-- =========================================================================
       PLANTILLAS HTML LISTAS PARA INYECTAR CON PHP / JAVASCRIPT DESDE LA BD
       ========================================================================= -->

  <!-- Plantilla 1: Tarjeta de Préstamo de Equipo -->
  <template id="tpl-prestamo-equipo">
    <div class="col-md-6 col-lg-4">
      <article class="equipment-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="card-icon-box">
            <i class="bi bi-laptop"></i>
          </div>
          <span class="rank-pill">01 &middot; Más prestado</span>
        </div>

        <h3 class="equipment-title">Nombre del equipo</h3>
        <p class="equipment-specs">Especificación 1 &middot; Especificación 2</p>

        <div class="mt-auto pt-2">
          <!-- Usar .text-available (verde) o .text-unavailable (naranja/café) -->
          <div class="availability-label text-available mb-2">8 de 12 disponibles</div>
          <div class="availability-track mb-4">
            <div class="availability-fill" style="width: 66%;"></div>
          </div>
          <!-- Usar .btn-card-loan si hay stock o .btn-card-alt si está agotado -->
          <a href="#" class="btn btn-card-loan w-100">Solicitar préstamo</a>
        </div>
      </article>
    </div>
  </template>

  <!-- Plantilla 2: Tarjeta de Servicio Popular -->
  <template id="tpl-servicio-popular">
    <div class="col-sm-6 col-lg-3">
      <article class="service-card h-100">
        <div class="card-icon-box mb-4">
          <i class="bi bi-wrench"></i>
        </div>

        <h3 class="service-title">Nombre del servicio</h3>
        <p class="service-description">Descripción breve del servicio tecnológico.</p>

        <a href="#" class="service-link mt-auto">
          Solicitar servicio <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </article>
    </div>
  </template>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>