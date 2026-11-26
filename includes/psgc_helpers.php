<?php
// PSGC address helpers: Region -> City/Municipality -> Barangay.
// setup.php imports packaged database/psgc_reference.json into psgc_* tables.
// Codes are 10-character strings and must never be treated as integers.

const PSGC_CODE_RE  = '/^\d{10}$/';
const PSGC_NCR_CODE = '1300000000';

/** @return array<int, array{code:string,label:string}> */
function psgc_regions(PDO $pdo): array {
    return $pdo->query('SELECT psgc_code AS code, name AS label FROM psgc_regions ORDER BY psgc_code')->fetchAll(PDO::FETCH_ASSOC);
}

function psgc_provinces(PDO $pdo, string $regionCode): array {
    if (!preg_match(PSGC_CODE_RE, $regionCode)) return [];
    if ($regionCode === PSGC_NCR_CODE) return [['code' => PSGC_NCR_CODE, 'label' => 'Metro Manila']];
    $stmt = $pdo->prepare('SELECT psgc_code AS code, name AS label FROM psgc_provinces WHERE region_code = ? ORDER BY name');
    $stmt->execute([$regionCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $independent = $pdo->prepare('SELECT 1 FROM psgc_localities WHERE region_code = ? AND province_code IS NULL LIMIT 1');
    $independent->execute([$regionCode]);
    if ($independent->fetchColumn()) $rows[] = ['code' => $regionCode, 'label' => 'Independent city / municipality'];
    return $rows;
}

/**
 * Cities/municipalities for a province, or independent localities for a synthetic region option.
 * @return array<int, array{code:string,label:string}>
 */
function psgc_localities(PDO $pdo, string $regionCode): array {
    if (!preg_match(PSGC_CODE_RE, $regionCode)) return [];
    $stmt = $pdo->prepare(
        "SELECT l.psgc_code AS code, l.name,
                TRIM(REGEXP_REPLACE(l.name, '^City of ', '')) AS sort_name
         FROM psgc_localities l
         LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
         WHERE COALESCE(l.region_code,p.region_code) = ?
         ORDER BY sort_name, l.name"
    );
    $stmt->execute([$regionCode]);
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
    $city   = (string)($sel['municipality_code'] ?? '');
    $localities = psgc_localities($pdo, $region);
    $cityInRegion = $city !== '' && in_array($city, array_column($localities, 'code'), true);
    return [
        'regions'    => psgc_regions($pdo),
        'localities' => $localities,
        'barangays'  => $cityInRegion ? psgc_barangays($pdo, $city) : [],
    ];
}

/** Resolve a region/province/locality selection (branch addresses have no barangay). */
function psgc_resolve_locality(PDO $pdo, string $regionCode, string $provinceCode, string $localityCode): ?array {
    if (!preg_match(PSGC_CODE_RE, $regionCode) || !preg_match(PSGC_CODE_RE, $localityCode)) return null;
    $stmt = $pdo->prepare(
        'SELECT r.psgc_code AS region_code, r.name AS region_name, p.psgc_code AS province_code,
                p.name AS province_name, l.psgc_code AS municipality_code, l.name AS municipality_name
         FROM psgc_localities l
         LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
         JOIN psgc_regions r ON r.psgc_code = COALESCE(l.region_code,p.region_code)
         WHERE l.psgc_code = ? AND r.psgc_code = ?'
    );
    $stmt->execute([$localityCode, $regionCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $actualProvince = (string)($row['province_code'] ?? '');
    if ($actualProvince !== $provinceCode
        && !($regionCode === $provinceCode && $actualProvince === '')) return null;
    return [
        'region_code' => $row['region_code'],
        'province_code' => $actualProvince !== '' ? $actualProvince : $regionCode,
        'municipality_code' => $row['municipality_code'],
        'province' => $row['province_name'] ?? ($regionCode === PSGC_NCR_CODE ? 'Metro Manila' : $row['region_name']),
        'municipality' => $row['municipality_name'],
    ];
}

/** Match legacy locality labels only when their region/province and locality are unambiguous. */
function psgc_find_locality_by_names(PDO $pdo, string $city, string $province): ?array {
    static $localityRows = null;
    static $provinceNamesByRegion = [];
    $normalize = static function (string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['ã‘', 'ã±', 'ñ'], 'n', $value);
        $value = preg_replace('/\s*\(capital\)\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\s*\(dadiangas\)\s*/u', '', $value) ?? $value;
        $value = preg_replace('/^(city of|municipality of)\s+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+city$/u', '', $value) ?? $value;
        $value = preg_replace('/\bsta\.?\s/u', 'santa ', $value) ?? $value;
        $value = preg_replace('/\bsto\.?\s/u', 'santo ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    };
    $cityNames = [$normalize($city)];
    $provinceName = $normalize($province);
    if (preg_match('/\bcity of\s+(.+)$/iu', $province, $embeddedCity)) $cityNames[] = $normalize($embeddedCity[1]);
    $cityNames = array_values(array_unique(array_filter($cityNames, static fn(string $name): bool => $name !== '')));
    if (!$cityNames || $provinceName === '') return null;
    $isNcr = str_contains($provinceName, 'metro manila');
    if ($localityRows === null) {
        $localityRows = $pdo->query(
            'SELECT r.psgc_code AS region_code, r.name AS region_name, p.psgc_code AS source_province_code,
                    p.name AS source_province_name, l.psgc_code AS municipality_code, l.name AS municipality_name
             FROM psgc_localities l
             LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
             JOIN psgc_regions r ON r.psgc_code = COALESCE(l.region_code,p.region_code)'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    $matches = [];
    foreach ($localityRows as $row) {
        if (!in_array($normalize((string)$row['municipality_name']), $cityNames, true)) continue;
        if ($row['source_province_code'] === null) {
            $matchesParent = $isNcr && $row['region_code'] === PSGC_NCR_CODE;
            $matchesParent = $matchesParent || $normalize((string)$row['region_name']) === $provinceName;
            if (!$matchesParent) {
                if (!array_key_exists($row['region_code'], $provinceNamesByRegion)) {
                    $provinceQuery = $pdo->prepare('SELECT name FROM psgc_provinces WHERE region_code = ?');
                    $provinceQuery->execute([$row['region_code']]);
                    $provinceNamesByRegion[$row['region_code']] = array_map($normalize, array_column($provinceQuery->fetchAll(PDO::FETCH_ASSOC), 'name'));
                }
                $matchesParent = in_array($provinceName, $provinceNamesByRegion[$row['region_code']], true);
            }
            if (!$matchesParent) continue;
        } elseif ($isNcr || $normalize((string)($row['source_province_name'] ?? '')) !== $provinceName) continue;
        $matches[] = [
            'region_code' => $row['region_code'],
            'province_code' => $row['source_province_code'] ?: $row['region_code'],
            'municipality_code' => $row['municipality_code'],
            'province' => $row['source_province_name'] ?? ($row['region_code'] === PSGC_NCR_CODE ? 'Metro Manila' : $row['region_name']),
            'municipality' => $row['municipality_name'],
        ];
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** Match a legacy user address to a full PSGC chain without guessing on ambiguous labels. */
function psgc_find_address_by_names(PDO $pdo, string $province, string $municipality, string $barangay): ?array {
    $locality = psgc_find_locality_by_names($pdo, $municipality, $province);
    if (!$locality) return null;
    $normalize = static function (string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/^(barangay|brgy\.?)\s+/u', '', $value) ?? $value;
        $value = preg_replace('/\bsta\.?\s/u', 'santa ', $value) ?? $value;
        $value = preg_replace('/\bsto\.?\s/u', 'santo ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    };
    $stmt = $pdo->prepare('SELECT psgc_code AS barangay_code, name AS barangay FROM psgc_barangays WHERE locality_code = ?');
    $stmt->execute([$locality['municipality_code']]);
    $matches = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => $normalize((string)$row['barangay']) === $normalize($barangay)));
    if (count($matches) !== 1) return null;
    return $locality + ['barangay_code' => $matches[0]['barangay_code'], 'barangay' => $matches[0]['barangay']];
}

/** Match an older manually entered address when PSGC names are embedded in free text. */
function psgc_find_address_in_text(PDO $pdo, string $address): ?array {
    $normalize = static function (string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['ñ', 'ã‘'], 'n', $value);
        $value = preg_replace('/\bsta\.?\s/u', 'santa ', $value) ?? $value;
        $value = preg_replace('/\bsto\.?\s/u', 'santo ', $value) ?? $value;
        $value = preg_replace('/\b(brgy\.?|barangay)\s+/u', '', $value) ?? $value;
        $value = preg_replace('/^(city of|municipality of)\s+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+city$/u', '', $value) ?? $value;
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value);
    };
    $contains = static function (string $haystack, string $needle) use ($normalize): bool {
        $haystack = ' ' . $normalize($haystack) . ' ';
        $needle = $normalize($needle);
        return $needle !== '' && str_contains($haystack, ' ' . $needle . ' ');
    };
    $cleanAddress = $normalize($address);
    if ($cleanAddress === '') return null;

    $localities = $pdo->query(
        'SELECT l.psgc_code AS municipality_code, l.name AS municipality,
                r.psgc_code AS region_code, r.name AS region_name,
                p.psgc_code AS source_province_code, p.name AS source_province
         FROM psgc_localities l
         LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
         JOIN psgc_regions r ON r.psgc_code = COALESCE(l.region_code,p.region_code)'
    )->fetchAll(PDO::FETCH_ASSOC);
    $matches = [];
    $barangays = $pdo->prepare('SELECT psgc_code AS barangay_code, name AS barangay FROM psgc_barangays WHERE locality_code = ?');
    foreach ($localities as $locality) {
        if (!$contains($address, (string)$locality['municipality'])) continue;
        $barangays->execute([$locality['municipality_code']]);
        foreach ($barangays->fetchAll(PDO::FETCH_ASSOC) as $barangay) {
            if (!$contains($address, (string)$barangay['barangay'])) continue;
            $matches[] = $locality + [
                'province_code' => $locality['source_province_code'] ?: $locality['region_code'],
                'province' => $locality['source_province'] ?: ($locality['region_code'] === PSGC_NCR_CODE ? 'Metro Manila' : $locality['region_name']),
                'barangay_code' => $barangay['barangay_code'],
                'barangay' => $barangay['barangay'],
            ];
        }
    }
    if (count($matches) !== 1) return null;
    $match = $matches[0];
    $street = $address;
    foreach ([$match['barangay'], $match['municipality'], $match['source_province'] ?? $match['region_name'], $match['region_name']] as $placeName) {
        if (!$placeName) continue;
        $aliases = [(string)$placeName];
        $aliases[] = preg_replace('/^Sta\.?\s/iu', 'Santa ', (string)$placeName) ?? (string)$placeName;
        $aliases[] = preg_replace('/^Sto\.?\s/iu', 'Santo ', (string)$placeName) ?? (string)$placeName;
        foreach (array_unique($aliases) as $alias) {
            $street = preg_replace('/\b' . preg_quote($alias, '/') . '\b/iu', ' ', $street) ?? $street;
        }
    }
    $street = preg_replace('/\b(brgy\.?|barangay)\b/iu', ' ', $street) ?? $street;
    $street = trim(preg_replace('/[\s,;]+/u', ' ', $street) ?? $street);
    $match['street_address'] = $street;
    return $match;
}

/** Validate a posted region, locality and barangay chain against the reference tables. */
function psgc_resolve(PDO $pdo, string $regionCode, string $municipalityCode, string $barangayCode): ?array {
    foreach ([$regionCode, $municipalityCode, $barangayCode] as $code) {
        if (!preg_match(PSGC_CODE_RE, $code)) return null;
    }
    $stmt = $pdo->prepare(
        'SELECT r.psgc_code AS region_code,r.name AS region_name,
                l.psgc_code AS municipality_code,l.name AS municipality_name,
                b.psgc_code AS barangay_code, b.name AS barangay_name
         FROM psgc_barangays b
         JOIN psgc_localities l ON l.psgc_code = b.locality_code
         LEFT JOIN psgc_provinces p ON p.psgc_code = l.province_code
         JOIN psgc_regions r ON r.psgc_code = COALESCE(l.region_code,p.region_code)
         WHERE b.psgc_code = ? AND l.psgc_code = ? AND r.psgc_code = ?'
    );
    $stmt->execute([$barangayCode, $municipalityCode, $regionCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'region_code'       => $row['region_code'],
        'municipality_code' => $row['municipality_code'],
        'barangay_code'     => $row['barangay_code'],
        'region'            => $row['region_name'],
        'municipality'      => $row['municipality_name'],
        'barangay'          => $row['barangay_name'],
    ];
}

/** Bootstrap select field. $level: region | municipality | barangay. $wrap: wrapper class. */
function psgc_select_field(string $level, array $rows, ?string $selected, ?string $error = null, string $wrap = 'mb-3', string $idSuffix = ''): void {
    static $meta = [
        'region'       => ['region_code', 'Region', 'Select region'],
        'municipality' => ['municipality_code', 'City / Municipality', 'Select city / municipality'],
        'barangay'     => ['barangay_code', 'Barangay', 'Select barangay'],
    ];
    if (!isset($meta[$level])) return;
    [$name, $label, $placeholder] = $meta[$level];
    $h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $selected = (string)$selected;
    $disabled = $level !== 'region' && !$rows;
    echo '<div class="' . $h($wrap) . '">';
    echo '<label for="' . $name . $h($idSuffix) . '" class="form-label">' . $label . '</label>';
    echo '<select id="' . $name . $h($idSuffix) . '" name="' . $name . '" class="form-select' . ($error ? ' is-invalid' : '') . '" required'
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
