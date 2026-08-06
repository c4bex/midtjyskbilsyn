const baseUrl = () => (process.env.LARAVEL_API_BASE_URL ?? "http://api:8000").replace(/\/$/, "");

export function copySetCookies(source: Headers, target: Headers): void {
  const setCookies = (source as Headers & { getSetCookie?: () => string[] }).getSetCookie?.() ?? [];
  if (setCookies.length > 0) {
    for (const cookie of setCookies) target.append("set-cookie", cookie);
    return;
  }
  const combined = source.get("set-cookie");
  if (combined) target.append("set-cookie", combined);
}

export async function proxyLaravel(request: Request, path: string): Promise<Response> {
  const headers = new Headers({ accept: "application/json" });
  const cookie = request.headers.get("cookie");
  const contentType = request.headers.get("content-type");
  if (cookie) headers.set("cookie", cookie);
  if (contentType) headers.set("content-type", contentType);
  if (process.env.BOOKING_API_TOKEN) headers.set("authorization", `Bearer ${process.env.BOOKING_API_TOKEN}`);
  const method = request.method.toUpperCase();
  const response = await fetch(`${baseUrl()}${path}`, {
    method,
    headers,
    body: method === "GET" || method === "HEAD" ? undefined : await request.arrayBuffer(),
    redirect: "manual",
    cache: "no-store",
  });
  const outgoing = new Headers({ "content-type": response.headers.get("content-type") ?? "application/json" });
  copySetCookies(response.headers, outgoing);
  return new Response(response.body, { status: response.status, headers: outgoing });
}
