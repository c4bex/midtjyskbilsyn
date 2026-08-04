import { ensureBookingDatabase, bookingStationId } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

type RuleRow = { id: string; kind: string; weekday: number | null; starts_at: string | null; ends_at: string | null; date_from: string | null; date_to: string | null; label: string };

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const result = await getD1().prepare("SELECT id, kind, weekday, starts_at, ends_at, date_from, date_to, label FROM availability_rules WHERE station_id = ? ORDER BY weekday, date_from")
    .bind(bookingStationId).all<RuleRow>();
  return Response.json({ rules: result.results });
}

export async function PATCH(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const input = await request.json() as { weekday?: number; closed?: boolean; startsAt?: string; endsAt?: string; breakStartsAt?: string; breakEndsAt?: string };
  if (!input.weekday || input.weekday < 1 || input.weekday > 7) return Response.json({ error: "Ugyldig ugedag" }, { status: 400 });
  if (!input.closed && (!input.startsAt || !input.endsAt || input.startsAt >= input.endsAt)) return Response.json({ error: "Åbningstiden er ugyldig" }, { status: 400 });
  const d1 = getD1();
  const now = Date.now();
  const correlationId = crypto.randomUUID();
  const statements = [
    d1.prepare("DELETE FROM availability_rules WHERE station_id = ? AND weekday = ? AND kind IN ('opening_hours', 'break', 'closed_day')").bind(bookingStationId, input.weekday),
  ];
  if (input.closed) {
    statements.push(d1.prepare("INSERT INTO availability_rules (id, station_id, kind, weekday, label, created_at, updated_at) VALUES (?, ?, 'closed_day', ?, 'Fast lukkedag', ?, ?)")
      .bind(`closed-${input.weekday}`, bookingStationId, input.weekday, now, now));
  } else {
    statements.push(d1.prepare("INSERT INTO availability_rules (id, station_id, kind, weekday, starts_at, ends_at, label, created_at, updated_at) VALUES (?, ?, 'opening_hours', ?, ?, ?, 'Normal åbningstid', ?, ?)")
      .bind(`opening-${input.weekday}`, bookingStationId, input.weekday, input.startsAt, input.endsAt, now, now));
    if (input.breakStartsAt && input.breakEndsAt && input.breakStartsAt < input.breakEndsAt) {
      statements.push(d1.prepare("INSERT INTO availability_rules (id, station_id, kind, weekday, starts_at, ends_at, label, created_at, updated_at) VALUES (?, ?, 'break', ?, ?, ?, 'Frokostpause', ?, ?)")
        .bind(`break-${input.weekday}`, bookingStationId, input.weekday, input.breakStartsAt, input.breakEndsAt, now, now));
    }
  }
  statements.push(d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json) VALUES (?, ?, 'employee', ?, 'availability.updated', 'availability', ?, ?, ?)")
    .bind(crypto.randomUUID(), now, actor.id, `weekday-${input.weekday}`, correlationId, JSON.stringify(input)));
  await d1.batch(statements);
  return Response.json({ ok: true });
}

export async function POST(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const input = await request.json() as { kind?: "holiday" | "vacation"; dateFrom?: string; dateTo?: string; label?: string };
  if (!input.kind || !input.dateFrom || !input.dateTo || !input.label?.trim() || input.dateFrom > input.dateTo) return Response.json({ error: "Udfyld en gyldig periode og årsag" }, { status: 400 });
  const d1 = getD1();
  const now = Date.now();
  const id = crypto.randomUUID();
  const correlationId = crypto.randomUUID();
  await d1.batch([
    d1.prepare("INSERT INTO availability_rules (id, station_id, kind, date_from, date_to, label, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
      .bind(id, bookingStationId, input.kind, input.dateFrom, input.dateTo, input.label.trim(), now, now),
    d1.prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json) VALUES (?, ?, 'employee', ?, 'closure.created', 'availability', ?, ?, ?)")
      .bind(crypto.randomUUID(), now, actor.id, id, correlationId, JSON.stringify(input)),
  ]);
  return Response.json({ id }, { status: 201 });
}
