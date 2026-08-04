import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

export type DmrVehicleLookup = { registration: string };
export type DmrVehicle = { registration: string; make: string | null; model: string | null; inspectionDueDate: string | null; lastInspectionDate: string | null };

export const normalizeDmrRegistration = (value: string) => value.toUpperCase().replace(/[^A-ZÆØÅ0-9]/g, "");
export async function lookupDmrVehicle(registration: string): Promise<{ found: boolean; dataVersion?: string; vehicle?: DmrVehicle }> {
  if (!dmrAdapter.enabled) throw new IntegrationDisabledError("motorstyrelsen");
  const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), Number(process.env.DMR_LOOKUP_TIMEOUT_MS ?? "5000"));
  try { const response = await fetch(`${process.env.DMR_LOOKUP_BASE_URL!.replace(/\/$/, "")}/api/dmr/vehicles?registration=${encodeURIComponent(normalizeDmrRegistration(registration))}`, { headers: { authorization: `Bearer ${process.env.DMR_LOOKUP_TOKEN!}`, accept: "application/json" }, signal: controller.signal }); if (!response.ok) throw new Error("DMR unavailable"); return await response.json() as { found: boolean; dataVersion?: string; vehicle?: DmrVehicle }; } finally { clearTimeout(timer); }
}

export const dmrAdapter: IntegrationAdapter<DmrVehicleLookup> = {
  name: "motorstyrelsen",
  enabled: Boolean(process.env.DMR_LOOKUP_BASE_URL && process.env.DMR_LOOKUP_TOKEN),
  validate(command) {
    if (!command.idempotencyKey || !/^[A-ZÆØÅ0-9]{2,8}$/.test(normalizeDmrRegistration(command.payload.registration))) throw new Error("Ugyldigt registreringsnummer");
  },
  async execute(command: IntegrationCommand<DmrVehicleLookup>): Promise<IntegrationResult> {
    this.validate(command);
    if (!this.enabled) throw new IntegrationDisabledError("motorstyrelsen");
    const baseUrl = process.env.DMR_LOOKUP_BASE_URL!;
    const token = process.env.DMR_LOOKUP_TOKEN!;
    const timeout = Number(process.env.DMR_LOOKUP_TIMEOUT_MS ?? "5000");
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), Number.isFinite(timeout) ? timeout : 5000);
    try {
      const response = await fetch(`${baseUrl.replace(/\/$/, "")}/api/dmr/vehicles?registration=${encodeURIComponent(normalizeDmrRegistration(command.payload.registration))}`, { headers: { authorization: `Bearer ${token}`, accept: "application/json" }, signal: controller.signal });
      if (!response.ok) return { ok: false, retryable: response.status >= 500, errorCode: `dmr_http_${response.status}`, safeMessage: "DMR midlertidigt utilgængelig" };
      const data = await response.json() as { found: boolean; dataVersion?: string; vehicle?: DmrVehicle };
      return { ok: true, externalReference: `dmr:${normalizeDmrRegistration(command.payload.registration)}:${data.dataVersion ?? "unknown"}`, auditSummary: data.found ? "DMR-køretøj fundet" : "DMR-nummerplade ikke fundet" };
    } catch {
      return { ok: false, retryable: true, errorCode: "dmr_timeout_or_network", safeMessage: "DMR midlertidigt utilgængelig" };
    } finally { clearTimeout(timer); }
  },
};
