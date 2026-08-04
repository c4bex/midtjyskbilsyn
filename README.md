# Midtjysk Bilsyn Drift

Selvstændigt greenfield-system til booking og daglig drift hos Midtjysk Bilsyn. Bookingoversigt, kunde-/køretøjshistorik og administration af åbningstider bruger en separat lokal D1-database med fiktive data. Eksterne integrationer er fortsat deaktiverede.

## Lokal start

Kræver Node.js 22.13 eller nyere.

```bash
npm install
npm run dev
```

Åbn `http://localhost:4317`. Kør `npm test` for build og grundlæggende tests, og `npm run db:generate` efter ændringer i datamodellen.

Se [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for datamodel, integrationsprincipper og overgangsplan.
