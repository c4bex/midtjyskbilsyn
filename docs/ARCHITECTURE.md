# Arkitektur — Midtjysk Bilsyn Drift

## Beslutning

Systemet er et selvstændigt projekt med React/TypeScript-brugerflade via vinext, Laravel 12 API og MySQL 8.4. MySQL er den eneste driftsdatabase; SQLite bruges kun isoleret af automatiske Laravel-tests. Projektet har eget Git-repository og anvender ingen filer, database eller port fra ARVO.

Booking, kunder, køretøjer, åbningstider, medarbejdere, fakturakladder, SMS-kø og audit gemmes gennem Laravel i MySQL. Alle browserkald går gennem den offentlige webcontainer; Laravel og MySQL ligger på et internt Docker-netværk. NAS-testmiljøet eksponeres kun gennem Tailscale og har sessionslogin, rollebaserede rettigheder og begrænsning af login/API-kald.

SMS forberedes som en separat GatewayAPI-adapter med faste skabeloner til bekræftelse, påmindelse, ændring og aflysning. Adapteren validerer telefonnummer, afsender, tekstlængde, idempotensnøgle og korrelations-id, men er deaktiveret indtil GatewayAPI-konto, afsender-id, testdata og en aktiveringsplan er dokumenteret.

Bookingpolitikken er: private kunder med telefonnummer får bekræftelse med det samme; en reminder planlægges kun ved en booking på en senere kalenderdag. Samme dags booking giver ingen reminder, så kunden ikke får en overflødig besked kort før synet.

Aktivering kræver tre serverkonfigurationer uden for Git: `GATEWAYAPI_TOKEN`, `GATEWAYAPI_SENDER` og `SMS_PHONE_ENCRYPTION_KEY`. Token og krypteringsnøgle må aldrig ligge i frontend, `.env` i Git eller chat. Først når de er sat i den lokale/serverbaserede secrets manager, kan en afgrænset testudsendelse gennemføres.

Forsidens ugekapacitet beregnes server-side ud fra åbningstider, pauser, lukkedage og aktive bookinger. API'et returnerer ugenummer og konkrete ledige tider pr. dag; et klik på en ledig dag åbner bookingflowet med dato og første ledige tid forudfyldt.

Kundeoversigten læser kunder, køretøjer og samlet synshistorik fra de normaliserede kernetabeller. Åbningstidsmodulet vedligeholder ugentlige åbningstider, pauser, faste lukkedage samt datobaseret ferie/helligdage. Bookingmotoren læser de samme regler, så ændringer påvirker ledige tider uden kopieret konfiguration.

## Moduler

- **Drift:** dashboard, kapacitet, fejl og hændelser.
- **Booking:** kalender, tilgængelighed, pauser, lukkedage, ferie og helligdage.
- **Kerne:** kunder, køretøjer, medarbejdere, roller og afdelinger.
- **Fakturering:** klargøring og historik med én faktura pr. booking og unik idempotensnøgle.
- **Integrationer:** separate adapters for synsprogram, Dinero, Motorstyrelsen og senere ARVO.
- **Audit:** append-only revisionshændelser med aktør, korrelations-id og før/efter-data.

## Integrationssikkerhed

Alle udgående handlinger bliver først skrevet til `integration_jobs`. En unik idempotensnøgle beskytter mod gentagelser. En worker reserverer et job, validerer payload, udfører kaldet og skriver resultatet til audit-loggen. Midlertidige fejl får eksponentielt genforsøg; permanente eller udmattede fejl går til `dead_letter`. Fakturatabellen håndhæver desuden unikke booking-, idempotens- og Dinero-referencer, så samme syn ikke kan faktureres to gange.

Synsprogram-, Dinero- og ARVO-adapterne er hard-disabled og kan ikke kalde eksterne systemer. Aktivering kræver versionsfast dokumentation, testmiljø/testdata, kontrakttest, hemmeligheder i en secret store og en godkendt rollback-plan. DMR er den eneste aktive read-only integration og går gennem en tokenbeskyttet NAS-bridge uden skriveadgang til kildedatabasen.

Køretøjsopslaget spørger den read-only DMR-adapter med fem sekunders timeout. Hvis bridgen er utilgængelig, kan systemet falde tilbage til et allerede kendt køretøj i MySQL. Fejl vises som `DMR midlertidigt utilgængelig` og blokerer ikke manuel booking. En officiel næste synsdato vises kun, hvis kilden leverer den eksplicit.

## Persondata og adgang

Der gemmes kun visningsnavn og nødvendige kontaktkanaler; telefon og e-mail er modelleret som krypterede felter. CPR-numre, fødselsdatoer og fritekst med følsomme oplysninger hører ikke hjemme i systemet. Roller håndhæves server-side efter princippet om mindst mulige rettigheder. Alle mutationer skal audit-logges. Hemmeligheder må kun ligge i miljøets secret store, aldrig i kode, databaseudtræk, logs eller chat.

## Kontrolleret overgang

Det nuværende driftssystem er produktionskilden, indtil en særskilt overgang er godkendt. Senere forløb: kortlægning → anonymiseret prøveimport → afstemningsrapport → parallelkørsel med skrivebeskyttet ny løsning → begrænset pilot → kontrolleret skift. Hvert importkørsels-ID og hver kildereference gemmes, så importen kan genkøres uden dubletter og afstemmes række for række.

## Første datamodel

`customers` ejer normalt `vehicles`; en `booking` binder kunde og køretøj til en tid. `availability_rules` dækker åbningstider, pauser samt ferie-/lukkedage. `employees`, `employee_work_rules` og `employee_absences` dækker adgang og arbejdstid. `invoice_drafts`, `sms_messages` og `audit_events` holder klargøring, idempotent beskedkø og revisionshistorik. En genereret, unik MySQL-kolonne beskytter aktive tider mod dobbeltbooking.

## Lokal afgrænsning

Lokal frontend bruger port `4317`; det private NAS-testmiljø bruger port `4321`. Projektet må ikke genbruge ARVO-miljøvariable, databasefiler, containernavne eller integrationsnøgler.
