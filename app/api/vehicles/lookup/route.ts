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

async function lookupOnNas(registration: string): Promise<Response | null> {
  if (!dmrAdapter.enabled) return null;

  try {
    const dmr = await lookupDmrVehicle(registration);
    if (dmr.found && dmr.vehicle) {
      return Response.json({
        found: true,
        source: "dmr-nas",
        registration: formatPlate(registration),
        vehicle: dmr.vehicle,
        lastInspectionDate: dmr.vehicle.lastInspectionDate ?? dmr.vehicle.inspectionDate ?? null,
        inspectionDueDate: dmr.vehicle.inspectionDueDate ?? null,
        dmr: { enabled: true, status: "connected", dataVersion: dmr.dataVersion ?? null },
      });
    }

    return Response.json({
      found: false,
      registration: formatPlate(registration),
      source: "dmr-nas",
      dmr: { enabled: true, status: "connected", dataVersion: dmr.dataVersion ?? null },
    });
  } catch {
    return Response.json({
      found: false,
      registration: formatPlate(registration),
      source: "dmr-nas",
      dmr: { enabled: true, status: "temporarily_unavailable" },
    });
  }
}

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  const registration = normalizePlate(new URL(request.url).searchParams.get("plate") ?? "");
  if (registration.length < 5) return Response.json({ error: "Indtast et gyldigt registreringsnummer" }, { status: 400 });
  const laravelBaseUrl = process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");
  if (process.env.USE_LARAVEL_BOOKING_API === "true" && laravelBaseUrl) {
    try {
      const response = await fetch(`${laravelBaseUrl}/api/vehicles/lookup?registration=${encodeURIComponent(registration)}`, { cache: "no-store" });
      if (!response.ok) throw new Error("Laravel vehicle API unavailable");
      const data = await response.json() as { found: boolean; vehicle?: { registration: string; make: string | null; model: string | null }; customer?: { name: string; customerType: "private" | "business" } };
      if (data.found) return Response.json({ ...data, source: "local-mysql", registration: formatPlate(registration), dmr: { enabled: dmrAdapter.enabled, status: dmrAdapter.enabled ? "connected" : "not_connected" } });
      const dmrResponse = await lookupOnNas(registration);
      return dmrResponse ?? Response.json({ ...data, source: "none", registration: formatPlate(registration), dmr: { enabled: false, status: "not_connected" } });
    } catch {
      const dmrResponse = await lookupOnNas(registration);
      return dmrResponse ?? Response.json({ found: false, registration: formatPlate(registration), source: "local-mysql", dmr: { enabled: false, status: "temporarily_unavailable" } });
    }
  }
  await ensureBookingDatabase();
  const row = await getD1().prepare(`SELECT v.id AS vehicle_id, v.registration_normalized, v.make, v.model,
    c.id AS customer_id, c.display_name, c.customer_type,
    MAX(CASE WHEN b.status = 'completed' THEN b.starts_at END) AS last_inspection_at
    FROM vehicles v JOIN customers c ON c.id = v.customer_id
    LEFT JOIN bookings b ON b.vehicle_id = v.id
    WHERE v.registration_normalized = ?
    GROUP BY v.id, c.id`).bind(registration).first<VehicleRow>();

  if (!row) {
    const dmrResponse = await lookupOnNas(registration);
    if (dmrResponse) return dmrResponse;
    return Response.json({ found: false, registration: formatPlate(registration), source: "none", dmr: { enabled: dmrAdapter.enabled, status: "not_connected" } });
  }
  return Response.json({
    found: true,
    source: "local",
    vehicle: { id: row.vehicle_id, registration: formatPlate(row.registration_normalized), make: row.make, model: row.model },
    customer: { id: row.customer_id, name: row.display_name, customerType: row.customer_type },
    lastInspectionDate: row.last_inspection_at ? toDateAndTime(row.last_inspection_at).date : null,
    inspectionDueDate: null,
    dmr: { enabled: dmrAdapter.enabled, status: dmrAdapter.enabled ? "connected" : "not_connected" },
  });
}
