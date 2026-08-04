import { sql } from "drizzle-orm";
import { index, integer, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

const timestamps = {
  createdAt: integer("created_at", { mode: "timestamp_ms" }).notNull(),
  updatedAt: integer("updated_at", { mode: "timestamp_ms" }).notNull(),
};

export const stations = sqliteTable("stations", {
  id: text("id").primaryKey(),
  name: text("name").notNull(),
  timezone: text("timezone").notNull().default("Europe/Copenhagen"),
  active: integer("active", { mode: "boolean" }).notNull().default(true),
  ...timestamps,
});

export const employees = sqliteTable("employees", {
  id: text("id").primaryKey(),
  stationId: text("station_id").notNull().references(() => stations.id),
  displayName: text("display_name").notNull(),
  emailNormalized: text("email_normalized").notNull(),
  role: text("role", { enum: ["administrator", "synsinspektoer", "bogholder", "laeseadgang"] }).notNull(),
  active: integer("active", { mode: "boolean" }).notNull().default(true),
  ...timestamps,
}, (table) => [
  uniqueIndex("uidx_employees_email").on(table.emailNormalized),
  index("idx_employees_station_active").on(table.stationId, table.active),
]);

export const customers = sqliteTable("customers", {
  id: text("id").primaryKey(),
  displayName: text("display_name").notNull(),
  customerType: text("customer_type", { enum: ["private", "business"] }).notNull(),
  phoneEncrypted: text("phone_encrypted"),
  emailEncrypted: text("email_encrypted"),
  externalReference: text("external_reference"),
  deletedAt: integer("deleted_at", { mode: "timestamp_ms" }),
  ...timestamps,
});

export const vehicles = sqliteTable("vehicles", {
  id: text("id").primaryKey(),
  customerId: text("customer_id").references(() => customers.id),
  registrationNormalized: text("registration_normalized").notNull(),
  make: text("make"),
  model: text("model"),
  vehicleKind: text("vehicle_kind"),
  ...timestamps,
}, (table) => [uniqueIndex("uidx_vehicles_registration").on(table.registrationNormalized)]);

export const availabilityRules = sqliteTable("availability_rules", {
  id: text("id").primaryKey(),
  stationId: text("station_id").notNull().references(() => stations.id),
  kind: text("kind", { enum: ["opening_hours", "break", "closed_day", "holiday", "vacation"] }).notNull(),
  weekday: integer("weekday"),
  startsAt: text("starts_at"),
  endsAt: text("ends_at"),
  dateFrom: text("date_from"),
  dateTo: text("date_to"),
  label: text("label").notNull(),
  ...timestamps,
}, (table) => [index("idx_availability_station_kind").on(table.stationId, table.kind)]);

export const bookings = sqliteTable("bookings", {
  id: text("id").primaryKey(),
  stationId: text("station_id").notNull().references(() => stations.id),
  customerId: text("customer_id").references(() => customers.id),
  vehicleId: text("vehicle_id").notNull().references(() => vehicles.id),
  assignedEmployeeId: text("assigned_employee_id").references(() => employees.id),
  startsAt: integer("starts_at", { mode: "timestamp_ms" }).notNull(),
  endsAt: integer("ends_at", { mode: "timestamp_ms" }).notNull(),
  inspectionType: text("inspection_type").notNull(),
  status: text("status", { enum: ["draft", "awaiting_confirmation", "confirmed", "arrived", "completed", "cancelled", "no_show"] }).notNull(),
  source: text("source", { enum: ["manual", "import", "synsprogram", "arvo"] }).notNull().default("manual"),
  sourceReference: text("source_reference"),
  internalNote: text("internal_note"),
  ...timestamps,
}, (table) => [
  index("idx_bookings_station_starts_at").on(table.stationId, table.startsAt),
  index("idx_bookings_vehicle_starts_at").on(table.vehicleId, table.startsAt),
  uniqueIndex("uidx_bookings_source_reference").on(table.source, table.sourceReference),
  uniqueIndex("uidx_bookings_station_starts_active")
    .on(table.stationId, table.startsAt)
    .where(sql`${table.status} NOT IN ('cancelled', 'no_show')`),
]);

export const invoices = sqliteTable("invoices", {
  id: text("id").primaryKey(),
  bookingId: text("booking_id").notNull().references(() => bookings.id),
  status: text("status", { enum: ["ready", "queued", "sent", "paid", "failed", "void"] }).notNull(),
  amountOere: integer("amount_oere").notNull(),
  currency: text("currency").notNull().default("DKK"),
  dineroInvoiceId: text("dinero_invoice_id"),
  idempotencyKey: text("idempotency_key").notNull(),
  invoicedAt: integer("invoiced_at", { mode: "timestamp_ms" }),
  ...timestamps,
}, (table) => [
  uniqueIndex("uidx_invoices_booking").on(table.bookingId),
  uniqueIndex("uidx_invoices_idempotency").on(table.idempotencyKey),
  uniqueIndex("uidx_invoices_dinero_id").on(table.dineroInvoiceId),
]);

export const integrationJobs = sqliteTable("integration_jobs", {
  id: text("id").primaryKey(),
  adapter: text("adapter", { enum: ["synsprogram", "dinero", "motorstyrelsen", "arvo"] }).notNull(),
  operation: text("operation").notNull(),
  aggregateType: text("aggregate_type").notNull(),
  aggregateId: text("aggregate_id").notNull(),
  idempotencyKey: text("idempotency_key").notNull(),
  payloadJson: text("payload_json").notNull(),
  status: text("status", { enum: ["pending", "processing", "retry", "completed", "dead_letter"] }).notNull(),
  attempts: integer("attempts").notNull().default(0),
  nextAttemptAt: integer("next_attempt_at", { mode: "timestamp_ms" }),
  lastErrorCode: text("last_error_code"),
  lastErrorSummary: text("last_error_summary"),
  ...timestamps,
}, (table) => [
  uniqueIndex("uidx_integration_jobs_idempotency").on(table.idempotencyKey),
  index("idx_integration_jobs_status_next_attempt").on(table.status, table.nextAttemptAt),
]);

export const auditEvents = sqliteTable("audit_events", {
  id: text("id").primaryKey(),
  occurredAt: integer("occurred_at", { mode: "timestamp_ms" }).notNull(),
  actorType: text("actor_type", { enum: ["employee", "system", "integration"] }).notNull(),
  actorId: text("actor_id"),
  action: text("action").notNull(),
  entityType: text("entity_type").notNull(),
  entityId: text("entity_id").notNull(),
  correlationId: text("correlation_id").notNull(),
  beforeJson: text("before_json"),
  afterJson: text("after_json"),
  metadataJson: text("metadata_json"),
}, (table) => [
  index("idx_audit_entity").on(table.entityType, table.entityId, table.occurredAt),
  index("idx_audit_correlation").on(table.correlationId),
]);
