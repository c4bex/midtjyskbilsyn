# DMR-opslag

DMR/Motorstyrelsen-adapteren er oprettet, men deaktiveret. Nummerpladeopslag bruger derfor kun lokale data indtil videre.

Adapteren forventer senere et test-endpoint, autentificering og dokumentation for felterne:

- registreringsnummer
- mærke og model
- seneste synsdato
- næste synsdato

Når dokumentationen foreligger, tilføjes en read-only lookup med timeout, begrænset genforsøg, audit-log og tydelig status ved manglende svar. DMR-data må ikke overskrive lokale kundeoplysninger uden en eksplicit match- og godkendelsesregel.
