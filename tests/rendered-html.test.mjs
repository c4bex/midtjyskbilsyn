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
  assert.match(dashboard, />Ikast</);
  assert.doesNotMatch(dashboard, />Afdeling</);
  assert.match(dashboard, /Escape/);
  assert.match(dashboard, /Ledige tider denne uge/);
  assert.match(dashboard, /UGE/);
  assert.match(dashboard, /selectDay/);
  assert.doesNotMatch(dashboard, /Dagens fremdrift/);
  assert.doesNotMatch(dashboard, /Se alle ledige tider/);
  assert.match(dashboard, /Filtrer efter kundetype/);
  assert.match(dashboard, /Private/);
  assert.match(dashboard, /Erhverv/);
  assert.match(dashboard, /Aflys booking/);
  assert.match(dashboard, /Send besked til kunden/);
  assert.match(dashboard, /GatewayAPI klargjort/);
  assert.match(dashboard, /Telefonnummer/);
  assert.match(dashboard, /\/api\/bookings/);
  assert.match(dashboard, /CustomersView/);
  assert.match(dashboard, /AvailabilityView/);
  assert.doesNotMatch(dashboard, /codex-preview|react-loading-skeleton/i);
});

test("ugeoversigten beregner kapacitet fra åbningstider og bookinger", async () => {
  const route = await readFile(new URL("../app/api/calendar/week/route.ts", import.meta.url), "utf8");
  assert.match(route, /availability_rules/);
  assert.match(route, /status NOT IN \('cancelled', 'no_show'\)/);
  assert.match(route, /availableSlots/);
  assert.match(route, /isoWeek/);
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

test("SMS-politikken bekræfter private straks og springer samme dags reminder over", async () => {
  const { planBookingSms } = await import("../lib/sms-policy.ts");
  const now = Date.parse("2026-08-04T08:00:00+02:00");
  assert.deepEqual(planBookingSms({ customerType: "private", phone: "+4520123456", startsAt: Date.parse("2026-08-04T14:20:00+02:00"), now }), { confirmation: "immediate", reminder: "same_day_skipped" });
  assert.deepEqual(planBookingSms({ customerType: "private", phone: "+4520123456", startsAt: Date.parse("2026-08-05T14:20:00+02:00"), now }), { confirmation: "immediate", reminder: "scheduled" });
  assert.deepEqual(planBookingSms({ customerType: "business", phone: "+4520123456", startsAt: Date.parse("2026-08-05T14:20:00+02:00"), now }), { confirmation: "not_applicable", reminder: "not_applicable" });
});

test("SMS-køen har idempotens pr. booking og krypteret modtagerfelt", async () => {
  const schema = await readFile(new URL("../db/schema.ts", import.meta.url), "utf8");
  const bootstrap = await readFile(new URL("../db/bootstrap.ts", import.meta.url), "utf8");
  assert.match(schema, /smsMessages/);
  assert.match(schema, /recipientEncrypted/);
  assert.match(schema, /uidx_sms_booking_kind/);
  assert.match(bootstrap, /CREATE TABLE IF NOT EXISTS sms_messages/);
  assert.match(bootstrap, /recipient_encrypted TEXT NOT NULL/);
});

test("importvalidering finder dubletter uden at skrive data", async () => {
  const { validateImport } = await import("../lib/import-validation.ts");
  const result = validateImport([
    { sourceReference: "syn:1", customer: "Test ApS", registration: "AB12345", date: "2026-08-04", amountOere: 38000 },
    { sourceReference: "syn:1", customer: "Test ApS", registration: "AB12345", date: "2026-08-04", amountOere: 38000 },
  ]);
  assert.equal(result.valid, 1);
  assert.equal(result.writes, 0);
  assert.equal(result.issues[0].code, "duplicate_source");
});

test("medarbejderdata har separat fraværstabel og API", async () => {
  const [bootstrap, route] = await Promise.all([
    readFile(new URL("../db/bootstrap.ts", import.meta.url), "utf8"),
    readFile(new URL("../app/api/employees/route.ts", import.meta.url), "utf8"),
  ]);
  assert.match(bootstrap, /CREATE TABLE IF NOT EXISTS employee_absences/);
  assert.match(bootstrap, /CREATE TABLE IF NOT EXISTS employee_work_rules/);
  assert.match(route, /employee_absences/);
  assert.match(route, /dateFrom/);
});

test("integrationsadaptere er deaktiverede som standard", async () => {
  const { dineroAdapter } = await import("../lib/integrations/adapters/dinero.ts");
  const { synsprogramAdapter } = await import("../lib/integrations/adapters/synsprogram.ts");
  const { dmrAdapter } = await import("../lib/integrations/adapters/dmr.ts");
  const { gatewayApiAdapter } = await import("../lib/integrations/adapters/gatewayapi.ts");
  assert.equal(dineroAdapter.enabled, false);
  assert.equal(synsprogramAdapter.enabled, false);
  assert.equal(dmrAdapter.enabled, false);
  assert.equal(gatewayApiAdapter.enabled, false);
  await assert.rejects(() => dineroAdapter.execute({ idempotencyKey: "invoice:demo-1", correlationId: "test-1", payload: { invoiceId: "demo-1", bookingId: "booking-1", amountOere: 59500, currency: "DKK" } }), /ikke aktiveret/i);
  await assert.rejects(() => gatewayApiAdapter.execute({ idempotencyKey: "sms:demo-1", correlationId: "test-1", payload: { recipient: "+4520123456", message: "Test", sender: "MB Bilsyn" } }), /ikke aktiveret/i);
});

test("DMR-opslag normaliserer nummerplader og bruges som fallback efter MySQL", async () => {
  const { normalizeDmrRegistration } = await import("../lib/integrations/adapters/dmr.ts");
  const route = await readFile(new URL("../app/api/vehicles/lookup/route.ts", import.meta.url), "utf8");
  assert.equal(normalizeDmrRegistration("en 48-111"), "EN48111");
  assert.equal(normalizeDmrRegistration("dv 50 040"), "DV50040");
  assert.match(route, /async function lookupOnNas/);
  assert.match(route, /if \(data\.found\)/);
  assert.match(route, /const dmrResponse = await lookupOnNas\(registration\)/);
  assert.match(route, /lastInspectionDate.*inspectionDate/);
});
