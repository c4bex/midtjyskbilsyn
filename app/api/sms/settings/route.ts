import { proxyLaravel } from "../../../../lib/laravel-api";
export const GET = (request: Request) => proxyLaravel(request, "/api/sms/settings");
export const PATCH = (request: Request) => proxyLaravel(request, "/api/sms/settings");
