import { proxyLaravel } from "../../../lib/laravel-api";

export const GET = (request: Request) => proxyLaravel(request, `/api/planning${new URL(request.url).search}`);
export const POST = (request: Request) => proxyLaravel(request, "/api/planning/buffers");
