import { proxyLaravel } from "../../../../../lib/laravel-api";

export const GET = (request: Request) => proxyLaravel(request, `/api/planning/v2/day${new URL(request.url).search}`);
