import { proxyLaravel } from "../../../../lib/laravel-api";

export const GET = (request: Request) => proxyLaravel(request, `/api/public/vehicle-lookup${new URL(request.url).search}`);
