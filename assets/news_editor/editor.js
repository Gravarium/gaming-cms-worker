const root = document.getElementById('news-editor');
if (root) {
  const blocks = document.getElementById('news-editor-blocks');
  const source = document.getElementById('news-editor-document');
  const status = document.getElementById('news-editor-status');
  const count = document.getElementById('news-editor-count');
  const prefix = 'cms-rich:v2\n';
  let initial;
  try { initial = JSON.parse(source.value.slice(prefix.length)); }
  catch { initial = {version: 2, blocks: [{type: 'paragraph', content: [{text: '', marks: []}]}]}; status.textContent = 'Dokument konnte nicht geöffnet werden.'; }

  const node = (tag, text = '') => { const el = document.createElement(tag); el.textContent = text; return el; };
  const input = (label, value) => { const wrapper = node('label', label + ' '); const field = node('input'); field.value = value ?? ''; wrapper.append(field); return wrapper; };
  const editable = (tag, content) => {
    const el = node(tag); el.contentEditable = 'true'; el.setAttribute('role', 'textbox'); el.setAttribute('aria-multiline', 'true');
    for (const run of content ?? []) {
      let child = document.createTextNode(run.text ?? '');
      for (const mark of run.marks ?? []) {
        const tagName = {strong: 'strong', em: 'em', underline: 'u', strike: 's', code: 'code', link: 'a'}[mark];
        if (!tagName) continue;
        const container = node(tagName);
        if (tagName === 'a' && typeof run.href === 'string') container.setAttribute('href', run.href);
        container.append(child); child = container;
      }
      el.append(child);
    }
    return el;
  };
  function renderBlock(block) {
    const row = node('section'); row.className = 'news-editor-block'; row.dataset.type = block.type;
    const remove = node('button', 'Entfernen'); remove.type = 'button'; remove.className = 'remove-block'; remove.setAttribute('aria-label', 'Block entfernen');
    remove.addEventListener('click', () => { if (blocks.children.length > 1) { row.remove(); changed(); } }); row.append(remove);
    for (const [label, direction] of [['↑', -1], ['↓', 1]]) {
      const move = node('button', label); move.type = 'button'; move.className = 'move-block';
      move.setAttribute('aria-label', direction < 0 ? 'Block nach oben' : 'Block nach unten');
      move.addEventListener('click', () => {
        const sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        if (direction < 0) blocks.insertBefore(row, sibling);
        else blocks.insertBefore(sibling, row);
        changed(); move.focus();
      }); row.append(move);
    }
    if (block.type === 'paragraph') row.append(editable('p', block.content));
    if (block.type === 'heading') {
      row.dataset.level = String(block.level);
      const level = node('select'); level.setAttribute('aria-label', 'Überschriftenebene');
      for (const value of [2, 3, 4]) { const option = node('option', 'Überschrift '+value); option.value = String(value); level.append(option); }
      level.value = String(block.level);
      let heading = editable('h' + block.level, block.content);
      level.addEventListener('change', () => { row.dataset.level = level.value; const replacement = editable('h' + level.value, runs(heading)); heading.replaceWith(replacement); heading = replacement; changed(); });
      row.append(level, heading);
    }
    if (block.type === 'quote') { row.append(editable('blockquote', block.content), input('Quelle', block.cite)); }
    if (block.type === 'list') {
      row.dataset.ordered = String(block.ordered); let list = node(block.ordered ? 'ol' : 'ul');
      const listType = node('select'); listType.setAttribute('aria-label', 'Listentyp');
      for (const [value, label] of [['false', 'Aufzählung'], ['true', 'Nummerierung']]) { const option = node('option', label); option.value = value; listType.append(option); }
      listType.value = row.dataset.ordered;
      listType.addEventListener('change', () => { row.dataset.ordered = listType.value; const replacement = node(listType.value === 'true' ? 'ol' : 'ul'); replacement.append(...list.childNodes); list.replaceWith(replacement); list = replacement; changed(); });
      row.append(listType);
      for (const item of block.items ?? []) list.append(editable('li', item)); row.append(list);
      const addItem = node('button', 'Listenpunkt hinzufügen'); addItem.type = 'button'; addItem.addEventListener('click', () => { list.append(editable('li', [{text: '', marks: []}])); changed(); }); row.append(addItem);
    }
    if (block.type === 'table') {
      const table = node('table');
      for (const cells of block.rows ?? []) { const tr = node('tr'); for (const cell of cells) tr.append(editable('td', cell)); table.append(tr); }
      row.append(table);
      const addRow = node('button', 'Zeile hinzufügen'); addRow.type = 'button'; addRow.addEventListener('click', () => {
        if (table.rows.length >= 20) return;
        const tr = node('tr'); for (let i = 0; i < table.rows[0].cells.length; i++) tr.append(editable('td', [{text: '', marks: []}])); table.append(tr); changed();
      }); row.append(addRow);
      const addColumn = node('button', 'Spalte hinzufügen'); addColumn.type = 'button'; addColumn.addEventListener('click', () => {
        if (table.rows[0].cells.length >= 10) return;
        for (const tr of table.rows) tr.append(editable('td', [{text: '', marks: []}])); changed();
      }); row.append(addColumn);
      const removeRow = node('button', 'Letzte Zeile entfernen'); removeRow.type = 'button'; removeRow.addEventListener('click', () => {
        if (table.rows.length > 1) { table.lastElementChild.remove(); changed(); }
      }); row.append(removeRow);
      const removeColumn = node('button', 'Letzte Spalte entfernen'); removeColumn.type = 'button'; removeColumn.addEventListener('click', () => {
        if (table.rows[0].cells.length > 1) { for (const tr of table.rows) tr.lastElementChild.remove(); changed(); }
      }); row.append(removeColumn);
    }
    if (block.type === 'media') { row.append(input('Medien-ID', block.assetId), input('Alternativtext', block.alt), input('Bildunterschrift und Quelle', block.caption)); }
    if (block.type === 'video') { row.append(input('Anbieter (youtube/vimeo)', block.provider), input('Video-ID', block.videoId), input('Bildunterschrift', block.caption)); }
    row.addEventListener('input', changed); blocks.append(row);
  }
  for (const block of initial.blocks ?? []) renderBlock(block);
  if (!blocks.children.length) renderBlock({type: 'paragraph', content: [{text: '', marks: []}]});

  function runs(element) {
    const result = [];
    function walk(current, marks = [], href) {
      if (current.nodeType === Node.TEXT_NODE) {
        if (current.nodeValue) result.push({...{text: current.nodeValue, marks}, ...(href ? {href} : {})});
        return;
      }
      if (current.nodeType !== Node.ELEMENT_NODE) return;
      const tag = current.tagName.toLowerCase();
      if (tag === 'br') { result.push({text: '\n', marks: []}); return; }
      const mark = {strong: 'strong', b: 'strong', em: 'em', i: 'em', u: 'underline', s: 'strike', strike: 'strike', code: 'code', a: 'link'}[tag];
      const nextMarks = mark && !marks.includes(mark) ? [...marks, mark] : marks;
      const nextHref = tag === 'a' ? current.getAttribute('href') : href;
      for (const child of current.childNodes) walk(child, nextMarks, nextHref);
    }
    for (const child of element.childNodes) walk(child);
    return result.length ? result : [{text: '', marks: []}];
  }
  function serialize() {
    const output = [];
    for (const row of blocks.children) {
      const type = row.dataset.type;
      if (type === 'paragraph' || type === 'heading' || type === 'quote') {
        const block = {type, ...(type === 'heading' ? {level: Number(row.dataset.level)} : {}), content: runs(row.querySelector('[contenteditable]'))};
        if (type === 'quote') block.cite = row.querySelector('input').value;
        output.push(block);
      } else if (type === 'list') output.push({type, ordered: row.dataset.ordered === 'true', items: [...row.querySelectorAll('li')].map(runs)});
      else if (type === 'table') output.push({type, rows: [...row.querySelectorAll('tr')].map(tr => [...tr.cells].map(runs))});
      else if (type === 'media') { const [id, alt, caption] = row.querySelectorAll('input'); output.push({type, assetId: Number(id.value), alt: alt.value, caption: caption.value}); }
      else if (type === 'video') { const [provider, videoId, caption] = row.querySelectorAll('input'); output.push({type, provider: provider.value, videoId: videoId.value, caption: caption.value}); }
    }
    return prefix + JSON.stringify({version: 2, blocks: output});
  }
  function changed() {
    status.textContent = 'Ungespeicherte Änderungen.';
    count.textContent = [...blocks.querySelectorAll('[contenteditable]')].map(el => el.textContent).join(' ').trim().split(/\s+/u).filter(Boolean).length + ' Wörter';
  }
  changed(); status.textContent = '';
  document.getElementById('news-editor-form').addEventListener('submit', () => { source.value = serialize(); });
  root.querySelectorAll('[data-command]').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      let command = button.dataset.command;
      if (command === 'link') {
        const url = window.prompt('Linkziel (HTTPS oder interner Pfad):'); if (!url) return;
        document.execCommand('createLink', false, url);
      } else document.execCommand(command, false);
      changed();
    });
  });
  const defaults = {
    paragraph: {type: 'paragraph', content: [{text: '', marks: []}]},
    heading: {type: 'heading', level: 2, content: [{text: '', marks: []}]},
    quote: {type: 'quote', content: [{text: '', marks: []}], cite: ''},
    list: {type: 'list', ordered: false, items: [[{text: '', marks: []}]]},
    table: {type: 'table', rows: [[[ {text: '', marks: []} ], [ {text: '', marks: []} ]]]},
    media: {type: 'media', assetId: 0, alt: '', caption: ''},
    video: {type: 'video', provider: 'youtube', videoId: '', caption: ''},
  };
  root.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    renderBlock(structuredClone(defaults[button.dataset.add])); blocks.lastElementChild.querySelector('[contenteditable], input')?.focus(); changed();
  }));
  blocks.addEventListener('paste', event => {
    if (!event.target.closest('[contenteditable]')) return;
    event.preventDefault();
    document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
  });
  document.getElementById('news-editor-preview-button').addEventListener('click', async () => {
    status.textContent = 'Vorschau wird geladen …';
    try {
      const response = await fetch(root.dataset.previewUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf}, body: JSON.stringify({document: serialize()})});
      const data = await response.json();
      if (!response.ok) { status.textContent = data.error ?? 'Vorschau fehlgeschlagen.'; return; }
      document.getElementById('news-editor-preview').innerHTML = data.html; status.textContent = 'Server-Vorschau aktualisiert.';
    } catch { status.textContent = 'Vorschau konnte nicht geladen werden.'; }
  });
}
