// ===== Client-side form validation =====
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', e => {
      let ok = true;
      form.querySelectorAll('[required]').forEach(field => {
        const errId = field.id + '-err';
        let err = document.getElementById(errId);
        if (!err) {
          err = document.createElement('small');
          err.id = errId; err.className = 'error';
          field.parentNode.appendChild(err);
        }
        err.textContent = '';
        if (!field.value.trim()) {
          err.textContent = 'This field is required.';
          ok = false;
        } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
          err.textContent = 'Invalid email format.';
          ok = false;
        } else if (field.type === 'password' && field.dataset.min && field.value.length < +field.dataset.min) {
          err.textContent = `Minimum ${field.dataset.min} characters.`;
          ok = false;
        }
      });
      if (!ok) e.preventDefault();
    });
  });

  // ===== Image preview before upload =====
  document.querySelectorAll('input[type=file][data-preview]').forEach(input => {
    input.addEventListener('change', () => {
      const target = document.getElementById(input.dataset.preview);
      if (!target) return;
      const file = input.files[0];
      if (!file) return;
      if (!file.type.startsWith('image/')) { alert('Please select an image.'); input.value=''; return; }
      const reader = new FileReader();
      reader.onload = ev => target.src = ev.target.result;
      reader.readAsDataURL(file);
    });
  });

  // ===== Live search =====
  const searchInput = document.getElementById('live-search');
  if (searchInput) {
    const results = document.getElementById('search-results');
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      const q = searchInput.value.trim();
      if (q.length < 2) { results.innerHTML = ''; return; }
      timer = setTimeout(() => {
        fetch('search.php?ajax=1&q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(data => {
            results.innerHTML = data.length
              ? data.map(item => `<div class="search-item"><a href="${item.url}">${item.label}</a></div>`).join('')
              : '<div class="search-item muted">No results</div>';
          });
      }, 300);
    });
  }
});