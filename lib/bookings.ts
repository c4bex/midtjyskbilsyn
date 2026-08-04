export type BookingStatus = "awaiting_confirmation" | "confirmed" | "arrived" | "completed" | "cancelled";

export type BookingRecord = {
  id: string;
  date: string;
  time: string;
  customer: string;
  customerType: "private" | "business";
  plate: string;
  vehicle: string;
  inspection: string;
  status: BookingStatus;
};

export type BookingInput = Omit<BookingRecord, "id" | "status"> & { status?: BookingStatus };

export const normalizePlate = (value: string) => value.toUpperCase().replace(/[^A-ZÆØÅ0-9]/g, "");

export const formatPlate = (value: string) => {
  const normalized = normalizePlate(value);
  return normalized.length === 7 ? `${normalized.slice(0, 2)} ${normalized.slice(2, 4)} ${normalized.slice(4)}` : normalized;
};

export const toTimestamp = (date: string, time: string) => {
  const [year, month, day] = date.split("-").map(Number);
  const [hour, minute] = time.split(":").map(Number);
  const desiredLocalParts = Date.UTC(year, month - 1, day, hour, minute);
  let timestamp = desiredLocalParts;
  const formatter = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Copenhagen", year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", hourCycle: "h23",
  });
  for (let pass = 0; pass < 2; pass += 1) {
    const parts = Object.fromEntries(formatter.formatToParts(new Date(timestamp)).map((part) => [part.type, part.value]));
    const renderedLocalParts = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute));
    timestamp += desiredLocalParts - renderedLocalParts;
  }
  return timestamp;
};

export const toDateAndTime = (timestamp: number) => {
  const formatter = new Intl.DateTimeFormat("da-DK", {
    timeZone: "Europe/Copenhagen",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
  const parts = Object.fromEntries(formatter.formatToParts(new Date(timestamp)).map((part) => [part.type, part.value]));
  return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
};
