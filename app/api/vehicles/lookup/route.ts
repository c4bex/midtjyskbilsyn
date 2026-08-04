import { ensureBookingDatabase } from "../../../../db/bootstrap";
import { getD1 } from "../../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";
import { formatPlate, normalizePlate, toDateAndTime } from "../../../../lib/bookings";
import { dmrAdapter, lookupDmrVehicle } from "../../../../lib/integrations/adapters/dmr";

type VehicleRow = {
  vehicle_id: string; registration_normalized: string; make: string | null; model: string | null;
  customer_id: string; display_name: string; customer_type: "private" | "business";
  last_inspection_at: number | null;
};

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const registration = normalizePlate(new URL(request.url).searchParams.get("plate") ?? "");
  if (registration.length < 5) return Response.json({ error: "Indtast et gyldigt registreringsnummer" }, { status: 400 });
  const row = await getD1().prepare(`SELECT v.id AS vehicle_id, v.registration_normalized, v.make, v.model,
    c.id AS customer_id, c.display_name, c.customer_type,
    MAX(CASE WHEN b.status = 'completed' THEN b.starts_at END) AS last_inspection_at
    FROM vehicles v JOIN customers c ON c.id = v.customer_id
    LEFT JOIN bookings b ON b.vehicle_id = v.id
    WHERE v.registration_normalized = ?
    GROUP BY v.id, c.id`).bind(registration).first<VehicleRow>();

  if (!row) {
    if (dmrAdapter.enabled) { try { const dmr = await lookupDmrVehicle(registration); if (dmr.found && dmr.vehicle) return Response.json({ found: true, source: "dmr-nas", registration: formatPlate(registration), vehicle: dmr.vehicle, lastInspectionDate: dmr.vehicle.inspectionDate, inspectionDueDate: null, dmr: { enabled: true, status: "connected", dataVersion: dmr.dataVersion } }); } catch { return Response.json({ found: false, registration: formatPlate(registration), source: "dmr-nas", dmr: { enabled: true, status: "temporarily_unavailable" } }); } }
    return Response.json({ found: false, registration: formatPlate(registration), source: "none", dmr: { enabled: dmrAdapter.enabled, status: "not_connected" } });
  }
  return Response.json({
    found: true,
    source: "local",
    vehicle: { id: row.vehicle_id, registration: formatPlate(row.registration_normalized), make: row.make, model: row.model },
    customer: { id: row.customer_id, name: row.display_name, customerType: row.customer_type },
    lastInspectionDate: row.last_inspection_at ? toDateAndTime(row.last_inspection_at).date : null,
    inspectionDueDate: null,
    dmr: { enabled: dmrAdapter.enabled, status: "not_connected" },
  });
}
