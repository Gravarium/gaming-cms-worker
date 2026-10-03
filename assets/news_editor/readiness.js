const categories = new Set(['structure', 'accessibility', 'attribution', 'links', 'readability', 'metadata', 'general']);
const categoryLabels = {
  structure: 'Aufbau', accessibility: 'Barrierefreiheit', attribution: 'Quellen und Zuschreibung',
  links: 'Links', readability: 'Lesbarkeit', metadata: 'Artikeldaten', general: 'Weitere Hinweise',
};

function finiteNumber(value, min, max, fallback = 0) {
  return typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max ? value : fallback;
}

function boundedInteger(value, min, max) {
  return Number.isSafeInteger(value) && value >= min && value <= max ? value : 0;
}

export function resolveReadinessBlockIndex(value, blockCount) {
  const index = typeof value === 'number'
    ? value
    : (typeof value === 'string' && /^(?:0|[1-9]\d{0,2})$/.test(value) ? Number(value) : Number.NaN);
  if (!Number.isSafeInteger(index) || !Number.isSafeInteger(blockCount) || blockCount < 0 || blockCount > 100 || index < 0 || index >= blockCount) {
    return null;
  }
  return index;
}

export function resolveReadinessFocusTarget(row, findingCode) {
  if (!row || typeof row.querySelector !== 'function') return null;
  const specific = typeof findingCode === 'string' && /^[a-z0-9_.-]{1,80}$/.test(findingCode)
    ? row.querySelector(`[data-readiness-target~="${findingCode}"]`)
    : null;
  return specific
    ?? row.querySelector('[contenteditable="true"]')
    ?? row.querySelector('textarea, input:not([type="hidden"]), select')
    ?? row;
}

export function normalizeReadinessReport(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)
    || !value.summary || typeof value.summary !== 'object' || Array.isArray(value.summary)
    || !value.metrics || typeof value.metrics !== 'object' || Array.isArray(value.metrics)
    || !Array.isArray(value.findings)
    || !['review_recommended', 'no_major_issues'].includes(value.summary.state)) {
    throw new TypeError('Ungültiger Prüfbericht.');
  }

  const findings = value.findings.slice(0, 80).flatMap(finding => {
    if (!finding || typeof finding !== 'object' || Array.isArray(finding)
      || typeof finding.code !== 'string' || !/^[a-z0-9_.-]{1,80}$/.test(finding.code)
      || !categories.has(finding.category)
      || !['warning', 'info'].includes(finding.severity)
      || typeof finding.message !== 'string' || finding.message.length > 220) {
      return [];
    }
    const blockIndex = finding.blockIndex === null
      ? null
      : (Number.isSafeInteger(finding.blockIndex) && finding.blockIndex >= 0 && finding.blockIndex < 100 ? finding.blockIndex : null);
    return [{code: finding.code, category: finding.category, severity: finding.severity, blockIndex, message: finding.message}];
  });
  const warningCount = findings.filter(finding => finding.severity === 'warning').length;
  const infoCount = findings.length - warningCount;
  const state = value.summary.state;

  return {
    summary: {
      state,
      message: typeof value.summary.message === 'string' ? value.summary.message.slice(0, 220) : '',
      findingCount: findings.length,
      warningCount,
      infoCount,
      limited: value.summary.limited === true || value.findings.length > findings.length,
    },
    metrics: {
      wordCount: boundedInteger(value.metrics.wordCount, 0, 60000),
      blockCount: boundedInteger(value.metrics.blockCount, 0, 100),
      headingCount: boundedInteger(value.metrics.headingCount, 0, 100),
      imageCount: boundedInteger(value.metrics.imageCount, 0, 100),
      linkCount: boundedInteger(value.metrics.linkCount, 0, 500),
      readingMinutes: boundedInteger(value.metrics.readingMinutes, 0, 1000),
      averageSentenceWords: finiteNumber(value.metrics.averageSentenceWords, 0, 60000),
      linkDensityPercent: finiteNumber(value.metrics.linkDensityPercent, 0, 100),
    },
    findings,
  };
}

function metric(label, value) {
  const wrapper = document.createElement('div');
  const term = document.createElement('dt');
  const detail = document.createElement('dd');
  term.textContent = label;
  detail.textContent = value;
  wrapper.append(term, detail);
  return wrapper;
}

function renderReport(panel, report) {
  const summary = panel.querySelector('[data-readiness-summary]');
  const results = panel.querySelector('[data-readiness-results]');
  summary.replaceChildren();
  results.replaceChildren();

  const heading = document.createElement('p');
  heading.className = 'news-readiness-summary';
  heading.textContent = report.summary.state === 'review_recommended'
    ? 'Prüfung empfohlen'
    : (report.summary.infoCount > 0 ? 'Optionale Hinweise verfügbar' : 'Keine größeren Hinweise');
  const description = document.createElement('p');
  description.textContent = report.summary.message;
  const metrics = document.createElement('dl');
  metrics.className = 'news-readiness-metrics';
  metrics.append(
    metric('Wörter', String(report.metrics.wordCount)),
    metric('Blöcke', String(report.metrics.blockCount)),
    metric('Überschriften', String(report.metrics.headingCount)),
    metric('Bilder', String(report.metrics.imageCount)),
    metric('Links', String(report.metrics.linkCount)),
    metric('Lesezeit, ca.', `${report.metrics.readingMinutes} Min.`),
    metric('Ø Wörter pro Satz', String(report.metrics.averageSentenceWords)),
    metric('Textanteil mit Links', `${report.metrics.linkDensityPercent}%`),
  );
  summary.append(heading, description, metrics);

  if (report.findings.length === 0) {
    const empty = document.createElement('p');
    empty.textContent = 'Für diesen Artikel gibt es keine Hinweise.';
    results.append(empty);
    return;
  }

  for (const category of Object.keys(categoryLabels)) {
    const group = report.findings.filter(finding => finding.category === category);
    if (group.length === 0) continue;
    const section = document.createElement('section');
    const title = document.createElement('h3');
    const list = document.createElement('ul');
    title.textContent = categoryLabels[category];
    for (const finding of group) {
      const item = document.createElement('li');
      const severity = document.createElement('strong');
      const message = document.createElement('span');
      severity.className = `news-readiness-severity news-readiness-severity--${finding.severity}`;
      severity.textContent = finding.severity === 'warning' ? 'Hinweis: ' : 'Info: ';
      message.textContent = finding.message;
      item.append(severity, message);
      if (finding.blockIndex !== null) {
        const jump = document.createElement('button');
        jump.type = 'button';
        jump.dataset.blockIndex = String(finding.blockIndex);
        jump.dataset.findingCode = finding.code;
        jump.textContent = `Zu Block ${finding.blockIndex + 1} springen`;
        item.append(document.createTextNode(' '), jump);
      }
      list.append(item);
    }
    section.append(title, list);
    results.append(section);
  }
  if (report.summary.limited) {
    const limited = document.createElement('p');
    limited.textContent = 'Die Anzeige ist auf 80 Hinweise begrenzt.';
    results.append(limited);
  }
}

export function initNewsReadinessPanel(panel, {readDocument, blocks}) {
  if (!panel || typeof readDocument !== 'function' || !blocks) return;
  const button = panel.querySelector('[data-readiness-refresh]');
  const status = panel.querySelector('[data-readiness-status]');
  const results = panel.querySelector('[data-readiness-results]');
  if (!button || !status || !results) return;

  button.addEventListener('click', async () => {
    const url = panel.dataset.url;
    const csrf = panel.dataset.csrf;
    if (!url || !csrf) {
      status.textContent = 'Der Veröffentlichungscheck ist nicht verfügbar.';
      return;
    }
    button.disabled = true;
    panel.setAttribute('aria-busy', 'true');
    status.textContent = 'Veröffentlichungscheck wird ausgeführt …';
    panel.querySelector('[data-readiness-summary]')?.replaceChildren();
    results.replaceChildren();
    try {
      const analyzedDocument = readDocument();
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
        body: JSON.stringify({document: analyzedDocument}),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error('Prüfung fehlgeschlagen.');
      if (readDocument() !== analyzedDocument) {
        status.textContent = 'Der Artikel wurde während der Prüfung geändert. Bitte starte den Check erneut.';
        return;
      }
      const report = normalizeReadinessReport(payload);
      renderReport(panel, report);
      status.textContent = `Prüfung abgeschlossen: ${report.summary.warningCount} Hinweise und ${report.summary.infoCount} optionale Punkte. Änderungen am Entwurf wurden nicht gespeichert.`;
    } catch {
      status.textContent = 'Prüfung fehlgeschlagen. Bitte prüfe deine Verbindung und versuche es erneut.';
    } finally {
      panel.setAttribute('aria-busy', 'false');
      button.disabled = false;
    }
  });

  results.addEventListener('click', event => {
    const target = event.target;
    const jump = target instanceof Element ? target.closest('[data-block-index]') : null;
    if (!jump || !results.contains(jump)) return;
    const index = resolveReadinessBlockIndex(jump.dataset.blockIndex, blocks.children.length);
    if (index === null) return;
    const row = blocks.children[index];
    if (!row || !blocks.contains(row)) return;
    const focusTarget = resolveReadinessFocusTarget(row, jump.dataset.findingCode);
    if (focusTarget === row && !row.hasAttribute('tabindex')) row.tabIndex = -1;
    focusTarget?.focus();
    row.scrollIntoView({block: 'center'});
  });
}
