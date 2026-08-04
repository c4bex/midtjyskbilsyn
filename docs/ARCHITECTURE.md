# Arkitektur — Midtjysk Bilsyn Drift

## Beslutning

Systemet er et selvstændigt TypeScript-projekt med React/Next-kompatibel brugerflade via vinext, Cloudflare Worker-kompatibel serverkode, SQLite/D1 og Drizzle til data. Det giver én kodebase, hurtig lokal udvikling og en enkel vej til senere serverdrift. Projektet har eget Git-repository og anvender ingen filer, database eller port fra ARVO.

Bookingdelen har nu sin egen D1-binding (`DB`) og et kontrolleret, fiktivt startdatasæt. Opret, redigér og aflys gemmes persistent lokalt og skriver audit-hændelser med aktør-id. Lokal udvikling tillades kun på loopback; en senere hostet API kræver platformens autentificerede brugerheaders og sitet skal samtidig have en privat workspace-adgangspolitik. Der foretages fortsat ingen eksterne kald.

SMS forberedes som en separat GatewayAPI-adapter med faste skabeloner til bekræftelse, påmindelse, ændring og aflysning. Adapteren validerer telefonnummer, afsender, tekstlængde, idempotensnøgle og korrelations-id, men er deaktiveret indtil GatewayAPI-konto, afsender-id, testdata og en aktiveringsplan er dokumenteret.

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

Adapters er nu hard-disabled og kan ikke kalde eksterne systemer. Aktivering kræver versionsfast dokumentation, testmiljø/testdata, kontrakttest, hemmeligheder i en secret store og en godkendt rollback-plan.

Køretøjsopslaget søger først på normaliseret registreringsnummer i den lokale database og kan derfor udfylde mærke, model, kunde og seneste lokale syn uden eksterne kald. Et manglende lokalt resultat returnerer kun status `DMR ikke tilkoblet`; det udløser aldrig et skjult netværkskald. Senere kan DMR-adapteren levere de samme felter samt officiel næste synsdato gennem den eksisterende adaptergrænseflade.

## Persondata og adgang

Der gemmes kun visningsnavn og nødvendige kontaktkanaler; telefon og e-mail er modelleret som krypterede felter. CPR-numre, fødselsdatoer og fritekst med følsomme oplysninger hører ikke hjemme i systemet. Roller håndhæves server-side efter princippet om mindst mulige rettigheder. Alle mutationer skal audit-logges. Hemmeligheder må kun ligge i miljøets secret store, aldrig i kode, databaseudtræk, logs eller chat.

## Kontrolleret overgang

Det nuværende driftssystem er produktionskilden, indtil en særskilt overgang er godkendt. Senere forløb: kortlægning → anonymiseret prøveimport → afstemningsrapport → parallelkørsel med skrivebeskyttet ny løsning → begrænset pilot → kontrolleret skift. Hvert importkørsels-ID og hver kildereference gemmes, så importen kan genkøres uden dubletter og afstemmes række for række.

## Første datamodel

`stations` ejer åbningstider og bookinger. `employees` knyttes til station og rolle. `customers` ejer normalt `vehicles`; en booking binder station, køretøj, eventuel kunde og medarbejder sammen. `availability_rules` dækker gentagne åbningstider og pauser samt datobaserede ferie-/lukkedage. `invoices` er 1:1 med booking. `integration_jobs` er kø, genforsøg og fejlkø. `audit_events` er den fælles revisionshistorik.

## Lokal afgrænsning

Anbefalet lokal port er `4317` og skal konfigureres specifikt ved opstart. Projektet må ikke genbruge ARVO-miljøvariable, databasefiler, containernavne eller integrationsnøgler.
