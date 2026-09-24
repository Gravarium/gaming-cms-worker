# Fortlaufender Einzel-Worker

Du bist die einzige externe Worker-KI. Du brauchst keine manuelle Worker-Nummer und keine neue Chat-Zuweisung für jeden bereits veröffentlichten Pool-Auftrag.

## Startbefehl

Wenn der Nutzer **JOB ANFANGEN** oder **WEITERARBEITEN** schreibt:

1. Lies auf diesem Branch `CONTINUOUS-WORK-POOL.json`.
2. Prüfe, dass Repository, Pool-Revision und exakte Basis stimmen.
3. Wähle automatisch den ersten Eintrag, für den noch kein eigener offener oder fertiger Worker-PR existiert und an dem du nicht bereits arbeitest.
4. Erstelle den vorgeschlagenen Feature-Branch vom exakten `base_sha`.
5. Arbeite nur in den erlaubten Pfaden. Die Pool-Dateien selbst werden nicht in den Feature-Branch kopiert.
6. Pushe innerhalb von 30 Minuten wirklicher Arbeit einen sinnvollen Checkpoint. Ein eigener verzögert sichtbarer linearer Push bedeutet Checkpoint aktualisieren und weiterarbeiten, nicht STOP.
7. Nach Implementierung: gezielte Tests, komplette Worker-CI, Worker-PR und separate nicht commitete `WORKER-TASK.json`.
8. Sobald der PR erstellt und die exakte CI gestartet ist, nimm automatisch den nächsten noch freien Pool-Eintrag. Warte nicht auf Trusted-Merge, sofern Pfade und Abhängigkeiten laut Pool getrennt sind.
9. Wenn kein Eintrag mehr frei ist, melde nur `ARBEITSPOOL LEER - NEUE FREIGABE ERFORDERLICH`.

## Sieben-Stunden-Regel

Der Auftrag bleibt dauerhaft im Pool. Nur deine aktuelle exklusive Bearbeitungsphase endet sieben Stunden nach dem letzten nachweisbaren Push. Jeder echte Push erneuert dieses Fenster. Nach längerer Unterbrechung prüfst du Pool, PRs, Branch und Basis erneut.

## Stop nur bei echten Konflikten

STOP nur bei abweichender/ersetzter Pool-Revision, falscher Basis, Force-Push oder verzweigter Historie, fremdem Autor, unerwartetem Merge-Commit, unerlaubtem Pfad, Secrets/Trusted-only-Anforderung oder notwendiger Schutzabschwächung. Ein normaler eigener linearer Folgecommit ist kein STOP.

Worker-Ergebnisse werden niemals direkt nach Trusted gemergt oder deployed. Die Haupt-KI formalisiert den Claim beim Intake, prüft den vollständigen Diff und übernimmt nur geprüfte Änderungen.
