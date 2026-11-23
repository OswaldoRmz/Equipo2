<?php
require_once 'config/conexion.php';

if (estaLogueado()) {
    header('Location: index.php');
    exit;
}

$errores = [];
$valores = [
    'nombre'           => '',
    'apellidoPaterno'  => '',
    'apellidoMaterno'  => '',
    'correo'           => '',
    'telefono'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valores['nombre']          = trim($_POST['nombre'] ?? '');
    $valores['apellidoPaterno'] = trim($_POST['apellidoPaterno'] ?? '');
    $valores['apellidoMaterno'] = trim($_POST['apellidoMaterno'] ?? '');
    $valores['correo']          = trim($_POST['correo'] ?? '');
    $valores['telefono']        = trim($_POST['telefono'] ?? '');
    $password                   = $_POST['password'] ?? '';
    $confirmPassword            = $_POST['confirmPassword'] ?? '';

    if ($valores['nombre'] === '' || $valores['apellidoPaterno'] === '') {
        $errores[] = 'El nombre y al menos el apellido paterno son obligatorios.';
    }
    if (!filter_var($valores['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingresa un correo electrónico válido.';
    }
    if ($valores['telefono'] === '' || strlen($valores['telefono']) > 15) {
        $errores[] = 'Ingresa un número de teléfono válido (máximo 15 caracteres).';
    }
    if (strlen($password) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    if ($password !== $confirmPassword) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    if (empty($errores)) {
        // Verificar si el correo ya está registrado en la tabla usuario
        $stmtCheck = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = :correo LIMIT 1");
        $stmtCheck->execute([':correo' => $valores['correo']]);

        if ($stmtCheck->fetch()) {
            $errores[] = 'Este correo electrónico ya está registrado. Intenta iniciar sesión.';
        } else {
            $apellidosCompletos = trim($valores['apellidoPaterno'] . ' ' . $valores['apellidoMaterno']);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmtInsert = $pdo->prepare("
                INSERT INTO usuario (nombres, apellidos, telefono, correo, password_hash, id_rol, estado)
                VALUES (:nombres, :apellidos, :telefono, :correo, :password_hash, 3, 'Activo')
            ");
            $stmtInsert->execute([
                ':nombres'       => $valores['nombre'],
                ':apellidos'     => $apellidosCompletos,
                ':telefono'      => $valores['telefono'],
                ':correo'        => $valores['correo'],
                ':password_hash' => $passwordHash,
            ]);

            header('Location: login.php?registrado=1');
            exit;
        }
    }
}
?>
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
  <link rel="stylesheet" href="styles/StyleCrearCuenta.css" />
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
            style="border-radius: 14px; object-fit: cover;"
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
          <a href="index.php" class="portal-label text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i> Volver al inicio
          </a>
          <a href="index.php#footer-institucional" class="help-link">¿Necesitas ayuda?</a>
        </header>

        <!-- Formulario -->
        <section class="form-container">
          <div class="eyebrow-text">Empieza aquí</div>
          <h2 class="form-title">Crea tu cuenta</h2>
          <p class="form-subtitle">Tus servicios tecnológicos comienzan aquí.</p>

          <?php if (!empty($errores)): ?>
            <div class="alert alert-danger py-2 px-3 small" role="alert">
              <ul class="mb-0 ps-3">
                <?php foreach ($errores as $err): ?>
                  <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form action="crear_cuenta.php" method="POST" novalidate>
            <!-- Nombre -->
            <div class="mb-3">
              <label for="nombre" class="form-label">Nombre</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-person"></i></span>
                <input
                  type="text"
                  class="form-control custom-input"
                  id="nombre"
                  name="nombre"
                  value="<?= htmlspecialchars($valores['nombre']) ?>"
                  placeholder="Ingresa tu nombre"
                  required
                />
              </div>
            </div>

            <!-- Apellidos -->
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label for="apellidoPaterno" class="form-label">Apellido paterno</label>
                <div class="input-icon-group">
                  <span class="input-icon-left"><i class="bi bi-person"></i></span>
                  <input
                    type="text"
                    class="form-control custom-input"
                    id="apellidoPaterno"
                    name="apellidoPaterno"
                    value="<?= htmlspecialchars($valores['apellidoPaterno']) ?>"
                    placeholder="Apellido paterno"
                    required
                  />
                </div>
              </div>
              <div class="col-sm-6">
                <label for="apellidoMaterno" class="form-label">Apellido materno</label>
                <div class="input-icon-group">
                  <span class="input-icon-left"><i class="bi bi-person"></i></span>
                  <input
                    type="text"
                    class="form-control custom-input"
                    id="apellidoMaterno"
                    name="apellidoMaterno"
                    value="<?= htmlspecialchars($valores['apellidoMaterno']) ?>"
                    placeholder="Apellido materno"
                  />
                </div>
              </div>
            </div>

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
                  value="<?= htmlspecialchars($valores['correo']) ?>"
                  placeholder="tu.correo@institucion.edu"
                  required
                />
              </div>
            </div>

            <!-- Teléfono (columna NOT NULL en tabla usuario) -->
            <div class="mb-3">
              <label for="telefono" class="form-label">Teléfono</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-telephone"></i></span>
                <input
                  type="tel"
                  class="form-control custom-input"
                  id="telefono"
                  name="telefono"
                  maxlength="15"
                  value="<?= htmlspecialchars($valores['telefono']) ?>"
                  placeholder="Ej. 7712345678"
                  required
                />
              </div>
            </div>

            <!-- Contraseña -->
            <div class="mb-3">
              <label for="password" class="form-label">Contraseña</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-lock"></i></span>
                <input
                  type="password"
                  class="form-control custom-input has-right-icon"
                  id="password"
                  name="password"
                  placeholder="Crea una contraseña"
                  required
                />
                <button type="button" class="toggle-password-btn" data-target="password" aria-label="Mostrar u ocultar contraseña">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- Confirmar contraseña -->
            <div class="mb-4">
              <label for="confirmPassword" class="form-label">Confirmar contraseña</label>
              <div class="input-icon-group">
                <span class="input-icon-left"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control custom-input has-right-icon" id="confirmPassword"name="confirmPassword"placeholder="Repite tu contraseña"required/>
                <button type="button" class="toggle-password-btn" data-target="confirmPassword" aria-label="Mostrar u ocultar confirmación de contraseña">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>

            <!-- Botón principal -->
            <button type="submit" class="btn btn-create-account w-100 mb-3">
              Crear cuenta
            </button>

            <!-- Iniciar sesión -->
            <p class="text-center login-prompt mb-0">
              ¿Ya tienes cuenta? <a href="login.php" class="login-link">Inicia sesión</a>
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