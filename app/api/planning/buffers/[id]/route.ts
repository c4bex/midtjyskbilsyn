import { proxyLaravel } from "../../../../../lib/laravel-api";

export async function DELETE(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return proxyLaravel(request, `/api/planning/buffers/${encodeURIComponent(id)}`);
}
