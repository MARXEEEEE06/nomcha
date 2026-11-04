(function () {
  var pw = document.getElementById('password');
  var list = document.getElementById('pw-rules');
  if (!pw || !list) return;

  var rules = {
    len:     function (v) { return v.length >= 8; },
    lower:   function (v) { return /[a-z]/.test(v); },
    upper:   function (v) { return /[A-Z]/.test(v); },
    number:  function (v) { return /\d/.test(v); },
    special: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };

  function update() {
    Object.keys(rules).forEach(function (key) {
      var li = list.querySelector('[data-rule="' + key + '"]');
      if (!li) return;
      var ok = rules[key](pw.value);
      li.classList.toggle('ok', ok);
      li.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    });
  }

  pw.addEventListener('input', update);
  update();
})();

(function () {
  document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentElement.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });
})();