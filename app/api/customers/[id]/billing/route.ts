import { proxyLaravel } from "../../../../../lib/laravel-api";

export const PATCH = async (request: Request, context: { params: Promise<{ id: string }> }) => {
  const { id } = await context.params;
  return proxyLaravel(request, `/api/customers/${id}/billing`);
};
