<?php
/**
 * Ferretería 4 Esquinas — receptor de cotizaciones.
 *
 * Recibe el carrito que arma el cliente en index.html y lo guarda en un
 * log local (cotizaciones.log) y, si el hosting tiene correo saliente
 * configurado, intenta enviarlo también por email a la ferretería.
 *
 * IMPORTANTE: este archivo NO se ejecuta en GitHub Pages (es hosting
 * estático, no corre PHP). Para usarlo, sube este archivo junto a un
 * hosting que sí soporte PHP (casi cualquier hosting compartido en
 * Colombia lo trae) y cambia en index.html la llamada de WhatsApp/descarga
 * por un fetch('cotizar.php', { method:'POST', body: JSON.stringify(...) }).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Solo aceptar POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

// Limitar tamaño del cuerpo para evitar abuso (200 KB es más que suficiente).
$raw = file_get_contents('php://input', false, null, 0, 200 * 1024);
$data = json_decode($raw ?: '', true);

if (!is_array($data) || !isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La cotización no tiene productos.']);
    exit;
}

function clean_text(string $value, int $maxLen = 200): string
{
    $value = trim($value);
    $value = preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value) ?? '';
    return mb_substr($value, 0, $maxLen);
}

$customerName = isset($data['name']) ? clean_text((string) $data['name'], 120) : '';
$customerPhone = isset($data['phone']) ? clean_text((string) $data['phone'], 40) : '';

$lines = [];
$subtotal = 0.0;

foreach ($data['items'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $name = clean_text((string) ($item['name'] ?? ''), 160);
    $unit = clean_text((string) ($item['unit'] ?? ''), 40);
    $qty = max(1, (int) ($item['qty'] ?? 1));
    $price = max(0.0, (float) ($item['price'] ?? 0));

    if ($name === '') {
        continue;
    }

    $lineTotal = $price * $qty;
    $subtotal += $lineTotal;

    $lines[] = sprintf('- %s (%s) x%d — $ %s COP', $name, $unit, $qty, number_format($lineTotal, 0, ',', '.'));
}

if (count($lines) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La cotización no tiene productos válidos.']);
    exit;
}

$timestamp = date('Y-m-d H:i:s');

$bodyLines = [
    'Cotización — Ferretería 4 Esquinas',
    'Bahía Solano, Chocó',
    'Fecha: ' . $timestamp,
    '',
];

if ($customerName !== '') {
    $bodyLines[] = 'Cliente: ' . $customerName;
}
if ($customerPhone !== '') {
    $bodyLines[] = 'Teléfono: ' . $customerPhone;
}
if ($customerName !== '' || $customerPhone !== '') {
    $bodyLines[] = '';
}

$bodyLines = array_merge($bodyLines, $lines);
$bodyLines[] = '';
$bodyLines[] = sprintf('Subtotal estimado: $ %s COP', number_format($subtotal, 0, ',', '.'));
$bodyLines[] = '';
$bodyLines[] = '(Precios de referencia, sujetos a confirmación)';

$body = implode("\n", $bodyLines);

// 1) Guardar siempre en un log local — esto funciona en cualquier hosting,
//    sin depender de que el correo saliente esté configurado.
$logEntry = json_encode([
    'fecha' => $timestamp,
    'cliente' => $customerName,
    'telefono' => $customerPhone,
    'items' => $data['items'],
    'subtotal' => $subtotal,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

$logFile = __DIR__ . '/cotizaciones.log';
$logged = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// 2) Intentar enviar por correo (best-effort; muchos hostings compartidos
//    ya traen mail() configurado). No se usa ningún dato del cliente en
//    las cabeceras del correo, para evitar inyección de cabeceras.
$mailSent = false;
$storeEmail = 'ferrecuatroesquinas@gmail.com';
if (function_exists('mail')) {
    $subject = '=?UTF-8?B?' . base64_encode('Nueva cotizacion - Ferreteria 4 Esquinas') . '?=';
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n" .
        "From: no-reply@ferreteria4esquinas.local\r\n";
    $mailSent = @mail($storeEmail, $subject, $body, $headers);
}

echo json_encode([
    'ok' => true,
    'logged' => $logged !== false,
    'mailed' => $mailSent,
]);
