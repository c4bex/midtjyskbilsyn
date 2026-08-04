import { ensureBookingDatabase } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";
import { formatPlate, toDateAndTime } from "../../../lib/bookings";

type CustomerRow = {
  customer_id: string; display_name: string; customer_type: "private" | "business";
  vehicle_id: string; registration_normalized: string; make: string | null; model: string | null;
  booking_id: string | null; starts_at: number | null; inspection_type: string | null; status: string | null;
};

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const result = await getD1().prepare(`SELECT c.id AS customer_id, c.display_name, c.customer_type,
    v.id AS vehicle_id, v.registration_normalized, v.make, v.model,
    b.id AS booking_id, b.starts_at, b.inspection_type, b.status
    FROM customers c JOIN vehicles v ON v.customer_id = c.id
    LEFT JOIN bookings b ON b.customer_id = c.id
    WHERE c.deleted_at IS NULL
    ORDER BY c.display_name COLLATE NOCASE, b.starts_at DESC`).all<CustomerRow>();

  const customers = new Map<string, {
    id: string; name: string; customerType: "private" | "business";
    vehicles: Array<{ id: string; plate: string; vehicle: string }>;
    history: Array<{ id: string; date: string; time: string; inspection: string; status: string }>;
  }>();
  for (const row of result.results) {
    const customer = customers.get(row.customer_id) ?? { id: row.customer_id, name: row.display_name, customerType: row.customer_type, vehicles: [], history: [] };
    if (!customer.vehicles.some((vehicle) => vehicle.id === row.vehicle_id)) {
      customer.vehicles.push({ id: row.vehicle_id, plate: formatPlate(row.registration_normalized), vehicle: [row.make, row.model].filter(Boolean).join(" ") });
    }
    if (row.booking_id && row.starts_at && !customer.history.some((booking) => booking.id === row.booking_id)) {
      const { date, time } = toDateAndTime(row.starts_at);
      customer.history.push({ id: row.booking_id, date, time, inspection: row.inspection_type ?? "Syn", status: row.status ?? "confirmed" });
    }
    customers.set(row.customer_id, customer);
  }
  return Response.json({ customers: [...customers.values()] });
}
