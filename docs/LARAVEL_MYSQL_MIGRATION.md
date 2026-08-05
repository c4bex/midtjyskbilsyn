# Valgt retning: React + Laravel + MySQL

Vi vælger en trinvis backend-migrering:

```text
React/TypeScript → Laravel API → MySQL
                         ├── DMR/NAS
                         ├── synsprogram
                         ├── Dinero
                         └── GatewayAPI
```

Den nuværende React-prototype forbliver kørende, mens Laravel-backenden bygges separat. ARVO forbliver et helt separat projekt og får ikke fælles database eller direkte kodeafhængighed.

## Første Laravel-etape

1. Opret separat Laravel-projekt og lokal MySQL-database via Herd.
2. Opret migrations for stationer, kunder, køretøjer, bookinger, åbningstider, medarbejdere, fakturakladder og audit.
3. Implementér read-only API for kunder, køretøjer og bookinger.
4. Kør importvalidering mod en kopi af eksisterende data.
5. Skift først React-klienten til Laravel API efter parallel test.

Ingen produktionsdata flyttes, før backup, datamapping og rollback er godkendt.
