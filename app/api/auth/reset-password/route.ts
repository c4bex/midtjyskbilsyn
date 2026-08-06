import { NextRequest } from "next/server";
import { proxyLaravel } from "@/lib/laravel-api";

export async function POST(request: NextRequest) {
  return proxyLaravel(request, "/api/reset-password");
}
