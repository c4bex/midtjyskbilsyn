# Booking-API (lokal backend)

Den lokale backend kører på `http://localhost:4317`.

## Read-only opslag

```text
GET /api/bookings?date=YYYY-MM-DD
```

Returnerer dagens bookinger og ledige tider:

```json
{
  "bookings": [],
  "availableSlots": []
}
```

Andre eksisterende read-only opslag er:

```text
GET /api/calendar/week?week=YYYY-MM-DD
GET /api/availability?date=YYYY-MM-DD
GET /api/customers
GET /api/vehicles/lookup?registration=AB12345
```

Den lokale API-kontrakt er klar til ARVO. Den er endnu ikke koblet til det eksterne bookingsystem/leverandørens API; det kræver leverandørens dokumenterede endpoint og autentificering. Skrivekald skal fortsat kræve eksplicit godkendelse og idempotency.
