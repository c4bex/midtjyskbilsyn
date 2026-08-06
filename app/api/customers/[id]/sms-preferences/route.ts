import { proxyLaravel } from "../../../../../lib/laravel-api";
export const GET = (request: Request, context: { params: Promise<{ id: string }> }) => context.params.then(({ id }) => proxyLaravel(request, `/api/customers/${id}/sms-preferences`));
export const PATCH = (request: Request, context: { params: Promise<{ id: string }> }) => context.params.then(({ id }) => proxyLaravel(request, `/api/customers/${id}/sms-preferences`));
