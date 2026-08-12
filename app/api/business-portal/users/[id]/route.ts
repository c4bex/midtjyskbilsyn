import { proxyLaravel } from "../../../../../lib/laravel-api";

type Context = { params: Promise<{ id: string }> };

export async function DELETE(request: Request, context: Context) {
  const { id } = await context.params;
  return proxyLaravel(request, `/api/business-portal/users/${encodeURIComponent(id)}`);
}
