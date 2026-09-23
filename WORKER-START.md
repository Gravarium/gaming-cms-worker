# Worker-Start FCP-051

Du bearbeitest ausschließlich **FCP-051: Safe content block editor and media-selection foundation**.

## Vor jeder Änderung

1. Prüfe, dass dein Repository `Gravarium/gaming-cms-worker` ist.
2. Prüfe den exakten Ausgangsstand `330c655636e6b18816c7334bbec9b9093f41c269` und arbeite nur auf `feature/fcp-051-safe-content-block-editor-g1`.
3. Prüfe Lease `FCP-051-g1`, Generation 1, Worker `worker-6`, Ablauf `2026-09-24T04:46:12Z`.
4. Wenn Lease, Generation, Branch, Base oder aktueller Trusted-Auftrag abweichen oder abgelaufen sind: **STOP**. Nicht selbst verlängern.
5. Lies `MODULE-ASSIGNMENT.json`. Es ist nur die worker-lesbare Kopie; autoritativ bleibt die aktuelle Trusted-Queue.

## Auftrag

Replace the plain content textarea with a bounded structured block-editor foundation that preserves revisions, preview, sanitization and safe media references without permitting arbitrary HTML or scripts.

Erlaubt sind nur die in `MODULE-ASSIGNMENT.json` genannten Pfade. Speichere innerhalb von 30 Minuten tatsächlicher Arbeit einen zusammenhängenden Commit und pushe ihn als Checkpoint. Bearbeite keine privaten, operativen, Deployment-, Workflow- oder Trusted-only-Dateien. Rekonstruiere keine fehlenden privaten Komponenten.

Behalte alle bestehenden Schutzmaßnahmen bei: Berechtigungen, CSRF, Validierung, Escaping, Uploadschutz, Recovery, Rollback, Tests, PHPStan und CI. ROT bedeutet STOP, Ursache beheben und vollständig neu prüfen.

Der Editor darf kein beliebiges HTML oder JavaScript speichern/ausführen. Verwende serverseitig versionierte, erlaubte Blocktypen; sichere Links und ausschließlich zentral geprüfte Medienreferenzen. Vorhandene Inhalte, Revisionen, Veröffentlichung und Berechtigungen dürfen nicht beschädigt werden.

## Pflichtübergabe

Erstelle einen Worker-PR und liefere:

- exaktes finales HEAD und exaktes Base-SHA
- vollständige geänderte Pfade
- gezielte Tests und vollständige Worker-CI auf dem finalen HEAD
- offene Risiken und bewusst verschobene Funktionen
- aktuellen Schutzweg und zukünftige Fortress-Vertragsgrenze
- betroffene alte Klassen, Tabellen und spätere Entfernungspunkte
- Datenhoheit, Migration auf frischer und gefüllter Datenbank sowie Restore/Rollback
- Negative-Tests für Rechte, CSRF, XSS, Medienpfade und deaktiviertes Modul
- separate, nicht commitete `WORKER-TASK.json`

Du mergst nicht nach Trusted und deployst nichts. Nach PR/CI wartest du auf die Haupt-KI.
