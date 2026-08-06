import { proxyLaravel } from "../../../../../lib/laravel-api";

export async function PATCH(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return proxyLaravel(request, `/api/planning/profiles/${encodeURIComponent(id)}`);
}
