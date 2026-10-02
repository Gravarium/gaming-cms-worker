import {parseVideoUrl} from './video-url.js';
import {EditorHistory} from './history.js';
import {articleOutline, insertAfter, withinDocumentLimits} from './longform.js';
import {editTable} from './table-ops.js';

const root = document.getElementById('news-editor');
if (root) {
  const blocks = document.getElementById('news-editor-blocks');
  const source = document.getElementById('news-editor-document');
  const status = document.getElementById('news-editor-status');
  const count = document.getElementById('news-editor-count');
  const prefix = 'cms-rich:v2\n';
  let history;
  let activeRow = null;
  const outline = document.getElementById('news-editor-outline');
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
    const insert = node('button', 'Absatz darunter'); insert.type = 'button'; insert.setAttribute('aria-label', 'Absatz nach diesem Block einfügen');
    insert.addEventListener('click', () => addBlock({type: 'paragraph', content: [{text: '', marks: []}]}, row)); row.append(insert);
    const duplicate = node('button', 'Duplizieren'); duplicate.type = 'button'; duplicate.setAttribute('aria-label', 'Block duplizieren');
    duplicate.addEventListener('click', () => addBlock(serializeBlock(row), row)); row.append(duplicate);
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
      const header = input('Erste Zeile als Kopfzeile', '');
      const checkbox = header.querySelector('input'); checkbox.type = 'checkbox'; checkbox.checked = block.header !== false;
      let selectedRow = 0, selectedColumn = 0;
      const tableRows = () => [...table.rows].map(tr => [...tr.cells].map(runs));
      const draw = rows => {
        table.replaceChildren();
        for (const [index, cells] of rows.entries()) {
          const tr = node('tr');
          for (const cell of cells) {
            const field = editable(index === 0 && checkbox.checked ? 'th' : 'td', cell);
            if (field.tagName === 'TH') field.setAttribute('scope', 'col');
            tr.append(field);
          }
          table.append(tr);
        }
      };
      draw(block.rows ?? []);
      table.addEventListener('focusin', event => {
        const cell = event.target.closest('th,td');
        if (cell) { selectedRow = cell.parentElement.rowIndex; selectedColumn = cell.cellIndex; }
      });
      checkbox.addEventListener('change', () => { draw(tableRows()); table.rows[selectedRow]?.cells[selectedColumn]?.focus(); changed(); });
      row.append(header, table);
      for (const [label, operation] of [['Zeile darunter einfügen', 'insert-row'], ['Gewählte Zeile entfernen', 'remove-row'], ['Spalte rechts einfügen', 'insert-column'], ['Gewählte Spalte entfernen', 'remove-column']]) {
        const button = node('button', label); button.type = 'button';
        button.addEventListener('click', () => {
          const edited = editTable(tableRows(), operation, selectedRow, selectedColumn);
          if (!edited) { status.textContent = 'Tabellengrenze erreicht oder gewählte Zeile/Spalte nicht vorhanden.'; return; }
          const candidate = JSON.parse(serialize().slice(prefix.length));
          candidate.blocks[[...blocks.children].indexOf(row)].rows = edited;
          if (!withinDocumentLimits(candidate)) { status.textContent = 'Dokumentgrenze erreicht: Tabelle konnte nicht erweitert werden.'; return; }
          selectedRow = Math.min(selectedRow, edited.length - 1);
          selectedColumn = Math.min(selectedColumn, edited[0].length - 1);
          draw(edited); table.rows[selectedRow].cells[selectedColumn].focus(); changed();
        }); row.append(button);
      }
    }
    if (block.type === 'callout') {
      const tone = node('select'); tone.setAttribute('aria-label', 'Art der Infobox');
      for (const [value, label] of [['info', 'Information'], ['tip', 'Tipp'], ['warning', 'Warnung']]) {
        const option = node('option', label); option.value = value; tone.append(option);
      }
      tone.value = block.tone; row.append(tone, editable('div', block.content));
    }
    if (block.type === 'code') {
      const language = node('select'); language.setAttribute('aria-label', 'Codesprache');
      for (const value of ['plain', 'bash', 'css', 'html', 'javascript', 'json', 'php', 'python']) {
        const option = node('option', value); option.value = value; language.append(option);
      }
      language.value = block.language;
      const code = node('textarea'); code.rows = 8; code.value = block.text; code.setAttribute('aria-label', 'Code');
      row.append(language, code);
    }
    if (block.type === 'separator') row.append(node('hr'));
    if (block.type === 'media') {
      const id = input('Medien-ID', block.assetId);
      const alt = input('Alternativtext', block.alt);
      const caption = input('Bildunterschrift und Quelle', block.caption);
      const select = node('button', 'Bild auswählen'); select.type = 'button';
      select.addEventListener('click', () => openMediaPicker(id.querySelector('input'), alt.querySelector('input')));
      row.append(id, select, alt, caption);
    }
    if (block.type === 'video') {
      const provider = input('Anbieter (youtube/vimeo)', block.provider);
      const videoId = input('Video-ID oder Video-URL', block.videoId);
      const field = videoId.querySelector('input');
      field.addEventListener('change', () => {
        if (!/^https:\/\//i.test(field.value)) return;
        const parsed = parseVideoUrl(field.value);
        if (parsed) {
          provider.querySelector('input').value = parsed.provider;
          field.value = parsed.videoId;
          changed(); return;
        }
        status.textContent = 'Video-URL nicht erkannt. Bitte YouTube- oder Vimeo-Link prüfen.';
      });
      row.append(provider, videoId, input('Bildunterschrift', block.caption));
    }
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
    return prefix + JSON.stringify({version: 2, blocks: [...blocks.children].map(serializeBlock)});
  }
  function serializeBlock(row) {
      const type = row.dataset.type;
      if (type === 'paragraph' || type === 'heading' || type === 'quote') {
        const block = {type, ...(type === 'heading' ? {level: Number(row.dataset.level)} : {}), content: runs(row.querySelector('[contenteditable]'))};
        if (type === 'quote') block.cite = row.querySelector('input').value;
        return block;
      }
      if (type === 'list') return {type, ordered: row.dataset.ordered === 'true', items: [...row.querySelectorAll('li')].map(runs)};
      if (type === 'table') return {type, rows: [...row.querySelectorAll('tr')].map(tr => [...tr.cells].map(runs)), header: row.querySelector('input[type="checkbox"]').checked};
      if (type === 'callout') return {type, tone: row.querySelector('select').value, content: runs(row.querySelector('[contenteditable]'))};
      if (type === 'code') return {type, language: row.querySelector('select').value, text: row.querySelector('textarea').value};
      if (type === 'separator') return {type};
      if (type === 'media') { const [id, alt, caption] = row.querySelectorAll('input'); return {type, assetId: Number(id.value), alt: alt.value, caption: caption.value}; }
      if (type === 'video') { const [provider, videoId, caption] = row.querySelectorAll('input'); return {type, provider: provider.value, videoId: videoId.value, caption: caption.value}; }
      throw new Error('Unbekannter Blocktyp.');
  }
  function updateOutline() {
    outline.replaceChildren();
    const headings = articleOutline([...blocks.children].map(serializeBlock));
    if (!headings.length) { outline.append(node('p', 'Noch keine Überschriften.')); return; }
    for (const heading of headings) {
      const button = node('button', heading.title); button.type = 'button'; button.className = 'news-outline-heading news-outline-level-' + heading.level;
      button.setAttribute('aria-label', 'Zu Überschrift ' + heading.title);
      button.addEventListener('click', () => { const field = blocks.children[heading.index]?.querySelector('[contenteditable]'); field?.focus(); field?.scrollIntoView({block: 'center'}); });
      outline.append(button);
    }
  }
  function addBlock(block, afterRow) {
    const index = afterRow ? [...blocks.children].indexOf(afterRow) : -1;
    const next = insertAfter(JSON.parse(serialize().slice(prefix.length)), block, index);
    if (!next) { status.textContent = 'Dokumentgrenze erreicht: Block konnte nicht eingefügt werden.'; return; }
    renderBlock(block);
    const inserted = blocks.lastElementChild;
    if (afterRow) afterRow.after(inserted);
    activeRow = inserted;
    inserted.querySelector('[contenteditable], textarea, input, select, button')?.focus();
    changed();
  }
  function changed(event) {
    status.textContent = 'Ungespeicherte Änderungen.';
    count.textContent = [...blocks.querySelectorAll('[contenteditable], textarea')].map(el => el.value ?? el.textContent).join(' ').trim().split(/\s+/u).filter(Boolean).length + ' Wörter';
    history?.record(serialize(), {input: event?.type === 'input'});
    updateOutline();
  }
  history = new EditorHistory(serialize());
  changed(); status.textContent = '';
  function restore(snapshot) {
    if (snapshot === null) return;
    const activeRow = document.activeElement?.closest('.news-editor-block');
    const rowIndex = activeRow ? [...blocks.children].indexOf(activeRow) : 0;
    blocks.replaceChildren();
    for (const block of JSON.parse(snapshot.slice(prefix.length)).blocks) renderBlock(block);
    source.value = snapshot;
    const target = blocks.children[Math.max(0, Math.min(rowIndex, blocks.children.length - 1))];
    target?.querySelector('[contenteditable], textarea, input, select')?.focus();
    status.textContent = 'Ungespeicherte Änderung in der Dokument-Historie.';
    count.textContent = [...blocks.querySelectorAll('[contenteditable], textarea')].map(el => el.value ?? el.textContent).join(' ').trim().split(/\s+/u).filter(Boolean).length + ' Wörter';
    updateOutline();
  }
  blocks.addEventListener('focusin', event => { activeRow = event.target.closest('.news-editor-block'); });
  root.addEventListener('keydown', event => {
    if (!blocks.contains(event.target)) return;
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
    const key = event.key.toLowerCase();
    if (key === 'z' || key === 'y') {
      event.preventDefault();
      restore(key === 'y' || event.shiftKey ? history.redo() : history.undo());
    }
  });
  document.getElementById('news-editor-form').addEventListener('submit', () => { source.value = serialize(); });
  root.querySelectorAll('[data-command]').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      let command = button.dataset.command;
      if (command === 'undo' || command === 'redo') {
        restore(command === 'undo' ? history.undo() : history.redo()); return;
      }
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
    table: {type: 'table', rows: [[[ {text: '', marks: []} ], [ {text: '', marks: []} ]]], header: true},
    callout: {type: 'callout', tone: 'info', content: [{text: '', marks: []}]},
    code: {type: 'code', language: 'plain', text: ''},
    separator: {type: 'separator'},
    media: {type: 'media', assetId: 0, alt: '', caption: ''},
    video: {type: 'video', provider: 'youtube', videoId: '', caption: ''},
  };
  root.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    addBlock(defaults[button.dataset.add], activeRow?.isConnected ? activeRow : blocks.lastElementChild);
  }));
  const picker = document.getElementById('news-editor-media-picker');
  const mediaSearch = document.getElementById('news-editor-media-search');
  const mediaResults = document.getElementById('news-editor-media-results');
  const mediaMessage = document.getElementById('news-editor-media-message');
  let selectedMediaField = null;
  let selectedAltField = null;
  async function searchMedia() {
    mediaMessage.textContent = 'Bilder werden geladen …'; mediaResults.replaceChildren();
    try {
      const url = new URL(root.dataset.mediaUrl, window.location.href);
      url.searchParams.set('q', mediaSearch.value);
      const response = await fetch(url, {credentials: 'same-origin'});
      if (!response.ok) throw new Error('Mediensuche fehlgeschlagen.');
      const {items} = await response.json();
      for (const item of items) {
        const button = node('button'); button.type = 'button'; button.className = 'news-editor-media-result';
        const thumbnail = node('img'); thumbnail.src = item.url; thumbnail.alt = '';
        thumbnail.loading = 'lazy'; thumbnail.referrerPolicy = 'no-referrer';
        button.append(thumbnail, node('span', item.title));
        button.addEventListener('click', () => {
          selectedMediaField.value = String(item.id);
          if (!selectedAltField.value) selectedAltField.value = item.title;
          picker.hidden = true; selectedMediaField.focus(); changed();
        }); mediaResults.append(button);
      }
      mediaMessage.textContent = items.length ? items.length + ' Bilder gefunden.' : 'Keine freigegebenen Bilder gefunden.';
    } catch { mediaMessage.textContent = 'Mediensuche fehlgeschlagen.'; }
  }
  function openMediaPicker(idField, altField) {
    selectedMediaField = idField; selectedAltField = altField;
    picker.hidden = false; mediaSearch.focus(); searchMedia();
  }
  document.getElementById('news-editor-media-search-button').addEventListener('click', searchMedia);
  mediaSearch.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchMedia(); } });
  document.getElementById('news-editor-media-close').addEventListener('click', () => { picker.hidden = true; selectedMediaField?.focus(); });
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
