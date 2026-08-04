import { getD1 } from "./index";
import { toTimestamp } from "../lib/bookings";

const stationId = "station-herning";

const schemaStatements = [
  `CREATE TABLE IF NOT EXISTS stations (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    timezone TEXT DEFAULT 'Europe/Copenhagen' NOT NULL,
    active INTEGER DEFAULT 1 NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS customers (
    id TEXT PRIMARY KEY NOT NULL,
    display_name TEXT NOT NULL,
    customer_type TEXT NOT NULL,
    phone_encrypted TEXT,
    email_encrypted TEXT,
    external_reference TEXT,
    deleted_at INTEGER,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS vehicles (
    id TEXT PRIMARY KEY NOT NULL,
    customer_id TEXT REFERENCES customers(id),
    registration_normalized TEXT NOT NULL,
    make TEXT,
    model TEXT,
    vehicle_kind TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE UNIQUE INDEX IF NOT EXISTS uidx_vehicles_registration ON vehicles(registration_normalized)`,
  `CREATE TABLE IF NOT EXISTS availability_rules (
    id TEXT PRIMARY KEY NOT NULL,
    station_id TEXT NOT NULL REFERENCES stations(id),
    kind TEXT NOT NULL,
    weekday INTEGER,
    starts_at TEXT,
    ends_at TEXT,
    date_from TEXT,
    date_to TEXT,
    label TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE INDEX IF NOT EXISTS idx_availability_station_kind ON availability_rules(station_id, kind)`,
  `CREATE TABLE IF NOT EXISTS bookings (
    id TEXT PRIMARY KEY NOT NULL,
    station_id TEXT NOT NULL REFERENCES stations(id),
    customer_id TEXT REFERENCES customers(id),
    vehicle_id TEXT NOT NULL REFERENCES vehicles(id),
    assigned_employee_id TEXT,
    starts_at INTEGER NOT NULL,
    ends_at INTEGER NOT NULL,
    inspection_type TEXT NOT NULL,
    status TEXT NOT NULL,
    source TEXT DEFAULT 'manual' NOT NULL,
    source_reference TEXT,
    internal_note TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE INDEX IF NOT EXISTS idx_bookings_station_starts_at ON bookings(station_id, starts_at)`,
  `CREATE INDEX IF NOT EXISTS idx_bookings_vehicle_starts_at ON bookings(vehicle_id, starts_at)`,
  `CREATE UNIQUE INDEX IF NOT EXISTS uidx_bookings_station_starts_active
    ON bookings(station_id, starts_at) WHERE status NOT IN ('cancelled', 'no_show')`,
  `CREATE TABLE IF NOT EXISTS audit_events (
    id TEXT PRIMARY KEY NOT NULL,
    occurred_at INTEGER NOT NULL,
    actor_type TEXT NOT NULL,
    actor_id TEXT,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    before_json TEXT,
    after_json TEXT,
    metadata_json TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_events(entity_type, entity_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_correlation ON audit_events(correlation_id)`,
  `CREATE TABLE IF NOT EXISTS sms_messages (
    id TEXT PRIMARY KEY NOT NULL,
    booking_id TEXT NOT NULL REFERENCES bookings(id),
    kind TEXT NOT NULL,
    recipient_encrypted TEXT NOT NULL,
    message_text TEXT NOT NULL,
    sender TEXT NOT NULL,
    status TEXT NOT NULL,
    scheduled_at INTEGER NOT NULL,
    attempts INTEGER DEFAULT 0 NOT NULL,
    provider_reference TEXT,
    last_error TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE UNIQUE INDEX IF NOT EXISTS uidx_sms_booking_kind ON sms_messages(booking_id, kind)`,
  `CREATE INDEX IF NOT EXISTS idx_sms_status_scheduled ON sms_messages(status, scheduled_at)`,
  `CREATE TABLE IF NOT EXISTS invoice_drafts (
    id TEXT PRIMARY KEY NOT NULL,
    customer_name TEXT NOT NULL,
    period TEXT NOT NULL,
    description TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price_ore INTEGER NOT NULL,
    status TEXT NOT NULL,
    source_reference TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE UNIQUE INDEX IF NOT EXISTS uidx_invoice_drafts_source ON invoice_drafts(source_reference)`,
  `CREATE TABLE IF NOT EXISTS employees (
    id TEXT PRIMARY KEY NOT NULL,
    station_id TEXT NOT NULL,
    display_name TEXT NOT NULL,
    email_normalized TEXT NOT NULL,
    role TEXT NOT NULL,
    active INTEGER DEFAULT 1 NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS employee_absences (
    id TEXT PRIMARY KEY NOT NULL,
    employee_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    note TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )`,
  `CREATE INDEX IF NOT EXISTS idx_employee_absences_dates ON employee_absences(date_from, date_to)`,
];

const demoBookings = [
  ["08:00", "Jysk VVS ApS", "business", "CF45821", "Ford", "Transit", "Periodisk syn", "completed"],
  ["08:20", "Maja Holm", "private", "AB12345", "VW", "Golf", "Periodisk syn", "completed"],
  ["08:40", "Thomas Dahl", "private", "DL76119", "Tesla", "Model 3", "Omsyn", "arrived"],
  ["09:00", "Hedens Montage", "business", "ND70416", "Renault", "Master", "Varebilssyn", "confirmed"],
  ["09:20", "Anne Skov", "private", "EH22604", "Peugeot", "208", "Periodisk syn", "confirmed"],
  ["09:40", "Murerfirma Lund", "business", "FA91037", "Mercedes", "Sprinter", "Varebilssyn", "confirmed"],
  ["10:00", "Søren Bech", "private", "GB18530", "Skoda", "Enyaq", "Periodisk syn", "awaiting_confirmation"],
  ["10:20", "Lone Madsen", "private", "HR63044", "Toyota", "Yaris", "Omsyn", "confirmed"],
  ["10:40", "Fjord Transport", "business", "JK37995", "Iveco", "Daily", "Varebilssyn", "confirmed"],
  ["11:00", "Emil Nygaard", "private", "KT40188", "Volvo", "XC40", "Periodisk syn", "confirmed"],
  ["11:40", "Line Friis", "private", "LP88271", "Kia", "Niro", "Periodisk syn", "confirmed"],
  ["12:00", "Niels Bak", "private", "MR51620", "Audi", "A4", "Omsyn", "confirmed"],
  ["13:00", "Mette Bruun", "private", "PV20147", "Nissan", "Qashqai", "Periodisk syn", "confirmed"],
  ["13:20", "Vest Auto ApS", "business", "RA44902", "Citroën", "Jumper", "Varebilssyn", "confirmed"],
  ["13:40", "Jonas Hald", "private", "SC77318", "Hyundai", "Ioniq 5", "Periodisk syn", "confirmed"],
  ["14:00", "Louise Krag", "private", "TE60941", "BMW", "i3", "Omsyn", "confirmed"],
  ["14:40", "Midtby El ApS", "business", "UK91366", "Ford", "E-Transit", "Varebilssyn", "confirmed"],
  ["15:00", "Kasper Vester", "private", "VL35720", "Mazda", "CX-5", "Periodisk syn", "confirmed"],
  ["15:20", "Birgit Lund", "private", "XM18439", "Honda", "Jazz", "Periodisk syn", "confirmed"],
  ["15:40", "Højgaard Service", "business", "YN72504", "VW", "Crafter", "Varebilssyn", "confirmed"],
  ["16:00", "Freja Møller", "private", "ZT48215", "Cupra", "Born", "Periodisk syn", "confirmed"],
] as const;

export async function ensureBookingDatabase() {
  const d1 = getD1();
  for (const statement of schemaStatements) {
    await d1.prepare(statement).run();
  }

  const existing = await d1.prepare("SELECT COUNT(*) AS count FROM bookings").first<{ count: number }>();
  if ((existing?.count ?? 0) > 0) return;

  const now = Date.now();
  await d1.prepare("INSERT OR IGNORE INTO stations (id, name, timezone, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)")
    .bind(stationId, "Herning", "Europe/Copenhagen", now, now).run();

  const ruleStatements = [1, 2, 3, 4, 5].flatMap((weekday) => [
    d1.prepare("INSERT OR IGNORE INTO availability_rules (id, station_id, kind, weekday, starts_at, ends_at, label, created_at, updated_at) VALUES (?, ?, 'opening_hours', ?, '08:00', '16:20', 'Normal åbningstid', ?, ?)")
      .bind(`opening-${weekday}`, stationId, weekday, now, now),
    d1.prepare("INSERT OR IGNORE INTO availability_rules (id, station_id, kind, weekday, starts_at, ends_at, label, created_at, updated_at) VALUES (?, ?, 'break', ?, '12:20', '13:00', 'Frokostpause', ?, ?)")
      .bind(`break-${weekday}`, stationId, weekday, now, now),
  ]);
  await d1.batch(ruleStatements);

  const seedStatements = demoBookings.flatMap((booking, index) => {
    const [time, customer, customerType, plate, make, model, inspection, status] = booking;
    const customerId = `demo-customer-${index + 1}`;
    const vehicleId = `demo-vehicle-${index + 1}`;
    const bookingId = `demo-booking-${index + 1}`;
    const startsAt = toTimestamp("2026-08-04", time);
    return [
      d1.prepare("INSERT OR IGNORE INTO customers (id, display_name, customer_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
        .bind(customerId, customer, customerType, now, now),
      d1.prepare("INSERT OR IGNORE INTO vehicles (id, customer_id, registration_normalized, make, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        .bind(vehicleId, customerId, plate, make, model, now, now),
      d1.prepare("INSERT OR IGNORE INTO bookings (id, station_id, customer_id, vehicle_id, starts_at, ends_at, inspection_type, status, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)")
        .bind(bookingId, stationId, customerId, vehicleId, startsAt, startsAt + 20 * 60_000, inspection, status, now, now),
    ];
  });
  await d1.batch(seedStatements);
  await d1.prepare("PRAGMA optimize").run();
}

export const bookingStationId = stationId;
