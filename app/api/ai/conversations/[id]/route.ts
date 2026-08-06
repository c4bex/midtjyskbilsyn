import { proxyLaravel } from "../../../../../lib/laravel-api";
export async function GET(request: Request, context: { params: Promise<{ id: string }> }) { const { id } = await context.params; return proxyLaravel(request, `/api/ai/conversations/${encodeURIComponent(id)}`); }
