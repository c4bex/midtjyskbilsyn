# Midtjysk Bilsyn Drift

Selvstændigt greenfield-system til booking og daglig drift hos Midtjysk Bilsyn. Forsiden viser ugekapacitet med ugenummer og klikbare, grønne dage, der åbner booking direkte på den valgte dato. React/vinext-brugerfladen bruger en Laravel 12 API og MySQL 8.4 som eneste driftsdatabase. DMR-opslag er read-only via NAS-bridgen; GatewayAPI, Dinero, synsprogrammet og ARVO er fortsat deaktiverede adapters.

Den integrationsfri leveranceplan står i [docs/ROADMAP.md](docs/ROADMAP.md). Synsprogram, Dinero, GatewayAPI, Flatpay-API og ARVO er ikke tilkoblet.

Bookingformularen viser kun beregnede ledige tider, har en søgbar erhvervskundevælger og kan hente køretøjsdata gennem den aktive DMR-adapter. Hemmeligheder ligger kun server-side.

## Lokal start

Kræver Node.js 22.13 eller nyere, MySQL og PHP 8.4+ (Laravel Herd understøttes automatisk).

```bash
npm install
cd backend && php artisan migrate && cd ..
npm run dev:all
```

`dev:all` starter frontend, Laravel API og køarbejderen samlet og lukker dem samlet igen. Åbn `http://localhost:4317`. Kør `npm test` for build og grundlæggende tests, `cd backend && php artisan test` for API-tests, og `npm run db:generate` efter ændringer i datamodellen.

Det samlede NAS-testmiljø startes efter [deploy/nas/README.md](deploy/nas/README.md). Se [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for datamodel, integrationsprincipper og overgangsplan.
