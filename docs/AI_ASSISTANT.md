# Fagassistent og dokumentopslag

Fagassistenten er et internt modul. Dokumenter gemmes privat i Laravel storage,
tekst udtrækkes lokalt, og kun de mest relevante tekstafsnit må sendes til en
ekstern AI-model. Bookingkontekst medtages kun efter medarbejderens aktive valg.

## Status

- PDF- og tekstupload, lokal tekstudtrækning og kildeopslag er implementeret.
- Samtaler, kilder, undersøgelser og ARVO-opgavekladder gemmes i MySQL.
- AI-adapteren og ARVO-adapteren er deaktiveret som standard.
- Netsøgning kræver et aktivt valg for det enkelte spørgsmål og er begrænset
  til en godkendt liste af officielle domæner.
- Ved manglende kildegrundlag skal assistenten afstå fra at svare.

## Serverkonfiguration

Konfigurationen må kun ligge i serverens miljøfil:

```dotenv
AI_ENABLED=false
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.6-sol
OPENAI_TIMEOUT_SECONDS=45
AI_TEST_MODE=true
AI_WEB_SEARCH_ENABLED=false
AI_WEB_ALLOWED_DOMAINS=retsinformation.dk,fstyr.dk,motorst.dk,skat.dk,virk.dk,borger.dk
ARVO_ENABLED=false
ARVO_BASE_URL=
ARVO_API_TOKEN=
```

Aktivér ikke integrationerne, før dokumentation, databehandlerforhold,
adgangsregler og testdata er godkendt. Hemmeligheder må aldrig lægges i Git.

## Kontrolleret netsøgning

Dokumentbiblioteket er altid førstevalg. Medarbejderen kan pr. spørgsmål vælge
`Søg også i officielle kilder`. Serveren sender kun søgningen videre, hvis både
AI og websøgnings-flaget er aktiveret. OpenAI-adapteren anvender et påkrævet
web-search-kald med domænefilter; resultater uden for listen kasseres også ved
modtagelsen. Hvert godkendt webfund gemmes med titel, URL, domæne og tidspunkt i
audit-tabellen `ai_web_sources` og vises som et klikbart `Officiel webkilde`-link.

Bookingkontekst er fortsat et separat aktivt valg, og kundens navn sendes ikke.
Officielle webfund er ikke automatisk virksomhedens godkendte procedure.

Ved første aktivering skal `AI_TEST_MODE=true`. I testtilstanden medtages ingen
bookingkontekst, og netsøgning er låst. Først efter faglig godkendelse af
testsvar ændres testtilstanden bevidst til `false`.

## Dokumentkontrol og OCR

Nye dokumenter gemmes som kladder. Kun dokumenter med teknisk status `ready`,
faglig status `approved`, aktiv version og en aktuel gyldighedsperiode indgår i
svargrundlaget. En ny fil kan erstatte en tidligere version; den gamle version
markeres først som erstattet, når den nye fil både er læst korrekt og fagligt
godkendt.

PDF-filer forsøges først læst direkte. Hvis de ikke indeholder tilstrækkelig
tekst, anvendes Poppler og Tesseract OCR med dansk og engelsk sprogdata. Metode,
fejl, filhash, godkender, godkendelsestidspunkt og interne noter gemmes til audit.

Teknisk reference: https://developers.openai.com/api/docs/guides/tools-web-search

## Videre udvikling

Næste kontrollerede trin er OCR til scannede PDF-filer, redigering og versionering
af dokumenter, mere avanceret relevanssøgning samt en dokumenteret ARVO-adapter
med idempotens, genforsøg og fuld audit-log.
