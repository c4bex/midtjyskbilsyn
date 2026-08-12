import { proxyLaravel } from "../../../../../lib/laravel-api";

export const POST = (request: Request) => proxyLaravel(request, "/api/planning/v2/overrides");
