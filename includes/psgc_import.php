<?php

/** Import the packaged PSGC reference JSON using prepared statements. Existing rows are updated in place. */
function import_psgc_reference_data(PDO $pdo, string $path): int {
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The PSGC reference file is missing or unreadable.');
    $raw=file_get_contents($path);
    if ($raw===false) throw new RuntimeException('The PSGC reference file could not be read.');
    try { $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('The PSGC reference file is not valid JSON.'); }
    if (!is_array($data)) throw new RuntimeException('The PSGC reference file has an invalid structure.');

    $tables=['regions','provinces','localities','barangays'];
    foreach ($tables as $table) if (!isset($data[$table])||!is_array($data[$table])||!array_is_list($data[$table])) throw new RuntimeException('The PSGC reference file is missing a required list.');
    $validCode=static fn($value): bool => is_string($value)&&preg_match('/^\d{10}$/D',$value)===1;
    $validName=static fn($value): bool => is_string($value)&&trim($value)!==''&&mb_strlen($value)<=120;
    foreach ($data['regions'] as $row) if (!is_array($row)||!$validCode($row['psgc_code']??null)||!$validName($row['name']??null)) throw new RuntimeException('A PSGC region row is invalid.');
    foreach ($data['provinces'] as $row) if (!is_array($row)||!$validCode($row['psgc_code']??null)||!$validCode($row['region_code']??null)||!$validName($row['name']??null)) throw new RuntimeException('A PSGC province row is invalid.');
    foreach ($data['localities'] as $row) {
        if (!is_array($row)||!$validCode($row['psgc_code']??null)||!$validName($row['name']??null)||!in_array($row['locality_type']??null,['city','municipality'],true)) throw new RuntimeException('A PSGC city / municipality row is invalid.');
        $hasRegion=($row['region_code']??null)!==null&&$validCode($row['region_code']);
        $hasProvince=($row['province_code']??null)!==null&&$validCode($row['province_code']);
        if ($hasRegion===$hasProvince) throw new RuntimeException('A PSGC city / municipality must have exactly one region or province parent.');
    }
    foreach ($data['barangays'] as $row) if (!is_array($row)||!$validCode($row['psgc_code']??null)||!$validCode($row['locality_code']??null)||!$validName($row['name']??null)) throw new RuntimeException('A PSGC barangay row is invalid.');
    if ($pdo->inTransaction()) throw new RuntimeException('The PSGC import cannot run inside another transaction.');

    $upserts=[
        'regions'=>$pdo->prepare('INSERT INTO psgc_regions(psgc_code,name) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)'),
        'provinces'=>$pdo->prepare('INSERT INTO psgc_provinces(psgc_code,region_code,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE region_code=VALUES(region_code),name=VALUES(name)'),
        'localities'=>$pdo->prepare('INSERT INTO psgc_localities(psgc_code,region_code,province_code,name,locality_type) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE region_code=VALUES(region_code),province_code=VALUES(province_code),name=VALUES(name),locality_type=VALUES(locality_type)'),
        'barangays'=>$pdo->prepare('INSERT INTO psgc_barangays(psgc_code,locality_code,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE locality_code=VALUES(locality_code),name=VALUES(name)'),
    ];
    $pdo->beginTransaction();
    try {
        $count=0;
        foreach ($data['regions'] as $row) { $upserts['regions']->execute([$row['psgc_code'],$row['name']]); $count++; }
        foreach ($data['provinces'] as $row) { $upserts['provinces']->execute([$row['psgc_code'],$row['region_code'],$row['name']]); $count++; }
        foreach ($data['localities'] as $row) { $upserts['localities']->execute([$row['psgc_code'],$row['region_code']??null,$row['province_code']??null,$row['name'],$row['locality_type']]); $count++; }
        foreach ($data['barangays'] as $row) { $upserts['barangays']->execute([$row['psgc_code'],$row['locality_code'],$row['name']]); $count++; }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
