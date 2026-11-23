<?php
require_once 'config/conexion.php';

// Si ya inició sesión, redirigir al inicio
if (estaLogueado()) {
    header('Location: index.php');
    exit;
}

$error = '';
$correoPrevio = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php';

// Validar que el redirect sea una ruta interna segura
$rutasPermitidas = ['index.php', 'inventario.php'];
if (!in_array($redirect, $rutasPermitidas, true)) {
    $redirect = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    $correoPrevio = $correo;

    if ($correo === '' || $password === '') {
        $error = 'Por favor ingresa tu correo electrónico y contraseña.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id_usuario, nombres, apellidos, correo, password_hash, id_rol, estado
            FROM usuario
            WHERE correo = :correo
            LIMIT 1
        ");
        $stmt->execute([':correo' => $correo]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['estado'] !== 'Activo') {
                $error = 'Tu cuenta se encuentra inactiva. Contacta al administrador.';
            } else {
                session_regenerate_id(true);
                $_SESSION['id_usuario'] = (int)$user['id_usuario'];
                $_SESSION['nombres']    = $user['nombres'];
                $_SESSION['apellidos']  = $user['apellidos'];
                $_SESSION['correo']     = $user['correo'];
                $_SESSION['id_rol']     = (int)$user['id_rol'];

                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $error = 'Correo electrónico o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Iniciar sesión — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos personalizados del Login -->
  <link rel="stylesheet" href="styles/login.css" />
</head>
<body>

  <div class="container-fluid p-0">
    <div class="row g-0 min-vh-100">

      <!-- ================= COLUMNA IZQUIERDA ================= -->
      <aside class="col-lg-5 left-panel">
        <!-- Marca / Logo oficial -->
        <a href="index.php" class="d-flex align-items-center gap-3 text-decoration-none">
          <img
            src="images/logo.jpeg"
            alt="Logo oficial Capsule Tech"
            width="54"
            height="54"
            class="brand-logo"
          />
          <div>
            <div class="brand-title">Capsule Tech</div>
            <div class="brand-subtitle">Servicios Tecnológicos</div>
          </div>
        </a>

        <!-- Mensaje principal -->
        <div class="my-4">
          <div class="hero-accent-line"></div>
          <h1 class="hero-heading">
            Todo lo que<br />
            necesitas en un<br />
            solo lugar
          </h1>
          <p class="hero-description">
            Solicita ayuda, gestiona tus servicios y da seguimiento a cada solución. Desde un solo lugar.
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
          <a href="index.php" class="portal-label text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i> Volver al inicio
          </a>
          <a href="index.php#footer-institucional" class="help-link">¿Necesitas ayuda?</a>
        </header>

        <!-- Formulario de inicio de sesión -->
        <section class="form-container">
          <div class="eyebrow-text">Bienvenido de nuevo</div>
          <h2 class="form-title">Inicia sesión</h2>
          <p class="form-subtitle">Qué gusto verte. Ingresa tus datos para continuar.</p>

          <?php if (isset($_GET['registrado']) && $_GET['registrado'] == '1'): ?>
            <div class="alert alert-success py-2 px-3 small" role="alert">
              <i class="bi bi-check-circle me-1"></i> ¡Cuenta creada con éxito! Ahora puedes iniciar sesión.
            </div>
          <?php endif; ?>

          <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 px-3 small" role="alert">
              <i class="bi bi-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
          <?php endif; ?>

          <form action="login.php" method="POST" novalidate>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>" />

            <!-- Correo electrónico -->
            <div class="mb-3">
              <label for="correo" class="form-label">Correo electrónico</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-envelope"></i></span>
                <input
                  type="email"
                  class="form-control custom-input"
                  id="correo"
                  name="correo"
                  value="<?= htmlspecialchars($correoPrevio) ?>"
                  placeholder="tu.correo@institucion.edu"
                  required
                />
              </div>
            </div>

            <!-- Contraseña -->
            <div class="mb-2">
              <label for="password" class="form-label">Contraseña</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-lock"></i></span>
                <input
                  type="password"
                  class="form-control custom-input has-right-icon"
                  id="password"
                  name="password"
                  placeholder="Ingresa tu contraseña"
                  required
                />
                <button
                  type="button"
                  class="toggle-password-btn"
                  data-target="password"
                  aria-label="Mostrar u ocultar contraseña"
                >
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- Recuperar contraseña -->
            <div class="d-flex justify-content-end mb-4">
              <a href="#" class="forgot-password-link">Recuperar contraseña</a>
            </div>

            <!-- Botón principal: Iniciar sesión -->
            <button type="submit" class="btn btn-login-primary w-100 mb-3">
              Iniciar sesión
            </button>

            <!-- Registro -->
            <p class="text-center register-prompt mb-3">
              ¿Aún no tienes cuenta?
            </p>

            <a href="crear_cuenta.php" class="btn btn-register-outline w-100">
              Crear una cuenta
            </a>
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