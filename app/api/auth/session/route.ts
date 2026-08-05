const laravelUrl = () => process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");

export async function GET(request: Request) {
  const base = laravelUrl();
  if (!base) return Response.json({ authenticated: false, error: "Laravel-session er ikke konfigureret" }, { status: 503 });
  const response = await fetch(`${base}/api/session`, { headers: { cookie: request.headers.get("cookie") ?? "" }, cache: "no-store" });
  return new Response(await response.text(), { status: response.status, headers: { "content-type": "application/json" } });
}
