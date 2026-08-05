# Lokal MySQL til Midtjysk Bilsyn

Laravel-backenden er forberedt til MySQL, men den lokale maskine har endnu ikke en
MySQL-klient eller en kørende MySQL-service. Den nuværende `backend/.env` bruger derfor
fortsat SQLite, så frontend og Laravel-tests kan køre uden at ændre data.

Når MySQL er tilgængelig lokalt:

1. Opret en tom database med navnet `midtjysk_bilsyn`.
2. Kopiér `backend/.env.example` til `backend/.env` eller opdatér kun databasefelterne.
3. Kontrollér `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` og `DB_PASSWORD`.
4. Kør fra `backend/`:

   ```bash
   php artisan migrate
   php artisan test
   ```

Migrationerne opretter kun den nye lokale database. Den gamle MySQL-database skal først
forbindes efter backup, feltafstemning og en kontrolleret parallelkørsel.
