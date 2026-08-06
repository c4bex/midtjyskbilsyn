import { proxyLaravel } from "../../../../lib/laravel-api";

export const POST = (request: Request) => proxyLaravel(request, "/api/invoices/approve");
