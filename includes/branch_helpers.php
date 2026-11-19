<?php
function get_public_branches(PDO $pdo): array {
    $branches = $pdo->query("SELECT branch_id AS id,name,address,city,province,latitude AS lat,longitude AS lng,phone,schedule,takeout,pickup FROM branches WHERE status='open' ORDER BY name")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($branches as &$branch) {
        $branch['id'] = (int)$branch['id'];
        $branch['lat'] = (float)$branch['lat'];
        $branch['lng'] = (float)$branch['lng'];
        $branch['takeOut'] = (bool)$branch['takeout'];
        $branch['pickup'] = (bool)$branch['pickup'];
        unset($branch['takeout']);
    }
    unset($branch);
    return $branches;
}

function branch_is_within_philippines(float $lat, float $lng): bool {
    return $lat >= 4.5 && $lat <= 21.2 && $lng >= 116.9 && $lng <= 126.7;
}
