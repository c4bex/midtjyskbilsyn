import { ensureBookingDatabase, bookingStationId } from "../../../../db/bootstrap";
import { getD1 } from "../../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";

export async function DELETE(request: Request, context: { params: Promise<{ id: string }> }) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const { id } = await context.params;
  const d1 = getD1();
  const existing = await d1.prepare("SELECT id, kind, date_from, date_to, label FROM availability_rules WHERE id = ? AND station_id = ? AND kind IN ('holiday', 'vacation')")
    .bind(id, bookingStationId).first<Record<string, unknown>>();
  if (!existing) return Response.json({ error: "Lukkedagen findes ikke" }, { status: 404 });
  const now = Date.now();
  await d1.batch([
    d1.prepare("DELETE FROM availability_rules WHERE id = ? AND station_id = ?").bind(id, bookingStationId),
    d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, before_json) VALUES (?, ?, 'employee', ?, 'closure.deleted', 'availability', ?, ?, ?)")
      .bind(crypto.randomUUID(), now, actor.id, id, crypto.randomUUID(), JSON.stringify(existing)),
  ]);
  return Response.json({ ok: true });
}
