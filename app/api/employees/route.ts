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
  const [employees, absences] = await Promise.all([
    d1.prepare("SELECT id, display_name AS name, role, active FROM employees WHERE station_id = ? ORDER BY display_name").bind(bookingStationId).all(),
    d1.prepare("SELECT id, employee_id, kind, date_from, date_to, note FROM employee_absences ORDER BY date_from").all(),
  ]);
  return Response.json({ employees: employees.results, absences: absences.results });
}

export async function POST(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const body = await request.json() as { employeeId?: string; kind?: string; dateFrom?: string; dateTo?: string; note?: string };
  if (!body.employeeId || !body.kind || !/^\d{4}-\d{2}-\d{2}$/.test(body.dateFrom ?? "") || !/^\d{4}-\d{2}-\d{2}$/.test(body.dateTo ?? "")) return Response.json({ error: "Medarbejder, type og gyldig periode skal udfyldes" }, { status: 400 });
  const now = Date.now();
  const id = crypto.randomUUID();
  await getD1().prepare("INSERT INTO employee_absences (id, employee_id, kind, date_from, date_to, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)").bind(id, body.employeeId, body.kind, body.dateFrom, body.dateTo, body.note ?? null, now, now).run();
  return Response.json({ id, actor: actor.displayName }, { status: 201 });
}
