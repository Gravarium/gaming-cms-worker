# Gemeinsamer fortlaufender Worker-Auftragspool

Verwende ausschließlich Branch `continuous/work-pool-v4`.

## Wichtigste Regel

**Bestehende eigene Arbeit kommt immer vor einem neuen Claim.** Ein vorhandener Claim-Branch bedeutet nicht automatisch „überspringen“. Prüfe zuerst `recovery_queue`.

## Aktuelle Wiederaufnahme

Arbeite genau in dieser Reihenfolge und immer nur an einem Branch gleichzeitig:

1. `FCP-053` auf `feature/fcp-053-pool-g1`: CI-Run `35992798754` ist rot. Fehlerlogs lesen, Root Cause beheben, vollständige CI auf dem neuen exakten HEAD grün machen und PR #14 aktualisieren.
2. `FCP-067` auf `feature/fcp-067-pool-g1`: vorhandenen Stand `97d7168ca6515d4bbc1e3da0c9753bd2618164c7` prüfen, fertigstellen, vollständige CI ausführen und Worker-PR erstellen.
3. `FCP-071` auf `feature/fcp-071-pool-g1`: vorhandenen Stand `1386b459b960e3c97cb52dc5bddf8ae38130b3da` prüfen, fertigstellen, vollständige CI ausführen und Worker-PR erstellen.

Erst wenn diese drei Einträge abgeschlossen sind, darf nach einem neuen freien Claim gesucht werden.

## Abgeschlossene Worker-PRs

FCP-052/PR #13, FCP-056/PR #15, FCP-063/PR #16 und FCP-068/PR #17 sind abgeschlossen und warten auf Trusted-Prüfung. Ihre Claim-Branches bleiben als Historie bestehen und blockieren keine Wiederaufnahme.

## Allgemeine Regeln

- Keine feste Worker-Nummer als persönliche Zuteilung verlangen.
- Genau ein Feature-Branch wird gleichzeitig bearbeitet.
- Innerhalb von 30 Minuten tatsächlicher Arbeit einen sinnvollen Push erstellen.
- Eigene lineare Folgecommits sind kein STOP.
- Bei roter CI Root Cause beheben; Schutz, Tests, PHPStan und CI niemals abschwächen.
- Nur erlaubte Pfade bearbeiten.
- Nichts nach Trusted mergen und nichts deployen.
- Ein fertiger Worker-PR erfüllt Abhängigkeiten erst nach Trusted-Prüfung, Integration und neuer bereinigter Basis.

Wenn die Recovery-Liste abgeschlossen ist und kein neuer freier Auftrag existiert, melde:
`ARBEITSPOOL VORHANDEN - TRUSTED-PRÜFUNG FÜR FOLGEAUFTRÄGE ERFORDERLICH`
