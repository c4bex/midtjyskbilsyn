# Login og adgang

Loginlaget er valgfrit under udviklingen.

Aktivér lokal login-vagt i frontendens `.env.local`:

```env
NEXT_PUBLIC_USE_LARAVEL_AUTH=true
LARAVEL_API_BASE_URL=http://midtjysk-bilsyn-api.test
```

Genstart frontend-serveren og åbn `/login`. Laravel-sessionen bruger samme lokale
MySQL-database som API’et.

Hvis login skal slås fra under fejlsøgning, fjernes `NEXT_PUBLIC_USE_LARAVEL_AUTH` igen.
Det påvirker ikke medarbejderdata eller bookingdata.

Før produktionsbrug skal testbrugeren udskiftes, adgangskoder nulstilles, HTTPS aktiveres
og der skal oprettes individuelle brugere for de tre medarbejdere.
