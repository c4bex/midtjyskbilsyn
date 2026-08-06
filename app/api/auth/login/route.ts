import { copySetCookies } from "../../../../lib/laravel-api";

const laravelUrl = () => process.env.LARAVEL_API_BASE_URL?.replace(/\/$/, "");

export async function POST(request: Request) {
  const base = laravelUrl();
  if (!base) return Response.json({ error: "Laravel-login er ikke konfigureret" }, { status: 503 });
  try {
    const response = await fetch(`${base}/api/login`, { method: "POST", headers: { "content-type": "application/json", accept: "application/json" }, body: await request.text(), redirect: "manual" });
    const headers = new Headers({ "content-type": "application/json" });
    copySetCookies(response.headers, headers);
    return new Response(await response.text(), { status: response.status, headers });
  } catch {
    return Response.json({ error: "Loginserveren svarer ikke" }, { status: 503 });
  }
}
