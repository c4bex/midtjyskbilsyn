import { proxyLaravel } from "../../../../../../lib/laravel-api";
export const POST = (request: Request, context: { params: Promise<{ code: string }> }) => context.params.then(({ code }) => proxyLaravel(request, `/api/sms/templates/${encodeURIComponent(code)}/reset`));
