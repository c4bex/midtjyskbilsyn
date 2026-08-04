import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";
import { validateImport, type ImportRecord } from "../../../../lib/import-validation";

export async function POST(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  const body = await request.json() as { records?: ImportRecord[] };
  if (!Array.isArray(body.records) || body.records.length > 1000) return Response.json({ error: "Import skal være en liste på højst 1.000 poster" }, { status: 400 });
  return Response.json(validateImport(body.records));
}
