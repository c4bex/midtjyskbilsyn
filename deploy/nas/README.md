# Privat NAS-testmiljø

Miljøet kører React-brugerfladen, Laravel API, MySQL, kø, scheduler og backup som separate containere. Kun proxy-porten eksponeres. MySQL kan ikke nås direkte fra netværket.

Webbrugerfladen udgives som et færdigt container-image, når en testversion
udtrykkeligt godkendes. Almindelige commits og push til GitHub er kun
sikkerhedskopiering og ændrer ikke det aktive NAS-testmiljø.

1. Kopiér hele repositoryet til en privat mappe på NAS'en.
2. Kopiér `deploy/nas/.env.example` til `deploy/nas/.env` og erstat alle pladsholdere lokalt på NAS'en.
3. Opret mapperne `${NAS_DATA_ROOT}/mysql` og `backups`.
4. Kør `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml pull web api migrate queue scheduler`.
5. Start systemet og udfør eventuelle databasemigreringer med `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d`.
7. Åbn `http://<NAS-Tailscale-IP>:4321`. Der må ikke oprettes port-forwarding i routeren.
8. Restore-test: `docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml --profile maintenance run --rm restore-test`.

## Lokal udvikling og kontrolleret testudgivelse

Under aktivt designarbejde bruges `npm run dev` på port `4317`. Ændringer vises
her med det samme og påvirker ikke andre brugere af NAS-testmiljøet. Koden kan
fortsat committes og skubbes til `main` som sikkerhedskopi.

Når en samlet ændring udtrykkeligt er godkendt til test, oprettes og skubbes et
tag med præfikset `test-`, eksempelvis:

```sh
git tag test-20260806-1530
git push origin test-20260806-1530
```

Kun et sådant test-tag (eller en bevidst manuel start af GitHub-workflowet)
bygger både `ghcr.io/c4bex/midtjyskbilsyn-web:test` og
`ghcr.io/c4bex/midtjyskbilsyn-api:test`. Når bygningen er godkendt, opdateres
hele systemet samlet på NAS'en:

```sh
docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml pull web api migrate queue scheduler
docker compose --env-file deploy/nas/.env -f deploy/nas/docker-compose.yml up -d
```

MySQL-data og backupvolumenerne bevares. Migrationsjobbet kører sikkert før API,
kø og scheduler starter, så kode og database altid følger samme udgave.

NAS-testlinket ændres dermed først, når ændringen både er godkendt, bygget og
webcontaineren bevidst er opdateret.

## Sikkerhed og drift

- Adgangen sker kun via Tailscale på port `4321`; opret ikke port-forwarding i routeren.
- Kun Nginx-proxyen har en åben port. Laravel og MySQL er isoleret på Docker-netværket.
- Login begrænses til fem forsøg pr. minut, mens API-kald begrænses til 120 pr. minut.
- DMR-token, databasepasswords og applikationsnøgle må kun ligge i `deploy/nas/.env` på NAS'en.
- Administratorens startkode sættes kun i den private `.env`. Udrulninger ændrer aldrig adgangskoden på en eksisterende konto.
- Password-mails kræver en rigtig SMTP-opsætning (`MAIL_*`). Standardværdien `MAIL_MAILER=log` sender ingen mail og er kun til test.
- Ved offentlig HTTPS skal `APP_URL` og `FRONTEND_URL` være den offentlige `https://`-adresse, og `SESSION_SECURE_COOKIE` skal sættes til `true`.
- `SEED_DEMO_DATA` skal være `false` ved lancering. Demodata kan kun aktiveres bevidst i et isoleret testmiljø.
- Backup kører dagligt og gemmes i `${NAS_DATA_ROOT}/backups` i 14 dage som standard.
- Kør en restore-test efter første installation og derefter mindst månedligt.

## Adgang fra en anden computer

1. Installér Tailscale og log ind på den samme private konto.
2. Kontrollér at NAS-enheden er online i Tailscale.
3. Åbn `http://100.68.88.2:4321` og log ind med den oprettede systembruger.
4. Hvis siden ikke svarer, kontrollér først Tailscale og derefter `proxy`, `web`, `api` og `mysql` i Container Manager.

Hemmeligheder må kun ligge i den ignorerede `.env` på NAS'en. De må ikke sendes i chat eller lægges i Git.
