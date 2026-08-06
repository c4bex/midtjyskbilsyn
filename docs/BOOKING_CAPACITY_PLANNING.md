# Booking og kapacitetsplanlægning

Modulet er bygget videre på den eksisterende bookingstil og holder planlægningen enkel i hverdagen.

## Synstyper og toldsyn

Hver synstype har et antal nødvendige tider. Almindelige synstyper bruger én tid, mens toldsyn bruger to sammenhængende tider. Toldsyn oprettes som én booking med korrekt sluttid, så den anden tid ikke kan bookes separat. Ved flytning eller annullering frigives begge tider igen.

## Arbejdsplaner

En kalenderprofil angiver kapaciteten for dagen, fx én eller to medarbejdere. Den faktiske bemanding sætter samtidig en øvre grænse, så systemet aldrig åbner flere tider end medarbejderne kan håndtere. Hvis profil og bemanding ikke passer sammen, vises en tydelig advarsel i planlægningen.

## Pauser og blokeringer

Faste bufferregler kan bruges til gentagne pauser, og dagsbuffer kan bruges til en enkelt dag. En buffer ændrer ikke eksisterende bookinger; hvis der er overlap, vises booking-id'erne som en konflikt, før brugeren selv tager stilling.

## Brugeroplevelse

Bookingvinduet viser kun tider, der er ledige for den valgte synstype. Kapacitetslogikken ligger i backend, så samme regel gælder for dashboard, bookingformular og senere integrationer.

## API-overblik

- `GET /api/planning?date=YYYY-MM-DD` – profiler, synstyper, bufferregler, dagsopsætning og konflikter.
- `PATCH /api/planning/inspection-types/{id}` – ret navn eller antal tider.
- `PATCH /api/planning/profiles/{id}` – ret kapacitetsprofil.
- `PATCH /api/planning/days/{date}` – vælg profil og bemanding for en dag.
- `POST /api/planning/buffers` og `DELETE /api/planning/buffers/{id}` – opret/fjern dagsbuffer.

