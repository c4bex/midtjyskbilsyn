import { ensureBookingDatabase } from "../../../../db/bootstrap";
import { getD1 } from "../../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const result = await getD1().prepare("SELECT status, COUNT(*) AS count FROM sms_messages GROUP BY status").all<{ status: string; count: number }>();
  const counts = Object.fromEntries(result.results.map((row) => [row.status, row.count]));
  return Response.json({ counts, total: result.results.reduce((sum, row) => sum + row.count, 0), enabled: false });
}
