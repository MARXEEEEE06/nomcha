<?php
// PSGC address helpers: Region -> Province and City/Municipality -> Barangay.
// Data lives in the psgc_* tables (sql/psgc_data.sql). Codes are 10-char strings.

const PSGC_CODE_RE  = '/^\d{10}$/';
const PSGC_NCR_CODE = '1300000000';

/** @return array<int, array{code:string,label:string}> */
function psgc_regions(PDO $pdo): array {
    return $pdo->query('SELECT psgc_code AS code, name AS label FROM psgc_regions ORDER BY psgc_code')->fetchAll(PDO::FETCH_ASSOC);
}

function psgc_provinces(PDO $pdo, string $regionCode): array {
    if (!preg_match(PSGC_CODE_RE, $regionCode)) return [];
    $stmt = $pdo->prepare('SELECT psgc_code AS code, name AS label FROM psgc_provinces WHERE region_code = ? ORDER BY name');
    $stmt->execute([$regionCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Cities/municipalities of a region, sorted by name. Province is selected separately.
 * @return array<int, array{code:string,label:string}>
 */
function psgc_localities(PDO $pdo, string $regionCode): array {
    if (!preg_match(PSGC_CODE_RE, $regionCode)) return [];
    $stmt = $pdo->prepare(
        "SELECT l.psgc_code AS code, l.name,
                TRIM(REGEXP_REPLACE(l.name, '^City of ', '')) AS sort_name
         FROM psgc_localities l
         WHERE l.province_code = ? OR (l.region_code = ? AND l.province_code IS NULL)
         ORDER BY sort_name, l.name"
    );
    $stmt->execute([$regionCode, $regionCode]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $label = $r['sort_name'] === $r['name'] ? $r['name'] : $r['sort_name'] . ' City';
        $rows[] = ['code' => $r['code'], 'label' => $label];
    }
    return $rows;
}

/** Barangays of a city/municipality in natural order ("Barangay 2" before "Barangay 10"). */
function psgc_barangays(PDO $pdo, string $localityCode): array {
    if (!preg_match(PSGC_CODE_RE, $localityCode)) return [];
    $stmt = $pdo->prepare('SELECT psgc_code AS code, name AS label FROM psgc_barangays WHERE locality_code = ?');
    $stmt->execute([$localityCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return $rows;
}

/**
 * Option lists for server-side rendering of the three selects (edit mode, failed POST).
 * Child lists load only when the selected parent really belongs to the level above.
 */
function psgc_form_state(PDO $pdo, array $sel): array {
    $region = (string)($sel['region_code'] ?? '');
    $province = (string)($sel['province_code'] ?? '');
    $city   = (string)($sel['municipality_code'] ?? '');
    $localities = psgc_localities($pdo, $province !== '' ? $province : $region);
    $cityInRegion = $city !== '' && in_array($city, array_column($localities, 'code'), true);
    return [
        'regions'    => psgc_regions($pdo),
        'provinces'  => psgc_provinces($pdo, $region),
        'localities' => $localities,
        'barangays'  => $cityInRegion ? psgc_barangays($pdo, $city) : [],
    ];
}

/**
 * Validate a posted code chain against the database. Never trust the dropdowns alone.
 * Returns codes plus official names, or null when the chain is invalid.
 * `province` is NOT NULL in user_addresses: NCR -> "Metro Manila", independent cities -> ''.
 */
function psgc_resolve(PDO $pdo, string $regionCode, string $provinceOrMunicipalityCode, string $municipalityOrBarangayCode, ?string $barangayCode = null): ?array {
    $validateProvince = $barangayCode !== null;
    $provinceCode = $validateProvince ? $provinceOrMunicipalityCode : '';
    $municipalityCode = $validateProvince ? $municipalityOrBarangayCode : $provinceOrMunicipalityCode;
    $barangayCode = $validateProvince ? $barangayCode : $municipalityOrBarangayCode;
    foreach ([$regionCode, $municipalityCode, $barangayCode] as $code) {
        if (!preg_match(PSGC_CODE_RE, $code)) return null;
    }
    $stmt = $pdo->prepare(
        'SELECT r.psgc_code AS region_code, p.psgc_code AS province_code, p.name AS province_name,
                l.psgc_code AS municipality_code, l.name AS municipality_name,
                b.psgc_code AS barangay_code, b.name AS barangay_name
         FROM psgc_barangays b
         JOIN psgc_localities l ON l.psgc_code = b.locality_code
         JOIN psgc_regions r ON r.psgc_code = l.region_code
         LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
         WHERE b.psgc_code = ? AND l.psgc_code = ? AND r.psgc_code = ?'
    );
    $stmt->execute([$barangayCode, $municipalityCode, $regionCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ($validateProvince && $provinceCode !== (string)($row['province_code'] ?? '')
        && !($row['region_code'] === PSGC_NCR_CODE && $provinceCode === PSGC_NCR_CODE)) return null;
    return [
        'region_code'       => $row['region_code'],
        'province_code'     => $row['province_code'],
        'municipality_code' => $row['municipality_code'],
        'barangay_code'     => $row['barangay_code'],
        'province'          => $row['province_name'] ?? ($row['region_code'] === PSGC_NCR_CODE ? 'Metro Manila' : ''),
        'municipality'      => $row['municipality_name'],
        'barangay'          => $row['barangay_name'],
    ];
}

/** Bootstrap select field. $level: region | municipality | barangay. $wrap: wrapper class. */
function psgc_select_field(string $level, array $rows, ?string $selected, ?string $error = null, string $wrap = 'mb-3'): void {
    static $meta = [
        'region'       => ['region_code', 'Region', 'Select region'],
        'province'     => ['province_code', 'Province', 'Select province'],
        'municipality' => ['municipality_code', 'City / Municipality', 'Select city / municipality'],
        'barangay'     => ['barangay_code', 'Barangay', 'Select barangay'],
    ];
    if (!isset($meta[$level])) return;
    [$name, $label, $placeholder] = $meta[$level];
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $selected = (string)$selected;
    $disabled = $level !== 'region' && !$rows;
    echo '<div class="' . $h($wrap) . '">';
    echo '<label for="' . $name . '" class="form-label">' . $label . '</label>';
    echo '<select id="' . $name . '" name="' . $name . '" class="form-select' . ($error ? ' is-invalid' : '') . '" required'
        . ' data-psgc-level="' . $level . '" data-placeholder="' . $h($placeholder) . '" data-psgc-api="api/psgc.php"'
        . ($disabled ? ' disabled' : '') . '>';
    echo '<option value="">' . $h($placeholder) . '</option>';
    foreach ($rows as $r) {
        echo '<option value="' . $h($r['code']) . '"' . ($r['code'] === $selected ? ' selected' : '') . '>' . $h($r['label']) . '</option>';
    }
    echo '</select>';
    if ($error) echo '<div class="invalid-feedback">' . $h($error) . '</div>';
    echo '</div>';
}
