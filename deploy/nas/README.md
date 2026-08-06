# Privat NAS-testmiljø

Miljøet kører React-brugerfladen, Laravel API, MySQL, kø, scheduler og backup som separate containere. Kun proxy-porten eksponeres. MySQL kan ikke nås direkte fra netværket.

Webbrugerfladen bygges automatisk af GitHub Actions og udgives som et færdigt
container-image. NAS'en skal derfor ikke længere køre `npm ci` og bygge hele
webprojektet ved hver lille designændring.

1. Kopiér hele repositoryet til en privat mappe på NAS'en.
2. Kopiér `deploy/nas/.env.example` til `deploy/nas/.env` og erstat alle pladsholdere lokalt på NAS'en.
3. Opret mapperne `${NAS_DATA_ROOT}/mysql` og `backups`.
4. Kør `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml pull web`.
5. Kør `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d --build api migrate queue scheduler` ved første installation eller backendændringer.
6. Start resten med `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d`.
7. Åbn `http://<NAS-Tailscale-IP>:4321`. Der må ikke oprettes port-forwarding i routeren.
8. Restore-test: `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml --profile maintenance run --rm restore-test`.

## Hurtige designopdateringer

Når en designændring er godkendt og skubbet til `main`, bygger GitHub kun
web-image'et. Opdatér derefter kun webcontaineren på NAS'en:

```sh
docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml pull web
docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d --no-deps web
```

MySQL, Laravel, DMR, køen og backup fortsætter uændret. En designopdatering
medfører derfor ingen databasemigrering og ingen genstart af DMR.

Under aktivt designarbejde bruges `npm run dev` på port `4317`. Ændringer vises
her med det samme. NAS-testlinket opdateres først, når ændringen er samlet,
testet og skubbet til GitHub.

## Sikkerhed og drift

- Adgangen sker kun via Tailscale på port `4321`; opret ikke port-forwarding i routeren.
- Kun Nginx-proxyen har en åben port. Laravel og MySQL er isoleret på Docker-netværket.
- Login begrænses til fem forsøg pr. minut, mens API-kald begrænses til 120 pr. minut.
- DMR-token, databasepasswords og applikationsnøgle må kun ligge i `deploy/nas/.env` på NAS'en.
- Testmiljøets kendte admin-kode er bevidst `test`. Den skal erstattes af en stærk, hemmelig kode, før miljøet må bruges som produktion.
- Backup kører dagligt og gemmes i `${NAS_DATA_ROOT}/backups` i 14 dage som standard.
- Kør en restore-test efter første installation og derefter mindst månedligt.

## Adgang fra en anden computer

1. Installér Tailscale og log ind på den samme private konto.
2. Kontrollér at NAS-enheden er online i Tailscale.
3. Åbn `http://100.68.88.2:4321` og log ind med den oprettede systembruger.
4. Hvis siden ikke svarer, kontrollér først Tailscale og derefter `proxy`, `web`, `api` og `mysql` i Container Manager.

Hemmeligheder må kun ligge i den ignorerede `.env` på NAS'en. De må ikke sendes i chat eller lægges i Git.
