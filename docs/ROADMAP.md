# Lokal leveranceplan

Alle punkter nedenfor bygges først med fiktive data og lokale køer. Ingen eksterne integrationer aktiveres i denne fase.

1. Fakturaklargøring: modtagne testposter, redigering, momsberegning, godkendelsesstatus og revisionsspor.
2. Import/parallelkørsel: importformat, dubletnøgle, valideringsrapport og separat importkørsel uden påvirkning af bookingdata.
3. SMS: sikkert telefonfelt, skabeloner, kø, reminder-plan og historik. GatewayAPI forbliver slukket.
4. Flatpay: lokalt afstemningsoverblik for dagskladder og match mod fakturabeløb. Flatpay fortsætter med at bogføre til Dinero.
5. Drift: roller, adgangsgrænser, fejllog, kø-status og driftsindikatorer.
6. Test og overgang: samlet testdata, pilotperiode, parallelkørsel og senere integrationsaktivering én adapter ad gangen.

## Bevidste begrænsninger

Synsprogram, Dinero, GatewayAPI, Flatpay-API og ARVO kaldes ikke af lokalappen. Eksterne tokens og nøgler må ikke lægges i kode, browser eller chat.
