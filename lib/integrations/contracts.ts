export type AdapterName = "synsprogram" | "dinero" | "motorstyrelsen" | "arvo";

export type IntegrationCommand<TPayload> = {
  idempotencyKey: string;
  correlationId: string;
  payload: TPayload;
};

export type IntegrationResult =
  | { ok: true; externalReference: string; auditSummary: string }
  | { ok: false; retryable: boolean; errorCode: string; safeMessage: string };

export interface IntegrationAdapter<TPayload> {
  readonly name: AdapterName;
  readonly enabled: boolean;
  validate(command: IntegrationCommand<TPayload>): void;
  execute(command: IntegrationCommand<TPayload>): Promise<IntegrationResult>;
}

export class IntegrationDisabledError extends Error {
  constructor(adapter: AdapterName) {
    super(`${adapter}-adapteren er ikke aktiveret. Dokumentation, testdata og en godkendt aktiveringsplan kræves først.`);
    this.name = "IntegrationDisabledError";
  }
}
