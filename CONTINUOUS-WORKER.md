# Gemeinsamer fortlaufender Worker-Auftragspool

Verwende ausschließlich den aktuellen Stand von Branch `continuous/work-pool-v4` und die dortige `CONTINUOUS-WORK-POOL.json`. Veraltete Aufgabenhinweise aus früheren Commits, Chats oder anderen Branches sind nicht maßgeblich.

## Wichtigste Regel

**Bestehende eigene Arbeit kommt immer vor einem neuen Claim.** Ein Claim bleibt bei Fehlern, Limits, Chatverlust und Worker-Abschluss eine harte Sperre. Nach jeder Unterbrechung wird derselbe Feature-Branch vom letzten bestätigten Remote-HEAD fortgesetzt.

## GitHub-Zugriff

GitHub-Schreibzugriff erfolgt über den verbundenen GitHub-Plugin/Connector. Ein fehlendes Terminal-Token oder ein fehlgeschlagenes `git push` bedeutet nicht, dass kein Schreibzugriff existiert.

Für Schreibvorgänge die GitHub-Plugin-Funktionen verwenden, insbesondere Datei-, Blob-, Tree-, Commit-, Ref-, Branch-, Pull-Request- und Workflow-Funktionen. Keine Browser-Anmeldung verlangen, solange der Connector funktioniert. Den Nutzer nicht auffordern, Branches oder Pool-Dateien manuell anzulegen.

## Aktive Arbeit

Der aktuelle Pool-Eintrag ist die einzige Wahrheit. Wenn ein Paket als `worker_in_progress` markiert ist, seinen dort genannten `resume_branch`, `active_base_branch`, `active_base_sha` und `active_head` verwenden und es vollständig abschließen.

FCP-069 ist auf grünem exaktem Worker-HEAD abgeschlossen und sein Claim bleibt gesperrt. Der aktuelle JSON-Pool weist FCP-077 als nächstes vorbereitbares Paket aus: erst die belegten Vorgänger-HEADs in einer Worker-only-Composition prüfen, danach bei grüner vollständiger CI die Vorschlagsbasis veröffentlichen und FCP-077 claimen. Die Live-Abnahme von FCP-049 bleibt als Betriebsnachweis offen; dessen Produktcode ist bereits geprüft und im Worker-Snapshot vorhanden. Bei Abweichung gilt immer der neuere JSON-Pool.

## Autonomer Dauerlauf

Nach jedem vollständig grünen Worker-PR ohne Rückfrage sofort:

1. Den eigenen Pool-Eintrag auf `worker_complete_awaiting_trusted_review` setzen und Worker-PR, finalen HEAD sowie Exact-Head-CI-Run eintragen.
2. Den Claim als `locked_until_trusted_resolution` bestehen lassen.
3. Alle unbeanspruchten Pakete in Prioritätsreihenfolge neu bewerten. Eine Abhängigkeit gilt für Worker-Vorschlagsarbeit als erfüllt, wenn sie Trusted-integriert ist oder ein exakter grüner Worker-PR-HEAD vorliegt.
4. Den ersten dependency-sicheren Auftrag auswählen. Nicht darauf warten, dass der Nutzer eine FCP-Nummer nennt.
5. Bei genau einem fertigen Vorgänger dessen exakten grünen HEAD als Vorschlagsbasis verwenden.
6. Bei mehreren fertigen Vorgängern selbst eine Worker-only-Composition-Branch erstellen, ausschließlich die belegten exakten grünen HEADs konfliktfrei zusammenführen, einen Draft-PR gegen Worker-`main` öffnen und die vollständige Exact-Head-Worker-CI abwarten.
7. Nur bei vollständig grüner Composition-CI deren exakten HEAD im Pool als Proposal-Basis veröffentlichen.
8. Claim- und Feature-Branch atomar von genau dieser Basis erstellen, den Pool auf `worker_in_progress` setzen und sofort mit der Implementierung beginnen.
9. Diesen Ablauf nach dem nächsten grünen PR wiederholen. Nicht nach jedem Paket stoppen und keine neue Nutzeranweisung verlangen.

Wenn der zuerst geprüfte Auftrag echte unerfüllte Abhängigkeiten besitzt, den nächsten Auftrag prüfen. Der gesamte Pool darf nicht pauschal beendet werden, solange irgendein Auftrag mit nachweisbaren grünen Abhängigkeiten vorbereitet werden kann.

## Zulässige Stop-Gründe

Nur stoppen bei:

- echter nicht automatisch lösbarer Merge-Divergenz oder Konflikt,
- unbekanntem fremdem Schreiber auf dem aktiven Branch,
- tatsächlich fehlender GitHub-Connector-Berechtigung nach einem fehlgeschlagenen Plugin-Schreibaufruf,
- roter CI, deren Ursache trotz konkreter Diagnose und Reparaturversuchen nicht behoben werden kann,
- echtem Sicherheitskonflikt,
- keinem einzigen Auftrag, dessen reale Abhängigkeiten erfüllt oder durch zulässige Vorarbeit erfüllbar sind.

Ein fehlendes Terminal-Token, eine abgelaufene lokale Git-Anmeldung, eine veraltete Queue-Anzeige oder das Fehlen einer bereits vorbereiteten Composition-Basis sind allein keine Stop-Gründe.

## Sicherheits- und Integrationsregeln

- Genau ein Feature-Paket gleichzeitig aktiv bearbeiten.
- Vor Unterbrechungen einen sinnvollen Remote-Checkpoint erstellen.
- Bei roter CI die Ursache beheben; Tests, PHPStan, CI, Auth, CSRF, Validierung, Datenschutz, Recovery, Rollback und Uploadschutz niemals abschwächen.
- Nur erlaubte Paketpfade bearbeiten; Pool-Selbstverwaltung ist auf die beiden Pool-Dateien und dafür nötige Worker-only-Composition-Metadaten beschränkt.
- Worker-Code bleibt untrusted.
- Nichts nach Trusted mergen und nichts deployen.
- Keine künstlichen oder leeren Commits erzeugen.
- Fremde aktive Claims und Feature-Branches nicht verändern.
