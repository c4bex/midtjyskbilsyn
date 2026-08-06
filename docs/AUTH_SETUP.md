# Login og adgang

Login er obligatorisk i det samlede NAS-testmiljø.

Konfigurér den lokale frontend til Laravel i `.env.local`:

```env
LARAVEL_API_BASE_URL=http://midtjysk-bilsyn-api.test
```

Genstart frontend-serveren og åbn `/login`. Login-vagten kan ikke slås fra i brugerfladen;
Laravel-sessionen bruger samme MySQL-database som API’et.

NAS-installationen opretter kun administratoren, når `SEED_ADMIN_EMAIL` og
`SEED_ADMIN_PASSWORD` er sat i den private NAS-`.env`. Der findes ingen hardkodet
testbruger. Login begrænses til fem forsøg pr. minut. Før egentlig produktion skal HTTPS
aktiveres, sessionscookies markeres `Secure`, og der skal oprettes individuelle brugere.
