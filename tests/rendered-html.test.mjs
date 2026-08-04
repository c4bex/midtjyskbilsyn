import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("den danske dagsoversigt indeholder bookingflowet", async () => {
  const [dashboard, layout, page] = await Promise.all([
    readFile(new URL("../app/dashboard.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/layout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
  ]);
  assert.match(layout, /<html lang="da">/i);
  assert.match(page, /Driftsoverblik/);
  assert.match(dashboard, /Dagens bookinger/);
  assert.match(dashboard, /Filtrer efter kundetype/);
  assert.match(dashboard, /Private/);
  assert.match(dashboard, /Erhverv/);
  assert.match(dashboard, /Aflys booking/);
  assert.match(dashboard, /\/api\/bookings/);
  assert.doesNotMatch(dashboard, /codex-preview|react-loading-skeleton/i);
});

test("datamodellen beskytter aktive tider mod dobbeltbooking", async () => {
  const [schema, migration] = await Promise.all([
    readFile(new URL("../db/schema.ts", import.meta.url), "utf8"),
    readFile(new URL("../drizzle/0001_motionless_sandman.sql", import.meta.url), "utf8"),
  ]);
  assert.match(schema, /uidx_bookings_station_starts_active/);
  assert.match(migration, /CREATE UNIQUE INDEX [`"]uidx_bookings_station_starts_active[`"]/i);
  assert.match(migration, /NOT IN \('cancelled', 'no_show'\)/i);
});

test("danske bookingtider håndterer både sommer- og vintertid", async () => {
  const { toDateAndTime, toTimestamp } = await import("../lib/bookings.ts");
  assert.deepEqual(toDateAndTime(toTimestamp("2026-08-04", "11:20")), { date: "2026-08-04", time: "11:20" });
  assert.deepEqual(toDateAndTime(toTimestamp("2026-12-04", "11:20")), { date: "2026-12-04", time: "11:20" });
});

test("integrationsadaptere er deaktiverede som standard", async () => {
  const { dineroAdapter } = await import("../lib/integrations/adapters/dinero.ts");
  const { synsprogramAdapter } = await import("../lib/integrations/adapters/synsprogram.ts");
  assert.equal(dineroAdapter.enabled, false);
  assert.equal(synsprogramAdapter.enabled, false);
  await assert.rejects(() => dineroAdapter.execute({ idempotencyKey: "invoice:demo-1", correlationId: "test-1", payload: { invoiceId: "demo-1", bookingId: "booking-1", amountOere: 59500, currency: "DKK" } }), /ikke aktiveret/i);
});
