import { IntegrationDisabledError, type IntegrationAdapter, type IntegrationCommand, type IntegrationResult } from "../contracts.ts";

export type SmsPayload = {
  recipient: string;
  message: string;
  sender: string;
  bookingId?: string;
  templateKey?: "booking_confirmation" | "booking_reminder" | "booking_changed" | "booking_cancelled";
};

const isDanishOrInternationalNumber = (value: string) => /^\+?[1-9]\d{7,14}$/.test(value.replace(/[\s-]/g, ""));

/** GatewayAPI er bevidst slukket, indtil konto, afsender og testdata er godkendt. */
export const gatewayApiAdapter: IntegrationAdapter<SmsPayload> = {
  name: "gatewayapi",
  enabled: false,
  validate(command) {
    if (!command.idempotencyKey || !command.correlationId) throw new Error("SMS-kommando mangler sporingsnøgle");
    if (!isDanishOrInternationalNumber(command.payload.recipient)) throw new Error("Ugyldigt telefonnummer til SMS");
    if (!command.payload.message.trim() || command.payload.message.length > 1600) throw new Error("SMS-teksten skal være mellem 1 og 1600 tegn");
    if (!command.payload.sender.trim() || command.payload.sender.length > 11) throw new Error("SMS-afsenderen skal være mellem 1 og 11 tegn");
  },
  async execute(command: IntegrationCommand<SmsPayload>): Promise<IntegrationResult> {
    this.validate(command);
    throw new IntegrationDisabledError("gatewayapi");
  },
};
