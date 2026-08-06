import { proxyLaravel } from "../../../../../lib/laravel-api";

export const PATCH = (request: Request, context: { params: { id: string } }) => proxyLaravel(request, `/api/customers/${context.params.id}/billing`);
