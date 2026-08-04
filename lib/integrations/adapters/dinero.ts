import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

type InvoicePayload = { invoiceId: string; bookingId: string; amountOere: number; currency: "DKK" };

export const dineroAdapter: IntegrationAdapter<InvoicePayload> = {
  name: "dinero",
  enabled: false,
  validate(command) {
    if (!command.idempotencyKey || command.payload.amountOere <= 0) throw new Error("Ugyldig fakturakommando");
  },
  async execute(command: IntegrationCommand<InvoicePayload>): Promise<IntegrationResult> {
    this.validate(command);
    throw new IntegrationDisabledError("dinero");
  },
};
