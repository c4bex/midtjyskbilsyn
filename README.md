# Midtjysk Bilsyn Drift

Selvstændigt greenfield-system til booking og daglig drift hos Midtjysk Bilsyn. Forsiden viser ugekapacitet med ugenummer og klikbare, grønne dage, der åbner booking direkte på den valgte dato. Bookingoversigt, kunde-/køretøjshistorik og administration af åbningstider bruger en separat lokal D1-database med fiktive data. GatewayAPI-SMS er forberedt som separat adapter, men alle eksterne integrationer er fortsat deaktiverede.

Bookingformularen viser kun beregnede ledige tider, har en søgbar erhvervskundevælger og slår nummerplader op i egne data. En separat DMR/Motorstyrelsen-adapter er forberedt, men hard-disabled indtil dokumentation og testdata foreligger.

## Lokal start

Kræver Node.js 22.13 eller nyere.

```bash
npm install
npm run dev
```

Åbn `http://localhost:4317`. Kør `npm test` for build og grundlæggende tests, og `npm run db:generate` efter ændringer i datamodellen.

Se [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for datamodel, integrationsprincipper og overgangsplan.
