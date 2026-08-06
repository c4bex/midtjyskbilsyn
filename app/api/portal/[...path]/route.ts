import { proxyLaravel } from "../../../../lib/laravel-api";

type Context = { params: Promise<{ path: string[] }> };

async function proxy(request: Request, context: Context): Promise<Response> {
  const { path } = await context.params;
  const suffix = path.map((part) => encodeURIComponent(part)).join("/");
  return proxyLaravel(request, `/api/portal/${suffix}${new URL(request.url).search}`);
}

export const GET = proxy;
export const POST = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
