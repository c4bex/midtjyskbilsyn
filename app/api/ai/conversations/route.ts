import { proxyLaravel } from "../../../../lib/laravel-api";
export async function POST(request: Request) { return proxyLaravel(request, "/api/ai/conversations"); }
