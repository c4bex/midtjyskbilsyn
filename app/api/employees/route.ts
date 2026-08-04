import { ensureBookingDatabase, bookingStationId } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const d1 = getD1();
  const existing = await d1.prepare("SELECT COUNT(*) AS count FROM employees WHERE station_id = ?").bind(bookingStationId).first<{ count: number }>();
  if (!existing?.count) {
    const now = Date.now();
    await d1.batch([
      d1.prepare("INSERT OR IGNORE INTO employees (id, station_id, display_name, email_normalized, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)").bind("emp-1", bookingStationId, "Peter Hartz Jensen", "peter@example.invalid", "synsinspektoer", now, now),
      d1.prepare("INSERT OR IGNORE INTO employees (id, station_id, display_name, email_normalized, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)").bind("emp-2", bookingStationId, "Rasmus Havn Mourtizen", "rasmus@example.invalid", "administrator", now, now),
      d1.prepare("INSERT OR IGNORE INTO employees (id, station_id, display_name, email_normalized, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)").bind("emp-3", bookingStationId, "Pernille Havn Mouritzen", "pernille@example.invalid", "bogholder", now, now),
    ]);
  }
  const [employees, absences, workRules] = await Promise.all([
    d1.prepare("SELECT id, display_name AS name, role, active FROM employees WHERE station_id = ? ORDER BY display_name").bind(bookingStationId).all(),
    d1.prepare("SELECT id, employee_id, kind, date_from, date_to, note FROM employee_absences ORDER BY date_from").all(),
    d1.prepare("SELECT id, employee_id, weekday, starts_at, ends_at, working FROM employee_work_rules ORDER BY employee_id, weekday").all(),
  ]);
  return Response.json({ employees: employees.results, absences: absences.results, workRules: workRules.results });
}

export async function POST(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const body = await request.json() as { type?: "absence" | "work_rule" | "employee_update"; employeeId?: string; displayName?: string; role?: string; active?: boolean; kind?: string; dateFrom?: string; dateTo?: string; note?: string; weekday?: number; startsAt?: string; endsAt?: string; working?: boolean };
  if (body.type === "employee_update") {
    if (!body.employeeId || !body.displayName?.trim() || !body.role?.trim()) return Response.json({ error: "Navn og rolle skal udfyldes" }, { status: 400 });
    await getD1().prepare("UPDATE employees SET display_name = ?, role = ?, active = ?, updated_at = ? WHERE id = ? AND station_id = ?").bind(body.displayName.trim(), body.role.trim(), body.active === false ? 0 : 1, Date.now(), body.employeeId, bookingStationId).run();
    await getD1().prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json) VALUES (?, ?, 'employee', ?, 'employee.updated', 'employee', ?, ?, ?)").bind(crypto.randomUUID(), Date.now(), actor.id, body.employeeId, crypto.randomUUID(), JSON.stringify({ displayName: body.displayName.trim(), role: body.role.trim(), active: body.active !== false })).run();
    return Response.json({ ok: true, actor: actor.displayName });
  }
  if (body.type === "work_rule") {
    if (!body.employeeId || !body.weekday || body.weekday < 1 || body.weekday > 5 || (body.working && (!/^\d{2}:\d{2}$/.test(body.startsAt ?? "") || !/^\d{2}:\d{2}$/.test(body.endsAt ?? "")))) return Response.json({ error: "Ugyldig arbejdstidsregel" }, { status: 400 });
    const now = Date.now();
    await getD1().prepare("INSERT INTO employee_work_rules (id, employee_id, weekday, starts_at, ends_at, working, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(employee_id, weekday) DO UPDATE SET starts_at = excluded.starts_at, ends_at = excluded.ends_at, working = excluded.working, updated_at = excluded.updated_at").bind(crypto.randomUUID(), body.employeeId, body.weekday, body.startsAt ?? null, body.endsAt ?? null, body.working ? 1 : 0, now, now).run();
    await getD1().prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json) VALUES (?, ?, 'employee', ?, 'employee.work_rule.updated', 'employee_work_rule', ?, ?, ?)").bind(crypto.randomUUID(), now, actor.id, body.employeeId, crypto.randomUUID(), JSON.stringify(body)).run();
    return Response.json({ ok: true, actor: actor.displayName });
  }
  if (!body.employeeId || !body.kind || !/^\d{4}-\d{2}-\d{2}$/.test(body.dateFrom ?? "") || !/^\d{4}-\d{2}-\d{2}$/.test(body.dateTo ?? "")) return Response.json({ error: "Medarbejder, type og gyldig periode skal udfyldes" }, { status: 400 });
  const now = Date.now();
  const id = crypto.randomUUID();
  await getD1().prepare("INSERT INTO employee_absences (id, employee_id, kind, date_from, date_to, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)").bind(id, body.employeeId, body.kind, body.dateFrom, body.dateTo, body.note ?? null, now, now).run();
  await getD1().prepare("INSERT INTO audit_events (id, occurred_at, actor_type, actor_id, action, entity_type, entity_id, correlation_id, after_json) VALUES (?, ?, 'employee', ?, 'employee.absence.created', 'employee_absence', ?, ?, ?)").bind(crypto.randomUUID(), now, actor.id, id, crypto.randomUUID(), JSON.stringify(body)).run();
  return Response.json({ id, actor: actor.displayName }, { status: 201 });
}
