import { proxyLaravel } from "../../../lib/laravel-api";
export const GET = (request: Request) => proxyLaravel(request, "/api/availability");
export const POST = (request: Request) => proxyLaravel(request, "/api/availability");
export const PATCH = (request: Request) => proxyLaravel(request, "/api/availability");
