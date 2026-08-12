import { proxyLaravel } from "../../../../../lib/laravel-api";

export const GET = (request: Request) => {
  const token = new URL(request.url).searchParams.get("token") ?? "";
  return proxyLaravel(request, `/api/public/bookings/manage?token=${encodeURIComponent(token)}`);
};

export const PATCH = (request: Request) => proxyLaravel(request, "/api/public/bookings/manage");
