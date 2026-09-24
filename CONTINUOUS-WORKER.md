# Gemeinsamer fortlaufender Worker-Auftragspool

Diese Datei gilt für eine oder mehrere autorisierte Worker-KIs. Es gibt keine feste Worker-1/Worker-6-Zuteilung. Jede KI nimmt einen freien passenden Auftrag aus demselben Pool und reserviert ihn atomar.

## Start

Bei **JOB ANFANGEN** oder **WEITERARBEITEN**:

1. Lies auf Branch `continuous/work-pool-v2` vollständig `CONTINUOUS-WORK-POOL.json`.
2. Überspringe Einträge mit `worker_pr_complete_awaiting_trusted_review`.
3. Ein Auftrag ist nur wählbar, wenn `execution_state` `unclaimed` ist, `blocked_by_at_publication` leer ist und der angegebene Claim-Branch noch nicht existiert.
4. Reserviere den Auftrag, indem du den exakt angegebenen `claim_branch` atomar vom exakten `base_sha` erstellst.
5. Wenn GitHub meldet, dass der Claim-Branch schon existiert, gehört der Auftrag einer anderen KI. Nimm automatisch den nächsten freien passenden Auftrag. Verändere den bestehenden Claim nicht.
6. Erstelle danach den angegebenen Feature-Branch vom gleichen exakten `base_sha` und arbeite ausschließlich in `allowed_paths`.
7. Pushe spätestens nach 30 Minuten tatsächlicher Arbeit einen sinnvollen Checkpoint. Jeder verifizierte Push erneuert die siebenstündige Reservierung.
8. Ein eigener, linearer und erlaubter Folgecommit ist kein STOP. Checkpoint aktualisieren und weiterarbeiten.
9. Nach Fertigstellung: gezielte Tests, vollständige Worker-CI auf dem exakten HEAD, Worker-PR, vollständiger Bericht und separate nicht commitete `WORKER-TASK.json`.
10. Danach darf dieselbe KI automatisch den nächsten freien, nicht blockierten Auftrag reservieren. Sie wartet nicht auf eine persönliche Worker-Zuteilung.

## Abhängigkeiten

Ein fertiger Worker-PR erfüllt eine Code-Abhängigkeit noch nicht. Ein Folgeauftrag darf erst beginnen, wenn die Haupt-KI den Vorgänger geprüft und in Trusted integriert hat und der Pool mit einer neuen bereinigten Worker-Basis veröffentlicht wurde. Dadurch arbeitet eine KI nie auf einem Stand, in dem benötigter Vorgängercode fehlt.

## STOP nur bei echten Konflikten

STOP bei falscher Basis oder Pool-Revision, Force-Push/verzweigter Historie, fremdem Commit im eigenen Feature-Branch, unerwartetem Merge-Commit, unerlaubtem Pfad, Secrets/Trusted-only-Anforderung oder notwendiger Schutzabschwächung.

Wenn aktuell kein freier, nicht blockierter Auftrag existiert, melde:
`ARBEITSPOOL VORHANDEN - AKTUELL KEIN FREIER UNBLOCKIERTER AUFTRAG`

Das bedeutet nicht, dass der Pool leer ist. Es bedeutet, dass abgeschlossene Worker-PRs zuerst Trusted-geprüft und in eine neue bereinigte Basis übernommen werden müssen.

Niemals Worker-Code direkt nach Trusted mergen oder deployen.
