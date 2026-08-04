import { getChatGPTUser } from "../app/chatgpt-auth";

export type RequestActor = { id: string; displayName: string };

export async function authorizeBookingRequest(request: Request): Promise<RequestActor | null> {
  const hostname = new URL(request.url).hostname;
  if (hostname === "localhost" || hostname === "127.0.0.1") {
    return { id: "local-development", displayName: "Lokal udvikling" };
  }
  const user = await getChatGPTUser();
  return user ? { id: user.userId, displayName: user.displayName } : null;
}

export const unauthorizedResponse = () => Response.json({ error: "Du skal være logget ind for at bruge bookingsystemet" }, { status: 401 });
