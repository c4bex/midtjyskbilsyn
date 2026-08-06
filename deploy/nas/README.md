# Privat NAS-testmiljø

Miljøet kører React-brugerfladen, Laravel API, MySQL, kø, scheduler og backup som separate containere. Kun proxy-porten eksponeres. MySQL kan ikke nås direkte fra netværket.

1. Kopiér hele repositoryet til en privat mappe på NAS'en.
2. Kopiér `deploy/nas/.env.example` til `deploy/nas/.env` og erstat alle pladsholdere lokalt på NAS'en.
3. Opret mapperne `${NAS_DATA_ROOT}/mysql` og `backups`.
4. Kør `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d --build` fra repositoryets rod.
5. Åbn `http://<NAS-Tailscale-IP>:4321`. Der må ikke oprettes port-forwarding i routeren.
6. Restore-test: `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml --profile maintenance run --rm restore-test`.

## Sikkerhed og drift

- Adgangen sker kun via Tailscale på port `4321`; opret ikke port-forwarding i routeren.
- Kun Nginx-proxyen har en åben port. Laravel og MySQL er isoleret på Docker-netværket.
- Login begrænses til fem forsøg pr. minut, mens API-kald begrænses til 120 pr. minut.
- DMR-token, databasepasswords, applikationsnøgle og admin-password må kun ligge i `deploy/nas/.env` på NAS'en.
- Backup kører dagligt og gemmes i `${NAS_DATA_ROOT}/backups` i 14 dage som standard.
- Kør en restore-test efter første installation og derefter mindst månedligt.

## Adgang fra en anden computer

1. Installér Tailscale og log ind på den samme private konto.
2. Kontrollér at NAS-enheden er online i Tailscale.
3. Åbn `http://100.68.88.2:4321` og log ind med den oprettede systembruger.
4. Hvis siden ikke svarer, kontrollér først Tailscale og derefter `proxy`, `web`, `api` og `mysql` i Container Manager.

Hemmeligheder må kun ligge i den ignorerede `.env` på NAS'en. De må ikke sendes i chat eller lægges i Git.
