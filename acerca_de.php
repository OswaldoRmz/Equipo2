<?php
require_once 'config/conexion.php';

// Utilidades de la barra de administrador (las mismas de servicios.php e inventario.php).
// defined() y function_exists() evitan un error si algún día se mueven a config/conexion.php.
if (!defined('ROL_ADMINISTRADOR')) {
    define('ROL_ADMINISTRADOR', 1);
}

if (!function_exists('inicialesUsuario')) {
    /**
     * Iniciales del usuario para el avatar (ej. "Admin Principal" → "AP").
     */
    function inicialesUsuario(array $usuario): string {
        $iniciales = mb_substr(trim($usuario['nombres']), 0, 1, 'UTF-8') . mb_substr(trim($usuario['apellidos']), 0, 1, 'UTF-8');
        return $iniciales !== '' ? mb_strtoupper($iniciales, 'UTF-8') : 'U';
    }
}

/**
 * Convierte el icono guardado en la tabla valor (ej. "circle-check") en una clase de Bootstrap Icons.
 */
function iconoValor(?string $icono): string {
    $icono = strtolower(trim((string)$icono));
    if (str_starts_with($icono, 'bi-')) {
        return $icono;
    }
    return match ($icono) {
        'circle-check', 'check-circle' => 'bi-check-circle',
        'lightbulb'                    => 'bi-lightbulb',
        'accessibility'                => 'bi-universal-access',
        'shield-check'                 => 'bi-shield-check',
        default                        => preg_match('/^[a-z0-9-]+$/', $icono) ? 'bi-' . $icono : 'bi-stars',
    };
}

/**
 * Teléfono de 10 dígitos para mostrar: "7712345678" → "771 234 5678".
 */
function telefonoLegible(string $telefono): string {
    $digitos = preg_replace('/\D/', '', $telefono);
    return strlen($digitos) === 10
        ? substr($digitos, 0, 3) . ' ' . substr($digitos, 3, 3) . ' ' . substr($digitos, 6)
        : $telefono;
}

/**
 * URL del mapa de datos_empresa.mapa_iframe (puede guardar el <iframe> completo o solo su URL).
 * Solo se aceptan mapas incrustados de Google Maps.
 */
function urlMapa(?string $mapa): ?string {
    $mapa = trim((string)$mapa);
    if ($mapa === '') {
        return null;
    }
    if (preg_match('/src=["\']([^"\']+)["\']/i', $mapa, $partes)) {
        $mapa = html_entity_decode($partes[1]);
    }
    return preg_match('#^https://(www\.)?google\.[a-z.]+/maps/embed#i', $mapa) ? $mapa : null;
}

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);
$esAdmin = $usuario !== null && (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;

// Nuestros valores (tabla valor) y Preguntas frecuentes (tabla faq), en el orden definido en la BD
$valores = $pdo->query("
    SELECT titulo, descripcion, icono
    FROM valor
    WHERE estado = 'Activo'
    ORDER BY orden_visual ASC, id_valor ASC
")->fetchAll();

$preguntas = $pdo->query("
    SELECT id_faq, pregunta, respuesta
    FROM faq
    WHERE estado = 'Activo'
    ORDER BY orden_visual ASC, id_faq ASC
")->fetchAll();

// Datos de la empresa (tabla datos_empresa). Si un texto está vacío se usa el del diseño.
$descripcionNosotros = trim($empresa['descripcion_nosotros'] ?? '');
if ($descripcionNosotros === '') {
    $descripcionNosotros = 'Somos el espacio de servicios tecnológicos de nuestra comunidad. Conectamos a estudiantes y equipos de trabajo con herramientas, soporte y soluciones para que sus ideas sigan avanzando.';
}
$correoContacto = trim($empresa['correo_contacto'] ?? '') ?: 'contacto@techsolutions.com';
$direccion      = trim($empresa['direccion'] ?? '');
$telefono       = trim($empresa['telefono_principal'] ?? '');
$whatsapp       = preg_replace('/\D/', '', $empresa['whatsapp_soporte'] ?? '');
$urlMapa        = urlMapa($empresa['mapa_iframe'] ?? null);

// Si hay sesión, el formulario se llena con el nombre y correo del usuario
$nombreSesion = $usuario ? trim($usuario['nombres'] . ' ' . $usuario['apellidos']) : '';
$correoSesion = $usuario['correo'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Acerca de — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos compartidos: navbar, botones y footer (inventario.css);
       campos de formulario e insignia de administrador (servicios.css) -->
  <link rel="stylesheet" href="styles/inventario.css" />
  <link rel="stylesheet" href="styles/servicios.css" />
  <!-- Estilos propios de Acerca de -->
  <link rel="stylesheet" href="styles/acerca_de.css" />
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
              <a class="nav-link nav-pill-link" href="servicios.php">Servicios</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="inventario.php">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link active" aria-current="page" href="acerca_de.php">Acerca de</a>
            </li>

            <?php if ($esAdmin): ?>
              <li class="nav-item ms-lg-3 d-flex align-items-center">
                <span class="role-pill">Administrador</span>
              </li>
              <li class="nav-item dropdown">
                <a
                  class="avatar-initials"
                  href="#"
                  role="button"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                  aria-label="Menú de la cuenta de <?= htmlspecialchars($usuario['nombres']) ?>"
                >
                  <?= htmlspecialchars(inicialesUsuario($usuario)) ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm">
                  <li><span class="dropdown-item-text small fw-semibold"><?= htmlspecialchars(trim($usuario['nombres'] . ' ' . $usuario['apellidos'])) ?></span></li>
                  <li><span class="dropdown-item-text small text-muted"><?= htmlspecialchars($usuario['correo']) ?></span></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person me-2"></i>Perfil</a></li>
                  <li><a class="dropdown-item" href="servicios.php"><i class="bi bi-clipboard-check me-2"></i>Solicitudes</a></li>
                  <li><a class="dropdown-item" href="servicios.php?vista=catalogo"><i class="bi bi-grid me-2"></i>Catálogo de servicios</a></li>
                  <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
                </ul>
              </li>
            <?php elseif ($usuario): ?>
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
                  <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</a></li>
                </ul>
              </li>
            <?php else: ?>
              <li class="nav-item ms-lg-2">
                <a class="btn btn-card-alt px-3" style="height: 40px;" href="login.php">Iniciar sesión</a>
              </li>
              <li class="nav-item">
                <a class="btn btn-card-loan px-3" style="height: 40px;" href="crear_cuenta.php">Crear cuenta</a>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </nav>
  </header>

  <main>
    <!-- ================= QUIÉNES SOMOS (BD: datos_empresa.descripcion_nosotros) ================= -->
    <section class="pt-4 pb-3" aria-labelledby="titulo-acerca">
      <div class="container">
        <div class="about-banner">
          <div class="row align-items-center g-4">
            <div class="col-lg-9">
              <h1 id="titulo-acerca" class="about-title">Acerca de Capsule Tech</h1>
              <p class="about-slogan">Todo lo que necesitas en un solo lugar</p>
              <p class="about-text mb-0"><?= nl2br(htmlspecialchars($descripcionNosotros)) ?></p>
            </div>
            <div class="col-lg-3 d-none d-lg-flex justify-content-end">
              <img src="images/logo.jpeg" alt="Logo oficial Capsule Tech" width="112" height="112" class="about-logo" />
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= MISIÓN Y VISIÓN (BD: datos_empresa) ================= -->
    <section class="py-3" aria-label="Misión y visión">
      <div class="container">
        <div class="row g-4">
          <div class="col-md-6">
            <article class="about-card h-100">
              <div class="d-flex align-items-center gap-3 mb-3">
                <div class="about-card-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></div>
                <h2 class="about-card-title mb-0">Misión</h2>
              </div>
              <p class="about-card-text mb-0"><?= htmlspecialchars($empresa['mision'] ?? '') ?></p>
            </article>
          </div>
          <div class="col-md-6">
            <article class="about-card h-100">
              <div class="d-flex align-items-center gap-3 mb-3">
                <div class="about-card-icon" aria-hidden="true"><i class="bi bi-eye"></i></div>
                <h2 class="about-card-title mb-0">Visión</h2>
              </div>
              <p class="about-card-text mb-0"><?= htmlspecialchars($empresa['vision'] ?? '') ?></p>
            </article>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= NUESTROS VALORES (BD: valor) ================= -->
    <?php if (count($valores) > 0): ?>
      <section class="pt-4 pb-5" aria-labelledby="titulo-valores">
        <div class="container">
          <h2 id="titulo-valores" class="about-section-title mb-4">Nuestros valores</h2>
          <div class="row g-4">
            <?php foreach ($valores as $valor): ?>
              <div class="col-sm-6 col-lg-3">
                <article class="value-card h-100">
                  <div class="value-icon" aria-hidden="true">
                    <i class="bi <?= htmlspecialchars(iconoValor($valor['icono'])) ?>"></i>
                  </div>
                  <h3 class="value-title"><?= htmlspecialchars($valor['titulo']) ?></h3>
                  <p class="value-text mb-0"><?= htmlspecialchars($valor['descripcion']) ?></p>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- ================= PREGUNTAS FRECUENTES (BD: faq) ================= -->
    <?php if (count($preguntas) > 0): ?>
      <section id="preguntas-frecuentes" class="py-5 section-tinted" aria-labelledby="titulo-faq">
        <div class="container">
          <h2 id="titulo-faq" class="about-section-title mb-4">Preguntas frecuentes</h2>
          <div class="accordion faq-accordion" id="acordeonFaq">
            <?php foreach ($preguntas as $i => $faq): ?>
              <?php $idFaq = 'faq-' . (int)$faq['id_faq']; ?>
              <div class="accordion-item">
                <h3 class="accordion-header">
                  <button
                    class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= $idFaq ?>"
                    aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>"
                    aria-controls="<?= $idFaq ?>"
                  >
                    <?= htmlspecialchars($faq['pregunta']) ?>
                  </button>
                </h3>
                <div id="<?= $idFaq ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#acordeonFaq">
                  <div class="accordion-body"><?= nl2br(htmlspecialchars($faq['respuesta'])) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- ================= CONTÁCTANOS (BD: datos_empresa) ================= -->
    <section id="contacto" class="py-5" aria-labelledby="titulo-contacto">
      <div class="container">
        <h2 id="titulo-contacto" class="about-section-title mb-1">Contáctanos</h2>
        <p class="about-section-subtitle mb-4">Cuéntanos qué necesitas. Estamos para ayudarte.</p>

        <div class="row g-4">
          <!-- Información de contacto -->
          <div class="col-lg-5">
            <div class="contact-card h-100">
              <h3 class="contact-card-title">Visítanos o escríbenos</h3>
              <p class="contact-card-text">Nuestro equipo está para ayudarte.</p>

              <ul class="list-unstyled d-flex flex-column gap-4 mb-0">
                <?php if ($direccion !== ''): ?>
                  <li class="contact-item">
                    <span class="contact-icon" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                    <span>
                      <span class="contact-label">Ubicación</span>
                      <span class="contact-value"><?= htmlspecialchars($direccion) ?></span>
                    </span>
                  </li>
                <?php endif; ?>
                <li class="contact-item">
                  <span class="contact-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                  <span>
                    <span class="contact-label">Correo</span>
                    <a class="contact-value contact-link" href="mailto:<?= htmlspecialchars($correoContacto) ?>"><?= htmlspecialchars($correoContacto) ?></a>
                  </span>
                </li>
                <?php if ($telefono !== ''): ?>
                  <li class="contact-item">
                    <span class="contact-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                    <span>
                      <span class="contact-label">Teléfono</span>
                      <a class="contact-value contact-link" href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $telefono)) ?>"><?= htmlspecialchars(telefonoLegible($telefono)) ?></a>
                    </span>
                  </li>
                <?php endif; ?>
                <?php if ($whatsapp !== ''): ?>
                  <li class="contact-item">
                    <span class="contact-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
                    <span>
                      <span class="contact-label">WhatsApp</span>
                      <a class="contact-value contact-link" href="https://wa.me/<?= strlen($whatsapp) === 10 ? '52' . $whatsapp : $whatsapp ?>" target="_blank" rel="noopener"><?= htmlspecialchars(telefonoLegible($whatsapp)) ?></a>
                    </span>
                  </li>
                <?php endif; ?>
              </ul>

              <?php if ($urlMapa !== null): ?>
                <div class="contact-map mt-4">
                  <iframe src="<?= htmlspecialchars($urlMapa) ?>" title="Mapa de ubicación de Capsule Tech" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Formulario: abre la app de correo con el mensaje listo (no se guarda en la BD) -->
          <div class="col-lg-7">
            <form id="formContacto" class="contact-form h-100" data-correo="<?= htmlspecialchars($correoContacto) ?>" novalidate>
              <h3 class="contact-card-title mb-3">Escríbenos</h3>

              <div class="row g-3">
                <div class="col-sm-6">
                  <label for="contactoNombre" class="form-label service-label">Nombre</label>
                  <input
                    type="text"
                    id="contactoNombre"
                    name="nombre"
                    class="form-control service-input"
                    minlength="3"
                    maxlength="100"
                    value="<?= htmlspecialchars($nombreSesion) ?>"
                    placeholder="Ej. Ana López Martínez"
                    autocomplete="name"
                    required
                  />
                  <div class="invalid-feedback" id="error-contacto-nombre">Escribe tu nombre.</div>
                </div>
                <div class="col-sm-6">
                  <label for="contactoCorreo" class="form-label service-label">Correo</label>
                  <input
                    type="email"
                    id="contactoCorreo"
                    name="correo"
                    class="form-control service-input"
                    maxlength="100"
                    value="<?= htmlspecialchars($correoSesion) ?>"
                    placeholder="Ej. ana.lopez@institucion.edu"
                    autocomplete="email"
                    required
                  />
                  <div class="invalid-feedback" id="error-contacto-correo">Escribe un correo válido.</div>
                </div>
                <div class="col-12">
                  <label for="contactoAsunto" class="form-label service-label">Asunto</label>
                  <input
                    type="text"
                    id="contactoAsunto"
                    name="asunto"
                    class="form-control service-input"
                    minlength="3"
                    maxlength="100"
                    placeholder="Ej. Consulta de disponibilidad"
                    required
                  />
                  <div class="invalid-feedback" id="error-contacto-asunto">Escribe el asunto de tu mensaje.</div>
                </div>
                <div class="col-12">
                  <label for="contactoMensaje" class="form-label service-label">Mensaje</label>
                  <textarea
                    id="contactoMensaje"
                    name="mensaje"
                    class="form-control service-input"
                    rows="5"
                    minlength="10"
                    maxlength="1000"
                    placeholder="Ej. Hola, quisiera saber cómo consultar la disponibilidad de equipos para mi próximo proyecto. Gracias."
                    required
                  ></textarea>
                  <div class="invalid-feedback" id="error-contacto-mensaje">Escribe tu mensaje.</div>
                  <div class="form-counter" id="contadorMensaje">0 / 1000</div>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-card-loan w-100 contact-submit" id="botonContacto">
                    <i class="bi bi-send me-2"></i>Enviar mensaje
                  </button>
                </div>
              </div>

              <p class="service-help mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Al enviar se abrirá tu aplicación de correo con el mensaje listo para <strong><?= htmlspecialchars($correoContacto) ?></strong>.
              </p>
              <div class="alert alert-success mt-3 mb-0 d-none" id="contactoListo" role="status">
                <i class="bi bi-check-circle me-1"></i>
                Abrimos tu aplicación de correo con el mensaje listo. Si no se abrió, escríbenos a
                <a href="mailto:<?= htmlspecialchars($correoContacto) ?>"><?= htmlspecialchars($correoContacto) ?></a>.
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- ================= FOOTER (VERSIÓN REDUCIDA: MISIÓN Y VISIÓN YA ESTÁN EN LA PÁGINA) ================= -->
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

          <p class="footer-lead mb-0">
            Todo lo que necesitas<br />
            en un solo lugar
          </p>
        </div>

        <div class="col-md-6 col-lg-3 offset-lg-6">
          <h3 class="footer-heading">Contáctanos</h3>
          <div class="d-flex flex-column gap-2">
            <a href="#contacto" class="footer-action-link">
              Enviar una consulta <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="#preguntas-frecuentes" class="footer-action-link">
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

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Formulario "Escríbenos": valida los campos y abre la app de correo con el mensaje listo
    const formContacto = document.getElementById('formContacto');
    if (formContacto) {
      const destino = formContacto.dataset.correo;
      const campoNombre = document.getElementById('contactoNombre');
      const campoCorreo = document.getElementById('contactoCorreo');
      const campoAsunto = document.getElementById('contactoAsunto');
      const campoMensaje = document.getElementById('contactoMensaje');
      const contadorMensaje = document.getElementById('contadorMensaje');
      const avisoListo = document.getElementById('contactoListo');

      // Reglas de validación: devuelven el mensaje de error o '' si el campo es válido
      const reglas = {
        nombre: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe tu nombre.';
          if (largo < 3) return 'Escribe al menos 3 caracteres.';
          return '';
        },
        correo: campo => {
          const valor = campo.value.trim();
          if (valor === '') return 'Escribe tu correo.';
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor)) return 'Escribe un correo válido (ej. nombre@institucion.edu).';
          return '';
        },
        asunto: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe el asunto de tu mensaje.';
          if (largo < 3) return 'Escribe al menos 3 caracteres.';
          return '';
        },
        mensaje: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe tu mensaje.';
          if (largo < 10) return `Agrega más detalle: escribe al menos 10 caracteres (llevas ${largo}).`;
          if (largo > 1000) return 'El mensaje no puede superar los 1000 caracteres.';
          return '';
        }
      };
      const campos = [campoNombre, campoCorreo, campoAsunto, campoMensaje];

      function validarCampo(campo) {
        const mensaje = reglas[campo.name](campo);
        campo.setCustomValidity(mensaje);
        const feedback = document.getElementById('error-contacto-' + campo.name);
        if (feedback && mensaje) feedback.textContent = mensaje;
        campo.classList.toggle('is-invalid', mensaje !== '');
        campo.classList.toggle('is-valid', mensaje === '' && campo.value.trim() !== '');
        return mensaje === '';
      }

      campos.forEach(campo => {
        campo.addEventListener('input', () => {
          avisoListo.classList.add('d-none');
          if (campo.dataset.tocado === '1' || campo.classList.contains('is-invalid')) validarCampo(campo);
        });
        campo.addEventListener('blur', () => {
          campo.dataset.tocado = '1';
          validarCampo(campo);
        });
      });

      const actualizarContador = () => { contadorMensaje.textContent = `${campoMensaje.value.length} / 1000`; };
      campoMensaje.addEventListener('input', actualizarContador);
      actualizarContador();

      // Mantener el foco en el campo al hacer clic en "Enviar" (evita que el botón se mueva y el clic se pierda)
      document.getElementById('botonContacto').addEventListener('mousedown', event => event.preventDefault());

      formContacto.addEventListener('submit', event => {
        event.preventDefault();
        let primerInvalido = null;
        campos.forEach(campo => {
          campo.dataset.tocado = '1';
          if (!validarCampo(campo) && !primerInvalido) primerInvalido = campo;
        });
        if (primerInvalido) {
          primerInvalido.focus();
          return;
        }

        const cuerpo = `${campoMensaje.value.trim()}\n\n—\n${campoNombre.value.trim()}\n${campoCorreo.value.trim()}`;
        window.location.href = `mailto:${destino}?subject=${encodeURIComponent(campoAsunto.value.trim())}&body=${encodeURIComponent(cuerpo)}`;
        avisoListo.classList.remove('d-none');
      });
    }
  </script>
</body>
</html>
