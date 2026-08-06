import { proxyLaravel } from "../../../../lib/laravel-api";
export async function GET(request: Request) { return proxyLaravel(request, "/api/ai/bootstrap"); }
