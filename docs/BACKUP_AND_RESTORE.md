# Backup og gendannelse

Produktionsdatabasen er Cloudflare D1. Backup skal derfor køres via Cloudflare-kontoens D1-backup/export, aldrig ved at kopiere hemmeligheder eller databasefiler fra chatten.

## Før produktion

1. Aktivér automatisk daglig backup med mindst 30 dages historik.
2. Gem backup i separat konto/område med begrænset adgang.
3. Lav en månedlig gendannelsestest til en separat testdatabase.
4. Kontrollér antal kunder, køretøjer, bookinger, fakturakladder og audit-hændelser efter gendannelse.
5. Dokumentér hvem der godkender backup og gendannelse.

En backup er først godkendt, når en gendannelse er testet og resultatet er skrevet ned.
