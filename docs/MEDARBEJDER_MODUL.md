# Medarbejdermodul

## Formål

Medarbejdermodulet samler medarbejdere, roller, adgang, arbejdstider, faste fridage og fravær ét sted. Modulet styrer samtidig, hvor mange samtidige syn systemet må åbne i kalenderen.

Målet er, at den daglige bruger kun skal vedligeholde ændringer. Den faste arbejdsplan og eventuelle rul bruges automatisk af booking- og kapacitetsberegningen.

## Medarbejdere i version 1

Systemet er forberedt til følgende medarbejdere:

| Medarbejder | Rolle | Aktiv | Tæller i bookingkapacitet |
|---|---|---:|---:|
| Peter Hartz Jensen | Synsinspektør | Ja | Ja |
| Rasmus Havn Mouritzen | Teknisk ansvarlig / Ejer | Ja | Ja |
| Pernille Havn Mouritzen | Bogholder / blæksprut | Ja | Nej |

En medarbejder, der ikke tæller i bookingkapaciteten, kan stadig have adgang til booking og kunder. Det betyder blot, at personen ikke åbner ekstra samtidige tider.

## Funktioner i brugerfladen

### Medarbejdere

Her vises alle medarbejdere med:

- navn og initialer
- rolle
- aktiv/inaktiv-status
- arbejdsplanens korte opsummering
- om medarbejderen åbner bookingtider

Med **Rediger** kan en administrator ændre navn, rolle, aktiv-status og bookingkapacitet. Ændringer gemmes først, når der trykkes **Gem**.

### Arbejdsplan

Arbejdsplanen vedligeholdes pr. medarbejder og ugedag:

- arbejdstid fra/til
- **På arbejde** eller **Fast fridag**
- gentagelse: hver uge, hver 2. uge eller hver 3. uge
- uge i rul, når rulperioden er længere end én uge

Rul beregnes efter ISO-ugen. Eksempel: Hvis en medarbejder arbejder hver anden mandag i uge 1, sættes mandag til **Hver 2. uge** og **Uge 1**. En anden medarbejder kan sættes til samme mandag og **Uge 2**.

Arbejdsplanen er en fast grundplan. Ferie, sygdom og andre enkeltstående fravær registreres separat og ændrer ikke grundplanen.

### Ferie og fravær

Fravær oprettes med:

- medarbejder
- type: Ferie, Sygdom eller Andet fravær
- fra-dato og til-dato
- valgfri note

Fravær reducerer automatisk bemandingen og dermed antallet af mulige samtidige bookinger i perioden. Det påvirker ikke allerede oprettede bookinger.

### Adgang

På fanen **Adgang** kan en administrator vælge medarbejder og tildele rettigheder enkeltvis eller via hurtigvalg:

- **Kun booking og kunder**
- **Bogholder**
- **Fuld adgang**

AI-assistenten er altid synlig og kan ikke skjules af medarbejderrettigheder. Adgangen til dokumentadministration, undersøgelser og ARVO-afsendelse kan dog begrænses separat.

## Roller og standardadgang

Roller giver en fornuftig standard. Hvis der gemmes en eksplicit rettighedsprofil for en medarbejder, bruges den profil i stedet for rolle-standarderne.

| Rolle | Standardadgang |
|---|---|
| Teknisk ansvarlig / Ejer | Fuld drift, medarbejdere, indstillinger, faktura/import og AI-funktioner |
| Synsinspektør | Se/oprette/rette bookinger og kunder samt bruge AI-assistenten |
| Bogholder / blæksprut | Se bookinger og kunder, klargøre fakturaer og bruge AI-assistenten |

### Rettighedskatalog

| Nøgle | Betydning |
|---|---|
| `bookings.read` | Se bookinger |
| `bookings.write` | Oprette og rette bookinger |
| `customers.read` | Se kunder og køretøjer |
| `customers.write` | Oprette og rette kunder |
| `invoices.write` | Klargøre og rette fakturaer |
| `imports.write` | Validere importer |
| `employees.write` | Administrere medarbejdere og rettigheder |
| `settings.write` | Ændre åbningstider og systemindstillinger |
| `ai.use` | Bruge AI-assistenten; altid tilladt |
| `ai.documents.write` | Administrere AI-dokumenter |
| `ai.investigations.read` | Se AI-undersøgelser |
| `ai.investigations.write` | Oprette AI-undersøgelser |
| `ai.arvo.send` | Sende godkendt materiale til ARVO |

Rettighedskontrollen håndhæves på serversiden med middleware. Skjulte menupunkter alene er derfor ikke en sikkerhedsgrænse.

## Bookingkapacitet

Kapaciteten beregnes ud fra:

1. medarbejderen er aktiv
2. medarbejderen er markeret som bookingkapacitet
3. medarbejderen er på arbejde den pågældende dato og eventuelt er med i det valgte rul
4. medarbejderen ikke har registreret fravær
5. åbningstider, pauser, lukkedage og kalenderprofil

Hvis to godkendte medarbejdere er på arbejde samtidigt, kan systemet åbne to samtidige bookingpladser. Hvis kun én er på arbejde, begrænses kapaciteten tilsvarende. Modulet tildeler ikke en bestemt medarbejder som ansvarlig for en booking.

## Datamodel

Modulet bruger følgende tabeller i MySQL:

- `employees`: navn, rolle, e-mail, aktiv-status og bookingkapacitet
- `employee_work_rules`: ugedag, arbejdstid, arbejdsstatus og rul
- `employee_absences`: fraværstype, periode og note
- `employee_permissions`: eksplicitte tilladelser pr. medarbejder
- `users`: loginidentitet, som kobles til medarbejderen via `employees.user_id`
- `audit_events`: revisionsspor for ændringer

Der gemmes kun de oplysninger, der er nødvendige for drift, adgang og revisionshistorik.

## API-overblik

Alle interne kald kræver login. Skrivekald kræver desuden `employees.write`.

| Metode | Endpoint | Formål |
|---|---|---|
| `GET` | `/api/employees` | Hent medarbejdere, rettighedskatalog, fravær og arbejdsregler |
| `POST` | `/api/employees` | Gem medarbejder, arbejdsregel, fravær eller rettighedsprofil |

`POST /api/employees` bruger feltet `type`:

- `employee_update`
- `work_rule`
- `absence`
- `employee_permissions`

Serveren validerer roller, datoer, klokkeslæt, rul og medarbejderrelationer, før data gemmes.

## Revisionsspor og sikkerhed

Følgende ændringer logges:

- medarbejder opdateret
- arbejdsregel opdateret
- fravær oprettet
- rettigheder ændret

Adgang gives efter mindste privilegium. Login sker med sessionscookies, og adgangskoder må ikke ligge i frontend, lokal storage eller dokumentation.

## Daglig arbejdsgang

1. Opret eller ret medarbejderen under **Medarbejdere**.
2. Markér om medarbejderen tæller i bookingkapaciteten.
3. Sæt den faste arbejdsplan og eventuelle rul under **Arbejdsplan**.
4. Registrér ferie eller andet fravær, når det kendes.
5. Tildel adgang under **Adgang**.
6. Kontrollér kalenderens ledige tider efter ændringen.

## Afgrænsning i version 1

Følgende er bevidst ikke en del af modulet endnu:

- automatisk løn- eller vagtplanssystem
- individuel ansvarlig medarbejder på en booking
- integration til ekstern HR/lønadministration
- selvbetjent adgangskode-nulstilling
- godkendelsesworkflow for ferie

Disse funktioner kan tilføjes senere uden at ændre den grundlæggende kapacitetsmodel.

## Test og acceptkriterier

Modulet er klar til version 1, når:

- hver medarbejder kan redigeres og gemmes
- arbejdstider, faste fridage og rul påvirker kalenderkapaciteten korrekt
- fravær reducerer kapaciteten i den valgte periode
- eksplicitte rettigheder håndhæves på API’et
- AI-assistenten fortsat er synlig for alle
- alle medarbejderændringer fremgår af revisionshistorikken
- adgang nægtes med HTTP 403, når en medarbejder mangler den nødvendige rettighed
