# DMR-opslag

Booking-systemet har en aktiv, read-only DMR-adapter til den lokale NAS-bridge. Opslaget sker kun server-side; token og databaseoplysninger sendes aldrig til browseren. En manglende eller utilgængelig DMR-forbindelse må ikke blokere en manuel booking.

## Opslagsrækkefølge

1. Registreringsnummeret normaliseres til store bogstaver uden mellemrum og bindestreger.
2. Systemet søger først i sin egen MySQL/D1-køretøjsdatabase.
3. Hvis bilen ikke findes lokalt, kaldes NAS-bridgen med en timeout på fem sekunder.
4. Fundne DMR-data udfylder køretøjet i bookingdialogen. Data gemmes ikke automatisk som kundedata.

Brugerfladen skelner mellem `fundet`, `ikke fundet` og `midlertidigt utilgængelig`, så en netværksfejl aldrig vises som et sikkert negativt opslag.

## Konfiguration

Følgende værdier ligger kun i det lokale servermiljø og må ikke committes:

```text
DMR_LOOKUP_BASE_URL=http://<nas-adresse>:4318
DMR_LOOKUP_TOKEN=<server-hemmelighed>
DMR_LOOKUP_TIMEOUT_MS=5000
DMR_LOOKUP_DATASET=full
```

NAS-bridgen har ét tokenbeskyttet GET-endpoint og read-only databaseadgang. PostgreSQL er ikke eksponeret til lokalnettet.

## Felter

Adapteren accepterer registreringsnummer, stelnummer, mærke, model, variant, køretøjstype, anvendelse, status, første registreringsdato, drivmiddel, seneste synsdato, synsresultat og kilometertal. En eventuel officiel næste synsfrist vises kun, når den leveres eksplicit; systemet gætter ikke fristen ud fra en fast periode.

## Import og drift

Den fulde DMR-import bygges i en staging-tabel. Først efter vellykket indlæsning, indeksbygning og analyse bliver tabellen skiftet atomisk til aktiv. Det sikrer, at bookingopslag fortsætter mod den senest godkendte version under en lang import. Ugyldige valgfrie kilometertal gemmes som `null` og må ikke afbryde hele importen.
