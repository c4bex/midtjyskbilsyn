import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  const base = process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");
  if (process.env.USE_LARAVEL_BOOKING_API !== "true" || !base) return Response.json({ imports: [] });
  const response = await fetch(`${base}/api/imports`, { cache: "no-store" });
  if (!response.ok) return Response.json({ error: "Importstatus er midlertidigt utilgængelig" }, { status: 503 });
  return Response.json(await response.json());
}
