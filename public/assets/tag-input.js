(function () {
  function normalizeTag(value) {
    return value.trim().replace(/\s+/g, ' ').toLowerCase();
  }
  document.querySelectorAll('.js-tag-input').forEach(function (root) {
    const hidden = root.querySelector('.js-tag-hidden');
    const input = root.querySelector('.js-tag-text');
    const chips = root.querySelector('.js-tag-chips');
    const list = root.querySelector('.js-tag-suggestions');
    if (!hidden || !input || !chips || !list) return;

    let selected = [];
    let suggestions = [];
    let activeIndex = -1;

    function syncHidden() { hidden.value = selected.join(', '); }
    function renderChips() {
      chips.innerHTML = '';
      selected.forEach(function (tag, index) {
        const chip = document.createElement('span'); chip.className='tag-chip'; chip.textContent = tag;
        const btn = document.createElement('button'); btn.type='button'; btn.className='tag-chip-remove'; btn.textContent='×';
        btn.addEventListener('click', function(){ selected.splice(index,1); renderChips(); syncHidden(); });
        chip.appendChild(btn); chips.appendChild(chip);
      });
    }
    function addTag(raw) {
      const clean = raw.trim().replace(/\s+/g, ' ');
      const norm = normalizeTag(clean);
      if (!norm || clean.length > 50) return;
      if (selected.some(t => normalizeTag(t) === norm)) return;
      selected.push(clean);
      renderChips(); syncHidden(); input.value=''; closeList();
    }
    function closeList(){ list.hidden=true; list.innerHTML=''; suggestions=[]; activeIndex=-1; }
    function renderList(query) {
      list.innerHTML='';
      suggestions.forEach(function (s, i) {
        const li = document.createElement('button'); li.type='button'; li.className='tag-suggestion' + (i===activeIndex?' is-active':''); li.textContent = s.name;
        li.addEventListener('click', function(){ addTag(s.name); });
        list.appendChild(li);
      });
      const exists = suggestions.some(s => normalizeTag(s.name)===normalizeTag(query)) || selected.some(t=>normalizeTag(t)===normalizeTag(query));
      if (query.trim() && !exists) {
        const create = document.createElement('button'); create.type='button'; create.className='tag-suggestion tag-create' + (activeIndex===suggestions.length?' is-active':'');
        create.textContent = 'Create tag "' + query.trim() + '"';
        create.addEventListener('click', function(){ addTag(query); });
        list.appendChild(create);
      }
      list.hidden = list.children.length === 0;
    }

    selected = hidden.value.split(',').map(v=>v.trim()).filter(Boolean);
    selected = selected.filter((v,i,a)=>a.findIndex(x=>normalizeTag(x)===normalizeTag(v))===i);
    renderChips(); syncHidden();

    input.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) { e.preventDefault(); if (activeIndex>=0 && list.children[activeIndex]) {list.children[activeIndex].click();} else {addTag(input.value);} return; }
      if (e.key === 'Backspace' && !input.value && selected.length) { selected.pop(); renderChips(); syncHidden(); }
      if (e.key === 'Escape') { closeList(); }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        const max = list.children.length - 1; if (max < 0) return;
        e.preventDefault();
        activeIndex = e.key === 'ArrowDown' ? Math.min(max, activeIndex + 1) : Math.max(0, activeIndex - 1);
        Array.from(list.children).forEach((el, i)=>el.classList.toggle('is-active', i===activeIndex));
      }
    });
    let timer = null;
    input.addEventListener('input', function () {
      clearTimeout(timer); const q=input.value.trim(); if (!q) { closeList(); return; }
      timer = setTimeout(function () {
        fetch('/api/tags/search.php?q=' + encodeURIComponent(q)).then(r=>r.json()).then(function(data){ suggestions = Array.isArray(data)?data.slice(0,10):[]; activeIndex=-1; renderList(q); }).catch(closeList);
      }, 120);
    });
  });
})();
