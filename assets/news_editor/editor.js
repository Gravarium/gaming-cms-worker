import {parseVideoUrl, videoPreviewUrl} from './video-url.js';
import {EditorHistory} from './history.js';
import {articleOutline, insertAfter, withinDocumentLimits} from './longform.js';
import {editTable} from './table-ops.js';
import {safeEditorHref, clipboardRuns, editList, replaceArticle, findArticleBlocks} from './authoring.js';
import {AutosaveState} from './autosave-state.js';
import {structuredMarkdown} from './markdown-paste.js';

const root = document.getElementById('news-editor');
if (root) {
  const blocks = document.getElementById('news-editor-blocks');
  const source = document.getElementById('news-editor-document');
  const status = document.getElementById('news-editor-status');
  const count = document.getElementById('news-editor-count');
  const search = document.getElementById('news-editor-find');
  const replacement = document.getElementById('news-editor-replace');
  const searchResult = document.getElementById('news-editor-find-result');
  const prefix = 'cms-rich:v2\n';
  let history;
  let autosaveState;
  let autosaveTimer;
  let submitting = false;
  let findCursor = -1;
  let activeRow = null;
  const outline = document.getElementById('news-editor-outline');
  let initial;
  let openError = false;
  try {
    if (!source.value.startsWith(prefix)) throw new Error('Dokumentformat');
    initial = JSON.parse(source.value.slice(prefix.length));
    if (initial.version !== 2 || !Array.isArray(initial.blocks) || !initial.blocks.length) throw new Error('Dokumentstruktur');
  }
  catch { initial = {version: 2, blocks: [{type: 'paragraph', content: [{text: '', marks: []}]}]}; openError = true; status.textContent = 'Dokument konnte nicht geöffnet werden.'; }

  const node = (tag, text = '') => { const el = document.createElement(tag); el.textContent = text; return el; };
  const closestFrom = (value, selector) => (value?.nodeType === Node.ELEMENT_NODE ? value : value?.parentElement)?.closest(selector);
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
      let selectedItem = 0;
      const listType = node('select'); listType.setAttribute('aria-label', 'Listentyp');
      for (const [value, label] of [['false', 'Aufzählung'], ['true', 'Nummerierung']]) { const option = node('option', label); option.value = value; listType.append(option); }
      listType.value = row.dataset.ordered;
      listType.addEventListener('change', () => { row.dataset.ordered = listType.value; const replacement = node(listType.value === 'true' ? 'ol' : 'ul'); replacement.append(...list.childNodes); list.replaceWith(replacement); list = replacement; changed(); });
      row.append(listType);
      const draw = items => { list.replaceChildren(); for (const item of items) list.append(editable('li', item)); };
      draw(block.items ?? []); row.append(list);
      row.addEventListener('focusin', event => { if (list.contains(event.target)) selectedItem = [...list.children].indexOf(event.target.closest('li')); });
      const apply = action => {
        const items = [...list.children].map(runs);
        const edited = editList(items, action, selectedItem);
        if (!edited) { status.textContent = 'Listenpunkt kann nicht geändert werden.'; return; }
        const candidate = JSON.parse(serialize().slice(prefix.length));
        candidate.blocks[[...blocks.children].indexOf(row)].items = edited;
        if (!withinDocumentLimits(candidate)) { status.textContent = 'Dokumentgrenze erreicht.'; return; }
        selectedItem = Math.max(0, Math.min(edited.length - 1, selectedItem + (action === 'insert' || action === 'down' ? 1 : action === 'up' ? -1 : 0)));
        draw(edited); list.children[selectedItem].focus(); changed();
      };
      row.addEventListener('keydown', event => { if (list.contains(event.target) && event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); apply('insert'); } });
      for (const [label, action] of [['Punkt darunter', 'insert'], ['Gewählten Punkt entfernen', 'remove'], ['Punkt nach oben', 'up'], ['Punkt nach unten', 'down']]) {
        const button = node('button', label); button.type = 'button'; button.addEventListener('click', () => apply(action)); row.append(button);
      }
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
      const idField = id.querySelector('input'), altField = alt.querySelector('input'), captionField = caption.querySelector('input');
      const figure = node('figure'); figure.className = 'news-editor-figure';
      const image = node('img'); image.hidden = true; image.loading = 'lazy'; image.referrerPolicy = 'no-referrer';
      const figcaption = node('figcaption', captionField.value);
      const message = node('p'); figure.append(image, figcaption, message);
      let lookup = 0;
      const refreshImage = async () => {
        const serial = ++lookup;
        image.hidden = true; image.removeAttribute('src');
        if (!/^[1-9][0-9]{0,18}$/.test(idField.value)) { message.textContent = 'Bild aus der Mediathek auswählen.'; return; }
        message.textContent = 'Bild wird geladen …';
        try {
          const url = new URL(root.dataset.mediaUrl, window.location.href); url.searchParams.set('id', idField.value);
          const response = await fetch(url, {credentials: 'same-origin'});
          if (!response.ok) throw new Error('Bild nicht verfügbar');
          const {items} = await response.json();
          if (serial !== lookup) return;
          if (String(items?.[0]?.id) !== idField.value) throw new Error('Bild nicht freigegeben');
          image.src = items[0].url; image.alt = altField.value || items[0].title; image.hidden = false; message.textContent = '';
        } catch { if (serial === lookup) message.textContent = 'Bild nicht verfügbar oder nicht freigegeben.'; }
      };
      const select = node('button', 'Bild auswählen'); select.type = 'button';
      select.addEventListener('click', () => openMediaPicker(idField, altField, refreshImage));
      idField.addEventListener('input', () => { ++lookup; image.hidden = true; image.removeAttribute('src'); message.textContent = 'Bildauswahl prüfen …'; });
      idField.addEventListener('change', refreshImage);
      altField.addEventListener('input', () => { image.alt = altField.value; });
      captionField.addEventListener('input', () => { figcaption.textContent = captionField.value; });
      row.append(id, select, alt, caption, figure);
      if (Number(block.assetId) > 0) refreshImage(); else message.textContent = 'Bild aus der Mediathek auswählen.';
    }
    if (block.type === 'video') {
      const provider = input('Anbieter (youtube/vimeo)', block.provider);
      const videoId = input('Video-ID oder Video-URL', block.videoId);
      const field = videoId.querySelector('input');
      const providerField = provider.querySelector('input');
      const caption = input('Bildunterschrift', block.caption);
      const figure = node('figure'); figure.className = 'news-editor-video';
      const frameHolder = node('div');
      const figcaption = node('figcaption', caption.querySelector('input').value);
      const preview = node('button', 'Video bewusst laden'); preview.type = 'button';
      const clearPreview = () => { frameHolder.replaceChildren(); };
      preview.addEventListener('click', () => {
        const url = videoPreviewUrl(providerField.value, field.value);
        if (!url) { status.textContent = 'Video-ID und Anbieter prüfen.'; return; }
        const iframe = node('iframe'); iframe.src = url; iframe.title = 'Video-Vorschau'; iframe.loading = 'lazy'; iframe.referrerPolicy = 'strict-origin-when-cross-origin'; iframe.allowFullscreen = true;
        frameHolder.replaceChildren(iframe);
      });
      field.addEventListener('change', () => {
        if (!/^https:\/\//i.test(field.value)) return;
        const parsed = parseVideoUrl(field.value);
        if (parsed) {
          providerField.value = parsed.provider;
          field.value = parsed.videoId;
          changed(); return;
        }
        status.textContent = 'Video-URL nicht erkannt. Bitte YouTube- oder Vimeo-Link prüfen.';
      });
      field.addEventListener('input', clearPreview);
      providerField.addEventListener('input', clearPreview);
      caption.querySelector('input').addEventListener('input', event => { figcaption.textContent = event.target.value; });
      figure.append(preview, frameHolder, figcaption);
      row.append(provider, videoId, caption, figure);
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
    status.textContent = autosaveState?.blocked ? 'Speicherkonflikt: Der Artikel wurde anderswo geändert. Bitte lokale Änderungen sichern und neu laden.' : 'Ungespeicherte Änderungen.';
    const snapshot = serialize();
    const bytes = new TextEncoder().encode(snapshot).length;
    count.textContent = [...blocks.querySelectorAll('[contenteditable], textarea')].map(el => el.value ?? el.textContent).join(' ').trim().split(/\s+/u).filter(Boolean).length + ' Wörter · ' + blocks.children.length + '/100 Blöcke · ' + bytes + '/60000 Bytes';
    if (!withinDocumentLimits(JSON.parse(snapshot.slice(prefix.length)))) status.textContent = 'Dokumentgrenze überschritten. Bitte Text oder Blöcke kürzen.';
    history?.record(snapshot, {input: event?.type === 'input'});
    updateOutline();
    updateSearch();
    scheduleAutosave();
  }
  history = new EditorHistory(serialize());
  autosaveState = new AutosaveState(serialize());
  changed(); status.textContent = openError ? 'Dokument konnte nicht geöffnet werden. Speichern ist gesperrt.' : '';
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
    changed();
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
  const form = document.getElementById('news-editor-form');
  const updatedAt = form.querySelector('input[name="updatedAt"]');
  const documentHash = form.querySelector('input[name="documentHash"]');
  async function saveAutomatically() {
    if (openError || submitting || !withinDocumentLimits(JSON.parse(serialize().slice(prefix.length)))) return;
    const snapshot = autosaveState.begin(serialize());
    if (snapshot === null) return;
    status.textContent = 'Entwurf wird automatisch gespeichert …';
    try {
      const response = await fetch(root.dataset.autosaveUrl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf}, body: JSON.stringify({document: snapshot, updatedAt: updatedAt.value, documentHash: documentHash.value})});
      const data = await response.json();
      if (!response.ok) {
        autosaveState.failure(response.status === 409 || response.status === 403);
        status.textContent = response.status === 409 ? 'Speicherkonflikt: Der Artikel wurde anderswo geändert. Lokale Änderungen bleiben erhalten.' : data.error ?? 'Autosave fehlgeschlagen.';
        return;
      }
      if (!autosaveState.success(snapshot)) return;
      updatedAt.value = data.updatedAt;
      documentHash.value = data.documentHash;
      status.textContent = autosaveState.isDirty(serialize()) ? 'Neue Änderungen warten auf Autosave.' : 'Entwurf automatisch als Revision gespeichert.';
      if (autosaveState.isDirty(serialize())) scheduleAutosave();
    } catch {
      autosaveState.failure();
      status.textContent = 'Autosave konnte keine Verbindung herstellen. Änderungen bleiben im Editor.';
    }
  }
  function scheduleAutosave() {
    clearTimeout(autosaveTimer);
    if (!autosaveState || openError || submitting || autosaveState.blocked || !autosaveState.isDirty(serialize())) return;
    autosaveTimer = setTimeout(saveAutomatically, 15000);
  }
  window.addEventListener('beforeunload', event => { if (!submitting && autosaveState.isDirty(serialize())) { event.preventDefault(); event.returnValue = ''; } });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden' && !autosaveState.blocked) { clearTimeout(autosaveTimer); saveAutomatically(); } });
  form.addEventListener('submit', event => {
    if (autosaveState.inFlight !== null) { event.preventDefault(); status.textContent = 'Autosave läuft noch. Bitte Speichern gleich erneut auslösen.'; return; }
    if (autosaveState.blocked) { event.preventDefault(); status.textContent = 'Speicherkonflikt: Bitte lokale Änderungen sichern und den Artikel neu laden.'; return; }
    if (openError || !withinDocumentLimits(JSON.parse(serialize().slice(prefix.length)))) { event.preventDefault(); status.textContent = 'Dokument kann so nicht gespeichert werden. Bitte Inhalt und Grenzen prüfen.'; return; }
    clearTimeout(autosaveTimer);
    source.value = serialize(); submitting = true;
  });
  root.querySelectorAll('[data-command]').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      let command = button.dataset.command;
      if (command === 'undo' || command === 'redo') {
        restore(command === 'undo' ? history.undo() : history.redo()); return;
      }
      if (command === 'link') {
        const selection = window.getSelection();
        const current = closestFrom(selection?.anchorNode, 'a');
        const url = window.prompt('Linkziel (HTTP(S) oder interner Pfad):', current?.getAttribute('href') ?? '');
        if (url === null) return;
        const href = safeEditorHref(url);
        if (!href) { status.textContent = 'Linkziel nicht erlaubt. Bitte HTTP(S) oder internen Pfad verwenden.'; return; }
        if (current && blocks.contains(current)) current.setAttribute('href', href);
        else if (selection && !selection.isCollapsed && selection.rangeCount && closestFrom(selection.anchorNode, '[contenteditable]') && closestFrom(selection.anchorNode, '[contenteditable]') === closestFrom(selection.focusNode, '[contenteditable]')) document.execCommand('createLink', false, href);
        else { status.textContent = 'Bitte zuerst Text innerhalb eines Feldes markieren.'; return; }
      } else if (command === 'unlink') document.execCommand('unlink', false);
      else if (command === 'removeFormat') document.execCommand('removeFormat', false);
      else if (command === 'inlineCode') {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || !selection.rangeCount || !blocks.contains(selection.anchorNode) || !closestFrom(selection.anchorNode, '[contenteditable]') || closestFrom(selection.anchorNode, '[contenteditable]') !== closestFrom(selection.focusNode, '[contenteditable]')) return;
        const text = selection.toString();
        const code = node('code', text);
        const range = selection.getRangeAt(0); range.deleteContents(); range.insertNode(code);
        range.setStartAfter(code); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);
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
  let selectedMediaRefresh = null;
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
          picker.hidden = true; selectedMediaField.focus(); selectedMediaRefresh?.(); changed();
        }); mediaResults.append(button);
      }
      mediaMessage.textContent = items.length ? items.length + ' Bilder gefunden.' : 'Keine freigegebenen Bilder gefunden.';
    } catch { mediaMessage.textContent = 'Mediensuche fehlgeschlagen.'; }
  }
  function openMediaPicker(idField, altField, refresh) {
    selectedMediaField = idField; selectedAltField = altField; selectedMediaRefresh = refresh;
    picker.hidden = false; mediaSearch.focus(); searchMedia();
  }
  document.getElementById('news-editor-media-search-button').addEventListener('click', searchMedia);
  mediaSearch.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchMedia(); } });
  document.getElementById('news-editor-media-close').addEventListener('click', () => { picker.hidden = true; selectedMediaField?.focus(); });
  blocks.addEventListener('paste', event => {
    if (!event.target.closest('[contenteditable]')) return;
    event.preventDefault();
    const target = event.target.closest('[contenteditable]');
    const html = event.clipboardData?.getData('text/html');
    const row = target.closest('.news-editor-block');
    const plainText = event.clipboardData?.getData('text/plain') ?? '';
    if (!html && row?.dataset.type === 'paragraph' && !target.textContent.trim()) {
      const pastedBlocks = structuredMarkdown(plainText);
      if (pastedBlocks) {
        const before = serialize();
        const document = JSON.parse(before.slice(prefix.length));
        const index = [...blocks.children].indexOf(row);
        document.blocks.splice(index, 1, ...pastedBlocks);
        if (!withinDocumentLimits(document)) { status.textContent = 'Eingefügter Inhalt überschreitet die Dokumentgrenze.'; return; }
        for (const block of pastedBlocks) { renderBlock(block); row.before(blocks.lastElementChild); }
        row.remove(); activeRow = blocks.children[index];
        activeRow?.querySelector('[contenteditable], textarea')?.focus();
        changed(); status.textContent = pastedBlocks.length + ' strukturierte Blöcke eingefügt.';
        return;
      }
    }
    const content = html ? new DOMParser().parseFromString(html, 'text/html').body : null;
    const pasted = content ? clipboardRuns(content) : [{text: plainText, marks: []}];
    if (!pasted.length) return;
    const before = serialize();
    const fragment = document.createDocumentFragment();
    for (const run of pasted) {
      let child = document.createTextNode(run.text);
      for (const mark of run.marks) {
        const tag = {strong: 'strong', em: 'em', underline: 'u', strike: 's', code: 'code', link: 'a'}[mark];
        const wrapper = node(tag); if (tag === 'a') wrapper.setAttribute('href', run.href);
        wrapper.append(child); child = wrapper;
      }
      fragment.append(child);
    }
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount) { target.focus(); selection?.selectAllChildren(target); selection?.collapseToEnd(); }
    const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
    if (!range || !target.contains(range.commonAncestorContainer)) return;
    range.deleteContents();
    const last = fragment.lastChild;
    range.insertNode(fragment);
    range.setStartAfter(last); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);
    const candidate = JSON.parse(serialize().slice(prefix.length));
    if (!withinDocumentLimits(candidate) || pasted.some(run => run.text.length > 8000)) { restore(before); status.textContent = 'Eingefügter Inhalt überschreitet die Dokumentgrenze.'; return; }
    changed();
  });
  function updateSearch() {
    if (!search?.value) { if (searchResult) searchResult.textContent = ''; return; }
    const result = replaceArticle(JSON.parse(serialize().slice(prefix.length)), search.value, search.value);
    searchResult.textContent = result ? result.count + ' Treffer' : 'Suchtext ist zu lang.';
  }
  search.addEventListener('input', () => { findCursor = -1; updateSearch(); });
  document.getElementById('news-editor-find-next').addEventListener('click', () => {
    const matches = findArticleBlocks(JSON.parse(serialize().slice(prefix.length)), search.value);
    if (!matches.length) { searchResult.textContent = 'Keine Treffer.'; return; }
    findCursor = (findCursor + 1) % matches.length;
    const row = blocks.children[matches[findCursor]];
    row?.querySelector('[contenteditable], textarea, input')?.focus();
    row?.scrollIntoView({block: 'center'});
    searchResult.textContent = 'Treffer in Block ' + (matches[findCursor] + 1) + ' von ' + blocks.children.length + '.';
  });
  document.getElementById('news-editor-replace-all').addEventListener('click', () => {
    const result = replaceArticle(JSON.parse(serialize().slice(prefix.length)), search.value, replacement.value);
    if (!result || !result.count) { status.textContent = 'Keine Treffer oder ungültiger Suchtext.'; return; }
    if (!withinDocumentLimits(result.document)) { status.textContent = 'Ersetzung würde die Dokumentgrenze überschreiten.'; return; }
    const previous = serialize();
    restore(prefix + JSON.stringify(result.document));
    if (serialize() === previous) return;
    status.textContent = result.count + ' Stellen ersetzt. Ungespeicherte Änderungen.';
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
