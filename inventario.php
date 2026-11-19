<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Inventario de equipos — Capsule Tech</title>
</head>
<body>

  <!-- ================= ENCABEZADO / NAVEGACIÓN ================= -->
  <header>
    <div>
      <a href="index.html">
        <svg width="46" height="46" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Capsule Tech">
          <rect width="64" height="64" rx="14" fill="#090C10" />
          <circle cx="32" cy="31" r="24" stroke="#FFFFFF" stroke-width="2" />
          <path d="M43.5 23.5C41.2 19.6 36.9 17 32 17C24.3 17 18 23.3 18 31C18 38.7 24.3 45 32 45C36.9 45 41.2 42.4 43.5 38.5L37.5 35C36.3 36.8 34.3 38 32 38C28.1 38 25 34.9 25 31C25 27.1 28.1 24 32 24C34.3 24 36.3 25.2 37.5 27L43.5 23.5Z" fill="#FFFFFF" />
          <circle cx="32" cy="31" r="4.5" stroke="#FFFFFF" stroke-width="2.2" />
          <rect x="11" y="47" width="42" height="8" rx="4" fill="#090C10" stroke="#FFFFFF" stroke-width="1.5" />
          <text x="32" y="52.6" fill="#FFFFFF" font-family="sans-serif" font-size="4.6" font-weight="700" text-anchor="middle" letter-spacing="0.4">CAPSULE CORP</text>
        </svg>
        <div>
          <strong>Capsule Tech</strong>
          <span>Servicios Tecnológicos</span>
        </div>
      </a>
    </div>

    <nav aria-label="Navegación principal">
      <ul>
        <li><a href="index.html">Inicio</a></li>
        <li><a href="servicios.html">Servicios</a></li>
        <li><a href="inventario.html" aria-current="page">Inventario</a></li>
        <li><a href="perfil.html">Perfil</a></li>
        <li><a href="#footer-institucional">Acerca de</a></li>
      </ul>
    </nav>
  </header>

  <main>
    <!-- ================= TÍTULO Y CONTROLES DE BÚSQUEDA / FILTROS ================= -->
    <section aria-labelledby="titulo-inventario">
      <h1 id="titulo-inventario">Inventario de equipos</h1>
      <p>Consulta la disponibilidad y solicita tu préstamo</p>

      <!-- Barra de búsqueda -->
      <form action="#" method="GET" role="search">
        <label for="buscador-equipos" hidden>Busca un equipo</label>
        <input
          type="search"
          id="buscador-equipos"
          name="q"
          placeholder="Busca un equipo"
        />
      </form>

      <!-- Filtros de Categoría (preparado si se llena desde BD) -->
      <div id="filtro-categorias" data-source="db-categorias" role="group" aria-label="Categoría">
        <span>Categoría</span>
        <div>
          <button type="button" data-categoria="todas">Todas</button>
          <button type="button" data-categoria="computo">Cómputo</button>
          <button type="button" data-categoria="audio-video">Audio y video</button>
          <button type="button" data-categoria="diseno">Diseño</button>
          <button type="button" data-categoria="electronica">Electrónica</button>
          <button type="button" data-categoria="redes">Redes</button>
        </div>
      </div>

      <!-- Filtros de Disponibilidad y Orden -->
      <div>
        <div id="filtro-disponibilidad" role="group" aria-label="Disponibilidad">
          <span>Disponibilidad</span>
          <div>
            <button type="button" data-disponibilidad="todos">Todos</button>
            <button type="button" data-disponibilidad="disponible">Disponible</button>
            <button type="button" data-disponibilidad="agotado">Agotado</button>
          </div>
        </div>

        <div id="filtro-orden" role="group" aria-label="Ordenar por">
          <span>Ordenar por</span>
          <div>
            <button type="button" data-orden="mas-prestados">Más prestados</button>
            <button type="button" data-orden="az">A–Z</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= LISTADO DE EQUIPOS (DESDE BASE DE DATOS) ================= -->
    <section aria-label="Catálogo de equipos">
      <!-- CONTENEDOR PRINCIPAL PARA INYECTAR LOS EQUIPOS DE LA BASE DE DATOS -->
      <div id="contenedor-inventario-equipos" data-source="db-equipos">
        <!-- Espacio preparado: aquí se renderizarán los equipos desde la base de datos -->
      </div>

      <!-- Paginación de resultados (preparada para BD) -->
      <div id="barra-paginacion-equipos" data-source="db-paginacion">
        <p id="conteo-equipos">
          <!-- Espacio preparado: ej. Mostrando 1–8 de 8 equipos (BD) -->
        </p>
        <nav aria-label="Paginación de inventario">
          <button type="button" id="btn-pagina-anterior">Anterior</button>
          <span id="numeros-pagina">
            <button type="button" aria-current="page">1</button>
          </span>
          <button type="button" id="btn-pagina-siguiente">Siguiente</button>
        </nav>
      </div>
    </section>

    <!-- ================= SECCIÓN INFORMATIVA: ANTES DE PEDIR UN PRÉSTAMO ================= -->
    <section aria-labelledby="titulo-reglas-prestamo">
      <h2 id="titulo-reglas-prestamo">Antes de pedir un préstamo</h2>

      <div>
        <article>
          <h3>Duración</h3>
          <p>El préstamo dura 3 días como máximo.</p>
        </article>

        <article>
          <h3>Matrícula</h3>
          <p>Presenta tu matrícula.</p>
        </article>

        <article>
          <h3>Revisión</h3>
          <p>Revisa el equipo al recibirlo.</p>
        </article>
      </div>
    </section>

    <!-- ================= SECCIÓN: MIS PRÉSTAMOS ACTIVOS (DESDE BASE DE DATOS) ================= -->
    <section aria-labelledby="titulo-prestamos-activos">
      <div>
        <h2 id="titulo-prestamos-activos">Mis préstamos activos</h2>
        <span id="fecha-corte-prestamos" data-source="db-fecha-corte">
          <!-- Espacio preparado: fecha de corte desde BD (ej. Al 17 nov 2026) -->
        </span>
      </div>

      <!-- CONTENEDOR PREPARADO PARA PRÉSTAMOS ACTIVOS DEL USUARIO DESDE BD -->
      <div id="contenedor-prestamos-activos" data-source="db-prestamos-activos">
        <!-- Espacio preparado: aquí se renderizarán los préstamos activos del usuario -->
      </div>
    </section>
  </main>

  <!-- ================= FOOTER INSTITUCIONAL (MISIÓN Y VISIÓN DESDE BD) ================= -->
  <footer id="footer-institucional">
    <div>
      <!-- Columna 1: Identidad de marca -->
      <div>
        <div>
          <svg width="38" height="38" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Capsule Tech">
            <rect width="64" height="64" rx="14" fill="#090C10" />
            <circle cx="32" cy="31" r="24" stroke="#FFFFFF" stroke-width="2" />
            <path d="M43.5 23.5C41.2 19.6 36.9 17 32 17C24.3 17 18 23.3 18 31C18 38.7 24.3 45 32 45C36.9 45 41.2 42.4 43.5 38.5L37.5 35C36.3 36.8 34.3 38 32 38C28.1 38 25 34.9 25 31C25 27.1 28.1 24 32 24C34.3 24 36.3 25.2 37.5 27L43.5 23.5Z" fill="#FFFFFF" />
            <circle cx="32" cy="31" r="4.5" stroke="#FFFFFF" stroke-width="2.2" />
            <rect x="11" y="47" width="42" height="8" rx="4" fill="#090C10" stroke="#FFFFFF" stroke-width="1.5" />
            <text x="32" y="52.6" fill="#FFFFFF" font-family="sans-serif" font-size="4.6" font-weight="700" text-anchor="middle" letter-spacing="0.4">CAPSULE CORP</text>
          </svg>
          <strong>Capsule Tech</strong>
        </div>
        <p><strong>Todo lo que necesitas en un solo lugar</strong></p>
        <p>Tecnología que conecta a nuestra comunidad.</p>
      </div>

      <!-- Columna 2: Nuestra misión (Preparado para BD) -->
      <div>
        <h3>Nuestra misión</h3>
        <div id="contenedor-mision" data-source="db-mision">
          <!-- Espacio preparado: texto de Nuestra misión desde la base de datos -->
        </div>
      </div>

      <!-- Columna 3: Nuestra visión (Preparado para BD) -->
      <div>
        <h3>Nuestra visión</h3>
        <div id="contenedor-vision" data-source="db-vision">
          <!-- Espacio preparado: texto de Nuestra visión desde la base de datos -->
        </div>
      </div>

      <!-- Columna 4: Contáctanos -->
      <div>
        <h3>Contáctanos</h3>
        <p>¿Tienes una duda? Nuestro equipo te acompaña.</p>
        <ul>
          <li><a href="#">Enviar una consulta &rarr;</a></li>
          <li><a href="#">Centro de ayuda &rarr;</a></li>
        </ul>
      </div>
    </div>

    <hr />

    <!-- Barra legal inferior -->
    <div>
      <small>&copy; 2026 Capsule Tech. Todos los derechos reservados.</small>
      <ul>
        <li><a href="#">Privacidad</a></li>
        <li><a href="#">Términos de uso</a></li>
      </ul>
    </div>
  </footer>

  <!-- =========================================================================
       PLANTILLAS HTML PURAS PARA CLONAR E INYECTAR DATOS DESDE LA BASE DE DATOS
       ========================================================================= -->

  <!-- Plantilla: Tarjeta de equipo en inventario -->
  <template id="tpl-equipo-inventario">
    <article data-equipo-id="">
      <div>
        <span data-campo="icono"><!-- Icono del equipo --></span>
        <span data-campo="categoria"><!-- Categoría (ej. Cómputo) --></span>
      </div>

      <h3 data-campo="nombre"><!-- Nombre del equipo --></h3>
      <p data-campo="especificaciones"><!-- Especificaciones --></p>

      <div>
        <p data-campo="disponibilidad-texto"><!-- Ej. 8 de 12 disponibles / 0 de 4 disponibles --></p>
        <progress data-campo="disponibilidad-barra" value="0" max="100"></progress>
      </div>

      <a href="#" data-campo="accion-btn"><!-- Solicitar préstamo / Ver alternativas --></a>
    </article>
  </template>

  <!-- Plantilla: Tarjeta de préstamo activo del usuario -->
  <template id="tpl-prestamo-activo">
    <article data-prestamo-id="">
      <div>
        <span data-campo="icono"><!-- Icono del equipo --></span>
        <div>
          <h3 data-campo="nombre-equipo"><!-- Nombre del equipo --></h3>
          <p data-campo="folio"><!-- Ej. Préstamo PR-2026-0093 --></p>
        </div>
      </div>

      <div>
        <div>
          <span>Fecha de devolución</span>
          <strong data-campo="fecha-devolucion"><!-- Ej. 19 nov 2026 --></strong>
        </div>
        <span data-campo="dias-restantes"><!-- Ej. 2 días restantes --></span>
      </div>
    </article>
  </template>

</body>
</html>