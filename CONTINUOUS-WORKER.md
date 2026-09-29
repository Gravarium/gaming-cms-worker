# Gemeinsamer fortlaufender Worker-Auftragspool

Verwende ausschließlich den aktuellen Stand von Branch `continuous/work-pool-v4` und die dortige `CONTINUOUS-WORK-POOL.json`. Veraltete Aufgabenhinweise aus früheren Commits, Chats oder anderen Branches sind nicht maßgeblich.

## Dauerauftrag des Eigentümers

Das Ziel ist ein nutzbares, sicheres Gaming-CMS. Der Eigentümer nennt nicht nach jedem Paket den nächsten Schritt. Jeder Chat plant aus diesem Ziel und den belegten Code-Lücken selbst kleine Worker-sichere Vorschläge. Ist die veröffentlichte Liste erschöpft oder blockiert, bleibt das ein Planungsauftrag: erst grüne Vorgänger und Composition-Möglichkeiten prüfen, dann einen unabhängigen Worker-only-Vorschlag mit eindeutiger WCP-Kennung, atomarem Claim, exaktem Sanitized-Base-SHA, disjunkten erlaubten Pfaden, Abhängigkeiten, Tests und Sicherheitsgrenze im Pool veröffentlichen und bearbeiten. Ein WCP-Vorschlag ist keine Trusted-Integration und darf keinen bestehenden FCP-Vorgänger künstlich als erledigt markieren. Verschiedene Chats dürfen nur an unterschiedlichen disjunkten Claims zugleich arbeiten. Jeder Chat setzt seine eigene unterbrochene Arbeit zuerst fort.

## Wichtigste Regel

**Bestehende eigene Arbeit kommt immer vor einem neuen Claim, solange die Lease gültig ist.** Ein `worker_in_progress`-Claim läuft sieben Stunden nach dem letzten verifizierten Push ab; vor dem ersten Push sieben Stunden nach `claimed_on`. Danach wird die Aufgabe wieder `unclaimed` und ist mit einer neuen Claim-Generation übernehmbar. Die bisherige Feature-Branch, der exakte HEAD, PR und CI-Nachweise bleiben erhalten; der neue Claim setzt dort fort. Ein Chat mit abgelaufener Lease hat keine Schreibberechtigung mehr und muss vor jeder Fortsetzung den aktuellen Pool lesen. Eine abgeschlossene grüne Worker-PR, die auf Trusted wartet, bleibt gesperrt und läuft nicht ab. Ein gemeinsames GitHub-Konto sperrt unabhängige, disjunkte Chats nicht global.

## GitHub-Zugriff

GitHub-Schreibzugriff erfolgt über den verbundenen GitHub-Plugin/Connector. Ein fehlendes Terminal-Token oder ein fehlgeschlagenes `git push` bedeutet nicht, dass kein Schreibzugriff existiert.

Für Schreibvorgänge die GitHub-Plugin-Funktionen verwenden, insbesondere Datei-, Blob-, Tree-, Commit-, Ref-, Branch-, Pull-Request- und Workflow-Funktionen. Keine Browser-Anmeldung verlangen, solange der Connector funktioniert. Den Nutzer nicht auffordern, Branches oder Pool-Dateien manuell anzulegen.

## Aktive Arbeit

Der aktuelle Pool-Eintrag und die Live-Branch sind maßgeblich. Prüfe vor jedem Resume den letzten tatsächlichen Commit und die Lease. Ist die Lease abgelaufen, behandle `previous_claim` nur als Wiederherstellungsnachweis: neue Claim-Generation sichern, dann `resume_branch` am exakten Live-HEAD fortsetzen. Das alte Claim-Branch-Objekt allein hält die Aufgabe nicht gesperrt.

Abgeschlossene grüne Worker-PRs bleiben bis zur Trusted-Entscheidung gesperrt. In-progress-Leases laufen nach sieben Stunden ohne verifizierten Push ab. Bei Abweichung gilt immer der neuere JSON-Pool und der tatsächliche Live-Branch-HEAD.

## Trusted ist kein Worker-Scheduler

Trusted prüft und integriert fertige Vorschläge später. Ein offener Trusted-Review, ein gesperrter Claim einer bereits fertigen Aufgabe oder eine noch nicht aktualisierte Trusted-Queue verbietet keine weitere unabhängige Arbeit im sichtbaren CMS-Code. Die Worker-KI wartet deshalb nicht auf Trusted, wenn sie einen disjunkten Auftrag, eine belegte Vorgänger-Composition oder einen neuen begrenzten WCP-Vorschlag sicher bearbeiten kann. Nur private beziehungsweise nicht exportierte Implementierung bleibt der Trusted-Seite vorbehalten.

## Autonomer Dauerlauf

Nach jedem vollständig grünen Worker-PR ohne Rückfrage sofort:

1. Vor weiterer Arbeit die Lease und den Live-HEAD prüfen. Nach sieben Stunden ohne verifizierten Push ist ein `worker_in_progress`-Claim abgelaufen; wiederveröffentliche ihn als `unclaimed` mit neuer Claim-Generation und erhalte Branch, PR, HEAD und CI-Nachweise.
2. Den eigenen Pool-Eintrag nach vollständig grüner Exact-Head-CI auf `worker_complete_awaiting_trusted_review` setzen und Worker-PR, finalen HEAD sowie CI-Run eintragen.
3. Den Claim einer abgeschlossenen grünen PR als `locked_until_trusted_resolution` bestehen lassen.
4. Alle unbeanspruchten Pakete in Prioritätsreihenfolge neu bewerten. Eine Abhängigkeit gilt für Worker-Vorschlagsarbeit als erfüllt, wenn sie Trusted-integriert ist oder ein exakter grüner Worker-PR-HEAD vorliegt.
5. Den ersten dependency-sicheren Auftrag auswählen. Nicht darauf warten, dass der Nutzer eine FCP-Nummer nennt.
6. Bei genau einem fertigen Vorgänger dessen exakten grünen HEAD als Vorschlagsbasis verwenden.
7. Bei mehreren fertigen Vorgängern selbst eine Worker-only-Composition-Branch erstellen, ausschließlich die belegten exakten grünen HEADs konfliktfrei zusammenführen, einen Draft-PR gegen Worker-`main` öffnen und die vollständige Exact-Head-Worker-CI abwarten.
8. Nur bei vollständig grüner Composition-CI deren exakten HEAD im Pool als Proposal-Basis veröffentlichen.
9. Claim-Branch mit neuer Generation atomar; bei einer Wiederaufnahme den bestehenden Feature-Branch unverändert fortsetzen. Den Pool auf `worker_in_progress` setzen und sofort mit der Implementierung beginnen. von genau dieser Basis erstellen, den Pool auf `worker_in_progress` setzen und sofort mit der Implementierung beginnen.
10. Sind alle vorbereiteten FCPs tatsächlich blockiert, selbst einen begrenzten unabhängigen CMS-Teilauftrag aus dem genehmigten Ziel herleiten, als Worker-only-WCP im Pool mit eindeutiger Kennung und Sicherheits-/Pfad-/Testnachweisen veröffentlichen, atomar claimen und bearbeiten. Danach diesen Ablauf wiederholen. Nicht nach jedem Paket stoppen und keine neue Nutzeranweisung verlangen.

Wenn der zuerst geprüfte Auftrag echte unerfüllte Abhängigkeiten besitzt, den nächsten Auftrag prüfen. Der gesamte Pool darf nicht pauschal beendet werden, solange irgendein Auftrag mit nachweisbaren grünen Abhängigkeiten vorbereitet werden kann.

## Zulässige Stop-Gründe

Nur stoppen bei:

- echter nicht automatisch lösbarer Merge-Divergenz oder Konflikt,
- unbekanntem fremdem Schreiber auf dem aktiven Branch,
- tatsächlich fehlender GitHub-Connector-Berechtigung nach einem fehlgeschlagenen Plugin-Schreibaufruf,
- roter CI, deren Ursache trotz konkreter Diagnose und Reparaturversuchen nicht behoben werden kann,
- echtem Sicherheitskonflikt,
- keinem sicheren Teilziel im genehmigten CMS-Umfang, das ohne Konflikt mit fremden Claims und ohne Änderung echter FCP-Abhängigkeiten bearbeitet werden kann.

Ein fehlendes Terminal-Token, eine abgelaufene lokale Git-Anmeldung, eine veraltete Queue-Anzeige oder das Fehlen einer bereits vorbereiteten Composition-Basis sind allein keine Stop-Gründe.

## Sicherheits- und Integrationsregeln

- Genau ein Feature-Paket je Chat gleichzeitig aktiv bearbeiten; andere Chats dürfen disjunkte Claims mit überprüften Pfadgrenzen bearbeiten.
- Vor Unterbrechungen einen sinnvollen Remote-Checkpoint erstellen.
- Bei roter CI die Ursache beheben; Tests, PHPStan, CI, Auth, CSRF, Validierung, Datenschutz, Recovery, Rollback und Uploadschutz niemals abschwächen.
- Nur erlaubte Paketpfade bearbeiten; Pool-Selbstverwaltung ist auf die beiden Pool-Dateien und dafür nötige Worker-only-Composition-Metadaten beschränkt.
- Worker-Code bleibt untrusted.
- Nichts nach Trusted mergen und nichts deployen.
- Keine künstlichen oder leeren Commits erzeugen.
- Fremde aktive Claims und Feature-Branches nicht verändern.
