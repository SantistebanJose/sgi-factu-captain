<?php
/**
 * Registra una sucursal y sus archivos.
 * Envío recomendado: multipart/form-data con `datos` (JSON), `certificado`
 * y `logo`. El campo `datos` también puede enviarse como objeto JSON crudo
 * cuando no se adjuntan archivos.
 */

header('Content-Type: application/json; charset=utf-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, array('ok' => false, 'error' => 'Use el método POST.'));
}

$contenido = isset($_POST['datos']) ? $_POST['datos'] : file_get_contents('php://input');
$datos = json_decode($contenido, true);
if (!is_array($datos)) {
    responder(400, array('ok' => false, 'error' => 'Envíe un JSON válido; en multipart use el campo datos.'));
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

$certificado = validar_archivo_subido('certificado');
$logo = validar_archivo_subido('logo');
if ($certificado === null) {
    responder(422, array('ok' => false, 'error' => 'Adjunte el certificado en el campo certificado.'));
}
$extensionCertificado = strtolower(pathinfo($certificado['name'], PATHINFO_EXTENSION));
if (!in_array($extensionCertificado, array('pfx', 'p12'), true)) {
    responder(422, array('ok' => false, 'error' => 'El certificado debe ser .pfx o .p12.'));
}
if ($logo !== null) {
    $mimeLogo = function_exists('mime_content_type') ? mime_content_type($logo['tmp_name']) : '';
    $tiposLogo = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
    if (!isset($tiposLogo[$mimeLogo]) || $logo['size'] > 5 * 1024 * 1024) {
        responder(422, array('ok' => false, 'error' => 'El logo debe ser PNG, JPEG o WebP y pesar hasta 5 MB.'));
    }
}

$directorio = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sucursales' . DIRECTORY_SEPARATOR . $ruc;
if (file_exists($directorio)) {
    responder(409, array('ok' => false, 'error' => 'Ya existe una sucursal con ese RUC.'));
}
if (!mkdir($directorio, 0750, true)) {
    responder(500, array('ok' => false, 'error' => 'No se pudo crear el directorio de la sucursal.'));
}

$rutas = array(
    $directorio . DIRECTORY_SEPARATOR . 'cdr',
    $directorio . DIRECTORY_SEPARATOR . 'xml'
);
foreach ($rutas as $ruta) {
    if (!mkdir($ruta, 0750)) {
        limpiar_directorio($directorio);
        responder(500, array('ok' => false, 'error' => 'No se pudieron crear las carpetas CDR y XML.'));
    }
}

$rutaCertificado = $directorio . DIRECTORY_SEPARATOR . $nombreCertificado;
if (!move_uploaded_file($certificado['tmp_name'], $rutaCertificado)) {
    limpiar_directorio($directorio);
    responder(500, array('ok' => false, 'error' => 'No se pudo guardar el certificado.'));
}

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
    $extensionLogo = $tiposLogo[$mimeLogo];
    $nombreLogo = 'logo.' . $extensionLogo;
    if (!move_uploaded_file($logo['tmp_name'], $directorio . DIRECTORY_SEPARATOR . $nombreLogo)) {
        limpiar_directorio($directorio);
        responder(500, array('ok' => false, 'error' => 'No se pudo guardar el logo.'));
    }
    $config['logo'] = 'sucursales/' . $ruc . '/' . $nombreLogo;
}

$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($directorio . DIRECTORY_SEPARATOR . 'datos.json', $json . PHP_EOL, LOCK_EX) === false) {
    limpiar_directorio($directorio);
    responder(500, array('ok' => false, 'error' => 'No se pudo guardar la configuración de la sucursal.'));
}

responder(201, array(
    'ok' => true,
    'mensaje' => 'Sucursal registrada correctamente.',
    'ruc' => $ruc,
    'carpeta' => 'sucursales/' . $ruc,
    'archivos' => array('configuracion' => 'datos.json', 'certificado' => $nombreCertificado, 'logo' => $config['logo'])
));
