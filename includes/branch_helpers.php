<?php
function get_public_branches(PDO $pdo): array {
    $branches = $pdo->query("SELECT b.branch_id AS id,b.name,CONCAT_WS(', ',NULLIF(b.street_address,''),ba.name,l.name,COALESCE(p.name,r.name),b.zip_code) AS address,l.name AS city,COALESCE(p.name,r.name) AS province,r.name AS region,b.latitude AS lat,b.longitude AS lng,b.phone,b.schedule,b.supports_delivery,b.supports_pickup FROM branches b JOIN psgc_barangays ba ON ba.psgc_code=b.barangay_code JOIN psgc_localities l ON l.psgc_code=ba.locality_code LEFT JOIN psgc_provinces p ON p.psgc_code=l.province_code JOIN psgc_regions r ON r.psgc_code=COALESCE(l.region_code,p.region_code) WHERE b.status='open' ORDER BY b.name")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($branches as &$branch) {
        $branch['id'] = (int)$branch['id'];
        $branch['lat'] = (float)$branch['lat'];
        $branch['lng'] = (float)$branch['lng'];
        $branch['supports_delivery'] = (bool)$branch['supports_delivery'];
        $branch['supports_pickup'] = (bool)$branch['supports_pickup'];
    }
    unset($branch);
    return $branches;
}

function branch_is_within_philippines(float $lat, float $lng): bool {
    return $lat >= 4.5 && $lat <= 21.2 && $lng >= 116.9 && $lng <= 126.7;
}
