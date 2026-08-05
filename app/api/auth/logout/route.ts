export async function POST(request: Request) {
  const base = process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");
  if (!base) return Response.json({ ok: true });
  const response = await fetch(`${base}/api/logout`, { method: "POST", headers: { cookie: request.headers.get("cookie") ?? "", accept: "application/json" } });
  return new Response(await response.text(), { status: response.status, headers: { "content-type": "application/json" } });
}
