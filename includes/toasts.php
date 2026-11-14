<?php
/** Shared Nomcha toast renderer. Messages may include kind, title, text, and items. */
function render_toasts(array $messages): void {
    $messages = array_values(array_filter($messages, static fn($message) => is_array($message) && (!empty($message['text']) || !empty($message['items']))));
    if (!$messages) return;
    echo '<div class="order-toast-container" aria-live="assertive" aria-atomic="false">';
    foreach ($messages as $message) {
        $kind = in_array($message['kind'] ?? '', ['danger', 'warning', 'success'], true) ? $message['kind'] : 'warning';
        $icon = ['danger' => 'bi-exclamation-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'success' => 'bi-check-circle-fill'][$kind];
        echo '<div class="order-toast order-toast-' . $kind . '" role="alert"><i class="bi ' . $icon . '" aria-hidden="true"></i><div>';
        if (!empty($message['title'])) echo '<strong>' . e((string)$message['title']) . '</strong>';
        if (!empty($message['text'])) echo (!empty($message['title']) ? ' ' : '') . e((string)$message['text']);
        if (!empty($message['items']) && is_array($message['items'])) {
            echo '<ul>';
            foreach ($message['items'] as $item) echo '<li>' . e((string)$item) . '</li>';
            echo '</ul>';
        }
        echo '</div><button type="button" class="order-toast-close" data-order-toast-close aria-label="Dismiss">&times;</button></div>';
    }
    echo '</div><script>document.addEventListener("click",e=>{const b=e.target.closest("[data-order-toast-close]");if(b)b.closest(".order-toast")?.remove()});document.querySelectorAll(".order-toast").forEach(t=>{const life=7000;setTimeout(()=>{t.style.transition="opacity .25s,transform .25s";t.style.opacity="0";t.style.transform="translateY(-8px)";setTimeout(()=>t.remove(),280)},life)});</script>';
}
