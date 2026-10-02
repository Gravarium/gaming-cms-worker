const emptyRuns = () => [{text: '', marks: []}];

export const BLOCK_COMMANDS = Object.freeze([
  {id: 'paragraph', label: 'Absatz', description: 'Fließtext schreiben', keywords: 'text paragraph normal'},
  {id: 'heading', label: 'Überschrift', description: 'Abschnitt gliedern', keywords: 'titel heading h2'},
  {id: 'list', label: 'Liste', description: 'Aufzählung beginnen', keywords: 'punkte bullet aufzählung'},
  {id: 'quote', label: 'Zitat', description: 'Zitat mit Quelle', keywords: 'quote quelle'},
  {id: 'callout', label: 'Infobox', description: 'Hinweis hervorheben', keywords: 'hinweis tipp warnung callout'},
  {id: 'code', label: 'Code', description: 'Codeblock einfügen', keywords: 'quelltext preformatted'},
  {id: 'table', label: 'Tabelle', description: 'Zweispaltige Tabelle', keywords: 'zeilen spalten grid'},
  {id: 'media', label: 'Bild', description: 'Mediathek-Bild auswählen', keywords: 'media foto grafik image'},
  {id: 'video', label: 'Video', description: 'YouTube oder Vimeo', keywords: 'film embed youtube vimeo'},
  {id: 'separator', label: 'Trennlinie', description: 'Abschnitte trennen', keywords: 'linie separator hr'},
]);

const fold = value => String(value ?? '').trim().toLocaleLowerCase('de-DE').normalize('NFKD').replace(/[\u0300-\u036f]/gu, '');

export function matchBlockCommands(query, limit = 10) {
  if (typeof query !== 'string' || query.length > 40 || !Number.isInteger(limit) || limit < 1 || limit > 10) return [];
  const needle = fold(query.replace(/^\//u, ''));
  return BLOCK_COMMANDS
    .map((command, index) => {
      const label = fold(command.label), id = fold(command.id), haystack = fold(command.label + ' ' + command.id + ' ' + command.keywords);
      const rank = !needle ? index : label.startsWith(needle) || id.startsWith(needle) ? index : haystack.includes(needle) ? 100 + index : -1;
      return {command, rank};
    })
    .filter(item => item.rank >= 0)
    .sort((left, right) => left.rank - right.rank)
    .slice(0, limit)
    .map(item => item.command);
}

export function blockForCommand(commandId) {
  if (!BLOCK_COMMANDS.some(command => command.id === commandId)) return null;
  const blocks = {
    paragraph: {type: 'paragraph', content: emptyRuns()},
    heading: {type: 'heading', level: 2, content: emptyRuns()},
    quote: {type: 'quote', content: emptyRuns(), cite: ''},
    list: {type: 'list', ordered: false, items: [emptyRuns()]},
    table: {type: 'table', rows: [[emptyRuns(), emptyRuns()]], header: true},
    callout: {type: 'callout', tone: 'info', content: emptyRuns()},
    code: {type: 'code', language: 'plain', text: ''},
    separator: {type: 'separator'},
    media: {type: 'media', assetId: 0, alt: '', caption: ''},
    video: {type: 'video', provider: 'youtube', videoId: '', caption: ''},
  };
  return blocks[commandId];
}

export function applySlashCommand(document, index, commandId) {
  if (document?.version !== 2 || !Array.isArray(document.blocks) || !Number.isInteger(index) || index < 0 || index >= document.blocks.length) return null;
  const current = document.blocks[index];
  if (current?.type !== 'paragraph' || !Array.isArray(current.content) || !current.content.length) return null;
  if (current.content.some(run => typeof run?.text !== 'string' || !Array.isArray(run.marks) || run.marks.length || 'href' in run)) return null;
  const text = current.content.map(run => run.text).join('');
  if (!/^\/[^\r\n]{0,40}$/u.test(text)) return null;
  const replacement = blockForCommand(commandId);
  const command = BLOCK_COMMANDS.find(item => item.id === commandId);
  if (!replacement || !command) return null;
  const next = JSON.parse(JSON.stringify(document));
  next.blocks[index] = replacement;
  return {document: next, index, command, announcement: command.label + ' eingefügt. Block ' + (index + 1) + ' von ' + next.blocks.length + '.'};
}
