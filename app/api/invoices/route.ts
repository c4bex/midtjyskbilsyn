import { ensureBookingDatabase } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../lib/authorization";

type Draft = { id: string; customer_name: string; period: string; description: string; quantity: number; unit_price_ore: number; status: "Klargøres" | "Klar til Dinero"; source_reference: string | null };
const seed = [
  ["invoice-demo-1", "Autogården", "Juli 2026", "Syn · 1. Syn / P-syn · Syns nr. 166869 · Reg. nr. EC20464 · SUZUKI BALENO", 1, 38000, "Klargøres", "syn:166869"],
  ["invoice-demo-2", "Autohuset", "Juli 2026", "Syn · 1. Syn / P-syn · Syns nr. 167023 · Reg. nr. EH67875 · OPEL Crossland X", 1, 38000, "Klar til Dinero", "syn:167023"],
  ["invoice-demo-3", "Bbc Biler", "Juli 2026", "Syn · Omsyn · Syns nr. 167356 · Reg. nr. CN72849 · SUZUKI VITARA", 1, 38000, "Klar til Dinero", "syn:167356"],
] as const;

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const d1 = getD1();
  const count = await d1.prepare("SELECT COUNT(*) AS count FROM invoice_drafts").first<{ count: number }>();
  if (!count?.count) await d1.batch(seed.map((row) => d1.prepare("INSERT OR IGNORE INTO invoice_drafts (id, customer_name, period, description, quantity, unit_price_ore, status, source_reference, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)").bind(...row, Date.now(), Date.now())));
  const result = await d1.prepare("SELECT * FROM invoice_drafts ORDER BY customer_name").all<Draft>();
  return Response.json({ invoices: result.results });
}

export async function PATCH(request: Request) {
  const actor = await authorizeBookingRequest(request);
  if (!actor) return unauthorizedResponse();
  await ensureBookingDatabase();
  const body = await request.json() as { id?: string; description?: string; quantity?: number; unitPriceOre?: number; status?: Draft["status"] };
  if (!body.id || !body.description?.trim() || !body.quantity || body.quantity < 1 || body.unitPriceOre === undefined || body.unitPriceOre < 0) return Response.json({ error: "Ugyldige fakturadata" }, { status: 400 });
  await getD1().prepare("UPDATE invoice_drafts SET description = ?, quantity = ?, unit_price_ore = ?, status = ?, updated_at = ? WHERE id = ?").bind(body.description.trim(), body.quantity, body.unitPriceOre, body.status ?? "Klargøres", Date.now(), body.id).run();
  return Response.json({ ok: true, actor: actor.displayName });
}
