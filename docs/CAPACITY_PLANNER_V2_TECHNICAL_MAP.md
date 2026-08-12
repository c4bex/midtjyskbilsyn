# Kapacitetsplanlægning V2 – teknisk kortlægning

## Genbrug

- `availability_rules`: åbningstider, pauser og lukkedage forbliver kildedata.
- `employees`, `employee_work_rules`, `employee_absences`, `departments` og `employee_departments`: bruges til bemanding pr. afdeling og 20-minutters blok.
- `inspection_types.required_slots`: bruges direkte til flerblok-bookinger, herunder toldsyn.
- `bookings`: bevares uændret som historisk sandhed; V2 tilføjer kun afdelingstilknytning.
- `buffer_slots` og `profile_buffer_rules`: læses fortsat som ældre faste/manuelle buffere under overgangsperioden.
- `audit_events`: fortsætter som fælles revisionshistorik.
- Eksisterende offentligt, internt og branchekunde-flow beholdes og kobles til samme V2-service.

## Nye data

- `capacity_profiles_v2`: redigerbare profiler for nul, én, to+ og specialbemanding.
- `recurring_buffer_rules`: tilbagevendende bufferregler pr. afdeling.
- `schedule_overrides`: låste enkeltstående åbninger, interne tider, buffere og lukninger.
- `buffer_relocations`: atomisk spor mellem anvendt buffer, erstatningsbuffer og booking.
- `capacity_day_locks`: én stabil databaselås pr. afdeling og dato, så samtidige bookinger serialiseres selv på en endnu tom dag.
- `bookings.department_id`: sikker, nullable relation; eksisterende bookinger knyttes til Ikast uden at ændre bookingindhold.

## Feature flag og rollback

`CAPACITY_PLANNER_V2` vælger motoren. Den gamle beregning bliver stående som rollback, indtil V2 er accepteret. Lokal standard er aktiv V2; flaget kan sættes til `false` uden datatab.

## Risici og afværgning

- V2 genvaliderer under samme transaktion som skrivningen og låser en stabil dagsrække; parallelle forsøg på samme afdeling/dato serialiseres også, når dagen endnu ikke har bookinger.
- Åbningstider er i dag globale. V2 profiler, bemanding, buffere og overrides er afdelingsopdelte, mens åbningstider genbruges globalt indtil en senere afdelingstilknytning er nødvendig.
- Medarbejdere uden afdelingstilknytning behandles som Ikast under migreringen for at bevare nuværende kapacitet.
- Historiske bookinger flyttes eller slettes aldrig. Nulbemanding markeres som konflikt internt.

## Filer/områder

- Migration og konfiguration i `backend/database/migrations` og `backend/config`.
- Central motor i `backend/app/Services/CapacityPlannerV2.php`.
- API-integration i `OperationsController` og `backend/routes/api.php`.
- Administrations- og kalenderpræsentation i `app/availability-view.tsx` og `app/dashboard.tsx`.
- Regression- og accepttests i `backend/tests/Feature`.
