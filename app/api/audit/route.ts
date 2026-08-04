import { ensureBookingDatabase, bookingStationId } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const rows = await getD1().prepare("SELECT action, entity_type, entity_id, occurred_at, actor_id FROM audit_events ORDER BY occurred_at DESC LIMIT 30").all();
  return Response.json({ stationId: bookingStationId, events: rows.results });
}
