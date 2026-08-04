import { ensureBookingDatabase, bookingStationId } from "../../../../db/bootstrap";
import { getD1 } from "../../../../db";
import { normalizePlate, toTimestamp, type BookingInput } from "../../../../lib/bookings";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";

export async function PATCH(request: Request, context: { params: Promise<{ id: string }> }) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const { id } = await context.params;
  const input = await request.json() as Partial<BookingInput> & { action?: "cancel" };
  const d1 = getD1();
  const current = await d1.prepare(`SELECT b.*, c.display_name, c.customer_type, v.registration_normalized, v.make, v.model
    FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN vehicles v ON v.id = b.vehicle_id WHERE b.id = ? AND b.station_id = ?`)
    .bind(id, bookingStationId).first<Record<string, unknown>>();
  if (!current) return Response.json({ error: "Bookingen findes ikke" }, { status: 404 });

  const now = Date.now();
  const correlationId = crypto.randomUUID();
  if (input.action === "cancel") {
    await d1.batch([
      d1.prepare("UPDATE bookings SET status = 'cancelled', updated_at = ? WHERE id = ?").bind(now, id),
      d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, before_json, after_json) VALUES (?, ?, 'employee', ?, 'booking.cancelled', 'booking', ?, ?, ?, ?)")
        .bind(crypto.randomUUID(), now, actor.id, id, correlationId, JSON.stringify(current), JSON.stringify({ status: "cancelled" })),
    ]);
    return Response.json({ ok: true });
  }

  if (!input.date || !input.time || !input.customer?.trim() || !input.plate?.trim() || !input.customerType || !input.vehicle || !input.inspection) {
    return Response.json({ error: "Alle bookingfelter skal udfyldes" }, { status: 400 });
  }
  const startsAt = toTimestamp(input.date, input.time);
  const [make, ...modelParts] = input.vehicle.trim().split(/\s+/);
  try {
    await d1.batch([
      d1.prepare("UPDATE customers SET display_name = ?, customer_type = ?, updated_at = ? WHERE id = ?")
        .bind(input.customer.trim(), input.customerType, now, current.customer_id),
      d1.prepare("UPDATE vehicles SET registration_normalized = ?, make = ?, model = ?, updated_at = ? WHERE id = ?")
        .bind(normalizePlate(input.plate), make, modelParts.join(" ") || null, now, current.vehicle_id),
      d1.prepare("UPDATE bookings SET starts_at = ?, ends_at = ?, inspection_type = ?, updated_at = ? WHERE id = ?")
        .bind(startsAt, startsAt + 20 * 60_000, input.inspection, now, id),
      d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, before_json, after_json) VALUES (?, ?, 'employee', ?, 'booking.updated', 'booking', ?, ?, ?, ?)")
        .bind(crypto.randomUUID(), now, actor.id, id, correlationId, JSON.stringify(current), JSON.stringify(input)),
    ]);
    return Response.json({ ok: true });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Ukendt databasefejl";
    if (message.includes("UNIQUE")) return Response.json({ error: "Tidspunktet eller registreringsnummeret er allerede i brug" }, { status: 409 });
    return Response.json({ error: "Bookingen kunne ikke opdateres" }, { status: 500 });
  }
}
