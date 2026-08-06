# Fagassistent og dokumentopslag

Fagassistenten er et internt modul. Dokumenter gemmes privat i Laravel storage,
tekst udtrækkes lokalt, og kun de mest relevante tekstafsnit må sendes til en
ekstern AI-model. Bookingkontekst medtages kun efter medarbejderens aktive valg.

## Status

- PDF- og tekstupload, lokal tekstudtrækning og kildeopslag er implementeret.
- Samtaler, kilder, undersøgelser og ARVO-opgavekladder gemmes i MySQL.
- AI-adapteren og ARVO-adapteren er deaktiveret som standard.
- Ved manglende kildegrundlag skal assistenten afstå fra at svare.

## Serverkonfiguration

Konfigurationen må kun ligge i serverens miljøfil:

```dotenv
AI_ENABLED=false
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.6-sol
OPENAI_TIMEOUT_SECONDS=45
ARVO_ENABLED=false
ARVO_BASE_URL=
ARVO_API_TOKEN=
```

Aktivér ikke integrationerne, før dokumentation, databehandlerforhold,
adgangsregler og testdata er godkendt. Hemmeligheder må aldrig lægges i Git.

## Videre udvikling

Næste kontrollerede trin er OCR til scannede PDF-filer, redigering og versionering
af dokumenter, mere avanceret relevanssøgning samt en dokumenteret ARVO-adapter
med idempotens, genforsøg og fuld audit-log.
