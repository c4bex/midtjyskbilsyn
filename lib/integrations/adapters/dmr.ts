import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

export type DmrVehicleLookup = { registration: string };
export type DmrVehicle = { registration: string; make: string | null; model: string | null; inspectionDueDate: string | null; lastInspectionDate: string | null };

export const normalizeDmrRegistration = (value: string) => value.toUpperCase().replace(/[^A-ZÆØÅ0-9]/g, "");

export const dmrAdapter: IntegrationAdapter<DmrVehicleLookup> = {
  name: "motorstyrelsen",
  enabled: false,
  validate(command) {
    if (!command.idempotencyKey || !/^[A-ZÆØÅ0-9]{2,8}$/.test(normalizeDmrRegistration(command.payload.registration))) throw new Error("Ugyldigt registreringsnummer");
  },
  async execute(command: IntegrationCommand<DmrVehicleLookup>): Promise<IntegrationResult> {
    this.validate(command);
    throw new IntegrationDisabledError("motorstyrelsen");
  },
};
