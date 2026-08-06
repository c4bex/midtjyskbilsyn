import { proxyLaravel } from "../../../../../lib/laravel-api";

export async function PATCH(request: Request, context: { params: Promise<{ date: string }> }) {
  const { date } = await context.params;
  return proxyLaravel(request, `/api/planning/days/${encodeURIComponent(date)}`);
}
