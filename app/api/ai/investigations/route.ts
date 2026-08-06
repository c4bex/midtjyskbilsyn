import { proxyLaravel } from "../../../../lib/laravel-api";
export async function GET(request: Request) { return proxyLaravel(request, `/api/ai/investigations${new URL(request.url).search}`); }
export async function POST(request: Request) { return proxyLaravel(request, "/api/ai/investigations"); }
