import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

export type DmrVehicleLookup = { registration: string };

export const dmrAdapter: IntegrationAdapter<DmrVehicleLookup> = {
  name: "motorstyrelsen",
  enabled: false,
  validate(command) {
    if (!command.idempotencyKey || !/^[A-ZÆØÅ0-9]{2,8}$/.test(command.payload.registration)) throw new Error("Ugyldigt registreringsnummer");
  },
  async execute(command: IntegrationCommand<DmrVehicleLookup>): Promise<IntegrationResult> {
    this.validate(command);
    throw new IntegrationDisabledError("motorstyrelsen");
  },
};
