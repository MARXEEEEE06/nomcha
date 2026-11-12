document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-image-input]').forEach(function (input) {
    const preview = document.querySelector(input.dataset.imageInput);
    if (!preview) return;
    input.addEventListener('change', function () {
      const file = input.files && input.files[0];
      if (!file) return;
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = function () { preview.src = String(reader.result); preview.hidden = false; };
      reader.readAsDataURL(file);
    });
  });
});
