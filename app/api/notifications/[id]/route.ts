import { proxyLaravel } from "../../../../lib/laravel-api";

export const PATCH = (request: Request, context: { params: Promise<{ id: string }> }) =>
  context.params.then(({ id }) => proxyLaravel(request, `/api/notifications/${encodeURIComponent(id)}`));
