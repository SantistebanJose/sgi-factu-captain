<?php
/**
 * Registra una sucursal y sus archivos.
 *
 * Envío: multipart/form-data con
 *   - datos        (texto)   JSON con ruc, contrasenia_firma, nombre_archivo_firma,
 *                            ws, usuario_sol, clave, series
 *   - certificado  (archivo) .pfx o .p12  (obligatorio)
 *   - logo         (archivo) PNG, JPEG o WebP hasta 5 MB (opcional)
 *
 * Seguridad (configurar en el servidor):
 *   - Defina la variable de entorno FACTURADOR_API_TOKEN y envíe el header
 *     "Authorization: Bearer <token>". Si no está definida, no se exige token.
 *   - Lo ideal es que la carpeta /sucursales quede FUERA del directorio público.
 *     Si no es posible, este script crea un .htaccess que bloquea el acceso web.
 *   - Revise upload_max_filesize y post_max_size en php.ini (mínimo 6M).
 */

header('Content-Type: application/json; charset=utf-8');

// Cambie a true para verificar que la contraseña abre el certificado.
// Con OpenSSL 3 algunos .pfx antiguos pueden fallar; pruebe antes de activarlo.
const VALIDAR_CERTIFICADO = false;

function responder($estado, $cuerpo)
{
    http_response_code($estado);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function limpiar_directorio($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    foreach (scandir($ruta) as $entrada) {
        if ($entrada === '.' || $entrada === '..') {
            continue;
        }
        $elemento = $ruta . DIRECTORY_SEPARATOR . $entrada;
        if (is_dir($elemento) && !is_link($elemento)) {
            limpiar_directorio($elemento);
        } else {
            @unlink($elemento);
        }
    }
    @rmdir($ruta);
}

function validar_archivo_subido($campo)
{
    if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$campo]['tmp_name'])) {
        responder(400, array('ok' => false, 'error' => 'La carga del archivo ' . $campo . ' no se completó.'));
    }
    return $_FILES[$campo];
}

function exigir_token()
{
    $esperado = getenv('FACTURADOR_API_TOKEN');
    if ($esperado === false || $esperado === '') {
        return;
    }
    $cabecera = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $cabecera, $m) || !hash_equals($esperado, trim($m[1]))) {
        responder(401, array('ok' => false, 'error' => 'No autorizado.'));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, array('ok' => false, 'error' => 'Use el método POST.'));
}

exigir_token();

// ---------- Datos ----------
$contenido = isset($_POST['datos']) ? $_POST['datos'] : '';
$datos = json_decode($contenido, true);
if (!is_array($datos)) {
    responder(400, array('ok' => false, 'error' => 'Envíe multipart/form-data con el campo datos en JSON válido.'));
}

$ruc = isset($datos['ruc']) ? trim((string) $datos['ruc']) : '';
if (!preg_match('/^[0-9]{11}$/', $ruc)) {
    responder(422, array('ok' => false, 'error' => 'El RUC debe tener exactamente 11 dígitos.'));
}
$contrasenia = isset($datos['contrasenia_firma']) ? (string) $datos['contrasenia_firma'] : '';
$nombreCertificado = isset($datos['nombre_archivo_firma']) ? basename((string) $datos['nombre_archivo_firma']) : '';
$ws = isset($datos['ws']) ? strtolower(trim((string) $datos['ws'])) : '';
$usuarioSol = isset($datos['usuario_sol']) ? trim((string) $datos['usuario_sol']) : '';
$clave = isset($datos['clave']) ? (string) $datos['clave'] : '';
$series = isset($datos['series']) && is_array($datos['series']) ? $datos['series'] : array();
$seriesEsperadas = array('boleta', 'factura', 'nota_credito', 'guia_remision');

if ($nombreCertificado === '' || !preg_match('/^[A-Za-z0-9._-]+\.(pfx|p12)$/i', $nombreCertificado)) {
    responder(422, array('ok' => false, 'error' => 'nombre_archivo_firma debe ser un nombre .pfx o .p12 válido.'));
}
if (!in_array($ws, array('beta', 'produccion'), true)) {
    responder(422, array('ok' => false, 'error' => 'ws debe ser beta o produccion.'));
}
if ($contrasenia === '' || $usuarioSol === '' || $clave === '') {
    responder(422, array('ok' => false, 'error' => 'Complete contrasenia_firma, usuario_sol y clave.'));
}
foreach ($seriesEsperadas as $tipo) {
    if (!isset($series[$tipo]) || !preg_match('/^[A-Za-z0-9]{1,4}$/', (string) $series[$tipo])) {
        responder(422, array('ok' => false, 'error' => 'Falta una serie válida para ' . $tipo . '.'));
    }
    $series[$tipo] = strtoupper((string) $series[$tipo]);
}

// ---------- Archivos ----------
$certificado = validar_archivo_subido('certificado');
$logo = validar_archivo_subido('logo');
if ($certificado === null) {
    responder(422, array('ok' => false, 'error' => 'Adjunte el certificado en el campo certificado.'));
}
$extensionCertificado = strtolower(pathinfo($certificado['name'], PATHINFO_EXTENSION));
if (!in_array($extensionCertificado, array('pfx', 'p12'), true)) {
    responder(422, array('ok' => false, 'error' => 'El certificado debe ser .pfx o .p12.'));
}
if (VALIDAR_CERTIFICADO && function_exists('openssl_pkcs12_read')) {
    $almacen = array();
    $bytes = file_get_contents($certificado['tmp_name']);
    if ($bytes === false || !@openssl_pkcs12_read($bytes, $almacen, $contrasenia)) {
        responder(422, array('ok' => false, 'error' => 'No se pudo abrir el certificado: verifique el archivo y la contrasenia_firma.'));
    }
}

$tiposLogo = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
$mimeLogo = '';
if ($logo !== null) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeLogo = (string) finfo_file($finfo, $logo['tmp_name']);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $mimeLogo = (string) mime_content_type($logo['tmp_name']);
    }
    if (!isset($tiposLogo[$mimeLogo]) || $logo['size'] > 5 * 1024 * 1024) {
        responder(422, array('ok' => false, 'error' => 'El logo debe ser PNG, JPEG o WebP y pesar hasta 5 MB.'));
    }
}

// ---------- Carpetas ----------
$base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sucursales';
if (!is_dir($base)) {
    if (!mkdir($base, 0750, true) && !is_dir($base)) {
        responder(500, array('ok' => false, 'error' => 'No se pudo crear la carpeta base de sucursales.'));
    }
}
// Bloqueo de acceso web por si la carpeta queda dentro del directorio público (Apache).
if (!file_exists($base . DIRECTORY_SEPARATOR . '.htaccess')) {
    @file_put_contents($base . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
}

$directorio = $base . DIRECTORY_SEPARATOR . $ruc;
// mkdir sin recursividad es atómico: evita carreras entre dos solicitudes con el mismo RUC.
if (!@mkdir($directorio, 0750)) {
    if (file_exists($directorio)) {
        responder(409, array('ok' => false, 'error' => 'Ya existe una sucursal con ese RUC.'));
    }
    responder(500, array('ok' => false, 'error' => 'No se pudo crear el directorio de la sucursal.'));
}

foreach (array('cdr', 'xml') as $subcarpeta) {
    if (!mkdir($directorio . DIRECTORY_SEPARATOR . $subcarpeta, 0750)) {
        limpiar_directorio($directorio);
        responder(500, array('ok' => false, 'error' => 'No se pudieron crear las carpetas CDR y XML.'));
    }
}

$rutaCertificado = $directorio . DIRECTORY_SEPARATOR . $nombreCertificado;
if (!move_uploaded_file($certificado['tmp_name'], $rutaCertificado)) {
    limpiar_directorio($directorio);
    responder(500, array('ok' => false, 'error' => 'No se pudo guardar el certificado.'));
}
@chmod($rutaCertificado, 0640);

$config = array(
    'ruc' => $ruc,
    'contrasenia_firma' => $contrasenia,
    'nombre_archivo_firma' => $nombreCertificado,
    'ruta_firma' => 'sucursales/' . $ruc . '/' . $nombreCertificado,
    'ws' => $ws,
    'usuario_sol' => $usuarioSol,
    'clave' => $clave,
    'clave_sol' => $clave,
    'series' => $series,
    'logo' => null
);

if ($logo !== null) {
    $nombreLogo = 'logo.' . $tiposLogo[$mimeLogo];
    $rutaLogo = $directorio . DIRECTORY_SEPARATOR . $nombreLogo;
    if (!move_uploaded_file($logo['tmp_name'], $rutaLogo)) {
        limpiar_directorio($directorio);
        responder(500, array('ok' => false, 'error' => 'No se pudo guardar el logo.'));
    }
    @chmod($rutaLogo, 0640);
    $config['logo'] = 'sucursales/' . $ruc . '/' . $nombreLogo;
}

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$rutaJson = $directorio . DIRECTORY_SEPARATOR . 'datos.json';
if ($json === false || file_put_contents($rutaJson, $json . PHP_EOL, LOCK_EX) === false) {
    limpiar_directorio($directorio);
    responder(500, array('ok' => false, 'error' => 'No se pudo guardar la configuración de la sucursal.'));
}
@chmod($rutaJson, 0640);

responder(201, array(
    'ok' => true,
    'mensaje' => 'Sucursal registrada correctamente.',
    'ruc' => $ruc,
    'carpeta' => 'sucursales/' . $ruc,
    'archivos' => array(
        'configuracion' => 'datos.json',
        'certificado' => $nombreCertificado,
        'logo' => $config['logo']
    )
));