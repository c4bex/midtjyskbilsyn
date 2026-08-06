# Backup og gendannelse

Driftsdatabasen er MySQL 8.4. NAS-pakken tager automatisk en komprimeret, konsistent SQL-backup hver dag og beholder som standard 14 dages historik i `/volume1/docker/MidtjyskBilsyn/backups`.

## Før produktion

1. Kontrollér dagligt at backupcontaineren kører, og overvej 30 dages historik før produktion.
2. Kopiér senere backup til et separat, adgangsbegrænset drev eller ekstern destination.
3. Lav en månedlig gendannelsestest til en midlertidig separat database med `restore-test`-profilen.
4. Kontrollér antal kunder, køretøjer, bookinger, fakturakladder og audit-hændelser efter gendannelse.
5. Dokumentér hvem der godkender backup og gendannelse.

En backup er først godkendt, når en gendannelse er testet og resultatet er skrevet ned.

Den konkrete kommando og NAS-stier står i `deploy/nas/README.md`. Restore-testen sletter kun sin egen midlertidige kontroldatabase og ændrer ikke driftsdatabasen.
