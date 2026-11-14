<?php
// Public read-only PSGC lookup for the cascading address dropdowns.
//   GET api/psgc.php?level=regions
//   GET api/psgc.php?level=localities&parent=<region code>
//   GET api/psgc.php?level=barangays&parent=<city/municipality code>
// Returns [{"code": "...", "label": "..."}, ...]. No session, no writes.
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/psgc_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function psgc_api_fail(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    psgc_api_fail(405, 'Method not allowed.');
}

$level  = (string)($_GET['level'] ?? '');
$parent = (string)($_GET['parent'] ?? '');

if (in_array($level, ['provinces', 'localities', 'barangays'], true) && !preg_match(PSGC_CODE_RE, $parent)) {
    psgc_api_fail(400, 'A 10-digit parent code is required.');
}

try {
    $rows = match ($level) {
        'regions'    => psgc_regions($pdo),
        'provinces'  => $parent === PSGC_NCR_CODE ? [['code' => PSGC_NCR_CODE, 'label' => 'Metro Manila']] : psgc_provinces($pdo, $parent),
        'localities' => psgc_localities($pdo, $parent),
        'barangays'  => psgc_barangays($pdo, $parent),
        default      => null,
    };
} catch (Throwable $e) {
    psgc_api_fail(500, 'Lookup failed.');
}
if ($rows === null) psgc_api_fail(400, 'Unknown level.');

header('Cache-Control: public, max-age=3600');
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
