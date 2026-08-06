import { proxyLaravel } from "../../../lib/laravel-api";
export const GET = (request: Request) => proxyLaravel(request, "/api/employees");
export const POST = (request: Request) => proxyLaravel(request, "/api/employees");
