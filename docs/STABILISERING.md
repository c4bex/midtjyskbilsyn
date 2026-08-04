# Stabiliseringsrunde før integrationer

Denne runde lukker ufærdige kanter før systemet kobles til eksterne datakilder.

## Definition of done

- Alle brugerrettelser gemmes via API/database.
- Ferie, fridage og åbningstider påvirker kapacitetsberegningen.
- Fakturaer kan redigeres, gemmes og godkendes uden at sende til Dinero.
- Roller håndhæves ved API-kald og ændringer logges i `audit_events`.
- Driftsoverblikket viser systemstatus, hændelser og fejl.
- Mobil og desktop er gennemgået for popup- og layoutfejl.
- Integrationer er fortsat deaktiverede, indtil testdata og aktiveringsplan er godkendt.

## Integration klar

Først når punkterne er godkendt, tilføjes kontrolleret import fra synsprogrammet og Dinero. Import skal starte i validerings-/dry-run-mode og kunne køres idempotent.
