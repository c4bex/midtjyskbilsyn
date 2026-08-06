import { proxyLaravel } from "../../../lib/laravel-api";
export const GET = (request: Request) => proxyLaravel(request, `/api/bookings${new URL(request.url).search}`);
export const POST = (request: Request) => proxyLaravel(request, "/api/bookings");
