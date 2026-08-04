# Dataimport – runbook

Import fra det nuværende system skal altid ske i tre trin.

## 1. Dry run

- Modtag CSV/API-eksempel uden personfølsomme hemmeligheder.
- Valider kolonner, datoer, registreringsnumre og kundetyper.
- Find dubletter på ekstern reference og registreringsnummer.
- Skriv kun en rapport; ingen produktionsdata ændres.

## 2. Testimport

- Importér til separat testdatabase.
- Sammenlign antal kunder, køretøjer, bookinger og fakturakladder.
- Kontroller historik og relationer manuelt.

## 3. Parallelkørsel

- Behold nuværende system som produktionskilde.
- Importér kun nye eller ændrede poster via ekstern reference.
- Log alle ændringer i revisionshistorikken.
- Stop importen ved valideringsfejl eller uventede mængder.
