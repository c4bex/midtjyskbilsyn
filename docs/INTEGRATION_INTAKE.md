# Integrationspakke – klar til dokumentation

Integrationer er fortsat deaktiverede. Denne pakke beskriver det, der skal indsamles før aktivering.

## Synsprogram

- API-baseadresse og autentificeringsmetode
- Testmiljø eller anonymiserede testdata
- Unik nøgle for syn/fakturalinje
- Felter: kundenavn, CVR, registreringsnummer, mærke, model, synstype, synsdato, pris og moms
- Regler for rettelser, annulleringer og genimport

## Dinero

- Testadgang/OAuth-konfiguration
- Kontoplan og momskoder
- Kundematch (CVR som primær nøgle)
- Fakturanummerstrategi og kladde/godkendelsesflow
- Krav til kreditnota og fejlrettelser

## Aktiveringsrækkefølge

1. Importvalidering uden databaseændringer (dry run).
2. Testimport til lokal kladde med fuld revisionslog.
3. Parallelkørsel mod nuværende produktionskilde.
4. Godkendelse af resultater og dobbeltkontrol af fakturaer.
5. Begrænset aktivering for én kundegruppe.
6. Først derefter gradvis fuld aktivering.

Ingen hemmeligheder eller produktionsnøgler må gemmes i kode eller chat.
