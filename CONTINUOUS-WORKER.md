# Gemeinsamer fortlaufender Worker-Auftragspool

Verwende ausschließlich Branch `continuous/work-pool-v4`.

## Wichtigste Regel

**Bestehende eigene Arbeit kommt immer vor einem neuen Claim.** Ein Claim bleibt bei Fehlern, Limits, Chatverlust und Worker-Abschluss eine harte Sperre für jede zweite KI. Die besitzende Fortsetzung arbeitet denselben Branch weiter.

## Neue Basisregel

- Neue unabhängige Arbeit startet ausschließlich vom veröffentlichten exakten `worker/main`-HEAD.
- Nummerierte Account-Baselines sind außer Betrieb und dürfen nicht für neue Arbeit verwendet werden.
- Eine Folgeaufgabe darf auf dem exakten grünen HEAD ihres abgeschlossenen Worker-Vorgängers gestapelt werden.
- Diese Stapelung ist nur untrusted Worker-Vorschlagsarbeit. Sie bedeutet niemals Trusted-Integration, Merge- oder Deployment-Recht.
- Bei mehreren Vorgängern ist ein ausdrücklich veröffentlichter Worker-only-Kompositions-HEAD mit vollständiger grüner CI erforderlich. Nichts blind zusammenführen und keine Basis erraten.

## Sofortige nächste Arbeit

Der Recovery-Stand ist vollständig abgeglichen. FCP-053, FCP-067 und FCP-071 besitzen fertige Worker-PRs mit grüner exakter HEAD-CI.

Als nächstes ist `FCP-054` zulässig:

- Claim: `claim/fcp-054-g1`
- Feature-Branch: `feature/fcp-054-pool-g1`
- exakte Vorschlagsbasis: `feature/fcp-053-pool-g1`
- Basis-HEAD: `8ce1ea7d64ddf347ed5ab5ec322449ba0307a9e7`
- Nachweis: PR #14, Worker-CI `36010756549` erfolgreich

Erzeuge Claim und Feature-Branch atomar von genau diesem HEAD und arbeite FCP-054 vollständig ab.

## Fortlaufender Betrieb ohne Nachfragen

Nach jedem vollständig grünen Worker-PR:

1. den eigenen Pool-Eintrag auf `worker_complete_awaiting_trusted_review` setzen und PR, finalen HEAD sowie CI-Run eintragen;
2. den Claim als Sperre bestehen lassen;
3. genau den ersten unbeanspruchten Folgeauftrag auswählen, dessen Abhängigkeiten entweder Trusted-integriert oder durch exakte grüne Worker-PR-HEADs belegt sind;
4. für genau diesen nächsten Auftrag die eindeutige Vorschlagsbasis veröffentlichen;
5. sofort claimen und weiterarbeiten, ohne auf Trusted-Prüfung oder eine neue Chat-Anweisung zu warten.

Die KI hält nur an, wenn echte Divergenz, ein unbekannter Schreiber, fehlende Berechtigung, ein Sicherheitskonflikt oder bei mehreren Abhängigkeiten keine eindeutig grün geprüfte Kompositionsbasis existiert. In diesem Fall überspringt sie den unsicheren Auftrag und prüft den nächsten eindeutig zulässigen Auftrag, statt pauschal den gesamten Pool zu beenden.

## Allgemeine Regeln

- Genau ein Feature-Branch wird gleichzeitig aktiv bearbeitet.
- Innerhalb von 30 Minuten tatsächlicher Arbeit und vor Unterbrechungen einen sinnvollen Push erstellen.
- Eigene lineare Folgecommits sind kein STOP.
- Bei roter CI auf demselben Branch die Root Cause beheben; Schutz, Tests, PHPStan und CI niemals abschwächen.
- Nur erlaubte Pfade bearbeiten.
- Nichts nach Trusted mergen und nichts deployen.
- Keine künstlichen oder leeren Commits erzeugen.
- Nach Werkzeug-, Modell-, Chat- oder Nachrichtenlimit denselben Branch am letzten bestätigten Remote-HEAD fortsetzen.
- Fremde Claims, Feature-Branches, PRs und Pool-Einträge bleiben unangetastet.
