import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

type InspectionPayload = { bookingId: string; registration: string; inspectionType: string };

export const synsprogramAdapter: IntegrationAdapter<InspectionPayload> = {
  name: "synsprogram",
  enabled: false,
  validate(command) {
    if (!command.idempotencyKey || !command.payload.registration) throw new Error("Ugyldig synskommando");
  },
  async execute(command: IntegrationCommand<InspectionPayload>): Promise<IntegrationResult> {
    this.validate(command);
    throw new IntegrationDisabledError("synsprogram");
  },
};
