document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-otp-countdown]').forEach(function (counter) {
    const button = document.querySelector(counter.dataset.resendTarget);
    let remaining = Math.max(0, Number(counter.dataset.remaining || 0));
    if (!button) return;
    button.disabled = remaining > 0;
    function tick() {
      if (remaining <= 0) {
        counter.textContent = 'You can request a new code now.';
        button.disabled = false;
        return;
      }
      const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
      const seconds = String(remaining % 60).padStart(2, '0');
      counter.textContent = 'Resend available in ' + minutes + ':' + seconds;
      button.disabled = true;
      remaining -= 1;
      window.setTimeout(tick, 1000);
    }
    tick();
  });
});
