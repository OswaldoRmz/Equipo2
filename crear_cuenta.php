<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Crear cuenta — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos personalizados -->
  <link rel="stylesheet" href="styles/styleCrearCuenta.css" />
</head>
<body>

  <div class="container-fluid p-0">
    <div class="row g-0 min-vh-100">

      <!-- ================= COLUMNA IZQUIERDA ================= -->
      <aside class="col-lg-5 left-panel">
        <!-- Marca / Logo -->
        <div class="d-flex align-items-center gap-3">
          <svg width="54" height="54" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Capsule Tech">
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
        </div>

        <!-- Mensaje principal -->
        <div class="my-4">
          <div class="hero-accent-line"></div>
          <h1 class="hero-heading">
            Todo lo que<br />
            necesitas en un<br />
            solo lugar
          </h1>
          <p class="hero-description">
            Una cuenta. Todos tus servicios tecnológicos. Regístrate y encuentra el apoyo que necesitas.
          </p>
        </div>

        <!-- Tarjeta Vista Previa -->
        <div class="preview-card mb-3">
          <div class="preview-card-header">
            <span class="preview-kicker">Capsule / Tu Espacio</span>
            <span class="preview-badge-text">Vista previa</span>
          </div>

          <div class="preview-card-body">
            <div class="d-flex align-items-center gap-3">
              <div class="solution-icon-box">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="4" width="18" height="12" rx="2" />
                  <path d="M8 20h8" />
                  <path d="M12 16v4" />
                  <path d="m9 10 2 2 4-4" />
                </svg>
              </div>
              <div>
                <h2 class="solution-title">Tu centro de soluciones</h2>
                <p class="solution-subtitle">De la solicitud a la solución.</p>
              </div>
            </div>

            <p class="preview-tagline">Todo conectado, de principio a fin.</p>

            <!-- Stepper -->
            <div class="stepper-wrapper">
              <div class="stepper-line"></div>

              <div class="step-item">
                <div class="step-circle">
                  <i class="bi bi-check-lg"></i>
                </div>
                <span class="step-label">Solicita</span>
              </div>

              <div class="step-item">
                <div class="step-circle">
                  <i class="bi bi-check-lg"></i>
                </div>
                <span class="step-label">Conecta</span>
              </div>

              <div class="step-item active">
                <div class="step-circle active">
                  <i class="bi bi-check-lg"></i>
                </div>
                <span class="step-label">Resuelve</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer izquierdo -->
        <div class="left-footer-text pt-2">
          Capsule Tech &middot; Tu Espacio Tecnológico
        </div>
      </aside>

      <!-- ================= COLUMNA DERECHA ================= -->
      <main class="col-lg-7 right-panel">
        <!-- Barra superior -->
        <header class="top-nav-bar">
          <span class="portal-label">Portal de Servicios</span>
          <a href="#" class="help-link">¿Necesitas ayuda?</a>
        </header>

        <!-- Formulario -->
        <section class="form-container">
          <div class="eyebrow-text">Empieza aquí</div>
          <h2 class="form-title">Crea tu cuenta</h2>
          <p class="form-subtitle">Tus servicios tecnológicos comienzan aquí.</p>

          <form action="#" method="POST" novalidate>
            <!-- Nombre -->
            <div class="mb-3">
              <label for="nombre" class="form-label">Nombre</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-person"></i></span>
                <input type="text" class="form-control custom-input" id="nombre" placeholder="Ingresa tu nombre" required />
              </div>
            </div>

            <!-- Apellidos -->
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label for="apellidoPaterno" class="form-label">Apellido paterno</label>
                <div class="input-icon-group">
                  <span class="input-icon-left"><i class="bi bi-person"></i></span>
                  <input type="text" class="form-control custom-input" id="apellidoPaterno" placeholder="Apellido paterno" required />
                </div>
              </div>
              <div class="col-sm-6">
                <label for="apellidoMaterno" class="form-label">Apellido materno</label>
                <div class="input-icon-group">
                  <span class="input-icon-left"><i class="bi bi-person"></i></span>
                  <input type="text" class="form-control custom-input" id="apellidoMaterno" placeholder="Apellido materno" required />
                </div>
              </div>
            </div>

            <!-- Correo electrónico -->
            <div class="mb-3">
              <label for="correo" class="form-label">Correo electrónico</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control custom-input" id="correo" placeholder="tu.correo@institucion.edu" required />
              </div>
            </div>

            <!-- Contraseña -->
            <div class="mb-3">
              <label for="password" class="form-label">Contraseña</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control custom-input has-right-icon" id="password" placeholder="Crea una contraseña" required />
                <button type="button" class="toggle-password-btn" data-target="password" aria-label="Mostrar u ocultar contraseña">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- Confirmar contraseña -->
            <div class="mb-3">
              <label for="confirmPassword" class="form-label">Confirmar contraseña</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control custom-input has-right-icon" id="confirmPassword" placeholder="Repite tu contraseña" required />
                <button type="button" class="toggle-password-btn" data-target="confirmPassword" aria-label="Mostrar u ocultar confirmación de contraseña">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- Usuario -->
            <div class="mb-4">
              <label for="usuario" class="form-label">Usuario</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-person-vcard"></i></span>
                <input type="text" class="form-control custom-input" id="usuario" placeholder="Ej. ana.lopez" required />
              </div>
            </div>

            <!-- Botón principal -->
            <button type="submit" class="btn btn-create-account w-100 mb-3">
              Crear cuenta
            </button>

            <!-- Iniciar sesión -->
            <p class="text-center login-prompt mb-0">
              ¿Ya tienes cuenta? <a href="#" class="login-link">Inicia sesión</a>
            </p>
          </form>
        </section>

        <!-- Footer derecho -->
        <footer class="right-footer pt-3">
          <span>&copy; 2026 Capsule Tech. Servicios tecnológicos.</span>
          <div class="d-flex align-items-center gap-2">
            <a href="#">Privacidad</a>
            <span>&middot;</span>
            <a href="#">Términos de uso</a>
          </div>
        </footer>
      </main>

    </div>
  </div>

  <!-- Script para alternar visibilidad de contraseñas -->
  <script>
    document.querySelectorAll('.toggle-password-btn').forEach(button => {
      button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');
        const isPassword = input.type === 'password';

        input.type = isPassword ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !isPassword);
        icon.classList.toggle('bi-eye-slash', isPassword);
      });
    });
  </script>
</body>
</html>