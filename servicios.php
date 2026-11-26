<?php
require_once 'config/conexion.php';

$usuario = usuarioActual();
$empresa = obtenerDatosEmpresa($pdo);

// Roles (tabla rol)
const ROL_ADMINISTRADOR = 1;
const ROL_TECNICO       = 2;

// Reglas del formulario "Solicitar servicio"
const PRIORIDADES_SERVICIO = ['Baja', 'Media', 'Alta'];
const ARCHIVO_MAX_BYTES    = 10 * 1024 * 1024; // 10 MB
const CARPETA_ADJUNTOS     = 'uploads/servicios/';

// Estados de orden_servicio (mismos valores que el ENUM de la BD)
const ESTADOS_ORDEN      = ['Recibido', 'En Revisión', 'Esperando Piezas', 'Reparado', 'Entregado', 'Cancelado'];
const ESTADOS_EN_PROCESO = ['En Revisión', 'Esperando Piezas'];
const ESTADOS_RESUELTOS  = ['Reparado', 'Entregado'];

const SOLICITUDES_POR_PAGINA = 10;

// Rol real de la cuenta: el administrador conserva sus permisos y, además del menú normal,
// ve el acceso a su panel (admin/dashboard.php) en el menú de su nombre.
$esAdministrador = $usuario !== null && (int)$usuario['id_rol'] === ROL_ADMINISTRADOR;
// En el sitio público todos (también el administrador) ven el catálogo y «Solicitar servicio».
// La gestión de solicitudes y del catálogo se hace desde el panel admin/. El código de esa
// vista sigue abajo sin borrarse: para volver a mostrarla aquí, cambia false por $esAdministrador.
$esAdmin = false;

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

/**
 * Formatea una fecha de la BD como "17 nov 2026".
 */
function fechaCortaEs(string $fecha): string {
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ts = strtotime($fecha);
    return date('j', $ts) . ' ' . $meses[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/**
 * Clase de color para cada estado de orden_servicio.
 */
function claseEstadoOrden(string $estado): string {
    return match ($estado) {
        'Recibido'                        => 'status-pill-recibido',
        'En Revisión', 'Esperando Piezas' => 'status-pill-proceso',
        'Reparado', 'Entregado'           => 'status-pill-listo',
        default                           => 'status-pill-cancelado',
    };
}

/**
 * Construye una URL de servicios.php conservando los parámetros actuales.
 * Un valor null, '' o 0 quita ese parámetro. "servicio" solo se usa una vez (preseleccionar).
 */
function urlServicios(array $cambios = []): string {
    $query = array_merge($_GET, $cambios);
    unset($query['servicio']);
    $query = array_filter($query, fn($valor) => $valor !== null && $valor !== '' && $valor !== 0 && $valor !== '0');
    return 'servicios.php' . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Clase de validación de Bootstrap para un campo con error del servidor.
 */
function claseCampo(array $errores, string $campo): string {
    return isset($errores[$campo]) ? ' is-invalid' : '';
}

/**
 * Iniciales del usuario para el avatar (ej. "Admin Principal" → "AP").
 */
function inicialesUsuario(array $usuario): string {
    $iniciales = mb_substr(trim($usuario['nombres']), 0, 1, 'UTF-8') . mb_substr(trim($usuario['apellidos']), 0, 1, 'UTF-8');
    return $iniciales !== '' ? mb_strtoupper($iniciales, 'UTF-8') : 'U';
}

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

// Mensajes guardados antes de redirigir (evita repetir una acción al recargar)
$mensajeExito = $_SESSION['flash_servicio'] ?? '';
$mensajeError = $_SESSION['flash_servicio_error'] ?? '';
unset($_SESSION['flash_servicio'], $_SESSION['flash_servicio_error']);

// =====================================================================
// VISTA DE ADMINISTRADOR: solicitudes y catálogo de servicios
// =====================================================================
if ($esAdmin) {
    $vistaAdmin = (($_GET['vista'] ?? '') === 'catalogo') ? 'catalogo' : 'solicitudes';

    // Técnicos activos (para asignar solicitudes)
    $tecnicos = $pdo->query("
        SELECT id_usuario, nombres, apellidos
        FROM usuario
        WHERE id_rol = " . ROL_TECNICO . " AND estado = 'Activo'
        ORDER BY nombres ASC, apellidos ASC
    ")->fetchAll();
    $idsTecnicos = array_map('intval', array_column($tecnicos, 'id_usuario'));

    // Categorías de tipo 'Servicio' (formulario de servicio)
    $categoriasServicio = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Servicio' ORDER BY nombre ASC")->fetchAll();
    $idsCategorias = array_map('intval', array_column($categoriasServicio, 'id_categoria'));

    // Formulario "Nuevo / Editar servicio"
    $erroresServicio = [];
    $valoresServicio = [
        'id_servicio'     => 0,
        'nombre'          => '',
        'descripcion'     => '',
        'precio_estimado' => '',
        'id_categoria'    => '',
        'estado'          => 'Activo',
    ];
    $abrirModalServicio = false;
    $abrirDetalleId     = 0;
    $detalleSolicitudes = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = $_POST['accion'] ?? '';

        // ---------- Guardar cambios del detalle de una solicitud ----------
        if ($accion === 'actualizar_solicitud') {
            $idOrden      = (int)($_POST['id_orden'] ?? 0);
            $estadoNuevo  = $_POST['estado'] ?? '';
            $tecnicoTexto = trim($_POST['id_tecnico'] ?? '');
            $comentario   = trim($_POST['comentario_interno'] ?? '');

            $stmtOrden = $pdo->prepare("SELECT id_orden, folio, estado FROM orden_servicio WHERE id_orden = :id LIMIT 1");
            $stmtOrden->execute([':id' => $idOrden]);
            $orden = $stmtOrden->fetch();

            if (!$orden) {
                $mensajeError = 'La solicitud que intentas actualizar ya no existe.';
            } elseif (!in_array($estadoNuevo, ESTADOS_ORDEN, true)) {
                $mensajeError = 'Selecciona un estado válido para la solicitud.';
                $abrirDetalleId = $idOrden;
            } elseif ($tecnicoTexto !== '' && !in_array((int)$tecnicoTexto, $idsTecnicos, true)) {
                $mensajeError = 'El técnico seleccionado no está disponible. Elige otro.';
                $abrirDetalleId = $idOrden;
            } elseif (mb_strlen($comentario, 'UTF-8') > 1000) {
                $mensajeError = 'El comentario interno no puede superar los 1000 caracteres.';
                $abrirDetalleId = $idOrden;
            } else {
                // fecha_entrega guarda cuándo se resolvió la solicitud (Reparado o Entregado)
                $sqlFecha = '';
                if ($estadoNuevo !== $orden['estado']) {
                    $sqlFecha = in_array($estadoNuevo, ESTADOS_RESUELTOS, true)
                        ? ', fecha_entrega = COALESCE(fecha_entrega, CURRENT_TIMESTAMP)'
                        : ', fecha_entrega = NULL';
                }

                $stmtActualizar = $pdo->prepare("
                    UPDATE orden_servicio
                    SET id_tecnico = :id_tecnico,
                        estado = :estado,
                        comentario_interno = :comentario
                        {$sqlFecha}
                    WHERE id_orden = :id_orden
                ");
                $stmtActualizar->execute([
                    ':id_tecnico' => $tecnicoTexto === '' ? null : (int)$tecnicoTexto,
                    ':estado'     => $estadoNuevo,
                    ':comentario' => $comentario === '' ? null : $comentario,
                    ':id_orden'   => $idOrden,
                ]);

                $_SESSION['flash_servicio'] = "Se guardaron los cambios de la solicitud {$orden['folio']}.";
                header('Location: ' . urlServicios());
                exit;
            }
        }

        // ---------- Crear o editar un servicio del catálogo ----------
        if ($accion === 'guardar_servicio') {
            $valoresServicio = [
                'id_servicio'     => (int)($_POST['id_servicio'] ?? 0),
                'nombre'          => trim($_POST['nombre'] ?? ''),
                'descripcion'     => trim($_POST['descripcion'] ?? ''),
                'precio_estimado' => trim(str_replace(',', '.', $_POST['precio_estimado'] ?? '')),
                'id_categoria'    => trim($_POST['id_categoria'] ?? ''),
                'estado'          => isset($_POST['estado']) ? 'Activo' : 'Inactivo',
            ];

            // Si es edición, el servicio debe existir
            if ($valoresServicio['id_servicio'] > 0) {
                $stmtExiste = $pdo->prepare("SELECT COUNT(*) FROM servicio WHERE id_servicio = :id");
                $stmtExiste->execute([':id' => $valoresServicio['id_servicio']]);
                if ((int)$stmtExiste->fetchColumn() === 0) {
                    $mensajeError = 'El servicio que intentas editar ya no existe.';
                    $valoresServicio['id_servicio'] = 0;
                }
            }

            $largoNombre = mb_strlen($valoresServicio['nombre'], 'UTF-8');
            if ($largoNombre === 0) {
                $erroresServicio['nombre'] = 'Escribe el nombre del servicio.';
            } elseif ($largoNombre < 3) {
                $erroresServicio['nombre'] = 'El nombre debe tener al menos 3 caracteres.';
            } elseif ($largoNombre > 100) {
                $erroresServicio['nombre'] = 'El nombre no puede superar los 100 caracteres.';
            } else {
                $stmtDuplicado = $pdo->prepare("SELECT COUNT(*) FROM servicio WHERE LOWER(nombre) = LOWER(:nombre) AND id_servicio <> :id");
                $stmtDuplicado->execute([':nombre' => $valoresServicio['nombre'], ':id' => $valoresServicio['id_servicio']]);
                if ((int)$stmtDuplicado->fetchColumn() > 0) {
                    $erroresServicio['nombre'] = 'Ya existe un servicio con ese nombre.';
                }
            }

            if (!in_array((int)$valoresServicio['id_categoria'], $idsCategorias, true)) {
                $erroresServicio['id_categoria'] = 'Selecciona la categoría del servicio.';
            }

            $precio = $valoresServicio['precio_estimado'];
            if ($precio === '') {
                $erroresServicio['precio_estimado'] = 'Escribe el precio estimado.';
            } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $precio)) {
                $erroresServicio['precio_estimado'] = 'Escribe un precio válido con máximo 2 decimales (ej. 450.50).';
            }

            if (mb_strlen($valoresServicio['descripcion'], 'UTF-8') > 500) {
                $erroresServicio['descripcion'] = 'La descripción no puede superar los 500 caracteres.';
            }

            if (empty($erroresServicio) && $mensajeError === '') {
                $datos = [
                    ':nombre'       => $valoresServicio['nombre'],
                    ':descripcion'  => $valoresServicio['descripcion'] === '' ? null : $valoresServicio['descripcion'],
                    ':precio'       => $precio,
                    ':id_categoria' => (int)$valoresServicio['id_categoria'],
                    ':estado'       => $valoresServicio['estado'],
                ];

                if ($valoresServicio['id_servicio'] > 0) {
                    $datos[':id'] = $valoresServicio['id_servicio'];
                    $pdo->prepare("
                        UPDATE servicio
                        SET nombre = :nombre, descripcion = :descripcion, precio_estimado = :precio,
                            id_categoria = :id_categoria, estado = :estado
                        WHERE id_servicio = :id
                    ")->execute($datos);
                    $_SESSION['flash_servicio'] = "Se actualizó el servicio «{$valoresServicio['nombre']}».";
                } else {
                    $pdo->prepare("
                        INSERT INTO servicio (nombre, descripcion, precio_estimado, id_categoria, estado)
                        VALUES (:nombre, :descripcion, :precio, :id_categoria, :estado)
                    ")->execute($datos);
                    $_SESSION['flash_servicio'] = "Se agregó el servicio «{$valoresServicio['nombre']}» al catálogo.";
                }

                header('Location: ' . urlServicios(['vista' => 'catalogo']));
                exit;
            }

            $abrirModalServicio = true;
        }

        // ---------- Activar / desactivar un servicio ----------
        if ($accion === 'cambiar_disponibilidad') {
            $idServicio  = (int)($_POST['id_servicio'] ?? 0);
            $estadoNuevo = $_POST['estado'] ?? '';

            $stmtServicio = $pdo->prepare("SELECT nombre FROM servicio WHERE id_servicio = :id LIMIT 1");
            $stmtServicio->execute([':id' => $idServicio]);
            $nombreServicio = $stmtServicio->fetchColumn();

            if ($nombreServicio === false || !in_array($estadoNuevo, ['Activo', 'Inactivo'], true)) {
                $_SESSION['flash_servicio_error'] = 'No se pudo cambiar la disponibilidad del servicio.';
            } else {
                $pdo->prepare("UPDATE servicio SET estado = :estado WHERE id_servicio = :id")
                    ->execute([':estado' => $estadoNuevo, ':id' => $idServicio]);
                $_SESSION['flash_servicio'] = $estadoNuevo === 'Activo'
                    ? "«{$nombreServicio}» ya aparece en el catálogo para los clientes."
                    : "«{$nombreServicio}» se ocultó del catálogo. Las solicitudes que ya tenía no cambian.";
            }
            header('Location: ' . urlServicios(['vista' => 'catalogo']));
            exit;
        }

        // ---------- Eliminar un servicio sin solicitudes ----------
        if ($accion === 'eliminar_servicio') {
            $idServicio = (int)($_POST['id_servicio'] ?? 0);

            $stmtServicio = $pdo->prepare("
                SELECT s.nombre, COUNT(o.id_orden) AS total_solicitudes
                FROM servicio s
                LEFT JOIN orden_servicio o ON o.id_servicio = s.id_servicio
                WHERE s.id_servicio = :id
                GROUP BY s.id_servicio, s.nombre
            ");
            $stmtServicio->execute([':id' => $idServicio]);
            $servicioEliminar = $stmtServicio->fetch();

            if (!$servicioEliminar) {
                $_SESSION['flash_servicio_error'] = 'El servicio que intentas eliminar ya no existe.';
            } elseif ((int)$servicioEliminar['total_solicitudes'] > 0) {
                $_SESSION['flash_servicio_error'] = "«{$servicioEliminar['nombre']}» tiene solicitudes registradas y no se puede eliminar. Desactívalo para ocultarlo del catálogo.";
            } else {
                $pdo->prepare("DELETE FROM servicio WHERE id_servicio = :id")->execute([':id' => $idServicio]);
                $_SESSION['flash_servicio'] = "Se eliminó el servicio «{$servicioEliminar['nombre']}».";
            }
            header('Location: ' . urlServicios(['vista' => 'catalogo']));
            exit;
        }
    }

    // ---------- Resumen de solicitudes ----------
    $listaEnProceso = "'" . implode("','", ESTADOS_EN_PROCESO) . "'";
    $listaResueltos = "'" . implode("','", ESTADOS_RESUELTOS) . "'";

    $totalNuevas    = (int)$pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado = 'Recibido'")->fetchColumn();
    $totalEnProceso = (int)$pdo->query("SELECT COUNT(*) FROM orden_servicio WHERE estado IN ({$listaEnProceso})")->fetchColumn();

    $stmtResueltas = $pdo->prepare("
        SELECT COUNT(*) FROM orden_servicio
        WHERE estado IN ({$listaResueltos}) AND fecha_entrega >= :inicio AND fecha_entrega < :fin
    ");
    $stmtResueltas->execute([
        ':inicio' => date('Y-m-01 00:00:00'),
        ':fin'    => date('Y-m-01 00:00:00', strtotime('first day of next month')),
    ]);
    $totalResueltasMes = (int)$stmtResueltas->fetchColumn();

    // Tiempo promedio de atención: de fecha_ingreso a fecha_entrega en solicitudes resueltas
    $tiemposResueltas = $pdo->query("
        SELECT fecha_ingreso, fecha_entrega FROM orden_servicio
        WHERE estado IN ({$listaResueltos}) AND fecha_entrega IS NOT NULL
    ")->fetchAll();
    $textoTiempoPromedio = 'Sin datos';
    if (count($tiemposResueltas) > 0) {
        $segundos = 0;
        foreach ($tiemposResueltas as $fila) {
            $segundos += max(0, strtotime($fila['fecha_entrega']) - strtotime($fila['fecha_ingreso']));
        }
        $dias = $segundos / count($tiemposResueltas) / 86400;
        $textoTiempoPromedio = number_format($dias, 1) . ($dias >= 0.95 && $dias < 1.05 ? ' día' : ' días');
    }

    if ($vistaAdmin === 'solicitudes') {
        // ---------- Filtros de solicitudes ----------
        $filtroTexto     = trim($_GET['q'] ?? '');
        $filtroEstado    = in_array($_GET['estado'] ?? '', ESTADOS_ORDEN, true) ? $_GET['estado'] : '';
        $filtroServicio  = $_GET['srv'] ?? '';
        $filtroPrioridad = in_array($_GET['prioridad'] ?? '', PRIORIDADES_SERVICIO, true) ? $_GET['prioridad'] : '';

        // Todos los servicios (activos e inactivos) para el filtro
        $serviciosFiltro = $pdo->query("SELECT id_servicio, nombre FROM servicio ORDER BY nombre ASC")->fetchAll();

        $where  = [];
        $params = [];

        if ($filtroTexto !== '') {
            // Cada palabra debe aparecer en el folio, el nombre, los apellidos o el correo
            $palabras = array_slice(preg_split('/\s+/', $filtroTexto), 0, 5);
            foreach ($palabras as $i => $palabra) {
                $where[] = "(o.folio LIKE :p{$i}a OR u.nombres LIKE :p{$i}b OR u.apellidos LIKE :p{$i}c OR u.correo LIKE :p{$i}d)";
                $like = '%' . $palabra . '%';
                $params[":p{$i}a"] = $like;
                $params[":p{$i}b"] = $like;
                $params[":p{$i}c"] = $like;
                $params[":p{$i}d"] = $like;
            }
        }
        if ($filtroEstado !== '') {
            $where[] = "o.estado = :estado";
            $params[':estado'] = $filtroEstado;
        }
        if ($filtroServicio === 'general') {
            $where[] = "o.id_servicio IS NULL";
        } elseif ((int)$filtroServicio > 0) {
            $where[] = "o.id_servicio = :srv";
            $params[':srv'] = (int)$filtroServicio;
        } else {
            $filtroServicio = '';
        }
        if ($filtroPrioridad !== '') {
            $where[] = "o.prioridad = :prioridad";
            $params[':prioridad'] = $filtroPrioridad;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $hayFiltros = ($filtroTexto !== '' || $filtroEstado !== '' || $filtroServicio !== '' || $filtroPrioridad !== '');

        $stmtTotal = $pdo->prepare("
            SELECT COUNT(*)
            FROM orden_servicio o
            INNER JOIN usuario u ON u.id_usuario = o.id_cliente
            {$sqlWhere}
        ");
        $stmtTotal->execute($params);
        $totalSolicitudes = (int)$stmtTotal->fetchColumn();

        // ---------- Paginación ----------
        $totalPaginas = max(1, (int)ceil($totalSolicitudes / SOLICITUDES_POR_PAGINA));
        $paginaActual = min(max(1, (int)($_GET['pagina'] ?? 1)), $totalPaginas);
        $desplazamiento = ($paginaActual - 1) * SOLICITUDES_POR_PAGINA;

        $stmtSolicitudes = $pdo->prepare("
            SELECT
                o.id_orden, o.folio, o.equipo_receptor, o.falla_reportada, o.fecha_ingreso,
                o.estado, o.prioridad, o.archivo_adjunto, o.id_tecnico, o.comentario_interno,
                s.nombre AS servicio,
                u.nombres AS cliente_nombres, u.apellidos AS cliente_apellidos,
                u.correo AS cliente_correo, u.telefono AS cliente_telefono,
                t.nombres AS tecnico_nombres, t.apellidos AS tecnico_apellidos
            FROM orden_servicio o
            INNER JOIN usuario u ON u.id_usuario = o.id_cliente
            LEFT JOIN servicio s ON s.id_servicio = o.id_servicio
            LEFT JOIN usuario t ON t.id_usuario = o.id_tecnico
            {$sqlWhere}
            ORDER BY o.fecha_ingreso DESC, o.id_orden DESC
            LIMIT :limite OFFSET :desplazamiento
        ");
        foreach ($params as $clave => $valor) {
            $stmtSolicitudes->bindValue($clave, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmtSolicitudes->bindValue(':limite', SOLICITUDES_POR_PAGINA, PDO::PARAM_INT);
        $stmtSolicitudes->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $stmtSolicitudes->execute();
        $solicitudesAdmin = $stmtSolicitudes->fetchAll();

        // Datos de cada solicitud para el panel de detalle (se leen desde JavaScript)
        $detalleSolicitudes = [];
        foreach ($solicitudesAdmin as $sol) {
            $detalleSolicitudes[(int)$sol['id_orden']] = [
                'folio'        => $sol['folio'],
                'fecha'        => fechaCortaEs($sol['fecha_ingreso']),
                'estado'       => $sol['estado'],
                'claseEstado'  => claseEstadoOrden($sol['estado']),
                'cliente'      => trim($sol['cliente_nombres'] . ' ' . $sol['cliente_apellidos']),
                'correo'       => $sol['cliente_correo'],
                'telefono'     => $sol['cliente_telefono'],
                'equipo'       => $sol['equipo_receptor'],
                'servicio'     => $sol['servicio'] ?? 'Servicio general',
                'prioridad'    => $sol['prioridad'],
                'falla'        => $sol['falla_reportada'],
                'archivo'      => $sol['archivo_adjunto'],
                'idTecnico'    => $sol['id_tecnico'] !== null ? (string)$sol['id_tecnico'] : '',
                'comentario'   => $sol['comentario_interno'] ?? '',
            ];
        }
    } else {
        // ---------- Catálogo de servicios ----------
        $catalogoTexto = trim($_GET['cq'] ?? '');
        $catalogoDisp  = in_array($_GET['disp'] ?? '', ['Activo', 'Inactivo'], true) ? $_GET['disp'] : '';

        $where  = [];
        $params = [];
        if ($catalogoTexto !== '') {
            $where[] = "(s.nombre LIKE :cq1 OR s.descripcion LIKE :cq2)";
            $params[':cq1'] = '%' . $catalogoTexto . '%';
            $params[':cq2'] = '%' . $catalogoTexto . '%';
        }
        if ($catalogoDisp !== '') {
            $where[] = "s.estado = :disp";
            $params[':disp'] = $catalogoDisp;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCatalogoAdmin = $pdo->prepare("
            SELECT
                s.id_servicio, s.nombre, s.descripcion, s.precio_estimado, s.id_categoria, s.estado,
                c.nombre AS categoria,
                COUNT(o.id_orden) AS total_solicitudes
            FROM servicio s
            INNER JOIN categoria c ON c.id_categoria = s.id_categoria
            LEFT JOIN orden_servicio o ON o.id_servicio = s.id_servicio
            {$sqlWhere}
            GROUP BY s.id_servicio, s.nombre, s.descripcion, s.precio_estimado, s.id_categoria, s.estado, c.nombre
            ORDER BY s.nombre ASC
        ");
        $stmtCatalogoAdmin->execute($params);
        $catalogoAdmin = $stmtCatalogoAdmin->fetchAll();

        $resumenCatalogo = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN estado = 'Activo' THEN 1 ELSE 0 END) AS activos
            FROM servicio
        ")->fetch();
        $totalServicios    = (int)$resumenCatalogo['total'];
        $totalActivos      = (int)$resumenCatalogo['activos'];
        $totalInactivos    = $totalServicios - $totalActivos;
    }
}

// =====================================================================
// VISTA DE CLIENTE: catálogo y formulario "Solicitar servicio"
// (el seguimiento de las solicitudes está en mis_solicitudes.php)
// =====================================================================
if (!$esAdmin) {
    // Servicios activos (para el selector del formulario y para validar)
    $serviciosActivos = $pdo->query("
        SELECT id_servicio, nombre
        FROM servicio
        WHERE estado = 'Activo'
        ORDER BY nombre ASC
    ")->fetchAll();
    $idsServiciosActivos = array_map('intval', array_column($serviciosActivos, 'id_servicio'));

    $erroresSolicitud = [];
    $valoresSolicitud = [
        'id_servicio'     => '',
        'falla_reportada' => '',
        'equipo_receptor' => '',
        'prioridad'       => 'Media',
    ];
    $abrirModalSolicitud = false;
    $pedirArchivoDeNuevo = false;

    // Si el archivo supera post_max_size, PHP descarta todo el formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $mensajeError = 'El archivo es demasiado grande. El tamaño máximo permitido es de 10 MB.';
    }

    // Procesar la solicitud de servicio SOLO si el usuario está logueado
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'solicitar_servicio') {
        if (!$usuario) {
            header('Location: login.php?redirect=servicios.php');
            exit;
        }

        $valoresSolicitud['id_servicio']     = trim($_POST['id_servicio'] ?? '');
        $valoresSolicitud['falla_reportada'] = trim($_POST['falla_reportada'] ?? '');
        $valoresSolicitud['equipo_receptor'] = trim($_POST['equipo_receptor'] ?? '');
        $valoresSolicitud['prioridad']       = $_POST['prioridad'] ?? '';

        // Servicio: uno del catálogo activo u "otro" (se guarda como NULL)
        $idServicio = null;
        if ($valoresSolicitud['id_servicio'] === '') {
            $erroresSolicitud['id_servicio'] = 'Selecciona el servicio que necesitas.';
        } elseif ($valoresSolicitud['id_servicio'] !== 'otro') {
            $idServicio = (int)$valoresSolicitud['id_servicio'];
            if (!in_array($idServicio, $idsServiciosActivos, true)) {
                $erroresSolicitud['id_servicio'] = 'El servicio seleccionado ya no está disponible. Elige otro.';
            }
        }

        // Descripción del problema: 10 a 1000 caracteres
        $largoFalla = mb_strlen($valoresSolicitud['falla_reportada'], 'UTF-8');
        if ($largoFalla === 0) {
            $erroresSolicitud['falla_reportada'] = 'Describe el problema para que podamos ayudarte.';
        } elseif ($largoFalla < 10) {
            $erroresSolicitud['falla_reportada'] = 'Agrega más detalle: escribe al menos 10 caracteres.';
        } elseif ($largoFalla > 1000) {
            $erroresSolicitud['falla_reportada'] = 'La descripción no puede superar los 1000 caracteres.';
        }

        // Equipo: 3 a 100 caracteres (columna equipo_receptor)
        $largoEquipo = mb_strlen($valoresSolicitud['equipo_receptor'], 'UTF-8');
        if ($largoEquipo === 0) {
            $erroresSolicitud['equipo_receptor'] = 'Indica el equipo que vamos a revisar.';
        } elseif ($largoEquipo < 3) {
            $erroresSolicitud['equipo_receptor'] = 'Escribe al menos 3 caracteres (por ejemplo, la marca y el modelo).';
        } elseif ($largoEquipo > 100) {
            $erroresSolicitud['equipo_receptor'] = 'El nombre del equipo no puede superar los 100 caracteres.';
        }

        // Prioridad
        if (!in_array($valoresSolicitud['prioridad'], PRIORIDADES_SERVICIO, true)) {
            $erroresSolicitud['prioridad'] = 'Selecciona la prioridad de tu solicitud.';
            $valoresSolicitud['prioridad'] = 'Media';
        }

        // Archivo adjunto (opcional): PDF, JPG o PNG de máximo 10 MB
        $archivo    = $_FILES['archivo'] ?? null;
        $archivoExt = null;
        if ($archivo && $archivo['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $archivo['size'] > ARCHIVO_MAX_BYTES) {
                $erroresSolicitud['archivo'] = 'El archivo supera el tamaño máximo de 10 MB.';
            } elseif ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                $erroresSolicitud['archivo'] = 'No se pudo subir el archivo. Intenta seleccionarlo de nuevo.';
            } else {
                $archivoExt = detectarTipoArchivo($archivo['tmp_name']);
                if ($archivoExt === null) {
                    $erroresSolicitud['archivo'] = 'Solo se permiten archivos PDF, JPG o PNG.';
                }
            }
            // El navegador no conserva el archivo si la página se vuelve a mostrar con errores
            if (!empty($erroresSolicitud) && !isset($erroresSolicitud['archivo'])) {
                $pedirArchivoDeNuevo = true;
            }
        }

        if (empty($erroresSolicitud)) {
            $rutaArchivoGuardado = null;
            $rutaArchivoBd       = null;

            try {
                if ($archivoExt !== null) {
                    $carpeta = __DIR__ . '/' . CARPETA_ADJUNTOS;
                    if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
                        throw new RuntimeException('No se pudo crear la carpeta ' . CARPETA_ADJUNTOS);
                    }
                    $nombreArchivo       = 'orden_' . bin2hex(random_bytes(8)) . '.' . $archivoExt;
                    $rutaArchivoGuardado = $carpeta . $nombreArchivo;
                    if (!move_uploaded_file($archivo['tmp_name'], $rutaArchivoGuardado)) {
                        throw new RuntimeException('No se pudo guardar el archivo adjunto.');
                    }
                    $rutaArchivoBd = CARPETA_ADJUNTOS . $nombreArchivo;
                }

                $pdo->beginTransaction();

                // Folio temporal único mientras se obtiene el id de la orden
                $stmtInsert = $pdo->prepare("
                    INSERT INTO orden_servicio (folio, id_cliente, id_servicio, equipo_receptor, falla_reportada, prioridad, archivo_adjunto)
                    VALUES (:folio, :id_cliente, :id_servicio, :equipo_receptor, :falla_reportada, :prioridad, :archivo_adjunto)
                ");
                $stmtInsert->execute([
                    ':folio'           => 'TMP-' . bin2hex(random_bytes(8)),
                    ':id_cliente'      => $usuario['id_usuario'],
                    ':id_servicio'     => $idServicio,
                    ':equipo_receptor' => $valoresSolicitud['equipo_receptor'],
                    ':falla_reportada' => $valoresSolicitud['falla_reportada'],
                    ':prioridad'       => $valoresSolicitud['prioridad'],
                    ':archivo_adjunto' => $rutaArchivoBd,
                ]);

                // Folio definitivo con el mismo formato que los registros existentes: TKT-AAAA-NNN
                $idOrden = (int)$pdo->lastInsertId();
                $folio   = sprintf('TKT-%s-%03d', date('Y'), $idOrden);
                $stmtFolio = $pdo->prepare("UPDATE orden_servicio SET folio = :folio WHERE id_orden = :id_orden");
                $stmtFolio->execute([':folio' => $folio, ':id_orden' => $idOrden]);

                $pdo->commit();

                $_SESSION['flash_mis_solicitudes'] = "Solicitud de servicio registrada con el folio {$folio}. Aquí puedes darle seguimiento.";
                header('Location: mis_solicitudes.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($rutaArchivoGuardado !== null && is_file($rutaArchivoGuardado)) {
                    unlink($rutaArchivoGuardado);
                }
                error_log('servicios.php — error al registrar la solicitud: ' . $e->getMessage());
                $mensajeError = 'No pudimos registrar tu solicitud. Intenta de nuevo en unos minutos.';
                $pedirArchivoDeNuevo = ($archivoExt !== null);
            }
        }

        $abrirModalSolicitud = true;
    }

    // Abrir el formulario con un servicio ya elegido (por ejemplo, desde el inicio: servicios.php?servicio=2)
    $servicioDesdeUrl = (int)($_GET['servicio'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $servicioDesdeUrl > 0 && in_array($servicioDesdeUrl, $idsServiciosActivos, true)) {
        if ($usuario) {
            $valoresSolicitud['id_servicio'] = (string)$servicioDesdeUrl;
            $abrirModalSolicitud = true;
        }
    }
    $abrirModalSesion = (!$usuario && $servicioDesdeUrl > 0);

    // Parámetros de búsqueda y filtro
    $busqueda     = trim($_GET['q'] ?? '');
    $catSeleccion = (int)($_GET['categoria'] ?? 0);

    // Categorías de tipo 'Servicio' desde BD
    $categoriasServicio = $pdo->query("SELECT id_categoria, nombre FROM categoria WHERE tipo = 'Servicio' ORDER BY id_categoria ASC")->fetchAll();

    // Catálogo de servicios activos con su número de solicitudes
    $where  = ["s.estado = 'Activo'"];
    $params = [];

    if ($busqueda !== '') {
        $where[] = "(s.nombre LIKE :q1 OR s.descripcion LIKE :q2 OR c.nombre LIKE :q3)";
        $like = '%' . $busqueda . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
    }

    if ($catSeleccion > 0) {
        $where[] = "s.id_categoria = :cat";
        $params[':cat'] = $catSeleccion;
    }

    $sqlCatalogo = "
        SELECT
            s.id_servicio,
            s.nombre,
            s.descripcion,
            s.precio_estimado,
            c.nombre AS categoria,
            COUNT(o.id_orden) AS total_solicitudes
        FROM servicio s
        INNER JOIN categoria c ON s.id_categoria = c.id_categoria
        LEFT JOIN orden_servicio o ON o.id_servicio = s.id_servicio
        WHERE " . implode(' AND ', $where) . "
        GROUP BY s.id_servicio, s.nombre, s.descripcion, s.precio_estimado, c.nombre
        ORDER BY total_solicitudes DESC, s.id_servicio ASC
    ";
    $stmtCatalogo = $pdo->prepare($sqlCatalogo);
    $stmtCatalogo->execute($params);
    $servicios = $stmtCatalogo->fetchAll();

    // Servicio con más solicitudes (insignia "Más solicitado"), sin importar el filtro aplicado
    $idMasSolicitado = $pdo->query("
        SELECT o.id_servicio
        FROM orden_servicio o
        INNER JOIN servicio s ON s.id_servicio = o.id_servicio
        WHERE s.estado = 'Activo'
        GROUP BY o.id_servicio
        ORDER BY COUNT(*) DESC, o.id_servicio ASC
        LIMIT 1
    ")->fetchColumn();
    $idMasSolicitado = ($idMasSolicitado !== false) ? (int)$idMasSolicitado : 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Servicios — Capsule Tech</title>

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- Estilos compartidos (navbar, buscador, filtros, tarjetas y footer) -->
  <link rel="stylesheet" href="styles/inventario.css" />
  <!-- Estilos propios de Servicios -->
  <link rel="stylesheet" href="styles/servicios.css" />
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
              <a class="nav-link nav-pill-link active" aria-current="page" href="servicios.php">Servicios</a>
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
                <a class="btn btn-card-alt px-3" style="height: 40px;" href="login.php?redirect=servicios.php">Iniciar sesión</a>
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
    <!-- ================= ADMINISTRADOR: TÍTULO, RESUMEN Y PESTAÑAS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-gestion-servicios">
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
            <h1 id="titulo-gestion-servicios" class="page-title">Gestión de servicios</h1>
            <p class="page-subtitle mb-0">Gestiona solicitudes, asigna técnicos y organiza tu catálogo.</p>
          </div>
          <button
            type="button"
            class="btn btn-card-loan admin-primary-btn flex-shrink-0"
            data-bs-toggle="modal"
            data-bs-target="#modalServicioAdmin"
            data-modo="nuevo"
          >
            <i class="bi bi-plus-lg me-1"></i> Nuevo servicio
          </button>
        </div>

        <!-- Resumen de solicitudes (desde BD) -->
        <div class="row g-3">
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card is-highlighted h-100">
              <span class="admin-stat-label">Solicitudes nuevas</span>
              <strong class="admin-stat-value"><?= $totalNuevas ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">En proceso</span>
              <strong class="admin-stat-value"><?= $totalEnProceso ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Resueltas este mes</span>
              <strong class="admin-stat-value"><?= $totalResueltasMes ?></strong>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="admin-stat-card h-100">
              <span class="admin-stat-label">Tiempo promedio de atención</span>
              <strong class="admin-stat-value"><?= htmlspecialchars($textoTiempoPromedio) ?></strong>
            </div>
          </div>
        </div>

        <!-- Pestañas -->
        <nav class="admin-tabs mt-4" aria-label="Secciones de gestión de servicios">
          <a
            href="servicios.php"
            class="admin-tab <?= $vistaAdmin === 'solicitudes' ? 'active' : '' ?>"
            <?= $vistaAdmin === 'solicitudes' ? 'aria-current="page"' : '' ?>
          >Solicitudes</a>
          <a
            href="servicios.php?vista=catalogo"
            class="admin-tab <?= $vistaAdmin === 'catalogo' ? 'active' : '' ?>"
            <?= $vistaAdmin === 'catalogo' ? 'aria-current="page"' : '' ?>
          >Catálogo de servicios</a>
        </nav>
      </div>
    </section>

    <?php if ($vistaAdmin === 'solicitudes'): ?>
    <!-- ================= ADMINISTRADOR: SOLICITUDES ================= -->
    <section class="pb-5 bg-white" aria-label="Solicitudes de servicio">
      <div class="container">
        <!-- Filtros -->
        <form action="servicios.php" method="GET" class="row g-3 align-items-end" id="filtrosSolicitudes" role="search">
          <div class="col-lg-5">
            <label for="filtro-q" class="admin-filter-label">Buscar solicitudes</label>
            <div class="search-input-wrapper">
              <i class="bi bi-search search-icon"></i>
              <input
                type="search"
                id="filtro-q"
                name="q"
                value="<?= htmlspecialchars($filtroTexto) ?>"
                class="form-control search-input"
                placeholder="Buscar por folio, nombre o correo"
                maxlength="100"
              />
            </div>
          </div>
          <div class="col-sm-4 col-lg">
            <label for="filtro-estado" class="admin-filter-label">Estado</label>
            <select id="filtro-estado" name="estado" class="form-select admin-select js-autoenviar">
              <option value="">Todos los estados</option>
              <?php foreach (ESTADOS_ORDEN as $estado): ?>
                <option value="<?= htmlspecialchars($estado) ?>" <?= $filtroEstado === $estado ? 'selected' : '' ?>><?= htmlspecialchars($estado) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4 col-lg">
            <label for="filtro-servicio" class="admin-filter-label">Servicio</label>
            <select id="filtro-servicio" name="srv" class="form-select admin-select js-autoenviar">
              <option value="">Todos los servicios</option>
              <?php foreach ($serviciosFiltro as $opcion): ?>
                <option value="<?= (int)$opcion['id_servicio'] ?>" <?= $filtroServicio === (string)$opcion['id_servicio'] ? 'selected' : '' ?>><?= htmlspecialchars($opcion['nombre']) ?></option>
              <?php endforeach; ?>
              <option value="general" <?= $filtroServicio === 'general' ? 'selected' : '' ?>>Servicio general</option>
            </select>
          </div>
          <div class="col-sm-4 col-lg">
            <label for="filtro-prioridad" class="admin-filter-label">Prioridad</label>
            <select id="filtro-prioridad" name="prioridad" class="form-select admin-select js-autoenviar">
              <option value="">Todas las prioridades</option>
              <?php foreach (PRIORIDADES_SERVICIO as $prioridad): ?>
                <option value="<?= $prioridad ?>" <?= $filtroPrioridad === $prioridad ? 'selected' : '' ?>><?= $prioridad ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <noscript>
            <div class="col-12"><button type="submit" class="btn btn-card-alt px-4">Aplicar filtros</button></div>
          </noscript>
        </form>

        <?php if ($hayFiltros): ?>
          <div class="mt-3">
            <a href="servicios.php" class="btn-ver-todas"><i class="bi bi-x-circle me-1"></i>Quitar filtros</a>
          </div>
        <?php endif; ?>

        <!-- Tabla de solicitudes (desde BD) -->
        <div class="admin-table-card mt-3">
          <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Folio</th>
                  <th scope="col">Solicitante</th>
                  <th scope="col">Correo</th>
                  <th scope="col">Servicio</th>
                  <th scope="col">Fecha</th>
                  <th scope="col">Prioridad</th>
                  <th scope="col">Técnico asignado</th>
                  <th scope="col">Estado</th>
                  <th scope="col" class="text-center">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($solicitudesAdmin) === 0): ?>
                  <tr>
                    <td colspan="9" class="text-center py-5 admin-empty">
                      <?= $hayFiltros ? 'No hay solicitudes que coincidan con los filtros.' : 'Todavía no hay solicitudes de servicio registradas.' ?>
                    </td>
                  </tr>
                <?php endif; ?>

                <?php foreach ($solicitudesAdmin as $sol): ?>
                  <?php
                    $idOrden = (int)$sol['id_orden'];
                    $tecnicoNombre = $sol['id_tecnico'] !== null
                        ? trim($sol['tecnico_nombres'] . ' ' . $sol['tecnico_apellidos'])
                        : '';
                  ?>
                  <tr data-fila-id="<?= $idOrden ?>">
                    <td class="fw-semibold admin-folio"><?= htmlspecialchars($sol['folio']) ?></td>
                    <td><?= htmlspecialchars(trim($sol['cliente_nombres'] . ' ' . $sol['cliente_apellidos'])) ?></td>
                    <td class="admin-cell-muted text-truncate admin-cell-email" title="<?= htmlspecialchars($sol['cliente_correo']) ?>">
                      <?= htmlspecialchars($sol['cliente_correo']) ?>
                    </td>
                    <td><?= htmlspecialchars($sol['servicio'] ?? 'Servicio general') ?></td>
                    <td class="text-nowrap"><?= date('d/m/y', strtotime($sol['fecha_ingreso'])) ?></td>
                    <td><?= htmlspecialchars($sol['prioridad']) ?></td>
                    <td class="<?= $tecnicoNombre === '' ? 'admin-cell-muted' : '' ?>">
                      <?= $tecnicoNombre === '' ? 'Sin asignar' : htmlspecialchars($tecnicoNombre) ?>
                    </td>
                    <td>
                      <span class="status-pill status-pill-sm <?= claseEstadoOrden($sol['estado']) ?>">
                        <?= htmlspecialchars($sol['estado']) ?>
                      </span>
                    </td>
                    <td>
                      <div class="d-flex justify-content-center gap-1">
                        <button type="button" class="table-action" data-bs-toggle="offcanvas" data-bs-target="#panelSolicitud" data-id="<?= $idOrden ?>" data-enfoque="ver">
                          <span class="table-action-icon"><i class="bi bi-eye"></i></span>
                          <span class="table-action-label">Ver</span>
                        </button>
                        <button type="button" class="table-action" data-bs-toggle="offcanvas" data-bs-target="#panelSolicitud" data-id="<?= $idOrden ?>" data-enfoque="tecnico">
                          <span class="table-action-icon"><i class="bi bi-person-plus"></i></span>
                          <span class="table-action-label">Asignar</span>
                        </button>
                        <button type="button" class="table-action" data-bs-toggle="offcanvas" data-bs-target="#panelSolicitud" data-id="<?= $idOrden ?>" data-enfoque="estado">
                          <span class="table-action-icon"><i class="bi bi-arrow-repeat"></i></span>
                          <span class="table-action-label">Cambiar<br />estado</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Paginación -->
        <?php if ($totalSolicitudes > 0): ?>
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
            <span class="pagination-summary">
              Mostrando <?= $desplazamiento + 1 ?>–<?= $desplazamiento + count($solicitudesAdmin) ?> de <?= $totalSolicitudes ?> solicitudes
            </span>
            <?php if ($totalPaginas > 1): ?>
              <nav aria-label="Páginas de solicitudes">
                <ul class="d-flex flex-wrap gap-1 list-unstyled mb-0">
                  <li>
                    <?php if ($paginaActual > 1): ?>
                      <a class="pagination-btn d-inline-block text-decoration-none" href="<?= htmlspecialchars(urlServicios(['pagina' => $paginaActual - 1])) ?>">Anterior</a>
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
                          href="<?= htmlspecialchars(urlServicios(['pagina' => $pagina === 1 ? null : $pagina])) ?>"
                          <?= $pagina === $paginaActual ? 'aria-current="page"' : '' ?>
                        ><?= $pagina ?></a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                  <li>
                    <?php if ($paginaActual < $totalPaginas): ?>
                      <a class="pagination-btn d-inline-block text-decoration-none" href="<?= htmlspecialchars(urlServicios(['pagina' => $paginaActual + 1])) ?>">Siguiente</a>
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
    <!-- ================= ADMINISTRADOR: CATÁLOGO DE SERVICIOS ================= -->
    <section class="pb-5 bg-white" aria-label="Catálogo de servicios">
      <div class="container">
        <form action="servicios.php" method="GET" class="row g-3 align-items-end" role="search">
          <input type="hidden" name="vista" value="catalogo" />
          <div class="col-lg-8">
            <label for="catalogo-q" class="admin-filter-label">Buscar servicios</label>
            <div class="search-input-wrapper">
              <i class="bi bi-search search-icon"></i>
              <input
                type="search"
                id="catalogo-q"
                name="cq"
                value="<?= htmlspecialchars($catalogoTexto) ?>"
                class="form-control search-input"
                placeholder="Buscar por nombre de servicio"
                maxlength="100"
              />
            </div>
          </div>
          <div class="col-lg-4">
            <label for="catalogo-disp" class="admin-filter-label">Disponibilidad</label>
            <select id="catalogo-disp" name="disp" class="form-select admin-select js-autoenviar">
              <option value="">Todos los servicios</option>
              <option value="Activo" <?= $catalogoDisp === 'Activo' ? 'selected' : '' ?>>Solo activos</option>
              <option value="Inactivo" <?= $catalogoDisp === 'Inactivo' ? 'selected' : '' ?>>Solo inactivos</option>
            </select>
          </div>
        </form>

        <div class="admin-table-card mt-3">
          <div class="table-responsive">
            <table class="table admin-table catalog-table align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Servicio</th>
                  <th scope="col">Disponibilidad</th>
                  <th scope="col">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($catalogoAdmin) === 0): ?>
                  <tr>
                    <td colspan="3" class="text-center py-5 admin-empty">
                      <?= ($catalogoTexto !== '' || $catalogoDisp !== '') ? 'No hay servicios que coincidan con la búsqueda.' : 'Aún no hay servicios en el catálogo. Agrega el primero con «Nuevo servicio».' ?>
                    </td>
                  </tr>
                <?php endif; ?>

                <?php foreach ($catalogoAdmin as $srv): ?>
                  <?php
                    $idServicio = (int)$srv['id_servicio'];
                    $activo = ($srv['estado'] === 'Activo');
                    $totalSolicitudesSrv = (int)$srv['total_solicitudes'];
                    $datosServicio = [
                        'id_servicio'     => $idServicio,
                        'nombre'          => $srv['nombre'],
                        'descripcion'     => $srv['descripcion'] ?? '',
                        'precio_estimado' => number_format((float)$srv['precio_estimado'], 2, '.', ''),
                        'id_categoria'    => (string)$srv['id_categoria'],
                        'estado'          => $srv['estado'],
                    ];
                  ?>
                  <tr>
                    <td class="catalog-cell-main">
                      <div class="catalog-name"><?= htmlspecialchars($srv['nombre']) ?></div>
                      <div class="catalog-desc"><?= htmlspecialchars($srv['descripcion'] ?: 'Sin descripción.') ?></div>
                      <div class="catalog-meta">
                        <?= htmlspecialchars($srv['categoria']) ?>
                        &middot; $<?= number_format((float)$srv['precio_estimado'], 2) ?> MXN
                        &middot; <?= $totalSolicitudesSrv ?> solicitud<?= $totalSolicitudesSrv === 1 ? '' : 'es' ?>
                      </div>
                    </td>
                    <td>
                      <form action="<?= htmlspecialchars(urlServicios()) ?>" method="POST" class="m-0">
                        <input type="hidden" name="accion" value="cambiar_disponibilidad" />
                        <input type="hidden" name="id_servicio" value="<?= $idServicio ?>" />
                        <input type="hidden" name="estado" value="<?= $activo ? 'Inactivo' : 'Activo' ?>" />
                        <div class="form-check form-switch availability-switch m-0">
                          <input
                            class="form-check-input js-interruptor"
                            type="checkbox"
                            role="switch"
                            id="disponible-<?= $idServicio ?>"
                            <?= $activo ? 'checked' : '' ?>
                          />
                          <label class="form-check-label <?= $activo ? '' : 'is-off' ?>" for="disponible-<?= $idServicio ?>">
                            <?= $activo ? 'Activo' : 'Inactivo' ?>
                          </label>
                        </div>
                        <noscript><button type="submit" class="btn btn-link btn-sm p-0">Cambiar</button></noscript>
                      </form>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <button
                          type="button"
                          class="btn btn-table"
                          data-bs-toggle="modal"
                          data-bs-target="#modalServicioAdmin"
                          data-modo="editar"
                          data-servicio="<?= htmlspecialchars(json_encode($datosServicio, JSON_UNESCAPED_UNICODE)) ?>"
                        >
                          Editar
                        </button>
                        <button
                          type="button"
                          class="btn btn-table btn-table-danger"
                          data-bs-toggle="modal"
                          data-bs-target="#modalEliminarServicio"
                          data-id="<?= $idServicio ?>"
                          data-nombre="<?= htmlspecialchars($srv['nombre']) ?>"
                          data-solicitudes="<?= $totalSolicitudesSrv ?>"
                          data-estado="<?= htmlspecialchars($srv['estado']) ?>"
                        >
                          Eliminar
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <p class="pagination-summary mt-3 mb-0">
          <?= $totalServicios ?> servicio<?= $totalServicios === 1 ? '' : 's' ?>
          &middot; <?= $totalActivos ?> activo<?= $totalActivos === 1 ? '' : 's' ?>
          &middot; <?= $totalInactivos ?> inactivo<?= $totalInactivos === 1 ? '' : 's' ?>
        </p>
      </div>
    </section>
    <?php endif; ?>
  <?php else: ?>
    <!-- ================= ENCABEZADO, BUSCADOR Y FILTROS ================= -->
    <section class="pt-4 pb-3 bg-white" aria-labelledby="titulo-servicios">
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

        <h1 id="titulo-servicios" class="page-title">Servicios</h1>
        <p class="page-subtitle mb-4">Elige lo que necesitas y nosotros lo resolvemos</p>

        <!-- Barra de búsqueda -->
        <form action="servicios.php" method="GET" role="search" class="mb-3">
          <?php if ($catSeleccion > 0): ?>
            <input type="hidden" name="categoria" value="<?= $catSeleccion ?>" />
          <?php endif; ?>

          <div class="search-input-wrapper">
            <i class="bi bi-search search-icon"></i>
            <label for="buscador-servicios" class="visually-hidden">Busca un servicio</label>
            <input
              type="search"
              id="buscador-servicios"
              name="q"
              value="<?= htmlspecialchars($busqueda) ?>"
              class="form-control search-input"
              placeholder="Busca un servicio"
              maxlength="100"
            />
          </div>
        </form>

        <!-- Filtro de Categoría desde BD -->
        <div class="d-flex flex-wrap gap-2" role="group" aria-label="Categoría de servicio">
          <a href="<?= htmlspecialchars(urlServicios(['categoria' => 0])) ?>" class="filter-pill text-decoration-none <?= $catSeleccion === 0 ? 'active' : '' ?>">
            Todos
          </a>
          <?php foreach ($categoriasServicio as $cat): ?>
            <a
              href="<?= htmlspecialchars(urlServicios(['categoria' => (int)$cat['id_categoria']])) ?>"
              class="filter-pill text-decoration-none <?= $catSeleccion === (int)$cat['id_categoria'] ? 'active' : '' ?>"
            >
              <?= htmlspecialchars($cat['nombre']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ================= CATÁLOGO DE SERVICIOS (DESDE BASE DE DATOS) ================= -->
    <section class="py-4 bg-white" aria-label="Catálogo de servicios">
      <div class="container">
        <div class="row g-4" id="contenedor-catalogo-servicios">
          <?php if (count($servicios) > 0): ?>
            <?php foreach ($servicios as $srv): ?>
              <?php $iconoSrv = iconoCategoria($srv['categoria']); ?>
              <div class="col-md-6 col-lg-4">
                <article class="equipment-card service-card h-100">
                  <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="card-icon-box">
                      <i class="bi <?= htmlspecialchars($iconoSrv) ?>"></i>
                    </div>
                    <?php if ((int)$srv['id_servicio'] === $idMasSolicitado): ?>
                      <span class="category-pill">Más solicitado</span>
                    <?php endif; ?>
                  </div>

                  <h3 class="equipment-title"><?= htmlspecialchars($srv['nombre']) ?></h3>
                  <p class="equipment-specs">
                    <?= htmlspecialchars($srv['descripcion'] ?: 'Servicio tecnológico especializado.') ?>
                  </p>

                  <p class="service-price mt-auto">
                    Precio estimado: <strong>$<?= number_format((float)$srv['precio_estimado'], 2) ?> MXN</strong>
                  </p>

                  <?php if ($usuario): ?>
                    <button
                      type="button"
                      class="btn btn-card-loan w-100"
                      data-bs-toggle="modal"
                      data-bs-target="#modalSolicitarServicio"
                      data-servicio-id="<?= (int)$srv['id_servicio'] ?>"
                    >
                      Solicitar servicio
                    </button>
                  <?php else: ?>
                    <button
                      type="button"
                      class="btn btn-card-loan w-100"
                      data-bs-toggle="modal"
                      data-bs-target="#modalRequiereSesion"
                      data-accion="solicitar el servicio «<?= htmlspecialchars($srv['nombre']) ?>»"
                    >
                      Solicitar servicio
                    </button>
                  <?php endif; ?>
                </article>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="db-card-placeholder gap-2">
                <i class="bi bi-search fs-4"></i>
                <?php if ($busqueda !== ''): ?>
                  <span>No encontramos servicios que coincidan con «<?= htmlspecialchars($busqueda) ?>».</span>
                <?php else: ?>
                  <span>No hay servicios disponibles en esta categoría por el momento.</span>
                <?php endif; ?>
                <a href="servicios.php" class="footer-action-link mt-1">Ver todos los servicios</a>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <p class="pagination-summary mt-3 mb-0">
          Mostrando <?= count($servicios) ?> servicio(s) disponible(s)
        </p>
      </div>
    </section>

    <!-- ================= ¿CÓMO FUNCIONA? ================= -->
    <section id="como-funciona" class="py-5 section-tinted" aria-labelledby="titulo-como-funciona">
      <div class="container">
        <h2 id="titulo-como-funciona" class="section-title mb-4">¿Cómo funciona?</h2>

        <div class="how-steps">
          <div class="how-steps-line d-none d-md-block" aria-hidden="true"></div>
          <ol class="row g-4 list-unstyled text-center mb-0">
            <li class="col-md-4">
              <span class="how-step-circle" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
              <h3 class="how-step-title">Solicita</h3>
              <p class="how-step-text">Elige un servicio y cuéntanos qué necesitas.</p>
            </li>
            <li class="col-md-4">
              <span class="how-step-circle" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
              <h3 class="how-step-title">Conecta</h3>
              <p class="how-step-text">Nuestro equipo revisa tu caso y se pone en contacto.</p>
            </li>
            <li class="col-md-4">
              <span class="how-step-circle is-final" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
              <h3 class="how-step-title">Resuelve</h3>
              <p class="how-step-text">Recibe la solución y consulta el seguimiento.</p>
            </li>
          </ol>
        </div>
      </div>
    </section>

    <!-- ================= AYUDA Y CONTACTO ================= -->
    <section class="py-5 bg-white" aria-labelledby="titulo-ayuda">
      <div class="container">
        <div class="help-banner d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
          <div>
            <h2 id="titulo-ayuda" class="help-banner-title">¿No encuentras lo que buscas?</h2>
            <p class="help-banner-text mb-0">Cuéntanos tu caso. Nuestro equipo te ayuda a encontrar una solución.</p>
          </div>
          <?php if ($usuario): ?>
            <button
              type="button"
              class="btn btn-card-loan help-banner-btn flex-shrink-0"
              data-bs-toggle="modal"
              data-bs-target="#modalSolicitarServicio"
              data-servicio-id="otro"
            >
              Contáctanos
            </button>
          <?php else: ?>
            <a href="mailto:<?= htmlspecialchars($empresa['correo_contacto'] ?? 'contacto@techsolutions.com') ?>" class="btn btn-card-loan help-banner-btn flex-shrink-0">
              Contáctanos
            </a>
          <?php endif; ?>
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
            <a href="<?= $esAdmin ? 'inventario.php#titulo-reglas-prestamo' : '#como-funciona' ?>" class="footer-action-link">
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

  <?php if (!$esAdmin): ?>
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
            Para solicitar un servicio debes iniciar sesión. Si aún no tienes cuenta, créala en un momento.
          </p>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <a href="crear_cuenta.php" class="btn btn-card-alt flex-fill">
            Crear cuenta
          </a>
          <a href="login.php?redirect=servicios.php" class="btn btn-card-loan flex-fill">
            Iniciar sesión
          </a>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>

  <!-- ================= MODAL 2: SOLICITAR SERVICIO (CLIENTE LOGUEADO) ================= -->
  <?php if ($usuario && !$esAdmin): ?>
  <div class="modal fade modal-servicio" id="modalSolicitarServicio" tabindex="-1" aria-labelledby="modalSolicitarServicioLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <form
        id="formSolicitarServicio"
        action="servicios.php"
        method="POST"
        enctype="multipart/form-data"
        class="modal-content service-modal border-0 shadow"
        novalidate
      >
        <input type="hidden" name="accion" value="solicitar_servicio" />
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= ARCHIVO_MAX_BYTES ?>" />

        <div class="modal-header service-modal-header">
          <div>
            <h5 class="modal-title service-modal-title" id="modalSolicitarServicioLabel">Solicitar servicio</h5>
            <p class="service-modal-subtitle mb-0">Cuéntanos qué sucede para poder ayudarte.</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body service-modal-body">
          <!-- Servicio seleccionado -->
          <div class="mb-3">
            <label for="id_servicio" class="form-label service-label">Servicio seleccionado</label>
            <select
              id="id_servicio"
              name="id_servicio"
              class="form-select service-input<?= claseCampo($erroresSolicitud, 'id_servicio') ?>"
              aria-describedby="error-id_servicio"
              required
            >
              <option value="" <?= $valoresSolicitud['id_servicio'] === '' ? 'selected' : '' ?>>Selecciona un servicio</option>
              <?php foreach ($serviciosActivos as $opcion): ?>
                <option
                  value="<?= (int)$opcion['id_servicio'] ?>"
                  <?= $valoresSolicitud['id_servicio'] === (string)$opcion['id_servicio'] ? 'selected' : '' ?>
                >
                  <?= htmlspecialchars($opcion['nombre']) ?>
                </option>
              <?php endforeach; ?>
              <option value="otro" <?= $valoresSolicitud['id_servicio'] === 'otro' ? 'selected' : '' ?>>Otro / no estoy seguro</option>
            </select>
            <div class="invalid-feedback" id="error-id_servicio">
              <?= htmlspecialchars($erroresSolicitud['id_servicio'] ?? 'Selecciona el servicio que necesitas.') ?>
            </div>
          </div>

          <!-- Descripción del problema -->
          <div class="mb-3">
            <label for="falla_reportada" class="form-label service-label">Descripción del problema</label>
            <textarea
              id="falla_reportada"
              name="falla_reportada"
              class="form-control service-input<?= claseCampo($erroresSolicitud, 'falla_reportada') ?>"
              rows="3"
              minlength="10"
              maxlength="1000"
              placeholder="Ej. Mi laptop se calienta y el ventilador hace mucho ruido. Necesito una revisión y limpieza del equipo."
              aria-describedby="error-falla_reportada contador-falla"
              required
            ><?= htmlspecialchars($valoresSolicitud['falla_reportada']) ?></textarea>
            <div class="invalid-feedback" id="error-falla_reportada">
              <?= htmlspecialchars($erroresSolicitud['falla_reportada'] ?? 'Describe el problema para que podamos ayudarte.') ?>
            </div>
            <div class="form-counter" id="contador-falla">0 / 1000</div>
          </div>

          <!-- Equipo -->
          <div class="mb-3">
            <label for="equipo_receptor" class="form-label service-label">Equipo</label>
            <input
              type="text"
              id="equipo_receptor"
              name="equipo_receptor"
              class="form-control service-input<?= claseCampo($erroresSolicitud, 'equipo_receptor') ?>"
              minlength="3"
              maxlength="100"
              value="<?= htmlspecialchars($valoresSolicitud['equipo_receptor']) ?>"
              placeholder="Ej. Laptop Dell Latitude 5420 o CT-EQ-0248"
              aria-describedby="ayuda-equipo error-equipo_receptor"
              autocomplete="off"
              required
            />
            <div class="form-text service-help" id="ayuda-equipo">Marca y modelo de tu equipo, o su número de inventario si pertenece a la institución.</div>
            <div class="invalid-feedback" id="error-equipo_receptor">
              <?= htmlspecialchars($erroresSolicitud['equipo_receptor'] ?? 'Indica el equipo que vamos a revisar.') ?>
            </div>
          </div>

          <!-- Adjuntar archivo (opcional) -->
          <div class="mb-3 position-relative">
            <span class="form-label service-label d-block">Adjuntar archivo <span class="fw-normal text-muted">(opcional)</span></span>
            <input
              type="file"
              id="archivo"
              name="archivo"
              class="file-input-hidden<?= claseCampo($erroresSolicitud, 'archivo') ?>"
              accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
              aria-describedby="ayuda-archivo error-archivo"
            />
            <label for="archivo" class="file-drop" id="zonaArchivo">
              <i class="bi bi-upload file-drop-icon" aria-hidden="true"></i>
              <span class="d-flex flex-column min-w-0">
                <span class="file-drop-title" id="archivoNombre">Seleccionar archivo</span>
                <span class="file-drop-hint" id="ayuda-archivo">PDF, JPG o PNG · Máximo 10 MB · También puedes arrastrarlo aquí</span>
              </span>
            </label>
            <button type="button" class="btn btn-link file-remove-btn d-none" id="quitarArchivo">
              <i class="bi bi-x-circle me-1"></i>Quitar archivo
            </button>
            <div class="invalid-feedback" id="error-archivo">
              <?= htmlspecialchars($erroresSolicitud['archivo'] ?? 'Solo se permiten archivos PDF, JPG o PNG de máximo 10 MB.') ?>
            </div>
            <?php if ($pedirArchivoDeNuevo): ?>
              <div class="form-text text-warning-emphasis" id="avisoArchivo">
                <i class="bi bi-info-circle me-1"></i>Vuelve a seleccionar tu archivo antes de enviar.
              </div>
            <?php endif; ?>
          </div>

          <!-- Prioridad -->
          <fieldset class="mb-1">
            <legend class="form-label service-label mb-2">Prioridad</legend>
            <div class="row g-2 priority-group<?= claseCampo($erroresSolicitud, 'prioridad') ?>" role="radiogroup">
              <?php foreach (PRIORIDADES_SERVICIO as $prioridad): ?>
                <div class="col-4">
                  <input
                    type="radio"
                    class="btn-check"
                    name="prioridad"
                    id="prioridad<?= $prioridad ?>"
                    value="<?= $prioridad ?>"
                    autocomplete="off"
                    <?= $valoresSolicitud['prioridad'] === $prioridad ? 'checked' : '' ?>
                    required
                  />
                  <label class="btn priority-option w-100" for="prioridad<?= $prioridad ?>"><?= $prioridad ?></label>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (isset($erroresSolicitud['prioridad'])): ?>
              <div class="invalid-feedback d-block"><?= htmlspecialchars($erroresSolicitud['prioridad']) ?></div>
            <?php endif; ?>
            <p class="priority-hint mb-0" id="prioridadAyuda" aria-live="polite"></p>
          </fieldset>
        </div>

        <div class="modal-footer service-modal-footer">
          <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-card-loan modal-btn-submit" id="botonEnviarSolicitud">
            <span class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
            <span class="texto-boton">Enviar solicitud</span>
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($esAdmin): ?>
  <?php if ($vistaAdmin === 'solicitudes'): ?>
  <!-- ================= ADMINISTRADOR: PANEL LATERAL "DETALLE DE SOLICITUD" ================= -->
  <div class="offcanvas offcanvas-end panel-solicitud" tabindex="-1" id="panelSolicitud" aria-labelledby="panelSolicitudTitulo">
    <form
      action="<?= htmlspecialchars(urlServicios()) ?>"
      method="POST"
      class="d-flex flex-column h-100"
      id="formDetalleSolicitud"
      novalidate
    >
      <input type="hidden" name="accion" value="actualizar_solicitud" />
      <input type="hidden" name="id_orden" id="detalleIdOrden" value="" />

      <div class="panel-header">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <h2 class="panel-title" id="panelSolicitudTitulo">Detalle de solicitud</h2>
          <button type="button" class="btn panel-close" data-bs-dismiss="offcanvas" aria-label="Cerrar detalle">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
          <span class="panel-subtitle" id="detalleFolioFecha"></span>
          <span class="status-pill status-pill-sm" id="detalleEstadoActual"></span>
        </div>
      </div>

      <div class="panel-body flex-grow-1">
        <h3 class="panel-section-title">Datos del solicitante</h3>
        <p class="panel-name mb-1" id="detalleCliente"></p>
        <p class="panel-muted mb-3" id="detalleCorreo"></p>

        <dl class="panel-data mb-3">
          <dt>Teléfono</dt>
          <dd id="detalleTelefono"></dd>
          <dt>Equipo</dt>
          <dd id="detalleEquipo"></dd>
          <dt>Prioridad</dt>
          <dd id="detallePrioridad"></dd>
          <dt>Archivo</dt>
          <dd id="detalleArchivo"></dd>
        </dl>

        <p class="panel-label mb-1">Servicio solicitado</p>
        <p class="panel-service mb-3" id="detalleServicio"></p>

        <p class="panel-label mb-2">Descripción del problema</p>
        <div class="panel-problem mb-4" id="detalleFalla"></div>

        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label for="detalleTecnico" class="form-label service-label">Técnico asignado</label>
            <select id="detalleTecnico" name="id_tecnico" class="form-select service-input">
              <option value="">Sin asignar</option>
              <?php foreach ($tecnicos as $tecnico): ?>
                <option value="<?= (int)$tecnico['id_usuario'] ?>">
                  <?= htmlspecialchars(trim($tecnico['nombres'] . ' ' . $tecnico['apellidos'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (count($tecnicos) === 0): ?>
              <div class="form-text service-help">No hay usuarios activos con rol Técnico.</div>
            <?php endif; ?>
          </div>
          <div class="col-sm-6">
            <label for="detalleEstado" class="form-label service-label">Estado</label>
            <select id="detalleEstado" name="estado" class="form-select service-input" required>
              <?php foreach (ESTADOS_ORDEN as $estado): ?>
                <option value="<?= htmlspecialchars($estado) ?>"><?= htmlspecialchars($estado) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <label for="detalleComentario" class="form-label service-label">Comentario interno</label>
        <textarea
          id="detalleComentario"
          name="comentario_interno"
          class="form-control service-input"
          rows="4"
          maxlength="1000"
          placeholder="Agrega una nota para el equipo técnico…"
          aria-describedby="detalleComentarioAyuda"
        ></textarea>
        <div class="invalid-feedback" id="error-comentario">El comentario interno no puede superar los 1000 caracteres.</div>
        <div class="d-flex justify-content-between gap-2 mt-1">
          <span class="service-help" id="detalleComentarioAyuda">Solo el equipo administrador puede ver este comentario.</span>
          <span class="form-counter m-0" id="contadorComentario">0 / 1000</span>
        </div>
      </div>

      <div class="panel-footer">
        <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="offcanvas">Cerrar</button>
        <button type="submit" class="btn btn-card-loan modal-btn-submit" id="botonGuardarDetalle" disabled>Guardar cambios</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- ================= ADMINISTRADOR: MODAL "NUEVO / EDITAR SERVICIO" ================= -->
  <div class="modal fade modal-servicio" id="modalServicioAdmin" tabindex="-1" aria-labelledby="modalServicioAdminLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <form
        id="formServicioAdmin"
        action="<?= htmlspecialchars(urlServicios(['vista' => 'catalogo'])) ?>"
        method="POST"
        class="modal-content service-modal border-0 shadow"
        novalidate
      >
        <input type="hidden" name="accion" value="guardar_servicio" />
        <input type="hidden" name="id_servicio" id="servicioId" value="<?= (int)$valoresServicio['id_servicio'] ?>" />

        <div class="modal-header service-modal-header">
          <div>
            <h5 class="modal-title service-modal-title" id="modalServicioAdminLabel">
              <?= $valoresServicio['id_servicio'] > 0 ? 'Editar servicio' : 'Nuevo servicio' ?>
            </h5>
            <p class="service-modal-subtitle mb-0" id="modalServicioAdminSubtitulo">
              <?= $valoresServicio['id_servicio'] > 0 ? 'Actualiza la información que ven los clientes.' : 'Agrega un servicio al catálogo para que los clientes puedan solicitarlo.' ?>
            </p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body service-modal-body">
          <div class="mb-3">
            <label for="servicioNombre" class="form-label service-label">Nombre del servicio</label>
            <input
              type="text"
              id="servicioNombre"
              name="nombre"
              class="form-control service-input<?= claseCampo($erroresServicio, 'nombre') ?>"
              minlength="3"
              maxlength="100"
              value="<?= htmlspecialchars($valoresServicio['nombre']) ?>"
              placeholder="Ej. Mantenimiento preventivo"
              autocomplete="off"
              required
            />
            <div class="invalid-feedback" id="error-servicio-nombre">
              <?= htmlspecialchars($erroresServicio['nombre'] ?? 'Escribe el nombre del servicio.') ?>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-7">
              <label for="servicioCategoria" class="form-label service-label">Categoría</label>
              <select
                id="servicioCategoria"
                name="id_categoria"
                class="form-select service-input<?= claseCampo($erroresServicio, 'id_categoria') ?>"
                required
              >
                <option value="">Selecciona una categoría</option>
                <?php foreach ($categoriasServicio as $cat): ?>
                  <option value="<?= (int)$cat['id_categoria'] ?>" <?= $valoresServicio['id_categoria'] === (string)$cat['id_categoria'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback" id="error-servicio-id_categoria">
                <?= htmlspecialchars($erroresServicio['id_categoria'] ?? 'Selecciona la categoría del servicio.') ?>
              </div>
            </div>
            <div class="col-sm-5">
              <label for="servicioPrecio" class="form-label service-label">Precio estimado (MXN)</label>
              <div class="input-group has-validation">
                <span class="input-group-text service-input-addon">$</span>
                <input
                  type="number"
                  id="servicioPrecio"
                  name="precio_estimado"
                  class="form-control service-input<?= claseCampo($erroresServicio, 'precio_estimado') ?>"
                  min="0"
                  max="99999999.99"
                  step="0.01"
                  inputmode="decimal"
                  value="<?= htmlspecialchars($valoresServicio['precio_estimado']) ?>"
                  placeholder="0.00"
                  required
                />
                <div class="invalid-feedback" id="error-servicio-precio_estimado">
                  <?= htmlspecialchars($erroresServicio['precio_estimado'] ?? 'Escribe el precio estimado.') ?>
                </div>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label for="servicioDescripcion" class="form-label service-label">Descripción <span class="fw-normal text-muted">(opcional)</span></label>
            <textarea
              id="servicioDescripcion"
              name="descripcion"
              class="form-control service-input<?= claseCampo($erroresServicio, 'descripcion') ?>"
              rows="3"
              maxlength="500"
              placeholder="Ej. Limpieza interna y cambio de pasta térmica."
            ><?= htmlspecialchars($valoresServicio['descripcion']) ?></textarea>
            <div class="invalid-feedback" id="error-servicio-descripcion">
              <?= htmlspecialchars($erroresServicio['descripcion'] ?? 'La descripción no puede superar los 500 caracteres.') ?>
            </div>
            <div class="form-counter" id="contadorDescripcion">0 / 500</div>
          </div>

          <div class="form-check form-switch availability-switch">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              id="servicioActivo"
              name="estado"
              value="Activo"
              <?= $valoresServicio['estado'] === 'Activo' ? 'checked' : '' ?>
            />
            <label class="form-check-label" for="servicioActivo">Disponible en el catálogo para los clientes</label>
          </div>
        </div>

        <div class="modal-footer service-modal-footer">
          <button type="button" class="btn btn-card-alt modal-btn-cancel" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-card-loan modal-btn-submit" id="botonGuardarServicio">
            <?= $valoresServicio['id_servicio'] > 0 ? 'Guardar cambios' : 'Agregar servicio' ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ================= ADMINISTRADOR: MODAL "ELIMINAR SERVICIO" ================= -->
  <div class="modal fade" id="modalEliminarServicio" tabindex="-1" aria-labelledby="modalEliminarServicioLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow" style="border-radius: 16px;">
        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
          <div class="d-flex align-items-center gap-3">
            <div class="card-icon-box icon-box-danger">
              <i class="bi bi-trash3"></i>
            </div>
            <h5 class="modal-title fw-bold" id="modalEliminarServicioLabel" style="color: #153352;">Eliminar servicio</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body px-4 py-3">
          <p class="mb-0" style="color: #3d5d80; font-size: 0.94rem;" id="eliminarMensaje"></p>
        </div>

        <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-card-alt flex-fill" data-bs-dismiss="modal">Cancelar</button>

          <form action="<?= htmlspecialchars(urlServicios(['vista' => 'catalogo'])) ?>" method="POST" class="flex-fill m-0" id="formEliminarServicio">
            <input type="hidden" name="accion" value="eliminar_servicio" />
            <input type="hidden" name="id_servicio" id="eliminarId" value="" />
            <button type="submit" class="btn btn-danger-soft w-100">Eliminar servicio</button>
          </form>

          <form action="<?= htmlspecialchars(urlServicios(['vista' => 'catalogo'])) ?>" method="POST" class="flex-fill m-0 d-none" id="formDesactivarServicio">
            <input type="hidden" name="accion" value="cambiar_disponibilidad" />
            <input type="hidden" name="id_servicio" id="desactivarId" value="" />
            <input type="hidden" name="estado" value="Inactivo" />
            <button type="submit" class="btn btn-card-loan w-100">Desactivar servicio</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
  <?php if (!$esAdmin): ?>
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

      <?php if ($abrirModalSesion): ?>
      bootstrap.Modal.getOrCreateInstance(modalSesion).show();
      <?php endif; ?>
    }

    // Formulario "Solicitar servicio" (usuarios logueados)
    const modalServicio = document.getElementById('modalSolicitarServicio');
    const formServicio = document.getElementById('formSolicitarServicio');
    if (modalServicio && formServicio) {
      const TAMANO_MAXIMO = <?= ARCHIVO_MAX_BYTES ?>;
      const EXTENSIONES_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png'];
      const AYUDA_PRIORIDAD = {
        Baja: 'Baja: el problema no te impide usar el equipo.',
        Media: 'Media: el problema afecta el uso habitual del equipo.',
        Alta: 'Alta: no puedes usar el equipo o necesitas la solución con urgencia.'
      };

      const campoServicio = document.getElementById('id_servicio');
      const campoFalla = document.getElementById('falla_reportada');
      const campoEquipo = document.getElementById('equipo_receptor');
      const campoArchivo = document.getElementById('archivo');
      const zonaArchivo = document.getElementById('zonaArchivo');
      const nombreArchivo = document.getElementById('archivoNombre');
      const quitarArchivo = document.getElementById('quitarArchivo');
      const contadorFalla = document.getElementById('contador-falla');
      const ayudaPrioridad = document.getElementById('prioridadAyuda');
      const botonEnviar = document.getElementById('botonEnviarSolicitud');

      // Reglas de validación: devuelven el mensaje de error o '' si el campo es válido
      const reglas = {
        id_servicio: campo => campo.value === '' ? 'Selecciona el servicio que necesitas.' : '',
        falla_reportada: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Describe el problema para que podamos ayudarte.';
          if (largo < 10) return `Agrega más detalle: escribe al menos 10 caracteres (llevas ${largo}).`;
          if (largo > 1000) return 'La descripción no puede superar los 1000 caracteres.';
          return '';
        },
        equipo_receptor: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Indica el equipo que vamos a revisar.';
          if (largo < 3) return 'Escribe al menos 3 caracteres (por ejemplo, la marca y el modelo).';
          if (largo > 100) return 'El nombre del equipo no puede superar los 100 caracteres.';
          return '';
        },
        archivo: campo => {
          if (campo.files.length === 0) return '';
          const archivo = campo.files[0];
          const extension = archivo.name.split('.').pop().toLowerCase();
          if (!EXTENSIONES_PERMITIDAS.includes(extension)) return 'Solo se permiten archivos PDF, JPG o PNG.';
          if (archivo.size > TAMANO_MAXIMO) return `El archivo pesa ${(archivo.size / 1048576).toFixed(1)} MB; el máximo es 10 MB.`;
          return '';
        }
      };
      const campos = [campoServicio, campoFalla, campoEquipo, campoArchivo];

      function validarCampo(campo) {
        const mensaje = reglas[campo.name](campo);
        campo.setCustomValidity(mensaje);
        const feedback = document.getElementById('error-' + campo.name);
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

      // Contador de caracteres de la descripción
      function actualizarContador() {
        contadorFalla.textContent = `${campoFalla.value.length} / 1000`;
      }
      campoFalla.addEventListener('input', actualizarContador);
      actualizarContador();

      // Texto de ayuda según la prioridad elegida
      function actualizarAyudaPrioridad() {
        const elegida = formServicio.querySelector('input[name="prioridad"]:checked');
        ayudaPrioridad.textContent = elegida ? AYUDA_PRIORIDAD[elegida.value] : '';
      }
      formServicio.querySelectorAll('input[name="prioridad"]').forEach(opcion => {
        opcion.addEventListener('change', actualizarAyudaPrioridad);
      });
      actualizarAyudaPrioridad();

      // Archivo: mostrar nombre, quitar y arrastrar y soltar
      function actualizarArchivo() {
        const aviso = document.getElementById('avisoArchivo');
        if (campoArchivo.files.length > 0) {
          const archivo = campoArchivo.files[0];
          const tamano = archivo.size < 1048576
            ? `${Math.max(1, Math.round(archivo.size / 1024))} KB`
            : `${(archivo.size / 1048576).toFixed(1)} MB`;
          nombreArchivo.textContent = `${archivo.name} (${tamano})`;
          quitarArchivo.classList.remove('d-none');
          if (aviso) aviso.remove();
        } else {
          nombreArchivo.textContent = 'Seleccionar archivo';
          quitarArchivo.classList.add('d-none');
        }
        campoArchivo.dataset.tocado = '1';
        validarCampo(campoArchivo);
      }
      campoArchivo.addEventListener('change', actualizarArchivo);

      quitarArchivo.addEventListener('click', () => {
        campoArchivo.value = '';
        actualizarArchivo();
        campoArchivo.classList.remove('is-valid');
      });

      ['dragenter', 'dragover'].forEach(tipo => {
        zonaArchivo.addEventListener(tipo, evento => {
          evento.preventDefault();
          zonaArchivo.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(tipo => {
        zonaArchivo.addEventListener(tipo, evento => {
          evento.preventDefault();
          zonaArchivo.classList.remove('is-dragover');
        });
      });
      zonaArchivo.addEventListener('drop', evento => {
        if (!evento.dataTransfer || evento.dataTransfer.files.length === 0) return;
        const transferencia = new DataTransfer();
        transferencia.items.add(evento.dataTransfer.files[0]);
        campoArchivo.files = transferencia.files;
        actualizarArchivo();
      });

      // Preseleccionar el servicio de la tarjeta que abrió el formulario
      modalServicio.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        const idServicio = trigger ? trigger.getAttribute('data-servicio-id') : null;
        if (idServicio) {
          campoServicio.value = idServicio;
          if (campoServicio.dataset.tocado === '1' || campoServicio.classList.contains('is-invalid')) {
            validarCampo(campoServicio);
          }
        }
      });

      // Mantener el foco en el campo al hacer clic en "Enviar" (evita que el botón se mueva y el clic se pierda)
      botonEnviar.addEventListener('mousedown', event => event.preventDefault());

      // Validar todo antes de enviar y evitar doble envío
      formServicio.addEventListener('submit', event => {
        let primerInvalido = null;
        campos.forEach(campo => {
          campo.dataset.tocado = '1';
          if (!validarCampo(campo) && !primerInvalido) primerInvalido = campo;
        });

        if (primerInvalido) {
          event.preventDefault();
          event.stopPropagation();
          primerInvalido.focus();
          return;
        }

        botonEnviar.disabled = true;
        botonEnviar.querySelector('.spinner-border').classList.remove('d-none');
        botonEnviar.querySelector('.texto-boton').textContent = 'Enviando…';
      });

      <?php if ($abrirModalSolicitud): ?>
      bootstrap.Modal.getOrCreateInstance(modalServicio).show();
      <?php endif; ?>
    }
  <?php else: ?>
    // ================= ADMINISTRADOR =================

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

    // Interruptor de disponibilidad del catálogo
    document.querySelectorAll('.js-interruptor').forEach(interruptor => {
      interruptor.addEventListener('change', () => {
        interruptor.disabled = true;
        interruptor.form.submit();
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

    // ---------- Panel "Detalle de solicitud" ----------
    const panelSolicitud = document.getElementById('panelSolicitud');
    if (panelSolicitud) {
      const DETALLE_SOLICITUDES = <?= json_encode($detalleSolicitudes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
      const formDetalle = document.getElementById('formDetalleSolicitud');
      const campoTecnico = document.getElementById('detalleTecnico');
      const campoEstado = document.getElementById('detalleEstado');
      const campoComentario = document.getElementById('detalleComentario');
      const botonGuardar = document.getElementById('botonGuardarDetalle');
      const actualizarContadorComentario = conectarContador(campoComentario, document.getElementById('contadorComentario'), 1000);
      let valoresIniciales = null;
      let enfoquePendiente = 'ver';

      const texto = (id, valor) => { document.getElementById(id).textContent = valor; };

      function hayCambios() {
        return valoresIniciales !== null && (
          campoTecnico.value !== valoresIniciales.tecnico ||
          campoEstado.value !== valoresIniciales.estado ||
          campoComentario.value.trim() !== valoresIniciales.comentario.trim()
        );
      }

      function validarComentario() {
        const valido = campoComentario.value.trim().length <= 1000;
        campoComentario.classList.toggle('is-invalid', !valido);
        return valido;
      }

      function actualizarBotonGuardar() {
        botonGuardar.disabled = !hayCambios();
      }

      function llenarPanel(idOrden) {
        const sol = DETALLE_SOLICITUDES[idOrden];
        if (!sol) return false;

        document.getElementById('detalleIdOrden').value = idOrden;
        texto('detalleFolioFecha', `${sol.folio} · ${sol.fecha}`);
        const pill = document.getElementById('detalleEstadoActual');
        pill.textContent = sol.estado;
        pill.className = `status-pill status-pill-sm ${sol.claseEstado}`;
        texto('detalleCliente', sol.cliente);
        texto('detalleCorreo', sol.correo);
        texto('detalleTelefono', sol.telefono || 'Sin teléfono');
        texto('detalleEquipo', sol.equipo);
        texto('detallePrioridad', sol.prioridad);
        texto('detalleServicio', sol.servicio);
        texto('detalleFalla', sol.falla);

        const celdaArchivo = document.getElementById('detalleArchivo');
        celdaArchivo.textContent = '';
        if (sol.archivo) {
          const enlace = document.createElement('a');
          enlace.href = sol.archivo;
          enlace.target = '_blank';
          enlace.rel = 'noopener';
          enlace.innerHTML = '<i class="bi bi-paperclip"></i> Ver archivo adjunto';
          celdaArchivo.appendChild(enlace);
        } else {
          celdaArchivo.textContent = 'Sin archivo';
        }

        campoTecnico.value = sol.idTecnico;
        if (campoTecnico.value !== sol.idTecnico) campoTecnico.value = ''; // técnico ya inactivo
        campoEstado.value = sol.estado;
        campoComentario.value = sol.comentario;
        campoComentario.classList.remove('is-invalid');
        actualizarContadorComentario();

        valoresIniciales = { tecnico: campoTecnico.value, estado: campoEstado.value, comentario: sol.comentario };
        actualizarBotonGuardar();

        document.querySelectorAll('tr[data-fila-id]').forEach(fila => {
          fila.classList.toggle('is-selected', fila.dataset.filaId === String(idOrden));
        });
        return true;
      }

      panelSolicitud.addEventListener('show.bs.offcanvas', event => {
        const trigger = event.relatedTarget;
        if (trigger && trigger.dataset.id) {
          llenarPanel(trigger.dataset.id);
          enfoquePendiente = trigger.dataset.enfoque || 'ver';
        }
      });
      panelSolicitud.addEventListener('shown.bs.offcanvas', () => {
        if (enfoquePendiente === 'tecnico') campoTecnico.focus();
        if (enfoquePendiente === 'estado') campoEstado.focus();
      });
      panelSolicitud.addEventListener('hidden.bs.offcanvas', () => {
        document.querySelectorAll('tr.is-selected').forEach(fila => fila.classList.remove('is-selected'));
      });

      [campoTecnico, campoEstado].forEach(campo => campo.addEventListener('change', actualizarBotonGuardar));
      campoComentario.addEventListener('input', () => {
        validarComentario();
        actualizarBotonGuardar();
      });

      formDetalle.addEventListener('submit', event => {
        if (!validarComentario() || !hayCambios()) {
          event.preventDefault();
          campoComentario.focus();
          return;
        }
        botonGuardar.disabled = true;
        botonGuardar.textContent = 'Guardando…';
      });

      // Reabrir el panel si el servidor rechazó los cambios
      const detalleConError = <?= (int)$abrirDetalleId ?>;
      if (detalleConError && llenarPanel(detalleConError)) {
        bootstrap.Offcanvas.getOrCreateInstance(panelSolicitud).show();
      }
    }

    // ---------- Modal "Nuevo / Editar servicio" ----------
    const modalServicioAdmin = document.getElementById('modalServicioAdmin');
    const formServicioAdmin = document.getElementById('formServicioAdmin');
    if (modalServicioAdmin && formServicioAdmin) {
      const campoId = document.getElementById('servicioId');
      const campoNombre = document.getElementById('servicioNombre');
      const campoCategoria = document.getElementById('servicioCategoria');
      const campoPrecio = document.getElementById('servicioPrecio');
      const campoDescripcion = document.getElementById('servicioDescripcion');
      const campoActivo = document.getElementById('servicioActivo');
      const botonGuardarServicio = document.getElementById('botonGuardarServicio');
      const actualizarContadorDescripcion = conectarContador(campoDescripcion, document.getElementById('contadorDescripcion'), 500);

      const reglasServicio = {
        nombre: campo => {
          const largo = campo.value.trim().length;
          if (largo === 0) return 'Escribe el nombre del servicio.';
          if (largo < 3) return 'El nombre debe tener al menos 3 caracteres.';
          if (largo > 100) return 'El nombre no puede superar los 100 caracteres.';
          return '';
        },
        id_categoria: campo => campo.value === '' ? 'Selecciona la categoría del servicio.' : '',
        precio_estimado: campo => {
          const valor = campo.value.trim();
          if (valor === '' && campo.validity.badInput) return 'Escribe solo números (ej. 450 o 450.50).';
          if (valor === '') return 'Escribe el precio estimado.';
          if (!/^\d{1,8}(\.\d{1,2})?$/.test(valor)) return 'Escribe un precio válido con máximo 2 decimales (ej. 450.50).';
          return '';
        },
        descripcion: campo => campo.value.trim().length > 500 ? 'La descripción no puede superar los 500 caracteres.' : ''
      };
      const camposServicio = [campoNombre, campoCategoria, campoPrecio, campoDescripcion];

      function validarServicio(campo) {
        const mensaje = reglasServicio[campo.name](campo);
        campo.setCustomValidity(mensaje);
        const feedback = document.getElementById('error-servicio-' + campo.name);
        if (feedback && mensaje) feedback.textContent = mensaje;
        campo.classList.toggle('is-invalid', mensaje !== '');
        campo.classList.toggle('is-valid', mensaje === '' && campo.value.trim() !== '');
        return mensaje === '';
      }

      camposServicio.forEach(campo => {
        const evento = campo.tagName === 'SELECT' ? 'change' : 'input';
        campo.addEventListener(evento, () => {
          if (campo.dataset.tocado === '1' || campo.classList.contains('is-invalid')) validarServicio(campo);
        });
        campo.addEventListener('blur', () => {
          campo.dataset.tocado = '1';
          validarServicio(campo);
        });
      });

      function limpiarValidacion() {
        camposServicio.forEach(campo => {
          campo.classList.remove('is-invalid', 'is-valid');
          campo.setCustomValidity('');
          delete campo.dataset.tocado;
        });
      }

      // Llenar el formulario según el botón que lo abrió (Nuevo servicio o Editar)
      modalServicioAdmin.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return; // abierto por el servidor con errores: conservar valores
        limpiarValidacion();

        const titulo = document.getElementById('modalServicioAdminLabel');
        const subtitulo = document.getElementById('modalServicioAdminSubtitulo');

        if (trigger.dataset.modo === 'editar') {
          const datos = JSON.parse(trigger.dataset.servicio);
          campoId.value = datos.id_servicio;
          campoNombre.value = datos.nombre;
          campoCategoria.value = datos.id_categoria;
          campoPrecio.value = datos.precio_estimado;
          campoDescripcion.value = datos.descripcion;
          campoActivo.checked = datos.estado === 'Activo';
          titulo.textContent = 'Editar servicio';
          subtitulo.textContent = 'Actualiza la información que ven los clientes.';
          botonGuardarServicio.textContent = 'Guardar cambios';
        } else {
          formServicioAdmin.reset();
          campoId.value = '0';
          campoNombre.value = '';
          campoCategoria.value = '';
          campoPrecio.value = '';
          campoDescripcion.value = '';
          campoActivo.checked = true;
          titulo.textContent = 'Nuevo servicio';
          subtitulo.textContent = 'Agrega un servicio al catálogo para que los clientes puedan solicitarlo.';
          botonGuardarServicio.textContent = 'Agregar servicio';
        }
        actualizarContadorDescripcion();
      });
      modalServicioAdmin.addEventListener('shown.bs.modal', () => campoNombre.focus());

      // Mantener el foco en el campo al hacer clic en "Guardar" (evita que el botón se mueva y el clic se pierda)
      botonGuardarServicio.addEventListener('mousedown', event => event.preventDefault());

      formServicioAdmin.addEventListener('submit', event => {
        let primerInvalido = null;
        camposServicio.forEach(campo => {
          campo.dataset.tocado = '1';
          if (!validarServicio(campo) && !primerInvalido) primerInvalido = campo;
        });
        if (primerInvalido) {
          event.preventDefault();
          primerInvalido.focus();
          return;
        }
        botonGuardarServicio.disabled = true;
        botonGuardarServicio.textContent = 'Guardando…';
      });

      <?php if ($abrirModalServicio): ?>
      bootstrap.Modal.getOrCreateInstance(modalServicioAdmin).show();
      <?php endif; ?>
    }

    // ---------- Modal "Eliminar servicio" ----------
    const modalEliminar = document.getElementById('modalEliminarServicio');
    if (modalEliminar) {
      modalEliminar.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        const nombre = trigger.dataset.nombre;
        const solicitudes = parseInt(trigger.dataset.solicitudes, 10) || 0;
        const activo = trigger.dataset.estado === 'Activo';
        const mensaje = document.getElementById('eliminarMensaje');
        const formEliminar = document.getElementById('formEliminarServicio');
        const formDesactivar = document.getElementById('formDesactivarServicio');

        document.getElementById('eliminarId').value = trigger.dataset.id;
        document.getElementById('desactivarId').value = trigger.dataset.id;

        if (solicitudes > 0) {
          // La BD no permite borrar un servicio que ya tiene solicitudes
          mensaje.textContent = `«${nombre}» tiene ${solicitudes} solicitud${solicitudes === 1 ? '' : 'es'} registrada${solicitudes === 1 ? '' : 's'}, por eso no se puede eliminar. ` +
            (activo ? 'Puedes desactivarlo para que ya no aparezca en el catálogo de los clientes.' : 'Ya está desactivado, así que los clientes no lo ven en el catálogo.');
          formEliminar.classList.add('d-none');
          formDesactivar.classList.toggle('d-none', !activo);
        } else {
          mensaje.textContent = `¿Seguro que quieres eliminar «${nombre}»? Esta acción no se puede deshacer.`;
          formEliminar.classList.remove('d-none');
          formDesactivar.classList.add('d-none');
        }
      });
    }
  <?php endif; ?>
  </script>
</body>
</html>
