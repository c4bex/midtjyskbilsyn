import assert from "node:assert/strict";
import test from "node:test";

async function render() {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}`);
  const { default: worker } = await import(workerUrl.href);
  return worker.fetch(new Request("http://localhost/", { headers: { accept: "text/html" } }), {
    ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) },
  }, { waitUntil() {}, passThroughOnException() {} });
}

test("server-renderer den danske bookingoversigt", async () => {
  const response = await render();
  assert.equal(response.status, 200);
  const html = await response.text();
  assert.match(html, /<html lang="da">/i);
  assert.match(html, /<title>Driftsoverblik \| Midtjysk Bilsyn<\/title>/i);
  assert.match(html, /Bookingoversigt/);
  assert.match(html, /Ny booking/);
  assert.match(html, /Bookinger i dag/);
  assert.match(html, /AB 12 345/);
  assert.doesNotMatch(html, /codex-preview|react-loading-skeleton/i);
});

test("integrationsadaptere er deaktiverede som standard", async () => {
  const { dineroAdapter } = await import("../lib/integrations/adapters/dinero.ts");
  const { synsprogramAdapter } = await import("../lib/integrations/adapters/synsprogram.ts");
  assert.equal(dineroAdapter.enabled, false);
  assert.equal(synsprogramAdapter.enabled, false);
  await assert.rejects(() => dineroAdapter.execute({ idempotencyKey: "invoice:demo-1", correlationId: "test-1", payload: { invoiceId: "demo-1", bookingId: "booking-1", amountOere: 59500, currency: "DKK" } }), /ikke aktiveret/i);
});
