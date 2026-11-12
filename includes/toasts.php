<?php
function render_toasts(array $messages): void {
    $messages = array_values(array_filter($messages, static fn($message) => is_array($message) && !empty($message['text'])));
    if (!$messages) return;
    echo '<div class="toast-container position-fixed top-0 end-0 p-3 nomcha-toast-stack" aria-live="polite" aria-atomic="true">';
    foreach ($messages as $message) {
        $kind = in_array($message['kind'] ?? '', ['success','danger','warning','info'], true) ? $message['kind'] : 'info';
        echo '<div class="toast align-items-center text-bg-' . $kind . ' border-0 mb-2" role="status" data-bs-delay="6500"><div class="d-flex"><div class="toast-body">' . e((string)$message['text']) . '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
    }
    echo '</div><script>document.addEventListener("DOMContentLoaded",()=>document.querySelectorAll(".nomcha-toast-stack .toast").forEach(node=>new bootstrap.Toast(node).show()));</script>';
}
