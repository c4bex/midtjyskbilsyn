import { copySetCookies } from "../../../../lib/laravel-api";

export async function POST(request: Request) {
  const base = process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");
  if (!base) return Response.json({ ok: true });
  const response = await fetch(`${base}/api/logout`, { method: "POST", headers: { cookie: request.headers.get("cookie") ?? "", accept: "application/json" } });
  const headers = new Headers({ "content-type": "application/json" });
  copySetCookies(response.headers, headers);
  return new Response(await response.text(), { status: response.status, headers });
}
