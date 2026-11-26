<?php
require_once 'config/conexion.php';

// Hora local de México para calcular vencimientos (XAMPP trae Europe/Berlin por defecto). Solo afecta a esta página.
date_default_timezone_set('America/Mexico_City');

// =====================================================================
// Utilidades de la vista de administrador (las mismas de servicios.php).
// Se definen aquí para no modificar config/conexion.php; function_exists()
// evita un error si algún día se mueven a conexion.php.
// =====================================================================

// Roles (tabla rol)
if (!defined('ROL_ADMINISTRADOR')) {
    define('ROL_ADMINISTRADOR', 1);
}

if (!function_exists('detectarTipoArchivo')) {
    /**
     * Identifica el tipo real de un archivo leyendo sus primeros bytes.
     * Devuelve la extensión con la que se guardará o null si no es PDF, JPG o PNG.
     */
    function detectarTipoArchivo(string $ruta): ?string {
        $inicio = (string)file_get_contents($ruta, false, null, 0, 8);
        if (str_starts_with($inicio, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($inicio, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($inicio, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        return null;
    }
}

if (!function_exists('fechaCortaEs')) {
    /**
     * Formatea una fecha de la BD como "17 nov 2026".
     */
    function fechaCortaEs(string $fecha): string {
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $ts = strtotime($fecha);
        return date('j', $ts) . ' ' . $meses[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }
}

if (!function_exists('claseCampo')) {
    /**
     * Clase de validación de Bootstrap para un campo con error del servidor.
     */
    function claseCampo(array $errores, string $campo): string {
        return isset($errores[$campo]) ? ' is-invalid' : '';
    }
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

if (!function_exists('paginasVisibles')) {
    /**
     * Números de página a mostrar: 1 … 4 5 6 … 12
     */
    function paginasVisibles(int $actual, int $total): array {
        if ($total <= 7) {
            return range(1, $total);
        }
        $paginas = [1];
        $inicio = max(2, $actual - 1);
        $fin    = min($total - 1, $actual + 1);
        if ($inicio > 2) {
            $paginas[] = '…';
        }
        for ($i = $inicio; $i <= $fin; $i++) {
            $paginas[] = $i;
        }
        if ($fin < $total - 1) {
            $paginas[] = '…';
        }
        $paginas[] = $total;
        return $paginas;
    }
}

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

// El administrador ve el control de inventario; clientes, técnicos y visitantes ven el catálogo
$esAdmin = $usuario !== null && (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;

// Reglas del control de inventario (administrador)
const REGISTROS_POR_PAGINA = 8;
const CANTIDAD_MAXIMA      = 50;
const IMAGEN_MAX_BYTES     = 5 * 1024 * 1024; // 5 MB
const CARPETA_IMAGENES     = 'uploads/equipos/';
const HORAS_POR_VENCER     = 24; // Un préstamo "Por vencer" termina en las próximas 24 horas

// Helper para construir URLs conservando filtros actuales (vista de cliente)
function urlFiltro(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    unset($query['solicitar']);
    return 'inventario.php?' . http_build_query($query);
}

/**
 * Construye una URL de inventario.php conservando los parámetros actuales (vista de administrador).
 * Un valor null, '' o 0 quita ese parámetro. "exportar" nunca se conserva.
 */
function urlInventario(array $cambios = []): string {
    $query = array_merge($_GET, $cambios);
    unset($query['exportar']);
    $query = array_filter($query, fn($valor) => $valor !== null && $valor !== '' && $valor !== 0 && $valor !== '0');
    return 'inventario.php' . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Estado que ve el administrador en un préstamo. "Prestado" se divide en Activo, Por vencer y Vencido
 * según la fecha límite.
 */
function estadoPrestamo(string $estado, string $fechaLimite, int $ahoraTs): array {
    if ($estado === 'Prestado') {
        $limiteTs = strtotime($fechaLimite);
        if ($limiteTs < $ahoraTs) {
            return ['clave' => 'vencido', 'texto' => 'Vencido', 'clase' => 'status-pill-vencido'];
        }
        if ($limiteTs <= $ahoraTs + HORAS_POR_VENCER * 3600) {
            return ['clave' => 'por-vencer', 'texto' => 'Por vencer', 'clase' => 'status-pill-proceso'];
        }
        return ['clave' => 'activo', 'texto' => 'Activo', 'clase' => 'status-pill-recibido'];
    }
    return match ($estado) {
        'Pendiente' => ['clave' => 'pendiente', 'texto' => 'Pendiente', 'clase' => 'status-pill-proceso'],
        'Devuelto'  => ['clave' => 'devuelto',  'texto' => 'Devuelto',  'clase' => 'status-pill-listo'],
        default     => ['clave' => 'cancelado', 'texto' => 'Cancelado', 'clase' => 'status-pill-cancelado'],
    };
}

/**
 * Estado de un equipo para la tabla: Disponible, Prestado o Dado de baja.
 */
function estadoEquipo(string $estado, string $visibilidad): array {
    if ($visibilidad !== 'Activo') {
        return ['texto' => 'Dado de baja', 'clase' => 'status-pill-cancelado'];
    }
    return $estado === 'Prestado'
        ? ['texto' => 'Prestado',   'clase' => 'status-pill-recibido']
        : ['texto' => 'Disponible', 'clase' => 'status-pill-listo'];
}

/**
 * Fecha para las tablas: "13/11/2026" (o "—" si no hay fecha).
 */
function fechaTabla(?string $fecha, bool $conHora = false): string {
    if ($fecha === null || $fecha === '') {
        return '—';
    }
    return date($conHora ? 'd/m/Y H:i' : 'd/m/Y', strtotime($fecha));
}

/**
 * Nombre corto del usuario para las tablas: primer nombre y primer apellido.
 */
function nombreCorto(string $nombres, string $apellidos): string {
    $nombre   = explode(' ', trim($nombres))[0] ?? '';
    $apellido = explode(' ', trim($apellidos))[0] ?? '';
    return trim($nombre . ' ' . $apellido);
}

/**
 * Números de inventario consecutivos a partir del primero: EQ-006 ×3 → EQ-006, EQ-007, EQ-008.
 * Devuelve null si se piden varias unidades y el número no termina en dígitos.
 */
function codigosConsecutivos(string $codigo, int $cantidad): ?array {
    if ($cantidad === 1) {
        return [$codigo];
    }
    if (!preg_match('/^(.*?)(\d{1,15})$/', $codigo, $partes)) {
        return null;
    }
    $codigos = [];
    for ($i = 0; $i < $cantidad; $i++) {
        $codigos[] = $partes[1] . str_pad((string)((int)$partes[2] + $i), strlen($partes[2]), '0', STR_PAD_LEFT);
    }
    return $codigos;
}

/**
 * Siguiente número de inventario libre a partir del último equipo registrado (ej. EQ-005 → EQ-006).
 */
function codigoSugerido(PDO $pdo): string {
    $ultimo = $pdo->query("SELECT codigo FROM equipo ORDER BY id_equipo DESC LIMIT 1")->fetchColumn();
    if ($ultimo === false || !preg_match('/^(.*?)(\d{1,15})$/', $ultimo, $partes)) {
        return 'EQ-001';
    }
    $stmtExiste = $pdo->prepare("SELECT COUNT(*) FROM equipo WHERE codigo = :codigo");
    for ($i = 1; $i <= 200; $i++) {
        $candidato = $partes[1] . str_pad((string)((int)$partes[2] + $i), strlen($partes[2]), '0', STR_PAD_LEFT);
        $stmtExiste->execute([':codigo' => $candidato]);
        if ((int)$stmtExiste->fetchColumn() === 0) {
            return $candidato;
        }
    }
    return '';
}

/**
 * Borra una imagen de equipo del disco si ya ningún equipo la usa
 * (las unidades registradas juntas comparten la misma imagen).
 */
function borrarImagenSinUso(PDO $pdo, ?string $ruta): void {
    if ($ruta === null || $ruta === '' || !str_starts_with($ruta, CARPETA_IMAGENES)) {
        return;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM equipo WHERE imagen_url = :ruta");
    $stmt->execute([':ruta' => $ruta]);
    $archivo = __DIR__ . '/' . $ruta;
    if ((int)$stmt->fetchColumn() === 0 && is_file($archivo)) {
        unlink($archivo);
    }
}

/**
 * Descarga una tabla como CSV (se abre directo en Excel con acentos correctos) y termina la petición.
 */
function enviarCsv(string $nombreArchivo, array $encabezados, array $filas): never {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, $encabezados);
    foreach ($filas as $fila) {
        fputcsv($salida, $fila);
    }
    fclose($salida);
    exit;
}

/**
 * Condiciones de búsqueda por palabras: cada palabra debe aparecer en alguna de las columnas.
 */
function condicionesBusqueda(string $texto, array $columnas, array &$where, array &$params): void {
    if ($texto === '') {
        return;
    }
    $palabras = array_slice(preg_split('/\s+/', $texto), 0, 5);
    foreach ($palabras as $i => $palabra) {
        $partes = [];
        foreach ($columnas as $j => $columna) {
            $clave = ":b{$i}_{$j}";
            $partes[] = "{$columna} LIKE {$clave}";
            $params[$clave] = '%' . $palabra . '%';
        }
        $where[] = '(' . implode(' OR ', $partes) . ')';
    }
}

/**
 * Ejecuta una consulta paginada enlazando cada valor con su tipo (LIMIT y OFFSET deben ser enteros).
 */
function consultarPagina(PDO $pdo, string $sql, array $params, int $limite, int $desplazamiento): array {
    $stmt = $pdo->prepare($sql . ' LIMIT :limite OFFSET :desplazamiento');
    foreach ($params as $clave => $valor) {
        $stmt->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// VISTA DE ADMINISTRADOR: control de inventario
// =====================================================================
if ($esAdmin) {
    // Mensajes guardados antes de redirigir (evita repetir una acción al recargar)
    $mensajeExito = $_SESSION['flash_inventario'] ?? '';
    $mensajeError = $_SESSION['flash_inventario_error'] ?? '';
    unset($_SESSION['flash_inventario'], $_SESSION['flash_inventario_error']);

    $tabActiva = in_array($_GET['tab'] ?? '', ['prestamos', 'devoluciones'], true) ? $_GET['tab'] : 'equipos';
    $exportar  = (($_GET['exportar'] ?? '') === 'csv');
    $ahoraTs   = time();
    $ahora     = date('Y-m-d H:i:s', $ahoraTs);

    // Catálogos para el formulario y los filtros
    $categoriasEquipo = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Equipo' ORDER BY nombre ASC")->fetchAll();
    $idsCategorias = array_map('intval', array_column($categoriasEquipo, 'id_categoria'));
    $marcas = $pdo->query("SELECT id_marca, nombre FROM marca ORDER BY nombre ASC")->fetchAll();
    $idsMarcas = array_map('intval', array_column($marcas, 'id_marca'));

    // Formulario "Agregar / Editar equipo"
    $erroresEquipo = [];
    $valoresEquipo = [
        'id_equipo'     => 0,
        'nombre'        => '',
        'id_categoria'  => '',
        'cantidad'      => '1',
        'id_marca'      => '',
        'nueva_marca'   => '',
        'codigo'        => '',
        'descripcion'   => '',
        'ubicacion'     => '',
        'visibilidad'   => 'Activo',
        'imagen_actual' => '',
        'estado'        => 'Disponible',
    ];
    $abrirModalEquipo   = false;
    $pedirImagenDeNuevo = false;

    // Si la imagen supera post_max_size, PHP descarta todo el formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $mensajeError = 'La imagen es demasiado grande. El tamaño máximo permitido es de 5 MB.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = $_POST['accion'] ?? '';

        // ---------- Agregar o editar un equipo ----------
        if ($accion === 'guardar_equipo') {
            $valoresEquipo = array_merge($valoresEquipo, [
                'id_equipo'    => (int)($_POST['id_equipo'] ?? 0),
                'nombre'       => trim($_POST['nombre'] ?? ''),
                'id_categoria' => trim($_POST['id_categoria'] ?? ''),
                'cantidad'     => trim($_POST['cantidad'] ?? '1'),
                'id_marca'     => trim($_POST['id_marca'] ?? ''),
                'nueva_marca'  => trim($_POST['nueva_marca'] ?? ''),
                'codigo'       => trim($_POST['codigo'] ?? ''),
                'descripcion'  => trim($_POST['descripcion'] ?? ''),
                'ubicacion'    => trim($_POST['ubicacion'] ?? ''),
                'visibilidad'  => $_POST['visibilidad'] ?? '',
            ]);
            $quitarImagen = isset($_POST['quitar_imagen']);
            $esEdicion    = $valoresEquipo['id_equipo'] > 0;
            $equipoActual = null;

            if ($esEdicion) {
                $stmtActual = $pdo->prepare("SELECT id_equipo, estado, imagen_url FROM equipo WHERE id_equipo = :id LIMIT 1");
                $stmtActual->execute([':id' => $valoresEquipo['id_equipo']]);
                $equipoActual = $stmtActual->fetch();
                if (!$equipoActual) {
                    $_SESSION['flash_inventario_error'] = 'El equipo que intentas editar ya no existe.';
                    header('Location: ' . urlInventario());
                    exit;
                }
                $valoresEquipo['cantidad']      = '1';
                $valoresEquipo['imagen_actual'] = $equipoActual['imagen_url'] ?? '';
                $valoresEquipo['estado']        = $equipoActual['estado'];
            }

            // Nombre: 3 a 100 caracteres
            $largoNombre = mb_strlen($valoresEquipo['nombre'], 'UTF-8');
            if ($largoNombre === 0) {
                $erroresEquipo['nombre'] = 'Escribe el nombre del equipo.';
            } elseif ($largoNombre < 3) {
                $erroresEquipo['nombre'] = 'El nombre debe tener al menos 3 caracteres.';
            } elseif ($largoNombre > 100) {
                $erroresEquipo['nombre'] = 'El nombre no puede superar los 100 caracteres.';
            }

            // Categoría de tipo Equipo
            if (!in_array((int)$valoresEquipo['id_categoria'], $idsCategorias, true)) {
                $erroresEquipo['id_categoria'] = 'Selecciona la categoría del equipo.';
            }

            // Cantidad (solo al agregar): cada unidad es un registro con su propio número de inventario
            $cantidad = 1;
            if (!$esEdicion) {
                if (!preg_match('/^\d{1,3}$/', $valoresEquipo['cantidad']) || (int)$valoresEquipo['cantidad'] < 1 || (int)$valoresEquipo['cantidad'] > CANTIDAD_MAXIMA) {
                    $erroresEquipo['cantidad'] = 'Escribe una cantidad entre 1 y ' . CANTIDAD_MAXIMA . '.';
                } else {
                    $cantidad = (int)$valoresEquipo['cantidad'];
                }
            }

            // Marca: una del catálogo u "otra" (se registra en la tabla marca)
            $idMarca = null;
            if ($valoresEquipo['id_marca'] === 'otra') {
                $largoMarca = mb_strlen($valoresEquipo['nueva_marca'], 'UTF-8');
                if ($largoMarca < 2 || $largoMarca > 100) {
                    $erroresEquipo['nueva_marca'] = 'Escribe el nombre de la marca (de 2 a 100 caracteres).';
                }
            } elseif (in_array((int)$valoresEquipo['id_marca'], $idsMarcas, true)) {
                $idMarca = (int)$valoresEquipo['id_marca'];
            } else {
                $erroresEquipo['id_marca'] = 'Selecciona la marca del equipo.';
            }

            // Número de inventario (columna codigo, única)
            $codigos = [];
            if ($valoresEquipo['codigo'] === '') {
                $erroresEquipo['codigo'] = 'Escribe el número de inventario.';
            } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $valoresEquipo['codigo'])) {
                $erroresEquipo['codigo'] = 'Usa solo letras, números y guiones, sin espacios (ej. EQ-006).';
            } elseif (!isset($erroresEquipo['cantidad'])) {
                $codigos = codigosConsecutivos($valoresEquipo['codigo'], $cantidad);
                if ($codigos === null) {
                    $erroresEquipo['codigo'] = 'Para registrar varias unidades, el número de inventario debe terminar en número (ej. EQ-006).';
                    $codigos = [];
                } elseif (max(array_map('strlen', $codigos)) > 20) {
                    $erroresEquipo['codigo'] = 'El número de inventario no puede superar los 20 caracteres.';
                } else {
                    $marcadores = implode(',', array_fill(0, count($codigos), '?'));
                    $stmtOcupados = $pdo->prepare("SELECT codigo FROM equipo WHERE codigo IN ({$marcadores}) AND id_equipo <> ? ORDER BY codigo ASC");
                    $stmtOcupados->execute([...$codigos, $valoresEquipo['id_equipo']]);
                    $ocupados = $stmtOcupados->fetchAll(PDO::FETCH_COLUMN);
                    if ($ocupados) {
                        $erroresEquipo['codigo'] = count($codigos) === 1
                            ? "El número de inventario {$ocupados[0]} ya está registrado."
                            : 'Se necesitan los números ' . $codigos[0] . ' a ' . end($codigos) . ', pero ' . implode(', ', $ocupados) . (count($ocupados) === 1 ? ' ya está registrado.' : ' ya están registrados.');
                    }
                }
            }

            // Especificaciones y ubicación (opcionales)
            if (mb_strlen($valoresEquipo['descripcion'], 'UTF-8') > 500) {
                $erroresEquipo['descripcion'] = 'Las especificaciones no pueden superar los 500 caracteres.';
            }
            if (mb_strlen($valoresEquipo['ubicacion'], 'UTF-8') > 100) {
                $erroresEquipo['ubicacion'] = 'La ubicación no puede superar los 100 caracteres.';
            }

            // Estado: Disponible (visibilidad Activo) o Dado de baja (visibilidad Deshabilitado)
            if (!in_array($valoresEquipo['visibilidad'], ['Activo', 'Deshabilitado'], true)) {
                $erroresEquipo['visibilidad'] = 'Selecciona el estado del equipo.';
                $valoresEquipo['visibilidad'] = 'Activo';
            } elseif ($esEdicion && $valoresEquipo['visibilidad'] === 'Deshabilitado' && $equipoActual['estado'] === 'Prestado') {
                $erroresEquipo['visibilidad'] = 'Este equipo está prestado. Registra su devolución antes de darlo de baja.';
            }

            // Imagen (opcional): JPG o PNG de máximo 5 MB
            $imagen    = $_FILES['imagen'] ?? null;
            $imagenExt = null;
            if ($imagen && $imagen['error'] !== UPLOAD_ERR_NO_FILE) {
                if (in_array($imagen['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $imagen['size'] > IMAGEN_MAX_BYTES) {
                    $erroresEquipo['imagen'] = 'La imagen supera el tamaño máximo de 5 MB.';
                } elseif ($imagen['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($imagen['tmp_name'])) {
                    $erroresEquipo['imagen'] = 'No se pudo subir la imagen. Intenta seleccionarla de nuevo.';
                } else {
                    $imagenExt = detectarTipoArchivo($imagen['tmp_name']);
                    if (!in_array($imagenExt, ['jpg', 'png'], true)) {
                        $erroresEquipo['imagen'] = 'Solo se permiten imágenes JPG o PNG.';
                        $imagenExt = null;
                    }
                }
                // El navegador no conserva el archivo si la página se vuelve a mostrar con errores
                if (!empty($erroresEquipo) && !isset($erroresEquipo['imagen'])) {
                    $pedirImagenDeNuevo = true;
                }
            }

            if (empty($erroresEquipo)) {
                $rutaImagenGuardada = null;
                $rutaImagenBd       = null;

                try {
                    if ($imagenExt !== null) {
                        $carpeta = __DIR__ . '/' . CARPETA_IMAGENES;
                        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
                            throw new RuntimeException('No se pudo crear la carpeta ' . CARPETA_IMAGENES);
                        }
                        $nombreArchivo      = 'equipo_' . bin2hex(random_bytes(8)) . '.' . $imagenExt;
                        $rutaImagenGuardada = $carpeta . $nombreArchivo;
                        if (!move_uploaded_file($imagen['tmp_name'], $rutaImagenGuardada)) {
                            throw new RuntimeException('No se pudo guardar la imagen del equipo.');
                        }
                        $rutaImagenBd = CARPETA_IMAGENES . $nombreArchivo;
                    }

                    $pdo->beginTransaction();

                    // Marca nueva: si ya existe con otras mayúsculas se reutiliza
                    if ($idMarca === null) {
                        $stmtMarca = $pdo->prepare("SELECT id_marca FROM marca WHERE LOWER(nombre) = LOWER(:nombre) LIMIT 1");
                        $stmtMarca->execute([':nombre' => $valoresEquipo['nueva_marca']]);
                        $idMarca = $stmtMarca->fetchColumn();
                        if ($idMarca === false) {
                            $pdo->prepare("INSERT INTO marca (nombre) VALUES (:nombre)")->execute([':nombre' => $valoresEquipo['nueva_marca']]);
                            $idMarca = $pdo->lastInsertId();
                        }
                        $idMarca = (int)$idMarca;
                    }

                    $datos = [
                        ':nombre'       => $valoresEquipo['nombre'],
                        ':descripcion'  => $valoresEquipo['descripcion'] === '' ? null : $valoresEquipo['descripcion'],
                        ':ubicacion'    => $valoresEquipo['ubicacion'] === '' ? null : $valoresEquipo['ubicacion'],
                        ':visibilidad'  => $valoresEquipo['visibilidad'],
                        ':id_marca'     => $idMarca,
                        ':id_categoria' => (int)$valoresEquipo['id_categoria'],
                    ];

                    if ($esEdicion) {
                        $imagenFinal = $equipoActual['imagen_url'];
                        if ($rutaImagenBd !== null) {
                            $imagenFinal = $rutaImagenBd;
                        } elseif ($quitarImagen) {
                            $imagenFinal = null;
                        }
                        $pdo->prepare("
                            UPDATE equipo
                            SET codigo = :codigo, nombre = :nombre, descripcion = :descripcion, imagen_url = :imagen_url,
                                ubicacion = :ubicacion, visibilidad = :visibilidad, id_marca = :id_marca, id_categoria = :id_categoria
                            WHERE id_equipo = :id_equipo
                        ")->execute($datos + [
                            ':codigo'     => $codigos[0],
                            ':imagen_url' => $imagenFinal,
                            ':id_equipo'  => $valoresEquipo['id_equipo'],
                        ]);
                    } else {
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO equipo (codigo, nombre, descripcion, imagen_url, ubicacion, estado, visibilidad, id_marca, id_categoria)
                            VALUES (:codigo, :nombre, :descripcion, :imagen_url, :ubicacion, 'Disponible', :visibilidad, :id_marca, :id_categoria)
                        ");
                        foreach ($codigos as $codigo) {
                            $stmtInsert->execute($datos + [':codigo' => $codigo, ':imagen_url' => $rutaImagenBd]);
                        }
                    }

                    $pdo->commit();

                    // La imagen anterior se borra solo si ya ningún equipo la usa
                    if ($esEdicion && !empty($equipoActual['imagen_url']) && $equipoActual['imagen_url'] !== $imagenFinal) {
                        borrarImagenSinUso($pdo, $equipoActual['imagen_url']);
                    }

                    if ($esEdicion) {
                        $_SESSION['flash_inventario'] = "Se guardaron los cambios de «{$valoresEquipo['nombre']}» ({$codigos[0]}).";
                    } elseif (count($codigos) === 1) {
                        $_SESSION['flash_inventario'] = "Se agregó «{$valoresEquipo['nombre']}» ({$codigos[0]}) al inventario.";
                    } else {
                        $_SESSION['flash_inventario'] = 'Se agregaron ' . count($codigos) . " unidades de «{$valoresEquipo['nombre']}» ({$codigos[0]} a " . end($codigos) . ').';
                    }
                    header('Location: ' . urlInventario(['tab' => null, 'pagina' => null]));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($rutaImagenGuardada !== null && is_file($rutaImagenGuardada)) {
                        unlink($rutaImagenGuardada);
                    }
                    error_log('inventario.php — error al guardar el equipo: ' . $e->getMessage());
                    $mensajeError = 'No pudimos guardar el equipo. Intenta de nuevo en unos minutos.';
                    $pedirImagenDeNuevo = ($imagenExt !== null);
                }
            }

            $abrirModalEquipo = true;
        }

        // ---------- Eliminar un equipo sin préstamos ----------
        if ($accion === 'eliminar_equipo') {
            $idEquipo = (int)($_POST['id_equipo'] ?? 0);
            $stmtEquipo = $pdo->prepare("
                SELECT e.nombre, e.codigo, e.imagen_url,
                       (SELECT COUNT(*) FROM prestamo p WHERE p.id_equipo = e.id_equipo) AS total_prestamos
                FROM equipo e
                WHERE e.id_equipo = :id
                LIMIT 1
            ");
            $stmtEquipo->execute([':id' => $idEquipo]);
            $equipoEliminar = $stmtEquipo->fetch();

            if (!$equipoEliminar) {
                $_SESSION['flash_inventario_error'] = 'El equipo que intentas eliminar ya no existe.';
            } elseif ((int)$equipoEliminar['total_prestamos'] > 0) {
                $_SESSION['flash_inventario_error'] = "«{$equipoEliminar['nombre']}» ({$equipoEliminar['codigo']}) tiene préstamos registrados y no se puede eliminar. Dalo de baja para conservar su historial.";
            } else {
                $pdo->prepare("DELETE FROM equipo WHERE id_equipo = :id")->execute([':id' => $idEquipo]);
                borrarImagenSinUso($pdo, $equipoEliminar['imagen_url']);
                $_SESSION['flash_inventario'] = "Se eliminó «{$equipoEliminar['nombre']}» ({$equipoEliminar['codigo']}) del inventario.";
            }
            header('Location: ' . urlInventario());
            exit;
        }

        // ---------- Dar de baja un equipo (conserva su historial) ----------
        if ($accion === 'dar_baja_equipo') {
            $idEquipo = (int)($_POST['id_equipo'] ?? 0);
            $stmtEquipo = $pdo->prepare("SELECT nombre, codigo, estado, visibilidad FROM equipo WHERE id_equipo = :id LIMIT 1");
            $stmtEquipo->execute([':id' => $idEquipo]);
            $equipoBaja = $stmtEquipo->fetch();

            if (!$equipoBaja) {
                $_SESSION['flash_inventario_error'] = 'El equipo que intentas dar de baja ya no existe.';
            } elseif ($equipoBaja['visibilidad'] !== 'Activo') {
                $_SESSION['flash_inventario_error'] = "«{$equipoBaja['nombre']}» ({$equipoBaja['codigo']}) ya estaba dado de baja.";
            } elseif ($equipoBaja['estado'] === 'Prestado') {
                $_SESSION['flash_inventario_error'] = "«{$equipoBaja['nombre']}» ({$equipoBaja['codigo']}) está prestado. Registra su devolución antes de darlo de baja.";
            } else {
                $pdo->prepare("UPDATE equipo SET visibilidad = 'Deshabilitado' WHERE id_equipo = :id")->execute([':id' => $idEquipo]);
                $_SESSION['flash_inventario'] = "«{$equipoBaja['nombre']}» ({$equipoBaja['codigo']}) se dio de baja. Ya no aparece en el inventario de los usuarios y su historial se conserva.";
            }
            header('Location: ' . urlInventario());
            exit;
        }

        // ---------- Registrar la devolución de un préstamo ----------
        if ($accion === 'registrar_devolucion') {
            $idPrestamo    = (int)($_POST['id_prestamo'] ?? 0);
            $observaciones = trim($_POST['observaciones'] ?? '');

            $stmtPrestamo = $pdo->prepare("
                SELECT p.folio, p.estado, p.id_equipo, e.nombre AS equipo
                FROM prestamo p
                INNER JOIN equipo e ON e.id_equipo = p.id_equipo
                WHERE p.id_prestamo = :id
                LIMIT 1
            ");
            $stmtPrestamo->execute([':id' => $idPrestamo]);
            $prestamo = $stmtPrestamo->fetch();

            if (!$prestamo) {
                $_SESSION['flash_inventario_error'] = 'El préstamo que intentas actualizar ya no existe.';
            } elseif ($prestamo['estado'] !== 'Prestado') {
                $_SESSION['flash_inventario_error'] = "El préstamo {$prestamo['folio']} ya no está activo, por eso no se registró la devolución.";
            } elseif (mb_strlen($observaciones, 'UTF-8') > 500) {
                $_SESSION['flash_inventario_error'] = 'Las observaciones no pueden superar los 500 caracteres.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("
                    UPDATE prestamo
                    SET estado = 'Devuelto', fecha_devolucion = :fecha, observaciones_devolucion = :observaciones
                    WHERE id_prestamo = :id
                ")->execute([
                    ':fecha'         => $ahora,
                    ':observaciones' => $observaciones === '' ? null : $observaciones,
                    ':id'            => $idPrestamo,
                ]);
                $pdo->prepare("UPDATE equipo SET estado = 'Disponible' WHERE id_equipo = :id")->execute([':id' => $prestamo['id_equipo']]);
                $pdo->commit();
                $_SESSION['flash_inventario'] = "Se registró la devolución del préstamo {$prestamo['folio']}. «{$prestamo['equipo']}» vuelve a estar disponible.";
            }
            header('Location: ' . urlInventario());
            exit;
        }

        // ---------- Solicitudes de la app móvil: entregar o rechazar ----------
        if ($accion === 'entregar_prestamo' || $accion === 'rechazar_prestamo') {
            $idPrestamo = (int)($_POST['id_prestamo'] ?? 0);

            $stmtPrestamo = $pdo->prepare("
                SELECT p.folio, p.estado, p.id_equipo, e.nombre AS equipo, e.estado AS equipo_estado, e.visibilidad AS equipo_visibilidad
                FROM prestamo p
                INNER JOIN equipo e ON e.id_equipo = p.id_equipo
                WHERE p.id_prestamo = :id
                LIMIT 1
            ");
            $stmtPrestamo->execute([':id' => $idPrestamo]);
            $prestamo = $stmtPrestamo->fetch();

            if (!$prestamo) {
                $_SESSION['flash_inventario_error'] = 'La solicitud de préstamo ya no existe.';
            } elseif ($prestamo['estado'] !== 'Pendiente') {
                $_SESSION['flash_inventario_error'] = "La solicitud {$prestamo['folio']} ya fue atendida.";
            } elseif ($accion === 'rechazar_prestamo') {
                $pdo->prepare("UPDATE prestamo SET estado = 'Cancelado' WHERE id_prestamo = :id")->execute([':id' => $idPrestamo]);
                $_SESSION['flash_inventario'] = "Se rechazó la solicitud {$prestamo['folio']}. El usuario la verá como «Rechazada».";
            } elseif ($prestamo['equipo_visibilidad'] !== 'Activo' || $prestamo['equipo_estado'] !== 'Disponible') {
                $_SESSION['flash_inventario_error'] = "«{$prestamo['equipo']}» no está disponible en este momento, por eso no se puede entregar la solicitud {$prestamo['folio']}.";
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE prestamo SET estado = 'Prestado', fecha_entrega = :fecha WHERE id_prestamo = :id")
                    ->execute([':fecha' => $ahora, ':id' => $idPrestamo]);
                $pdo->prepare("UPDATE equipo SET estado = 'Prestado' WHERE id_equipo = :id")->execute([':id' => $prestamo['id_equipo']]);
                $pdo->commit();
                $_SESSION['flash_inventario'] = "Se entregó «{$prestamo['equipo']}» (préstamo {$prestamo['folio']}).";
            }
            header('Location: ' . urlInventario());
            exit;
        }
    }

    // ---------- Resumen del inventario y de los préstamos ----------
    $resumenEquipos = $pdo->query("
        SELECT
            SUM(CASE WHEN visibilidad = 'Activo' THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN visibilidad = 'Activo' AND estado = 'Disponible' THEN 1 ELSE 0 END) AS disponibles,
            SUM(CASE WHEN visibilidad = 'Activo' AND estado = 'Prestado' THEN 1 ELSE 0 END) AS prestados,
            SUM(CASE WHEN visibilidad <> 'Activo' THEN 1 ELSE 0 END) AS bajas
        FROM equipo
    ")->fetch();
    $totalActivos     = (int)$resumenEquipos['activos'];
    $totalDisponibles = (int)$resumenEquipos['disponibles'];
    $totalPrestados   = (int)$resumenEquipos['prestados'];
    $totalBajas       = (int)$resumenEquipos['bajas'];

    $stmtResumenPrestamos = $pdo->prepare("
        SELECT
            SUM(CASE WHEN estado = 'Prestado' THEN 1 ELSE 0 END) AS abiertos,
            SUM(CASE WHEN estado = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN estado = 'Devuelto' THEN 1 ELSE 0 END) AS devueltos,
            SUM(CASE WHEN estado = 'Prestado' AND fecha_limite < :ahora THEN 1 ELSE 0 END) AS vencidos
        FROM prestamo
    ");
    $stmtResumenPrestamos->execute([':ahora' => $ahora]);
    $resumenPrestamos = $stmtResumenPrestamos->fetch();
    $totalAbiertos   = (int)$resumenPrestamos['abiertos'];
    $totalPendientes = (int)$resumenPrestamos['pendientes'];
    $totalDevueltos  = (int)$resumenPrestamos['devueltos'];
    $totalVencidos   = (int)$resumenPrestamos['vencidos'];

    $codigoSugerido = codigoSugerido($pdo);

    $where  = [];
    $params = [];

    if ($tabActiva === 'equipos') {
        // ---------- Pestaña Equipos ----------
        $filtroTexto     = trim($_GET['q'] ?? '');
        $filtroCategoria = in_array((int)($_GET['categoria'] ?? 0), $idsCategorias, true) ? (int)$_GET['categoria'] : 0;
        $filtroEstado    = in_array($_GET['estado'] ?? '', ['disponible', 'prestado', 'baja'], true) ? $_GET['estado'] : '';

        condicionesBusqueda($filtroTexto, ['e.codigo', 'e.nombre', 'e.descripcion', 'm.nombre'], $where, $params);
        if ($filtroCategoria > 0) {
            $where[] = 'e.id_categoria = :categoria';
            $params[':categoria'] = $filtroCategoria;
        }
        $where[] = match ($filtroEstado) {
            'disponible' => "e.visibilidad = 'Activo' AND e.estado = 'Disponible'",
            'prestado'   => "e.visibilidad = 'Activo' AND e.estado = 'Prestado'",
            'baja'       => "e.visibilidad <> 'Activo'",
            default      => '1 = 1',
        };
        $hayFiltros = ($filtroTexto !== '' || $filtroCategoria > 0 || $filtroEstado !== '');

        $sqlDesde = "
            FROM equipo e
            INNER JOIN marca m ON m.id_marca = e.id_marca
            INNER JOIN categoria c ON c.id_categoria = e.id_categoria
            WHERE " . implode(' AND ', $where);
        $sqlEquipos = "
            SELECT e.id_equipo, e.codigo, e.nombre, e.descripcion, e.imagen_url, e.ubicacion, e.estado, e.visibilidad,
                   e.id_marca, e.id_categoria, e.fecha_registro, m.nombre AS marca, c.nombre AS categoria
            {$sqlDesde}
            ORDER BY CASE WHEN e.visibilidad = 'Activo' THEN 0 ELSE 1 END, e.codigo ASC";

        if ($exportar) {
            $stmtExportar = $pdo->prepare($sqlEquipos);
            $stmtExportar->execute($params);
            $filas = array_map(fn($eq) => [
                $eq['codigo'], $eq['nombre'], $eq['categoria'], $eq['marca'], $eq['ubicacion'] ?? '',
                $eq['descripcion'] ?? '', estadoEquipo($eq['estado'], $eq['visibilidad'])['texto'], fechaTabla($eq['fecha_registro']),
            ], $stmtExportar->fetchAll());
            enviarCsv('inventario_equipos_' . date('Y-m-d') . '.csv',
                ['No. de inventario', 'Equipo', 'Categoría', 'Marca', 'Ubicación', 'Especificaciones', 'Estado', 'Fecha de registro'], $filas);
        }

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) {$sqlDesde}");
        $stmtTotal->execute($params);
        $totalRegistros = (int)$stmtTotal->fetchColumn();
        $totalPaginas   = max(1, (int)ceil($totalRegistros / REGISTROS_POR_PAGINA));
        $paginaActual   = min(max(1, (int)($_GET['pagina'] ?? 1)), $totalPaginas);
        $desplazamiento = ($paginaActual - 1) * REGISTROS_POR_PAGINA;
        $equiposAdmin   = consultarPagina($pdo, $sqlEquipos, $params, REGISTROS_POR_PAGINA, $desplazamiento);

        // Historial de préstamos de los equipos de esta página (panel "Ver historial")
        $detalleEquipos = [];
        foreach ($equiposAdmin as $eq) {
            $estadoEq = estadoEquipo($eq['estado'], $eq['visibilidad']);
            $detalleEquipos[(int)$eq['id_equipo']] = [
                'codigo'      => $eq['codigo'],
                'nombre'      => $eq['nombre'],
                'categoria'   => $eq['categoria'],
                'marca'       => $eq['marca'],
                'ubicacion'   => $eq['ubicacion'] ?? '',
                'descripcion' => $eq['descripcion'] ?? '',
                'imagen'      => $eq['imagen_url'] ?? '',
                'registro'    => fechaCortaEs($eq['fecha_registro']),
                'estado'      => $estadoEq['texto'],
                'claseEstado' => $estadoEq['clase'],
                'prestamos'   => [],
            ];
        }
        if ($detalleEquipos) {
            $ids = array_keys($detalleEquipos);
            $stmtHistorial = $pdo->prepare("
                SELECT p.id_equipo, p.folio, p.fecha_inicio, p.fecha_limite, p.fecha_entrega, p.fecha_devolucion,
                       p.estado, p.observaciones_devolucion, u.nombres, u.apellidos, u.correo
                FROM prestamo p
                INNER JOIN usuario u ON u.id_usuario = p.id_cliente
                WHERE p.id_equipo IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                ORDER BY p.fecha_registro DESC, p.id_prestamo DESC
            ");
            $stmtHistorial->execute($ids);
            foreach ($stmtHistorial->fetchAll() as $pr) {
                $estadoPr = estadoPrestamo($pr['estado'], $pr['fecha_limite'], $ahoraTs);
                $detalleEquipos[(int)$pr['id_equipo']]['prestamos'][] = [
                    'folio'         => $pr['folio'],
                    'usuario'       => trim($pr['nombres'] . ' ' . $pr['apellidos']),
                    'correo'        => $pr['correo'],
                    'salida'        => fechaTabla($pr['fecha_entrega'] ?: $pr['fecha_inicio'], true),
                    'limite'        => fechaTabla($pr['fecha_limite'], true),
                    'devolucion'    => $pr['fecha_devolucion'] ? fechaTabla($pr['fecha_devolucion'], true) : '',
                    'observaciones' => $pr['observaciones_devolucion'] ?? '',
                    'estado'        => $estadoPr['texto'],
                    'claseEstado'   => $estadoPr['clase'],
                ];
            }
        }
    } else {
        // ---------- Pestañas Préstamos y Devoluciones ----------
        $filtroTexto = trim($_GET['q'] ?? '');
        condicionesBusqueda($filtroTexto, ['p.folio', 'u.nombres', 'u.apellidos', 'u.correo', 'e.nombre', 'e.codigo'], $where, $params);

        if ($tabActiva === 'prestamos') {
            $filtroEstado = in_array($_GET['estado'] ?? '', ['pendiente', 'activo', 'por-vencer', 'vencido', 'devuelto', 'cancelado'], true) ? $_GET['estado'] : '';
            $filtroVence  = in_array($_GET['vence'] ?? '', ['hoy', 'semana', 'mes'], true) ? $_GET['vence'] : '';
            $limitePorVencer = date('Y-m-d H:i:s', $ahoraTs + HORAS_POR_VENCER * 3600);

            switch ($filtroEstado) {
                case 'pendiente':
                    $where[] = "p.estado = 'Pendiente'";
                    break;
                case 'activo':
                    $where[] = "p.estado = 'Prestado' AND p.fecha_limite > :f_por_vencer";
                    $params[':f_por_vencer'] = $limitePorVencer;
                    break;
                case 'por-vencer':
                    $where[] = "p.estado = 'Prestado' AND p.fecha_limite >= :f_ahora AND p.fecha_limite <= :f_por_vencer";
                    $params[':f_ahora'] = $ahora;
                    $params[':f_por_vencer'] = $limitePorVencer;
                    break;
                case 'vencido':
                    $where[] = "p.estado = 'Prestado' AND p.fecha_limite < :f_ahora";
                    $params[':f_ahora'] = $ahora;
                    break;
                case 'devuelto':
                    $where[] = "p.estado = 'Devuelto'";
                    break;
                case 'cancelado':
                    $where[] = "p.estado = 'Cancelado'";
                    break;
            }

            if ($filtroVence !== '') {
                $hoy = date('Y-m-d 00:00:00', $ahoraTs);
                [$desde, $hasta] = match ($filtroVence) {
                    'hoy'    => [$hoy, date('Y-m-d 00:00:00', strtotime('+1 day', $ahoraTs))],
                    'semana' => [$hoy, date('Y-m-d 00:00:00', strtotime('+8 days', $ahoraTs))],
                    'mes'    => [date('Y-m-01 00:00:00', $ahoraTs), date('Y-m-01 00:00:00', strtotime('first day of next month', $ahoraTs))],
                };
                $where[] = 'p.fecha_limite >= :vence_desde AND p.fecha_limite < :vence_hasta';
                $params[':vence_desde'] = $desde;
                $params[':vence_hasta'] = $hasta;
            }
            $hayFiltros = ($filtroTexto !== '' || $filtroEstado !== '' || $filtroVence !== '');

            // Primero lo que requiere atención: vencidos, solicitudes pendientes y por vencer
            $sqlOrden = "
                ORDER BY CASE
                    WHEN p.estado = 'Prestado' AND p.fecha_limite < :orden_ahora THEN 0
                    WHEN p.estado = 'Pendiente' THEN 1
                    WHEN p.estado = 'Prestado' AND p.fecha_limite <= :orden_por_vencer THEN 2
                    WHEN p.estado = 'Prestado' THEN 3
                    WHEN p.estado = 'Devuelto' THEN 4
                    ELSE 5
                END, p.fecha_limite ASC, p.id_prestamo DESC";
            $paramsOrden = [':orden_ahora' => $ahora, ':orden_por_vencer' => $limitePorVencer];
        } else {
            $filtroEntrega = in_array($_GET['entrega'] ?? '', ['a-tiempo', 'con-retraso'], true) ? $_GET['entrega'] : '';
            $where[] = "p.estado = 'Devuelto'";
            if ($filtroEntrega === 'a-tiempo') {
                $where[] = 'p.fecha_devolucion <= p.fecha_limite';
            } elseif ($filtroEntrega === 'con-retraso') {
                $where[] = 'p.fecha_devolucion > p.fecha_limite';
            }
            $hayFiltros = ($filtroTexto !== '' || $filtroEntrega !== '');
            $sqlOrden    = 'ORDER BY p.fecha_devolucion DESC, p.id_prestamo DESC';
            $paramsOrden = [];
        }

        $sqlDesde = "
            FROM prestamo p
            INNER JOIN usuario u ON u.id_usuario = p.id_cliente
            INNER JOIN equipo e ON e.id_equipo = p.id_equipo
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '');
        $sqlPrestamos = "
            SELECT p.id_prestamo, p.folio, p.fecha_inicio, p.fecha_limite, p.fecha_entrega, p.fecha_devolucion,
                   p.motivo, p.estado, p.observaciones_devolucion,
                   u.nombres, u.apellidos, u.correo,
                   e.nombre AS equipo, e.codigo AS equipo_codigo
            {$sqlDesde}
            {$sqlOrden}";

        if ($exportar) {
            $stmtExportar = $pdo->prepare($sqlPrestamos);
            $stmtExportar->execute($params + $paramsOrden);
            $prestamosExportar = $stmtExportar->fetchAll();
            if ($tabActiva === 'prestamos') {
                $filas = array_map(fn($pr) => [
                    $pr['folio'], trim($pr['nombres'] . ' ' . $pr['apellidos']), $pr['correo'], $pr['equipo_codigo'], $pr['equipo'],
                    fechaTabla($pr['fecha_entrega'] ?: $pr['fecha_inicio'], true), fechaTabla($pr['fecha_limite'], true),
                    estadoPrestamo($pr['estado'], $pr['fecha_limite'], $ahoraTs)['texto'], $pr['motivo'] ?? '',
                ], $prestamosExportar);
                enviarCsv('inventario_prestamos_' . date('Y-m-d') . '.csv',
                    ['Folio', 'Usuario', 'Correo', 'No. de inventario', 'Equipo', 'Fecha de salida', 'Fecha de devolución', 'Estado', 'Motivo'], $filas);
            } else {
                $filas = array_map(fn($pr) => [
                    $pr['folio'], trim($pr['nombres'] . ' ' . $pr['apellidos']), $pr['correo'], $pr['equipo_codigo'], $pr['equipo'],
                    fechaTabla($pr['fecha_entrega'] ?: $pr['fecha_inicio'], true), fechaTabla($pr['fecha_limite'], true),
                    fechaTabla($pr['fecha_devolucion'], true),
                    strtotime($pr['fecha_devolucion']) > strtotime($pr['fecha_limite']) ? 'Con retraso' : 'A tiempo',
                    $pr['observaciones_devolucion'] ?? '',
                ], $prestamosExportar);
                enviarCsv('inventario_devoluciones_' . date('Y-m-d') . '.csv',
                    ['Folio', 'Usuario', 'Correo', 'No. de inventario', 'Equipo', 'Fecha de salida', 'Fecha límite', 'Devuelto el', 'Entrega', 'Observaciones'], $filas);
            }
        }

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) {$sqlDesde}");
        $stmtTotal->execute($params);
        $totalRegistros = (int)$stmtTotal->fetchColumn();
        $totalPaginas   = max(1, (int)ceil($totalRegistros / REGISTROS_POR_PAGINA));
        $paginaActual   = min(max(1, (int)($_GET['pagina'] ?? 1)), $totalPaginas);
        $desplazamiento = ($paginaActual - 1) * REGISTROS_POR_PAGINA;
        $prestamosAdmin = consultarPagina($pdo, $sqlPrestamos, $params + $paramsOrden, REGISTROS_POR_PAGINA, $desplazamiento);
    }
}

// =====================================================================
// VISTA DE CLIENTE: catálogo informativo (los préstamos se solicitan desde la app móvil)
// =====================================================================
if (!$esAdmin) {
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
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $esAdmin ? 'Control de inventario' : 'Inventario de equipos' ?> — Capsule Tech</title>

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
  <?php if ($esAdmin): ?>
  <!-- Estilos de las vistas de administrador (tarjetas, pestañas, tablas y modales) -->
  <link rel="stylesheet" href="styles/servicios.css" />
  <?php endif; ?>
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
              <a class="nav-link nav-pill-link active" aria-current="page" href="inventario.php">Inventario</a>
            </li>
            <li class="nav-item">
              <a class="nav-link nav-pill-link" href="acerca_de.php">Acerca de</a>
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
                <a class="btn btn-card-alt px-3" style="height: 40px;" href="login.php?redirect=inventario.php">Iniciar sesión</a>
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
  <?php if ($esAdmin): ?>
    <!-- ================= ADMINISTRADOR: TÍTULO, RESUMEN, AVISOS Y PESTAÑAS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-control-inventario">
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

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
          <div>
            <h1 id="titulo-control-inventario" class="page-title">Control de inventario</h1>
            <p class="page-subtitle mb-0">
              <?= $totalActivos ?> equipo<?= $totalActivos === 1 ? '' : 's' ?> activo<?= $totalActivos === 1 ? '' : 's' ?>.
              Las bajas se conservan en el historial y no cuentan en el total.
            </p>
          </div>
          <div class="d-flex flex-column flex-sm-row gap-2 flex-shrink-0">
            <a
              href="<?= htmlspecialchars(urlInventario(['exportar' => 'csv', 'pagina' => null])) ?>"
              class="btn btn-card-alt admin-secondary-btn"
              title="Descarga en CSV la tabla de la pestaña actual con sus filtros"
            >
              Exportar
            </a>
            <button
              type="button"
              class="btn btn-card-loan admin-primary-btn"
              data-bs-toggle="modal"
              data-bs-target="#modalEquipo"
              data-modo="nuevo"
            >
              <i class="bi bi-plus-lg me-1"></i> Agregar equipo
            </button>
          </div>
        </div>

        <!-- Resumen del inventario (desde BD) -->
        <div class="row g-3">
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card is-highlighted h-100">
              <span class="admin-stat-label">Total de equipos</span>
              <strong class="admin-stat-value"><?= $totalActivos ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Disponibles</span>
              <strong class="admin-stat-value"><?= $totalDisponibles ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">En préstamo</span>
              <strong class="admin-stat-value"><?= $totalPrestados ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Dados de baja</span>
              <strong class="admin-stat-value"><?= $totalBajas ?></strong>
            </div>
          </div>
        </div>

        <!-- Avisos: préstamos vencidos y solicitudes de la app móvil -->
        <?php if ($totalVencidos > 0 || $totalPendientes > 0): ?>
          <div class="d-flex flex-column gap-2 mt-4">
            <?php if ($totalVencidos > 0): ?>
              <a href="inventario.php?tab=prestamos&amp;estado=vencido" class="inventory-alert inventory-alert-danger">
                <span class="d-flex align-items-center gap-3">
                  <i class="bi bi-exclamation-triangle inventory-alert-icon" aria-hidden="true"></i>
                  <span class="inventory-alert-title">
                    <?= $totalVencidos ?> préstamo<?= $totalVencidos === 1 ? '' : 's' ?> vencido<?= $totalVencidos === 1 ? '' : 's' ?>
                  </span>
                </span>
                <span class="inventory-alert-link">Revisar préstamos <i class="bi bi-arrow-right"></i></span>
              </a>
            <?php endif; ?>
            <?php if ($totalPendientes > 0): ?>
              <a href="inventario.php?tab=prestamos&amp;estado=pendiente" class="inventory-alert inventory-alert-info">
                <span class="d-flex align-items-center gap-3">
                  <i class="bi bi-phone inventory-alert-icon" aria-hidden="true"></i>
                  <span class="inventory-alert-title">
                    <?= $totalPendientes ?> solicitud<?= $totalPendientes === 1 ? '' : 'es' ?> de préstamo por entregar
                  </span>
                </span>
                <span class="inventory-alert-link">Revisar solicitudes <i class="bi bi-arrow-right"></i></span>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Pestañas -->
        <nav class="admin-tabs mt-4" aria-label="Secciones del control de inventario">
          <a href="inventario.php" class="admin-tab <?= $tabActiva === 'equipos' ? 'active' : '' ?>" <?= $tabActiva === 'equipos' ? 'aria-current="page"' : '' ?>>Equipos</a>
          <a href="inventario.php?tab=prestamos" class="admin-tab <?= $tabActiva === 'prestamos' ? 'active' : '' ?>" <?= $tabActiva === 'prestamos' ? 'aria-current="page"' : '' ?>>Préstamos</a>
          <a href="inventario.php?tab=devoluciones" class="admin-tab <?= $tabActiva === 'devoluciones' ? 'active' : '' ?>" <?= $tabActiva === 'devoluciones' ? 'aria-current="page"' : '' ?>>Devoluciones</a>
        </nav>
      </div>
    </section>

    <section class="pb-5 bg-white" aria-label="<?= $tabActiva === 'equipos' ? 'Equipos' : ($tabActiva === 'prestamos' ? 'Préstamos' : 'Devoluciones') ?>">
      <div class="container">

        <?php if ($tabActiva === 'equipos'): ?>
          <!-- ================= PESTAÑA: EQUIPOS ================= -->
          <form action="inventario.php" method="GET" class="row g-3 align-items-end" role="search">
            <div class="col-lg-6">
              <label for="filtro-q" class="admin-filter-label">Buscar equipos</label>
              <div class="search-input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input
                  type="search"
                  id="filtro-q"
                  name="q"
                  value="<?= htmlspecialchars($filtroTexto) ?>"
                  class="form-control search-input"
                  placeholder="Busca por equipo, inventario o marca"
                  maxlength="100"
                />
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <label for="filtro-categoria" class="admin-filter-label">Categoría</label>
              <select id="filtro-categoria" name="categoria" class="form-select admin-select js-autoenviar">
                <option value="">Todas las categorías</option>
                <?php foreach ($categoriasEquipo as $cat): ?>
                  <option value="<?= (int)$cat['id_categoria'] ?>" <?= $filtroCategoria === (int)$cat['id_categoria'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6 col-lg-3">
              <label for="filtro-estado" class="admin-filter-label">Estado</label>
              <select id="filtro-estado" name="estado" class="form-select admin-select js-autoenviar">
                <option value="">Todos los estados</option>
                <option value="disponible" <?= $filtroEstado === 'disponible' ? 'selected' : '' ?>>Disponible</option>
                <option value="prestado" <?= $filtroEstado === 'prestado' ? 'selected' : '' ?>>Prestado</option>
                <option value="baja" <?= $filtroEstado === 'baja' ? 'selected' : '' ?>>Dado de baja</option>
              </select>
            </div>
            <noscript>
              <div class="col-12"><button type="submit" class="btn btn-card-alt px-4">Aplicar filtros</button></div>
            </noscript>
          </form>

          <?php if ($hayFiltros): ?>
            <div class="mt-3">
              <a href="inventario.php" class="btn-ver-todas"><i class="bi bi-x-circle me-1"></i>Quitar filtros</a>
            </div>
          <?php endif; ?>

          <div class="admin-table-card mt-3">
            <div class="table-responsive">
              <table class="table admin-table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">No. de inventario</th>
                    <th scope="col">Equipo</th>
                    <th scope="col">Categoría</th>
                    <th scope="col">Marca</th>
                    <th scope="col" class="text-center">Unidades<br />(disponibles/total)</th>
                    <th scope="col">Estado</th>
                    <th scope="col" class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($equiposAdmin) === 0): ?>
                    <tr>
                      <td colspan="7" class="text-center py-5 admin-empty">
                        <?= $hayFiltros ? 'No hay equipos que coincidan con los filtros.' : 'Todavía no hay equipos registrados. Agrega el primero con «Agregar equipo».' ?>
                      </td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($equiposAdmin as $eq): ?>
                    <?php
                      $idEquipo    = (int)$eq['id_equipo'];
                      $estadoEq    = estadoEquipo($eq['estado'], $eq['visibilidad']);
                      $disponibles = ($eq['visibilidad'] === 'Activo' && $eq['estado'] === 'Disponible') ? 1 : 0;
                      $datosEquipo = [
                          'id_equipo'    => $idEquipo,
                          'nombre'       => $eq['nombre'],
                          'id_categoria' => (string)$eq['id_categoria'],
                          'id_marca'     => (string)$eq['id_marca'],
                          'codigo'       => $eq['codigo'],
                          'descripcion'  => $eq['descripcion'] ?? '',
                          'ubicacion'    => $eq['ubicacion'] ?? '',
                          'visibilidad'  => $eq['visibilidad'],
                          'estado'       => $eq['estado'],
                          'imagen'       => $eq['imagen_url'] ?? '',
                      ];
                    ?>
                    <tr>
                      <td class="fw-semibold admin-folio"><?= htmlspecialchars($eq['codigo']) ?></td>
                      <td><?= htmlspecialchars($eq['nombre']) ?></td>
                      <td><?= htmlspecialchars($eq['categoria']) ?></td>
                      <td><?= htmlspecialchars($eq['marca']) ?></td>
                      <td class="text-center admin-units"><?= $disponibles ?> / 1</td>
                      <td>
                        <span class="status-pill status-pill-sm <?= $estadoEq['clase'] ?>"><?= $estadoEq['texto'] ?></span>
                      </td>
                      <td>
                        <div class="d-flex justify-content-center gap-1">
                          <button
                            type="button"
                            class="table-action"
                            data-bs-toggle="modal"
                            data-bs-target="#modalEquipo"
                            data-modo="editar"
                            data-equipo="<?= htmlspecialchars(json_encode($datosEquipo, JSON_UNESCAPED_UNICODE)) ?>"
                          >
                            <span class="table-action-icon"><i class="bi bi-pencil"></i></span>
                            <span class="table-action-label">Editar</span>
                          </button>
                          <button type="button" class="table-action" data-bs-toggle="offcanvas" data-bs-target="#panelHistorial" data-id="<?= $idEquipo ?>">
                            <span class="table-action-icon"><i class="bi bi-clock-history"></i></span>
                            <span class="table-action-label">Ver<br />historial</span>
                          </button>
                          <button
                            type="button"
                            class="table-action table-action-danger"
                            data-bs-toggle="modal"
                            data-bs-target="#modalEliminarEquipo"
                            data-id="<?= $idEquipo ?>"
                            data-nombre="<?= htmlspecialchars($eq['nombre']) ?>"
                            data-codigo="<?= htmlspecialchars($eq['codigo']) ?>"
                            data-prestamos="<?= count($detalleEquipos[$idEquipo]['prestamos']) ?>"
                            data-estado="<?= htmlspecialchars($eq['estado']) ?>"
                            data-visibilidad="<?= htmlspecialchars($eq['visibilidad']) ?>"
                          >
                            <span class="table-action-icon"><i class="bi bi-trash3"></i></span>
                            <span class="table-action-label">Eliminar</span>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php elseif ($tabActiva === 'prestamos'): ?>
          <!-- ================= PESTAÑA: PRÉSTAMOS ================= -->
          <form action="inventario.php" method="GET" class="row g-3 align-items-end" role="search">
            <input type="hidden" name="tab" value="prestamos" />
            <div class="col-lg-6">
              <label for="filtro-q" class="admin-filter-label">Buscar préstamos</label>
              <div class="search-input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input
                  type="search"
                  id="filtro-q"
                  name="q"
                  value="<?= htmlspecialchars($filtroTexto) ?>"
                  class="form-control search-input"
                  placeholder="Busca por folio, usuario o equipo"
                  maxlength="100"
                />
              </div>
            </div>
            <div class="col-sm-6 col-lg-3">
              <label for="filtro-estado" class="admin-filter-label">Estado</label>
              <select id="filtro-estado" name="estado" class="form-select admin-select js-autoenviar">
                <option value="">Todos los estados</option>
                <?php foreach (['pendiente' => 'Pendiente', 'activo' => 'Activo', 'por-vencer' => 'Por vencer', 'vencido' => 'Vencido', 'devuelto' => 'Devuelto', 'cancelado' => 'Cancelado'] as $valor => $texto): ?>
                  <option value="<?= $valor ?>" <?= $filtroEstado === $valor ? 'selected' : '' ?>><?= $texto ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6 col-lg-3">
              <label for="filtro-vence" class="admin-filter-label">Vencimiento</label>
              <select id="filtro-vence" name="vence" class="form-select admin-select js-autoenviar">
                <option value="">Todas las fechas</option>
                <option value="hoy" <?= $filtroVence === 'hoy' ? 'selected' : '' ?>>Vence hoy</option>
                <option value="semana" <?= $filtroVence === 'semana' ? 'selected' : '' ?>>Próximos 7 días</option>
                <option value="mes" <?= $filtroVence === 'mes' ? 'selected' : '' ?>>Este mes</option>
              </select>
            </div>
            <noscript>
              <div class="col-12"><button type="submit" class="btn btn-card-alt px-4">Aplicar filtros</button></div>
            </noscript>
          </form>

          <?php if ($hayFiltros): ?>
            <div class="mt-3">
              <a href="inventario.php?tab=prestamos" class="btn-ver-todas"><i class="bi bi-x-circle me-1"></i>Quitar filtros</a>
            </div>
          <?php endif; ?>

          <div class="admin-table-card mt-3">
            <div class="table-responsive">
              <table class="table admin-table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Folio</th>
                    <th scope="col">Usuario</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Equipo</th>
                    <th scope="col">Fecha de<br />salida</th>
                    <th scope="col">Fecha de<br />devolución</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($prestamosAdmin) === 0): ?>
                    <tr>
                      <td colspan="8" class="text-center py-5 admin-empty">
                        <?= $hayFiltros ? 'No hay préstamos que coincidan con los filtros.' : 'Todavía no hay préstamos registrados. Las solicitudes llegan desde la app móvil.' ?>
                      </td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($prestamosAdmin as $pr): ?>
                    <?php
                      $idPrestamo = (int)$pr['id_prestamo'];
                      $estadoPr   = estadoPrestamo($pr['estado'], $pr['fecha_limite'], $ahoraTs);
                      $vencido    = ($estadoPr['clave'] === 'vencido');
                      $usuarioPr  = trim($pr['nombres'] . ' ' . $pr['apellidos']);
                      $salidaPr   = $pr['fecha_entrega'] ?: $pr['fecha_inicio'];
                      $diasRetraso = $vencido ? (int)ceil(($ahoraTs - strtotime($pr['fecha_limite'])) / 86400) : 0;
                      $datosPrestamo = [
                          'id_prestamo' => $idPrestamo,
                          'folio'       => $pr['folio'],
                          'usuario'     => $usuarioPr,
                          'correo'      => $pr['correo'],
                          'equipo'      => $pr['equipo'] . ' · ' . $pr['equipo_codigo'],
                          'salida'      => fechaTabla($salidaPr, true),
                          'limite'      => fechaTabla($pr['fecha_limite'], true),
                          'motivo'      => $pr['motivo'] ?? '',
                          'retraso'     => $diasRetraso,
                      ];
                    ?>
                    <tr class="<?= $vencido ? 'is-overdue' : '' ?>">
                      <td class="fw-semibold admin-folio"><?= htmlspecialchars($pr['folio']) ?></td>
                      <td title="<?= htmlspecialchars($usuarioPr) ?>"><?= htmlspecialchars(nombreCorto($pr['nombres'], $pr['apellidos'])) ?></td>
                      <td class="admin-cell-muted text-truncate admin-cell-email" title="<?= htmlspecialchars($pr['correo']) ?>"><?= htmlspecialchars($pr['correo']) ?></td>
                      <td><?= htmlspecialchars($pr['equipo']) ?></td>
                      <td class="text-nowrap <?= $vencido ? 'text-overdue' : '' ?>" title="<?= fechaTabla($salidaPr, true) ?>"><?= fechaTabla($salidaPr) ?></td>
                      <td class="text-nowrap <?= $vencido ? 'text-overdue' : '' ?>" title="<?= fechaTabla($pr['fecha_limite'], true) ?>"><?= fechaTabla($pr['fecha_limite']) ?></td>
                      <td>
                        <span class="status-pill status-pill-sm <?= $estadoPr['clase'] ?>"><?= $estadoPr['texto'] ?></span>
                      </td>
                      <td class="admin-cell-actions">
                        <?php if ($pr['estado'] === 'Prestado'): ?>
                          <button
                            type="button"
                            class="btn btn-table btn-table-block"
                            data-bs-toggle="modal"
                            data-bs-target="#modalDevolucion"
                            data-prestamo="<?= htmlspecialchars(json_encode($datosPrestamo, JSON_UNESCAPED_UNICODE)) ?>"
                          >
                            Registrar devolución
                          </button>
                        <?php elseif ($pr['estado'] === 'Pendiente'): ?>
                          <div class="d-flex gap-2">
                            <button
                              type="button"
                              class="btn btn-table flex-fill"
                              data-bs-toggle="modal"
                              data-bs-target="#modalAccionPrestamo"
                              data-accion="entregar_prestamo"
                              data-prestamo="<?= htmlspecialchars(json_encode($datosPrestamo, JSON_UNESCAPED_UNICODE)) ?>"
                            >
                              Entregar
                            </button>
                            <button
                              type="button"
                              class="btn btn-table btn-table-danger flex-fill"
                              data-bs-toggle="modal"
                              data-bs-target="#modalAccionPrestamo"
                              data-accion="rechazar_prestamo"
                              data-prestamo="<?= htmlspecialchars(json_encode($datosPrestamo, JSON_UNESCAPED_UNICODE)) ?>"
                            >
                              Rechazar
                            </button>
                          </div>
                        <?php else: ?>
                          <button type="button" class="btn btn-table btn-table-block btn-table-done" disabled>
                            <?= $pr['estado'] === 'Devuelto' ? 'Devolución registrada' : 'Solicitud rechazada' ?>
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        <?php else: ?>
          <!-- ================= PESTAÑA: DEVOLUCIONES ================= -->
          <form action="inventario.php" method="GET" class="row g-3 align-items-end" role="search">
            <input type="hidden" name="tab" value="devoluciones" />
            <div class="col-lg-8">
              <label for="filtro-q" class="admin-filter-label">Buscar devoluciones</label>
              <div class="search-input-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input
                  type="search"
                  id="filtro-q"
                  name="q"
                  value="<?= htmlspecialchars($filtroTexto) ?>"
                  class="form-control search-input"
                  placeholder="Busca por folio, usuario o equipo"
                  maxlength="100"
                />
              </div>
            </div>
            <div class="col-lg-4">
              <label for="filtro-entrega" class="admin-filter-label">Entrega</label>
              <select id="filtro-entrega" name="entrega" class="form-select admin-select js-autoenviar">
                <option value="">Todas las devoluciones</option>
                <option value="a-tiempo" <?= $filtroEntrega === 'a-tiempo' ? 'selected' : '' ?>>A tiempo</option>
                <option value="con-retraso" <?= $filtroEntrega === 'con-retraso' ? 'selected' : '' ?>>Con retraso</option>
              </select>
            </div>
            <noscript>
              <div class="col-12"><button type="submit" class="btn btn-card-alt px-4">Aplicar filtros</button></div>
            </noscript>
          </form>

          <?php if ($hayFiltros): ?>
            <div class="mt-3">
              <a href="inventario.php?tab=devoluciones" class="btn-ver-todas"><i class="bi bi-x-circle me-1"></i>Quitar filtros</a>
            </div>
          <?php endif; ?>

          <div class="admin-table-card mt-3">
            <div class="table-responsive">
              <table class="table admin-table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Folio</th>
                    <th scope="col">Usuario</th>
                    <th scope="col">Equipo</th>
                    <th scope="col">Fecha de<br />salida</th>
                    <th scope="col">Fecha<br />límite</th>
                    <th scope="col">Devuelto el</th>
                    <th scope="col">Entrega</th>
                    <th scope="col">Observaciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($prestamosAdmin) === 0): ?>
                    <tr>
                      <td colspan="8" class="text-center py-5 admin-empty">
                        <?= $hayFiltros ? 'No hay devoluciones que coincidan con los filtros.' : 'Todavía no hay devoluciones registradas.' ?>
                      </td>
                    </tr>
                  <?php endif; ?>

                  <?php foreach ($prestamosAdmin as $pr): ?>
                    <?php
                      $usuarioPr   = trim($pr['nombres'] . ' ' . $pr['apellidos']);
                      $retrasoSeg  = strtotime($pr['fecha_devolucion']) - strtotime($pr['fecha_limite']);
                      $conRetraso  = $retrasoSeg > 0;
                      $diasRetraso = $conRetraso ? (int)ceil($retrasoSeg / 86400) : 0;
                    ?>
                    <tr>
                      <td class="fw-semibold admin-folio"><?= htmlspecialchars($pr['folio']) ?></td>
                      <td title="<?= htmlspecialchars($usuarioPr . ' · ' . $pr['correo']) ?>"><?= htmlspecialchars(nombreCorto($pr['nombres'], $pr['apellidos'])) ?></td>
                      <td><?= htmlspecialchars($pr['equipo']) ?></td>
                      <td class="text-nowrap" title="<?= fechaTabla($pr['fecha_entrega'] ?: $pr['fecha_inicio'], true) ?>"><?= fechaTabla($pr['fecha_entrega'] ?: $pr['fecha_inicio']) ?></td>
                      <td class="text-nowrap" title="<?= fechaTabla($pr['fecha_limite'], true) ?>"><?= fechaTabla($pr['fecha_limite']) ?></td>
                      <td class="text-nowrap" title="<?= fechaTabla($pr['fecha_devolucion'], true) ?>"><?= fechaTabla($pr['fecha_devolucion']) ?></td>
                      <td>
                        <?php if ($conRetraso): ?>
                          <span class="status-pill status-pill-sm status-pill-vencido">
                            <?= $diasRetraso ?> día<?= $diasRetraso === 1 ? '' : 's' ?> de retraso
                          </span>
                        <?php else: ?>
                          <span class="status-pill status-pill-sm status-pill-listo">A tiempo</span>
                        <?php endif; ?>
                      </td>
                      <td class="admin-cell-muted text-truncate admin-cell-notes" title="<?= htmlspecialchars($pr['observaciones_devolucion'] ?? '') ?>">
                        <?= htmlspecialchars($pr['observaciones_devolucion'] ?: '—') ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <!-- Paginación -->
        <?php if ($totalRegistros > 0): ?>
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
            <div>
              <p class="pagination-summary mb-1">
                Mostrando <?= $desplazamiento + 1 ?>–<?= $desplazamiento + count($tabActiva === 'equipos' ? $equiposAdmin : $prestamosAdmin) ?>
                de <?= $totalRegistros ?>
                <?= $tabActiva === 'equipos' ? 'registros' : ($tabActiva === 'prestamos' ? 'préstamos' : 'devoluciones') ?>
              </p>
              <p class="pagination-note mb-0">
                <?php if ($tabActiva === 'equipos'): ?>
                  <?= $totalActivos ?> equipo<?= $totalActivos === 1 ? '' : 's' ?> activo<?= $totalActivos === 1 ? '' : 's' ?>
                  y <?= $totalBajas ?> baja<?= $totalBajas === 1 ? '' : 's' ?> histórica<?= $totalBajas === 1 ? '' : 's' ?>
                <?php else: ?>
                  Al <?= fechaCortaEs($ahora) ?> ·
                  <?= $totalAbiertos ?> abierto<?= $totalAbiertos === 1 ? '' : 's' ?>,
                  <?= $totalPendientes ?> por entregar
                  y <?= $totalDevueltos ?> devuelto<?= $totalDevueltos === 1 ? '' : 's' ?>
                <?php endif; ?>
              </p>
            </div>
            <?php if ($totalPaginas > 1): ?>
              <nav aria-label="Páginas">
                <ul class="d-flex flex-wrap gap-1 list-unstyled mb-0">
                  <li>
                    <?php if ($paginaActual > 1): ?>
                      <a class="pagination-btn d-inline-block text-decoration-none" href="<?= htmlspecialchars(urlInventario(['pagina' => $paginaActual - 1])) ?>">Anterior</a>
                    <?php else: ?>
                      <span class="pagination-btn d-inline-block disabled" aria-disabled="true">Anterior</span>
                    <?php endif; ?>
                  </li>
                  <?php foreach (paginasVisibles($paginaActual, $totalPaginas) as $pagina): ?>
                    <li>
                      <?php if ($pagina === '…'): ?>
                        <span class="pagination-btn d-inline-block disabled">…</span>
                      <?php else: ?>
                        <a
                          class="pagination-btn d-inline-block text-decoration-none <?= $pagina === $paginaActual ? 'active' : '' ?>"
                          href="<?= htmlspecialchars(urlInventario(['pagina' => $pagina === 1 ? null : $pagina])) ?>"
                          <?= $pagina === $paginaActual ? 'aria-current="page"' : '' ?>
                        ><?= $pagina ?></a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                  <li>
                    <?php if ($paginaActual < $totalPaginas): ?>
                      <a class="pagination-btn d-inline-block text-decoration-none" href="<?= htmlspecialchars(urlInventario(['pagina' => $paginaActual + 1])) ?>">Siguiente</a>
                    <?php else: ?>
                      <span class="pagination-btn d-inline-block disabled" aria-disabled="true">Siguiente</span>
                    <?php endif; ?>
                  </li>
                </ul>
              </nav>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php else: ?>
    <!-- ================= ENCABEZADO, BUSCADOR Y FILTROS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-inventario">
      <div class="container">

        <h1 id="titulo-inventario" class="page-title">Inventario de equipos</h1>
        <p class="page-subtitle mb-4">Consulta qué equipos, accesorios y artículos están disponibles o en uso</p>

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
              <a href="<?= htmlspecialchars(urlFiltro(['disponibilidad' => 'agotado'])) ?>" class="filter-pill text-decoration-none <?= $dispSeleccion === 'agotado' ? 'active' : '' ?>">En uso</a>
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

                  <!-- Estado del equipo (solo informativo, sin acciones) -->
                  <div class="mt-auto pt-2">
                    <div class="availability-label <?= $disponible ? 'text-available' : 'text-unavailable' ?> mb-2">
                      <?= $disponible ? 'Disponible' : 'En uso' ?> &middot; <?= htmlspecialchars($eq['codigo']) ?>
                    </div>
                    <div class="availability-track">
                      <div class="availability-fill" style="width: <?= $disponible ? '100' : '0' ?>%;"></div>
                    </div>
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
        </div>
      </div>
    </section>
  <?php endif; ?>
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

  <?php if ($esAdmin): ?>
  <?php $esEdicionModal = $valoresEquipo['id_equipo'] > 0; ?>
  <!-- ================= ADMINISTRADOR: MODAL "AGREGAR / EDITAR EQUIPO" ================= -->
  <div class="modal fade modal-servicio modal-equipo" id="modalEquipo" tabindex="-1" aria-labelledby="modalEquipoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <form
        id="formEquipo"
        action="<?= htmlspecialchars(urlInventario()) ?>"
        method="POST"
        enctype="multipart/form-data"
        class="modal-content service-modal border-0 shadow"
        data-codigo-sugerido="<?= htmlspecialchars($codigoSugerido) ?>"
        novalidate
      >
        <input type="hidden" name="accion" value="guardar_equipo" />
        <input type="hidden" name="id_equipo" id="equipoId" value="<?= (int)$valoresEquipo['id_equipo'] ?>" />
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= IMAGEN_MAX_BYTES ?>" />

        <div class="modal-header service-modal-header">
          <div>
            <h5 class="modal-title service-modal-title" id="modalEquipoLabel"><?= $esEdicionModal ? 'Editar equipo' : 'Agregar equipo' ?></h5>
            <p class="service-modal-subtitle mb-0" id="modalEquipoSubtitulo">
              <?= $esEdicionModal ? 'Actualiza los datos del equipo. Su historial de préstamos se conserva.' : 'Registra las características y la disponibilidad inicial del equipo.' ?>
            </p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar formulario"></button>
        </div>

        <div class="modal-body service-modal-body">
          <!-- Nombre -->
          <div class="mb-3">
            <label for="equipoNombre" class="form-label service-label">Nombre</label>
            <input
              type="text"
              id="equipoNombre"
              name="nombre"
              class="form-control service-input<?= claseCampo($erroresEquipo, 'nombre') ?>"
              minlength="3"
              maxlength="100"
              value="<?= htmlspecialchars($valoresEquipo['nombre']) ?>"
              placeholder="Ej. Monitor LED de 24 pulgadas"
              autocomplete="off"
              required
            />
            <div class="invalid-feedback" id="error-equipo-nombre">
              <?= htmlspecialchars($erroresEquipo['nombre'] ?? 'Escribe el nombre del equipo.') ?>
            </div>
          </div>

          <!-- Categoría y cantidad -->
          <div class="row g-3 mb-3">
            <div class="col-sm">
              <label for="equipoCategoria" class="form-label service-label">Categoría</label>
              <select id="equipoCategoria" name="id_categoria" class="form-select service-input<?= claseCampo($erroresEquipo, 'id_categoria') ?>" required>
                <option value="">Selecciona una categoría</option>
                <?php foreach ($categoriasEquipo as $cat): ?>
                  <option value="<?= (int)$cat['id_categoria'] ?>" <?= $valoresEquipo['id_categoria'] === (string)$cat['id_categoria'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback" id="error-equipo-id_categoria">
                <?= htmlspecialchars($erroresEquipo['id_categoria'] ?? 'Selecciona la categoría del equipo.') ?>
              </div>
            </div>
            <div class="col-sm <?= $esEdicionModal ? 'd-none' : '' ?>" id="grupoCantidad">
              <label for="equipoCantidad" class="form-label service-label">Cantidad</label>
              <input
                type="number"
                id="equipoCantidad"
                name="cantidad"
                class="form-control service-input<?= claseCampo($erroresEquipo, 'cantidad') ?>"
                min="1"
                max="<?= CANTIDAD_MAXIMA ?>"
                step="1"
                inputmode="numeric"
                value="<?= htmlspecialchars($valoresEquipo['cantidad']) ?>"
                required
              />
              <div class="invalid-feedback" id="error-equipo-cantidad">
                <?= htmlspecialchars($erroresEquipo['cantidad'] ?? 'Escribe una cantidad entre 1 y ' . CANTIDAD_MAXIMA . '.') ?>
              </div>
            </div>
          </div>

          <!-- Marca y número de inventario -->
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label for="equipoMarca" class="form-label service-label">Marca</label>
              <select id="equipoMarca" name="id_marca" class="form-select service-input<?= claseCampo($erroresEquipo, 'id_marca') ?>" required>
                <option value="">Selecciona una marca</option>
                <?php foreach ($marcas as $marca): ?>
                  <option value="<?= (int)$marca['id_marca'] ?>" <?= $valoresEquipo['id_marca'] === (string)$marca['id_marca'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($marca['nombre']) ?>
                  </option>
                <?php endforeach; ?>
                <option value="otra" <?= $valoresEquipo['id_marca'] === 'otra' ? 'selected' : '' ?>>Otra marca…</option>
              </select>
              <div class="invalid-feedback" id="error-equipo-id_marca">
                <?= htmlspecialchars($erroresEquipo['id_marca'] ?? 'Selecciona la marca del equipo.') ?>
              </div>
              <div class="mt-2 <?= $valoresEquipo['id_marca'] === 'otra' ? '' : 'd-none' ?>" id="grupoNuevaMarca">
                <input
                  type="text"
                  id="equipoNuevaMarca"
                  name="nueva_marca"
                  class="form-control service-input<?= claseCampo($erroresEquipo, 'nueva_marca') ?>"
                  maxlength="100"
                  value="<?= htmlspecialchars($valoresEquipo['nueva_marca']) ?>"
                  placeholder="Escribe la marca (ej. Dell)"
                  aria-label="Nombre de la nueva marca"
                  autocomplete="off"
                />
                <div class="invalid-feedback" id="error-equipo-nueva_marca">
                  <?= htmlspecialchars($erroresEquipo['nueva_marca'] ?? 'Escribe el nombre de la marca (de 2 a 100 caracteres).') ?>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <label for="equipoCodigo" class="form-label service-label">Número de inventario</label>
              <input
                type="text"
                id="equipoCodigo"
                name="codigo"
                class="form-control service-input<?= claseCampo($erroresEquipo, 'codigo') ?>"
                maxlength="20"
                value="<?= htmlspecialchars($valoresEquipo['codigo'] !== '' || $esEdicionModal ? $valoresEquipo['codigo'] : $codigoSugerido) ?>"
                placeholder="Ej. EQ-006"
                aria-describedby="ayudaCodigo error-equipo-codigo"
                autocomplete="off"
                required
              />
              <div class="invalid-feedback" id="error-equipo-codigo">
                <?= htmlspecialchars($erroresEquipo['codigo'] ?? 'Escribe el número de inventario.') ?>
              </div>
              <div class="form-text service-help" id="ayudaCodigo">Debe ser único. Te sugerimos el siguiente disponible.</div>
            </div>
          </div>

          <!-- Especificaciones (columna descripcion) -->
          <div class="mb-3">
            <label for="equipoDescripcion" class="form-label service-label">Especificaciones <span class="fw-normal text-muted">(opcional)</span></label>
            <textarea
              id="equipoDescripcion"
              name="descripcion"
              class="form-control service-input<?= claseCampo($erroresEquipo, 'descripcion') ?>"
              rows="3"
              maxlength="500"
              placeholder="Ej. Pantalla de 24 pulgadas · Resolución Full HD (1920 × 1080) · Conexión HDMI"
            ><?= htmlspecialchars($valoresEquipo['descripcion']) ?></textarea>
            <div class="invalid-feedback" id="error-equipo-descripcion">
              <?= htmlspecialchars($erroresEquipo['descripcion'] ?? 'Las especificaciones no pueden superar los 500 caracteres.') ?>
            </div>
            <div class="form-counter" id="contadorDescripcion">0 / 500</div>
          </div>

          <div class="row g-3">
            <!-- Estado y ubicación -->
            <div class="col-sm-6">
              <label for="equipoVisibilidad" class="form-label service-label" id="equipoEstadoLabel"><?= $esEdicionModal ? 'Estado' : 'Estado inicial' ?></label>
              <select id="equipoVisibilidad" name="visibilidad" class="form-select service-input<?= claseCampo($erroresEquipo, 'visibilidad') ?>" aria-describedby="ayudaVisibilidad" required>
                <option value="Activo" <?= $valoresEquipo['visibilidad'] === 'Activo' ? 'selected' : '' ?>>
                  <?= $valoresEquipo['estado'] === 'Prestado' ? 'Prestado' : 'Disponible' ?>
                </option>
                <option value="Deshabilitado" <?= $valoresEquipo['visibilidad'] === 'Deshabilitado' ? 'selected' : '' ?> <?= $valoresEquipo['estado'] === 'Prestado' ? 'disabled' : '' ?>>
                  Dado de baja
                </option>
              </select>
              <div class="invalid-feedback" id="error-equipo-visibilidad">
                <?= htmlspecialchars($erroresEquipo['visibilidad'] ?? 'Selecciona el estado del equipo.') ?>
              </div>
              <p class="service-help mt-2 mb-3" id="ayudaVisibilidad">El equipo se mostrará disponible al guardar el registro.</p>

              <label for="equipoUbicacion" class="form-label service-label">Ubicación <span class="fw-normal text-muted">(opcional)</span></label>
              <input
                type="text"
                id="equipoUbicacion"
                name="ubicacion"
                class="form-control service-input<?= claseCampo($erroresEquipo, 'ubicacion') ?>"
                maxlength="100"
                value="<?= htmlspecialchars($valoresEquipo['ubicacion']) ?>"
                placeholder="Ej. Almacén de Tecnología"
              />
              <div class="invalid-feedback" id="error-equipo-ubicacion">
                <?= htmlspecialchars($erroresEquipo['ubicacion'] ?? 'La ubicación no puede superar los 100 caracteres.') ?>
              </div>
            </div>

            <!-- Imagen del equipo (opcional) -->
            <div class="col-sm-6 position-relative">
              <span class="form-label service-label d-block">Imagen del equipo <span class="fw-normal text-muted">(opcional)</span></span>
              <input
                type="file"
                id="equipoImagen"
                name="imagen"
                class="file-input-hidden<?= claseCampo($erroresEquipo, 'imagen') ?>"
                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                aria-describedby="ayuda-imagen error-equipo-imagen"
              />
              <label for="equipoImagen" class="file-drop file-drop-imagen" id="zonaImagen">
                <span class="d-flex align-items-center gap-3 w-100 min-w-0">
                  <i class="bi bi-image file-drop-icon" aria-hidden="true"></i>
                  <span class="file-drop-btn" id="imagenNombre">Seleccionar imagen</span>
                </span>
                <span class="file-drop-hint" id="ayuda-imagen">JPG o PNG · Máximo 5 MB</span>
                <span class="file-drop-hint">También puedes arrastrar una imagen.</span>
              </label>
              <button type="button" class="btn btn-link file-remove-btn d-none" id="quitarImagenSeleccionada">
                <i class="bi bi-x-circle me-1"></i>Quitar imagen seleccionada
              </button>
              <div class="invalid-feedback" id="error-equipo-imagen">
                <?= htmlspecialchars($erroresEquipo['imagen'] ?? 'Solo se permiten imágenes JPG o PNG de máximo 5 MB.') ?>
              </div>
              <?php if ($pedirImagenDeNuevo): ?>
                <div class="form-text text-warning-emphasis" id="avisoImagen">
                  <i class="bi bi-info-circle me-1"></i>Vuelve a seleccionar la imagen antes de guardar.
                </div>
              <?php endif; ?>
              <div class="mt-2 <?= $valoresEquipo['imagen_actual'] !== '' ? '' : 'd-none' ?>" id="imagenActual">
                <a href="<?= htmlspecialchars($valoresEquipo['imagen_actual']) ?>" target="_blank" rel="noopener" class="footer-action-link" id="imagenActualEnlace">
                  <i class="bi bi-image me-1"></i>Ver imagen actual
                </a>
                <div class="form-check mt-1">
                  <input class="form-check-input" type="checkbox" name="quitar_imagen" value="1" id="quitarImagenActual" />
                  <label class="form-check-label service-help" for="quitarImagenActual">Quitar la imagen actual</label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer service-modal-footer">
          <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-card-loan modal-btn-submit" id="botonGuardarEquipo"><?= $esEdicionModal ? 'Guardar cambios' : 'Guardar' ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($tabActiva === 'equipos'): ?>
  <!-- ================= ADMINISTRADOR: MODAL "ELIMINAR EQUIPO" ================= -->
  <div class="modal fade" id="modalEliminarEquipo" tabindex="-1" aria-labelledby="modalEliminarEquipoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow" style="border-radius: 16px;">
        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
          <div class="d-flex align-items-center gap-3">
            <div class="card-icon-box icon-box-danger">
              <i class="bi bi-trash3"></i>
            </div>
            <h5 class="modal-title fw-bold" id="modalEliminarEquipoLabel" style="color: #153352;">Eliminar equipo</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-3">
          <p class="mb-0" style="color: #3d5d80; font-size: 0.94rem;" id="eliminarEquipoMensaje"></p>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-card-alt flex-fill" data-bs-dismiss="modal">Cancelar</button>

          <form action="<?= htmlspecialchars(urlInventario()) ?>" method="POST" class="flex-fill m-0" id="formEliminarEquipo">
            <input type="hidden" name="accion" value="eliminar_equipo" />
            <input type="hidden" name="id_equipo" id="eliminarEquipoId" value="" />
            <button type="submit" class="btn btn-danger-soft w-100">Eliminar equipo</button>
          </form>

          <form action="<?= htmlspecialchars(urlInventario()) ?>" method="POST" class="flex-fill m-0 d-none" id="formBajaEquipo">
            <input type="hidden" name="accion" value="dar_baja_equipo" />
            <input type="hidden" name="id_equipo" id="bajaEquipoId" value="" />
            <button type="submit" class="btn btn-card-loan w-100">Dar de baja</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= ADMINISTRADOR: PANEL LATERAL "HISTORIAL DEL EQUIPO" ================= -->
  <div class="offcanvas offcanvas-end panel-solicitud" tabindex="-1" id="panelHistorial" aria-labelledby="panelHistorialTitulo">
    <div class="panel-header">
      <div class="d-flex justify-content-between align-items-start gap-3">
        <h2 class="panel-title" id="panelHistorialTitulo">Historial del equipo</h2>
        <button type="button" class="btn panel-close" data-bs-dismiss="offcanvas" aria-label="Cerrar historial">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
        <span class="panel-subtitle" id="historialSubtitulo"></span>
        <span class="status-pill status-pill-sm" id="historialEstado"></span>
      </div>
    </div>

    <div class="panel-body flex-grow-1">
      <img src="" alt="" class="history-image d-none mb-3" id="historialImagen" />

      <h3 class="panel-section-title" id="historialNombre"></h3>
      <dl class="panel-data mb-3">
        <dt>Categoría</dt>
        <dd id="historialCategoria"></dd>
        <dt>Marca</dt>
        <dd id="historialMarca"></dd>
        <dt>Ubicación</dt>
        <dd id="historialUbicacion"></dd>
        <dt>Registrado</dt>
        <dd id="historialRegistro"></dd>
      </dl>

      <p class="panel-label mb-2">Especificaciones</p>
      <div class="panel-problem mb-4" id="historialDescripcion"></div>

      <h3 class="panel-section-title">
        Préstamos <span class="tab-count" id="historialTotal">0</span>
      </h3>
      <div class="d-flex flex-column gap-2" id="historialLista"></div>
    </div>

    <div class="panel-footer">
      <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="offcanvas">Cerrar</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tabActiva === 'prestamos'): ?>
  <!-- ================= ADMINISTRADOR: MODAL "REGISTRAR DEVOLUCIÓN" ================= -->
  <div class="modal fade modal-servicio" id="modalDevolucion" tabindex="-1" aria-labelledby="modalDevolucionLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form action="<?= htmlspecialchars(urlInventario()) ?>" method="POST" class="modal-content service-modal border-0 shadow" id="formDevolucion" novalidate>
        <input type="hidden" name="accion" value="registrar_devolucion" />
        <input type="hidden" name="id_prestamo" id="devolucionId" value="" />

        <div class="modal-header service-modal-header">
          <div>
            <h5 class="modal-title service-modal-title" id="modalDevolucionLabel">Registrar devolución</h5>
            <p class="service-modal-subtitle mb-0">Confirma que el equipo regresó y anota cómo se recibió.</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body service-modal-body">
          <dl class="panel-data mb-3">
            <dt>Folio</dt>
            <dd id="devolucionFolio"></dd>
            <dt>Usuario</dt>
            <dd id="devolucionUsuario"></dd>
            <dt>Equipo</dt>
            <dd id="devolucionEquipo"></dd>
            <dt>Salida</dt>
            <dd id="devolucionSalida"></dd>
            <dt>Fecha límite</dt>
            <dd id="devolucionLimite"></dd>
          </dl>
          <p class="inventory-note mb-3" id="devolucionAviso"></p>

          <label for="devolucionObservaciones" class="form-label service-label">Observaciones <span class="fw-normal text-muted">(opcional)</span></label>
          <textarea
            id="devolucionObservaciones"
            name="observaciones"
            class="form-control service-input"
            rows="3"
            maxlength="500"
            placeholder="Ej. Regresó en buen estado, con su cargador."
          ></textarea>
          <div class="form-counter" id="contadorObservaciones">0 / 500</div>
        </div>

        <div class="modal-footer service-modal-footer">
          <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-card-loan modal-btn-submit" id="botonDevolucion">Registrar devolución</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ================= ADMINISTRADOR: MODAL "ENTREGAR / RECHAZAR SOLICITUD" ================= -->
  <div class="modal fade" id="modalAccionPrestamo" tabindex="-1" aria-labelledby="accionPrestamoTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow" style="border-radius: 16px;">
        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
          <div class="d-flex align-items-center gap-3">
            <div class="card-icon-box" id="accionPrestamoIcono">
              <i class="bi bi-box-arrow-up-right"></i>
            </div>
            <h5 class="modal-title fw-bold" id="accionPrestamoTitulo" style="color: #153352;"></h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-3">
          <p class="mb-3" style="color: #3d5d80; font-size: 0.94rem;" id="accionPrestamoMensaje"></p>
          <dl class="panel-data mb-0">
            <dt>Folio</dt>
            <dd id="accionPrestamoFolio"></dd>
            <dt>Usuario</dt>
            <dd id="accionPrestamoUsuario"></dd>
            <dt>Equipo</dt>
            <dd id="accionPrestamoEquipo"></dd>
            <dt>Periodo</dt>
            <dd id="accionPrestamoPeriodo"></dd>
            <dt>Motivo</dt>
            <dd id="accionPrestamoMotivo"></dd>
          </dl>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-card-alt flex-fill" data-bs-dismiss="modal">Cancelar</button>
          <form action="<?= htmlspecialchars(urlInventario()) ?>" method="POST" class="flex-fill m-0">
            <input type="hidden" name="accion" id="accionPrestamoAccion" value="" />
            <input type="hidden" name="id_prestamo" id="accionPrestamoId" value="" />
            <button type="submit" class="btn w-100" id="accionPrestamoBoton"></button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <?php if ($esAdmin): ?>
  <script>
    // ================= ADMINISTRADOR: CONTROL DE INVENTARIO =================

    // Filtros: se aplican al cambiar la opción y no envían campos vacíos (URL más limpia)
    function quitarCamposVacios(form) {
      form.querySelectorAll('input[name], select[name]').forEach(campo => {
        if (campo.value === '') campo.disabled = true;
      });
    }
    document.querySelectorAll('form[role="search"]').forEach(form => {
      form.addEventListener('submit', () => quitarCamposVacios(form));
    });
    document.querySelectorAll('.js-autoenviar').forEach(selector => {
      selector.addEventListener('change', () => {
        quitarCamposVacios(selector.form);
        selector.form.submit();
      });
    });

    // Contador de caracteres reutilizable
    function conectarContador(campo, contador, maximo) {
      if (!campo || !contador) return () => {};
      const actualizar = () => { contador.textContent = `${campo.value.length} / ${maximo}`; };
      campo.addEventListener('input', actualizar);
      actualizar();
      return actualizar;
    }

    const texto = (id, valor) => { document.getElementById(id).textContent = valor; };

    // ---------- Modal "Agregar / Editar equipo" ----------
    const modalEquipo = document.getElementById('modalEquipo');
    const formEquipo = document.getElementById('formEquipo');
    if (modalEquipo && formEquipo) {
      const TAMANO_MAXIMO = <?= IMAGEN_MAX_BYTES ?>;
      const CANTIDAD_MAXIMA = <?= CANTIDAD_MAXIMA ?>;
      const EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png'];

      const campoId = document.getElementById('equipoId');
      const campoNombre = document.getElementById('equipoNombre');
      const campoCategoria = document.getElementById('equipoCategoria');
      const grupoCantidad = document.getElementById('grupoCantidad');
      const campoCantidad = document.getElementById('equipoCantidad');
      const campoMarca = document.getElementById('equipoMarca');
      const grupoNuevaMarca = document.getElementById('grupoNuevaMarca');
      const campoNuevaMarca = document.getElementById('equipoNuevaMarca');
      const campoCodigo = document.getElementById('equipoCodigo');
      const ayudaCodigo = document.getElementById('ayudaCodigo');
      const campoDescripcion = document.getElementById('equipoDescripcion');
      const campoVisibilidad = document.getElementById('equipoVisibilidad');
      const ayudaVisibilidad = document.getElementById('ayudaVisibilidad');
      const campoUbicacion = document.getElementById('equipoUbicacion');
      const campoImagen = document.getElementById('equipoImagen');
      const zonaImagen = document.getElementById('zonaImagen');
      const nombreImagen = document.getElementById('imagenNombre');
      const quitarImagen = document.getElementById('quitarImagenSeleccionada');
      const bloqueImagenActual = document.getElementById('imagenActual');
      const enlaceImagenActual = document.getElementById('imagenActualEnlace');
      const quitarImagenActual = document.getElementById('quitarImagenActual');
      const botonGuardar = document.getElementById('botonGuardarEquipo');
      const actualizarContadorDescripcion = conectarContador(campoDescripcion, document.getElementById('contadorDescripcion'), 500);

      // Estado del formulario (el servidor lo deja listo si lo reabre con errores)
      let esEdicion = campoId.value !== '0';
      let estadoActual = <?= json_encode($valoresEquipo['estado']) ?>;

      // Mismo cálculo que el servidor: EQ-006 × 3 → EQ-006, EQ-007, EQ-008
      function codigosConsecutivos(codigo, cantidad) {
        if (cantidad === 1) return [codigo];
        const partes = codigo.match(/^(.*?)(\d{1,15})$/);
        if (!partes) return null;
        const numero = parseInt(partes[2], 10);
        return Array.from({ length: cantidad }, (_, i) => partes[1] + String(numero + i).padStart(partes[2].length, '0'));
      }

      function cantidadActual() {
        const valor = parseInt(campoCantidad.value, 10);
        return esEdicion || isNaN(valor) || valor < 1 ? 1 : Math.min(valor, CANTIDAD_MAXIMA);
      }

      // Reglas de validación: devuelven el mensaje de error o '' si el campo es válido
      const reglas = {
        nombre: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe el nombre del equipo.';
          if (largo < 3) return 'El nombre debe tener al menos 3 caracteres.';
          if (largo > 100) return 'El nombre no puede superar los 100 caracteres.';
          return '';
        },
        id_categoria: campo => campo.value === '' ? 'Selecciona la categoría del equipo.' : '',
        cantidad: campo => {
          if (esEdicion) return '';
          const valor = campo.value.trim();
          if (!/^\d{1,3}$/.test(valor) || Number(valor) < 1 || Number(valor) > CANTIDAD_MAXIMA) {
            return campo.validity.badInput ? 'Escribe solo números enteros.' : `Escribe una cantidad entre 1 y ${CANTIDAD_MAXIMA}.`;
          }
          return '';
        },
        id_marca: campo => campo.value === '' ? 'Selecciona la marca del equipo.' : '',
        nueva_marca: campo => {
          if (campoMarca.value !== 'otra') return '';
          const largo = campo.value.trim().length;
          return (largo < 2 || largo > 100) ? 'Escribe el nombre de la marca (de 2 a 100 caracteres).' : '';
        },
        codigo: campo => {
          const valor = campo.value.trim();
          if (valor === '') return 'Escribe el número de inventario.';
          if (!/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/.test(valor)) return 'Usa solo letras, números y guiones, sin espacios (ej. EQ-006).';
          const codigos = codigosConsecutivos(valor, cantidadActual());
          if (codigos === null) return 'Para registrar varias unidades, el número de inventario debe terminar en número (ej. EQ-006).';
          if (codigos.some(codigo => codigo.length > 20)) return 'El número de inventario no puede superar los 20 caracteres.';
          return '';
        },
        descripcion: campo => campo.value.trim().length > 500 ? 'Las especificaciones no pueden superar los 500 caracteres.' : '',
        ubicacion: campo => campo.value.trim().length > 100 ? 'La ubicación no puede superar los 100 caracteres.' : '',
        imagen: campo => {
          if (campo.files.length === 0) return '';
          const archivo = campo.files[0];
          const extension = archivo.name.split('.').pop().toLowerCase();
          if (!EXTENSIONES_PERMITIDAS.includes(extension)) return 'Solo se permiten imágenes JPG o PNG.';
          if (archivo.size > TAMANO_MAXIMO) return `La imagen pesa ${(archivo.size / 1048576).toFixed(1)} MB; el máximo es 5 MB.`;
          return '';
        }
      };
      const campos = [campoNombre, campoCategoria, campoCantidad, campoMarca, campoNuevaMarca, campoCodigo, campoDescripcion, campoUbicacion, campoImagen];

      function validarCampo(campo) {
        const mensaje = reglas[campo.name](campo);
        campo.setCustomValidity(mensaje);
        const feedback = document.getElementById('error-equipo-' + campo.name);
        if (feedback && mensaje) feedback.textContent = mensaje;

        const tieneValor = campo.type === 'file' ? campo.files.length > 0 : campo.value.trim() !== '';
        campo.classList.toggle('is-invalid', mensaje !== '');
        campo.classList.toggle('is-valid', mensaje === '' && tieneValor);
        return mensaje === '';
      }

      campos.forEach(campo => {
        const evento = (campo.tagName === 'SELECT' || campo.type === 'file') ? 'change' : 'input';
        campo.addEventListener(evento, () => {
          if (campo.dataset.tocado === '1' || campo.classList.contains('is-invalid')) validarCampo(campo);
        });
        campo.addEventListener('blur', () => {
          campo.dataset.tocado = '1';
          validarCampo(campo);
        });
      });

      function limpiarValidacion() {
        campos.forEach(campo => {
          campo.classList.remove('is-invalid', 'is-valid');
          campo.setCustomValidity('');
          delete campo.dataset.tocado;
        });
        campoVisibilidad.classList.remove('is-invalid');
      }

      // "Otra marca…" muestra el campo para escribirla
      function actualizarMarca() {
        const esOtra = campoMarca.value === 'otra';
        grupoNuevaMarca.classList.toggle('d-none', !esOtra);
        if (!esOtra) {
          campoNuevaMarca.classList.remove('is-invalid', 'is-valid');
          campoNuevaMarca.setCustomValidity('');
        }
      }
      campoMarca.addEventListener('change', () => {
        actualizarMarca();
        if (campoMarca.value === 'otra') campoNuevaMarca.focus();
      });

      // Ayuda del número de inventario: muestra el rango que se va a registrar
      function actualizarAyudaCodigo() {
        const cantidad = cantidadActual();
        const codigos = codigosConsecutivos(campoCodigo.value.trim(), cantidad);
        if (esEdicion) {
          ayudaCodigo.textContent = 'Debe ser único en el inventario.';
        } else if (cantidad > 1 && codigos && campoCodigo.value.trim() !== '') {
          ayudaCodigo.textContent = `Se registrarán ${cantidad} unidades: de ${codigos[0]} a ${codigos[codigos.length - 1]}.`;
        } else {
          ayudaCodigo.textContent = 'Debe ser único. Te sugerimos el siguiente disponible.';
        }
      }
      campoCodigo.addEventListener('input', actualizarAyudaCodigo);
      campoCantidad.addEventListener('input', () => {
        actualizarAyudaCodigo();
        if (campoCodigo.dataset.tocado === '1' || campoCodigo.classList.contains('is-invalid')) validarCampo(campoCodigo);
      });

      // Estado: texto de ayuda y opciones según el equipo
      function actualizarVisibilidad() {
        const opcionActivo = campoVisibilidad.querySelector('option[value="Activo"]');
        const opcionBaja = campoVisibilidad.querySelector('option[value="Deshabilitado"]');
        const prestado = esEdicion && estadoActual === 'Prestado';
        opcionActivo.textContent = prestado ? 'Prestado' : 'Disponible';
        opcionBaja.disabled = prestado;

        if (campoVisibilidad.value === 'Deshabilitado') {
          ayudaVisibilidad.textContent = 'No aparecerá en el inventario de los usuarios. Su historial se conserva.';
        } else if (prestado) {
          ayudaVisibilidad.textContent = 'Sigue prestado hasta que registres su devolución.';
        } else {
          ayudaVisibilidad.textContent = esEdicion
            ? 'El equipo aparece disponible en el inventario.'
            : 'El equipo se mostrará disponible al guardar el registro.';
        }
      }
      campoVisibilidad.addEventListener('change', () => {
        campoVisibilidad.classList.remove('is-invalid');
        actualizarVisibilidad();
      });

      // Imagen: mostrar nombre, quitar y arrastrar y soltar
      function actualizarImagen() {
        const aviso = document.getElementById('avisoImagen');
        if (campoImagen.files.length > 0) {
          const archivo = campoImagen.files[0];
          const tamano = archivo.size < 1048576
            ? `${Math.max(1, Math.round(archivo.size / 1024))} KB`
            : `${(archivo.size / 1048576).toFixed(1)} MB`;
          nombreImagen.textContent = `${archivo.name} (${tamano})`;
          quitarImagen.classList.remove('d-none');
          if (aviso) aviso.remove();
        } else {
          nombreImagen.textContent = 'Seleccionar imagen';
          quitarImagen.classList.add('d-none');
        }
        campoImagen.dataset.tocado = '1';
        validarCampo(campoImagen);
      }
      campoImagen.addEventListener('change', actualizarImagen);

      quitarImagen.addEventListener('click', () => {
        campoImagen.value = '';
        actualizarImagen();
        campoImagen.classList.remove('is-valid');
      });

      ['dragenter', 'dragover'].forEach(tipo => {
        zonaImagen.addEventListener(tipo, evento => {
          evento.preventDefault();
          zonaImagen.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(tipo => {
        zonaImagen.addEventListener(tipo, evento => {
          evento.preventDefault();
          zonaImagen.classList.remove('is-dragover');
        });
      });
      zonaImagen.addEventListener('drop', evento => {
        if (!evento.dataTransfer || evento.dataTransfer.files.length === 0) return;
        const transferencia = new DataTransfer();
        transferencia.items.add(evento.dataTransfer.files[0]);
        campoImagen.files = transferencia.files;
        actualizarImagen();
      });

      // Llenar el formulario según el botón que lo abrió (Agregar equipo o Editar)
      modalEquipo.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return; // abierto por el servidor con errores: conservar valores
        limpiarValidacion();
        formEquipo.reset();
        campoImagen.value = '';
        quitarImagenActual.checked = false;
        const aviso = document.getElementById('avisoImagen');
        if (aviso) aviso.remove();

        const titulo = document.getElementById('modalEquipoLabel');
        const subtitulo = document.getElementById('modalEquipoSubtitulo');
        const etiquetaEstado = document.getElementById('equipoEstadoLabel');

        if (trigger.dataset.modo === 'editar') {
          const datos = JSON.parse(trigger.dataset.equipo);
          esEdicion = true;
          estadoActual = datos.estado;
          campoId.value = datos.id_equipo;
          campoNombre.value = datos.nombre;
          campoCategoria.value = datos.id_categoria;
          campoCantidad.value = '1';
          campoMarca.value = datos.id_marca;
          campoNuevaMarca.value = '';
          campoCodigo.value = datos.codigo;
          campoDescripcion.value = datos.descripcion;
          campoUbicacion.value = datos.ubicacion;
          campoVisibilidad.value = datos.visibilidad;
          bloqueImagenActual.classList.toggle('d-none', datos.imagen === '');
          enlaceImagenActual.href = datos.imagen || '#';
          titulo.textContent = 'Editar equipo';
          subtitulo.textContent = 'Actualiza los datos del equipo. Su historial de préstamos se conserva.';
          etiquetaEstado.textContent = 'Estado';
          botonGuardar.textContent = 'Guardar cambios';
        } else {
          esEdicion = false;
          estadoActual = 'Disponible';
          campoId.value = '0';
          campoNombre.value = '';
          campoCategoria.value = '';
          campoCantidad.value = '1';
          campoMarca.value = '';
          campoNuevaMarca.value = '';
          campoCodigo.value = formEquipo.dataset.codigoSugerido;
          campoDescripcion.value = '';
          campoUbicacion.value = '';
          campoVisibilidad.value = 'Activo';
          bloqueImagenActual.classList.add('d-none');
          titulo.textContent = 'Agregar equipo';
          subtitulo.textContent = 'Registra las características y la disponibilidad inicial del equipo.';
          etiquetaEstado.textContent = 'Estado inicial';
          botonGuardar.textContent = 'Guardar';
        }
        grupoCantidad.classList.toggle('d-none', esEdicion);
        actualizarMarca();
        actualizarAyudaCodigo();
        actualizarVisibilidad();
        actualizarContadorDescripcion();
        nombreImagen.textContent = 'Seleccionar imagen';
        quitarImagen.classList.add('d-none');
      });
      modalEquipo.addEventListener('shown.bs.modal', () => campoNombre.focus());

      // Mantener el foco en el campo al hacer clic en "Guardar" (evita que el botón se mueva y el clic se pierda)
      botonGuardar.addEventListener('mousedown', event => event.preventDefault());

      formEquipo.addEventListener('submit', event => {
        let primerInvalido = null;
        campos.forEach(campo => {
          campo.dataset.tocado = '1';
          if (!validarCampo(campo) && !primerInvalido) primerInvalido = campo;
        });
        if (primerInvalido) {
          event.preventDefault();
          primerInvalido.focus();
          return;
        }
        // En edición la cantidad no se envía (cada unidad se edita por separado)
        campoCantidad.disabled = esEdicion;
        botonGuardar.disabled = true;
        botonGuardar.textContent = 'Guardando…';
      });

      actualizarMarca();
      actualizarAyudaCodigo();
      actualizarVisibilidad();

      <?php if ($abrirModalEquipo): ?>
      bootstrap.Modal.getOrCreateInstance(modalEquipo).show();
      <?php endif; ?>
    }

    <?php if ($tabActiva === 'equipos'): ?>
    // ---------- Panel "Historial del equipo" ----------
    const panelHistorial = document.getElementById('panelHistorial');
    if (panelHistorial) {
      const DETALLE_EQUIPOS = <?= json_encode($detalleEquipos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

      // Crea un elemento con clase y texto (sin insertar HTML del usuario)
      function crear(etiqueta, clase, contenido) {
        const elemento = document.createElement(etiqueta);
        if (clase) elemento.className = clase;
        if (contenido !== undefined) elemento.textContent = contenido;
        return elemento;
      }

      panelHistorial.addEventListener('show.bs.offcanvas', event => {
        const trigger = event.relatedTarget;
        const equipo = trigger ? DETALLE_EQUIPOS[trigger.dataset.id] : null;
        if (!equipo) return;

        const pill = document.getElementById('historialEstado');
        pill.textContent = equipo.estado;
        pill.className = `status-pill status-pill-sm ${equipo.claseEstado}`;
        texto('historialNombre', equipo.nombre);
        texto('historialCategoria', equipo.categoria);
        texto('historialMarca', equipo.marca);
        texto('historialUbicacion', equipo.ubicacion || 'Sin ubicación registrada');
        texto('historialRegistro', equipo.registro);
        texto('historialDescripcion', equipo.descripcion || 'Sin especificaciones registradas.');
        texto('historialSubtitulo', equipo.codigo);

        const imagen = document.getElementById('historialImagen');
        imagen.classList.toggle('d-none', !equipo.imagen);
        imagen.src = equipo.imagen || '';
        imagen.alt = equipo.imagen ? `Imagen de ${equipo.nombre}` : '';

        texto('historialTotal', equipo.prestamos.length);
        const lista = document.getElementById('historialLista');
        lista.textContent = '';
        if (equipo.prestamos.length === 0) {
          lista.appendChild(crear('div', 'history-empty', 'Este equipo todavía no tiene préstamos.'));
          return;
        }
        equipo.prestamos.forEach(prestamo => {
          const item = crear('article', 'history-item');
          const encabezado = crear('div', 'd-flex justify-content-between align-items-center gap-2 mb-1');
          encabezado.appendChild(crear('strong', 'history-folio', prestamo.folio));
          encabezado.appendChild(crear('span', `status-pill status-pill-sm ${prestamo.claseEstado}`, prestamo.estado));
          item.appendChild(encabezado);
          item.appendChild(crear('p', 'history-meta mb-1', `${prestamo.usuario} · ${prestamo.correo}`));
          item.appendChild(crear('p', 'history-meta mb-0', `Salida ${prestamo.salida} · Límite ${prestamo.limite}`));
          if (prestamo.devolucion) {
            item.appendChild(crear('p', 'history-meta mb-0 mt-1', `Devuelto ${prestamo.devolucion}${prestamo.observaciones ? ' · ' + prestamo.observaciones : ''}`));
          }
          lista.appendChild(item);
        });
      });
    }

    // ---------- Modal "Eliminar equipo" ----------
    const modalEliminarEquipo = document.getElementById('modalEliminarEquipo');
    if (modalEliminarEquipo) {
      modalEliminarEquipo.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        const nombre = `«${trigger.dataset.nombre}» (${trigger.dataset.codigo})`;
        const prestamos = parseInt(trigger.dataset.prestamos, 10) || 0;
        const activo = trigger.dataset.visibilidad === 'Activo';
        const prestado = trigger.dataset.estado === 'Prestado';
        const mensaje = document.getElementById('eliminarEquipoMensaje');
        const formEliminar = document.getElementById('formEliminarEquipo');
        const formBaja = document.getElementById('formBajaEquipo');

        document.getElementById('eliminarEquipoId').value = trigger.dataset.id;
        document.getElementById('bajaEquipoId').value = trigger.dataset.id;

        if (prestamos > 0) {
          // La BD no permite borrar un equipo que ya tiene préstamos
          let detalle = 'Puedes darlo de baja para que ya no aparezca en el inventario de los usuarios.';
          if (!activo) detalle = 'Ya está dado de baja, así que los usuarios no lo ven en el inventario.';
          else if (prestado) detalle = 'Está prestado: registra su devolución antes de darlo de baja.';
          mensaje.textContent = `${nombre} tiene ${prestamos} préstamo${prestamos === 1 ? '' : 's'} en su historial, por eso no se puede eliminar. ${detalle}`;
          formEliminar.classList.add('d-none');
          formBaja.classList.toggle('d-none', !activo || prestado);
        } else {
          mensaje.textContent = `¿Seguro que quieres eliminar ${nombre}? Esta acción no se puede deshacer.`;
          formEliminar.classList.remove('d-none');
          formBaja.classList.add('d-none');
        }
      });
    }
    <?php endif; ?>

    <?php if ($tabActiva === 'prestamos'): ?>
    // ---------- Modal "Registrar devolución" ----------
    const modalDevolucion = document.getElementById('modalDevolucion');
    if (modalDevolucion) {
      const campoObservaciones = document.getElementById('devolucionObservaciones');
      const actualizarContadorObservaciones = conectarContador(campoObservaciones, document.getElementById('contadorObservaciones'), 500);
      const botonDevolucion = document.getElementById('botonDevolucion');

      modalDevolucion.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        const prestamo = JSON.parse(trigger.dataset.prestamo);
        document.getElementById('devolucionId').value = prestamo.id_prestamo;
        texto('devolucionFolio', prestamo.folio);
        texto('devolucionUsuario', `${prestamo.usuario} · ${prestamo.correo}`);
        texto('devolucionEquipo', prestamo.equipo);
        texto('devolucionSalida', prestamo.salida);
        texto('devolucionLimite', prestamo.limite);

        const aviso = document.getElementById('devolucionAviso');
        aviso.className = `inventory-note mb-3 ${prestamo.retraso > 0 ? 'is-late' : 'is-on-time'}`;
        aviso.textContent = prestamo.retraso > 0
          ? `Se registrará con ${prestamo.retraso} día${prestamo.retraso === 1 ? '' : 's'} de retraso.`
          : 'Se registrará como entregado a tiempo.';

        campoObservaciones.value = '';
        actualizarContadorObservaciones();
        botonDevolucion.disabled = false;
        botonDevolucion.textContent = 'Registrar devolución';
      });
      modalDevolucion.addEventListener('shown.bs.modal', () => campoObservaciones.focus());

      document.getElementById('formDevolucion').addEventListener('submit', () => {
        botonDevolucion.disabled = true;
        botonDevolucion.textContent = 'Registrando…';
      });
    }

    // ---------- Modal "Entregar / Rechazar solicitud" ----------
    const modalAccionPrestamo = document.getElementById('modalAccionPrestamo');
    if (modalAccionPrestamo) {
      modalAccionPrestamo.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        const prestamo = JSON.parse(trigger.dataset.prestamo);
        const entregar = trigger.dataset.accion === 'entregar_prestamo';

        document.getElementById('accionPrestamoAccion').value = trigger.dataset.accion;
        document.getElementById('accionPrestamoId').value = prestamo.id_prestamo;
        texto('accionPrestamoTitulo', entregar ? 'Entregar equipo' : 'Rechazar solicitud');
        texto('accionPrestamoMensaje', entregar
          ? 'Confirma que el usuario recibió el equipo. El préstamo quedará activo y el equipo se marcará como prestado.'
          : 'El usuario verá su solicitud como «Rechazada» en la app y en «Mis solicitudes».');
        texto('accionPrestamoFolio', prestamo.folio);
        texto('accionPrestamoUsuario', `${prestamo.usuario} · ${prestamo.correo}`);
        texto('accionPrestamoEquipo', prestamo.equipo);
        texto('accionPrestamoPeriodo', `${prestamo.salida} a ${prestamo.limite}`);
        texto('accionPrestamoMotivo', prestamo.motivo || 'Sin motivo');

        const icono = document.getElementById('accionPrestamoIcono');
        icono.className = `card-icon-box${entregar ? '' : ' icon-box-danger'}`;
        icono.innerHTML = entregar ? '<i class="bi bi-box-arrow-up-right"></i>' : '<i class="bi bi-x-circle"></i>';

        const boton = document.getElementById('accionPrestamoBoton');
        boton.className = `btn w-100 ${entregar ? 'btn-card-loan' : 'btn-danger-soft'}`;
        boton.textContent = entregar ? 'Entregar equipo' : 'Rechazar solicitud';
      });
    }
    <?php endif; ?>
  </script>
  <?php endif; ?>

</body>
</html>