import { ensureBookingDatabase, bookingStationId } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { formatPlate, normalizePlate, toDateAndTime, toTimestamp, type BookingInput, type BookingRecord } from "../../../lib/bookings";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

type BookingRow = {
  id: string; starts_at: number; display_name: string; customer_type: "private" | "business";
  registration_normalized: string; make: string | null; model: string | null; inspection_type: string;
  status: BookingRecord["status"];
};

const validDate = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value);

function mapBooking(row: BookingRow): BookingRecord {
  const { date, time } = toDateAndTime(row.starts_at);
  return {
    id: row.id, date, time, customer: row.display_name, customerType: row.customer_type,
    plate: formatPlate(row.registration_normalized), vehicle: [row.make, row.model].filter(Boolean).join(" "),
    inspection: row.inspection_type, status: row.status,
  };
}

async function availabilityFor(date: string, rows: BookingRow[]) {
  const d1 = getD1();
  const weekday = new Date(`${date}T12:00:00+02:00`).getDay();
  const rules = await d1.prepare("SELECT kind, starts_at, ends_at FROM availability_rules WHERE station_id = ? AND weekday = ? ORDER BY kind")
    .bind(bookingStationId, weekday).all<{ kind: string; starts_at: string; ends_at: string }>();
  const opening = rules.results.find((rule) => rule.kind === "opening_hours");
  if (!opening) return [];
  const breaks = rules.results.filter((rule) => rule.kind === "break");
  const occupied = new Set(rows.filter((row) => row.status !== "cancelled").map((row) => toDateAndTime(row.starts_at).time));
  const toMinutes = (time: string) => { const [hour, minute] = time.split(":").map(Number); return hour * 60 + minute; };
  const toTime = (minutes: number) => `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
  const slots: string[] = [];
  for (let minute = toMinutes(opening.starts_at); minute < toMinutes(opening.ends_at); minute += 20) {
    const withinBreak = breaks.some((rule) => minute >= toMinutes(rule.starts_at) && minute < toMinutes(rule.ends_at));
    const time = toTime(minute);
    if (!withinBreak && !occupied.has(time)) slots.push(time);
  }
  return slots;
}

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const date = new URL(request.url).searchParams.get("date") ?? "2026-08-04";
  if (!validDate(date)) return Response.json({ error: "Ugyldig dato" }, { status: 400 });
  const from = toTimestamp(date, "00:00");
  const to = from + 24 * 60 * 60_000;
  const result = await getD1().prepare(`SELECT b.id, b.starts_at, c.display_name, c.customer_type,
    v.registration_normalized, v.make, v.model, b.inspection_type, b.status
    FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN vehicles v ON v.id = b.vehicle_id
    WHERE b.station_id = ? AND b.starts_at >= ? AND b.starts_at < ? AND b.status != 'cancelled' ORDER BY b.starts_at`)
    .bind(bookingStationId, from, to).all<BookingRow>();
  return Response.json({ bookings: result.results.map(mapBooking), availableSlots: await availabilityFor(date, result.results) });
}

export async function POST(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const input = await request.json() as BookingInput;
  if (!validDate(input.date) || !/^\d{2}:\d{2}$/.test(input.time) || !input.customer?.trim() || !input.plate?.trim()) {
    return Response.json({ error: "Kunde, dato, tid og registreringsnummer skal udfyldes" }, { status: 400 });
  }
  const d1 = getD1();
  const now = Date.now();
  const bookingId = crypto.randomUUID();
  const correlationId = crypto.randomUUID();
  const startsAt = toTimestamp(input.date, input.time);
  const [make, ...modelParts] = input.vehicle.trim().split(/\s+/);
  const normalizedPlate = normalizePlate(input.plate);
  const existingVehicle = await d1.prepare("SELECT v.id AS vehicle_id, v.customer_id FROM vehicles v WHERE v.registration_normalized = ?")
    .bind(normalizedPlate).first<{ vehicle_id: string; customer_id: string }>();
  const customerId = existingVehicle?.customer_id ?? crypto.randomUUID();
  const vehicleId = existingVehicle?.vehicle_id ?? crypto.randomUUID();
  try {
    const personStatements = existingVehicle ? [
      d1.prepare("UPDATE customers SET display_name = ?, customer_type = ?, updated_at = ? WHERE id = ?")
        .bind(input.customer.trim(), input.customerType, now, customerId),
      d1.prepare("UPDATE vehicles SET make = ?, model = ?, updated_at = ? WHERE id = ?")
        .bind(make || null, modelParts.join(" ") || null, now, vehicleId),
    ] : [
      d1.prepare("INSERT INTO customers (id, display_name, customer_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
        .bind(customerId, input.customer.trim(), input.customerType, now, now),
      d1.prepare("INSERT INTO vehicles (id, customer_id, registration_normalized, make, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        .bind(vehicleId, customerId, normalizedPlate, make || null, modelParts.join(" ") || null, now, now),
    ];
    await d1.batch([
      ...personStatements,
      d1.prepare("INSERT INTO bookings (id, station_id, customer_id, vehicle_id, starts_at, ends_at, inspection_type, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)")
        .bind(bookingId, bookingStationId, customerId, vehicleId, startsAt, startsAt + 20 * 60_000, input.inspection, input.status ?? "confirmed", now, now),
      d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json, metadata_json) VALUES (?, ?, 'employee', ?, 'booking.created', 'booking', ?, ?, ?, ?)")
        .bind(crypto.randomUUID(), now, actor.id, bookingId, correlationId, JSON.stringify({ ...input, plate: normalizedPlate }), JSON.stringify({ source: "manual", channel: "local-prototype", actorName: actor.displayName })),
    ]);
    return Response.json({ booking: { id: bookingId } }, { status: 201 });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Ukendt databasefejl";
    if (message.includes("UNIQUE")) return Response.json({ error: "Tidspunktet eller registreringsnummeret er allerede i brug" }, { status: 409 });
    return Response.json({ error: "Bookingen kunne ikke gemmes" }, { status: 500 });
  }
}
