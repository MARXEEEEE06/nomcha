<?php
require_once 'includes/auth_helpers.php';
$pageTitle = 'Nomcha Branches';
$branches = json_decode(file_get_contents(__DIR__ . '/assets/branches-data.json'), true) ?: [];
$pageStyles = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""><link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"><link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">';
require 'includes/header.php';
?>

<main class="branches-page">
    <section class="branches-hero">
        <div class="container py-5">
            <p class="order-eyebrow mb-2">Find your nearest Nomcha</p>
            <h1 class="mb-2">Nomcha Branches</h1>
            <p class="mb-0">Find a nearby branch, check its hours, and plan your visit.</p>
        </div>
    </section>

    <section class="container py-4 py-lg-5" aria-label="Branch finder">
        <div class="branch-search-panel">
            <div class="branch-search-field">
                <label for="branch-search" class="form-label">Search branches</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input id="branch-search" class="form-control" type="search" placeholder="Search by branch, address, or area" autocomplete="off">
                </div>
            </div>
            <div>
                <label for="branch-province" class="form-label">Province</label>
                <select id="branch-province" class="form-select"><option value="">All provinces</option></select>
            </div>
            <div>
                <label for="branch-city" class="form-label">City</label>
                <select id="branch-city" class="form-select"><option value="">All cities</option></select>
            </div>
            <button type="button" id="branch-use-location" class="btn btn-order branch-location-button" aria-pressed="false">
                <i class="bi bi-crosshair me-2" aria-hidden="true"></i><span>Locate nearest branch</span>
            </button>
        </div>
        <p id="branch-location-status" class="branch-location-status" role="status" aria-live="polite"></p>

        <div class="row g-4 align-items-start">
            <section class="col-lg-5" aria-label="Branch results">
                <div class="branch-results-heading">
                    <h2 class="h4 mb-0">Nearby branches</h2>
                    <span id="branch-result-count" class="branch-result-count"></span>
                </div>
                <div id="branch-results" class="branch-results"></div>
                <p id="branch-empty" class="branch-empty" hidden>No branches match your search. Try another name, city, or province.</p>
            </section>
            <div class="col-lg-7">
                <div id="branch-map" class="branch-map" role="application" aria-label="Map of branch locations"></div>
                <p class="branch-map-credit">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a></p>
            </div>
        </div>
    </section>
</main>

<div class="modal fade" id="branch-range-modal" tabindex="-1" aria-labelledby="branch-range-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="branch-range-modal-title">No branches within range</h2>
            </div>
            <div class="modal-body">No branches are available within your area and the 10 km range limit. We’re showing the nearest branch only.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-order" data-bs-dismiss="modal">Understood</button>
            </div>
        </div>
    </div>
</div>

<script id="branch-data" type="application/json"><?= json_encode($branches, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php
$pageScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script><script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script><script src="assets/js/branches.js?v=' . filemtime(__DIR__ . '/assets/js/branches.js') . '"></script>';
require 'includes/footer.php';
?>
