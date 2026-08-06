import { proxyLaravel } from "../../../lib/laravel-api";
export const GET = (request: Request) => proxyLaravel(request, `/api/search${new URL(request.url).search}`);
