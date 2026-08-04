import { ensureBookingDatabase, bookingStationId } from "../../../../db/bootstrap";
import { getD1 } from "../../../../db";
import { authorizeBookingRequest, unauthorizedResponse } from "../../../../lib/authorization";
import { toDateAndTime, toTimestamp } from "../../../../lib/bookings";

type Rule = { kind: string; weekday: number | null; starts_at: string | null; ends_at: string | null; date_from: string | null; date_to: string | null };
type BookingRow = { starts_at: number };

const addDays = (date: string, days: number) => {
  const value = new Date(`${date}T12:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
};
const toMinutes = (time: string) => { const [hour, minute] = time.split(":").map(Number); return hour * 60 + minute; };
const toTime = (minutes: number) => `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
const isoWeek = (date: string) => {
  const value = new Date(`${date}T12:00:00Z`);
  const day = value.getUTCDay() || 7;
  value.setUTCDate(value.getUTCDate() + 4 - day);
  const yearStart = new Date(Date.UTC(value.getUTCFullYear(), 0, 1));
  return Math.ceil((((value.getTime() - yearStart.getTime()) / 86_400_000) + 1) / 7);
};

export async function GET(request: Request) {
  if (!await authorizeBookingRequest(request)) return unauthorizedResponse();
  await ensureBookingDatabase();
  const start = new URL(request.url).searchParams.get("start") ?? "2026-08-03";
  if (!/^\d{4}-\d{2}-\d{2}$/.test(start)) return Response.json({ error: "Ugyldig startdato" }, { status: 400 });
  const end = addDays(start, 7);
  const d1 = getD1();
  const [ruleResult, bookingResult] = await Promise.all([
    d1.prepare("SELECT kind, weekday, starts_at, ends_at, date_from, date_to FROM availability_rules WHERE station_id = ?").bind(bookingStationId).all<Rule>(),
    d1.prepare("SELECT starts_at FROM bookings WHERE station_id = ? AND starts_at >= ? AND starts_at < ? AND status NOT IN ('cancelled', 'no_show')")
      .bind(bookingStationId, toTimestamp(start, "00:00"), toTimestamp(end, "00:00")).all<BookingRow>(),
  ]);
  const occupied = new Map<string, Set<string>>();
  for (const booking of bookingResult.results) {
    const value = toDateAndTime(booking.starts_at);
    const times = occupied.get(value.date) ?? new Set<string>();
    times.add(value.time);
    occupied.set(value.date, times);
  }

  const days = Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index);
    const weekday = index + 1;
    const rules = ruleResult.results.filter((rule) => rule.weekday === weekday || (rule.date_from && rule.date_to && rule.date_from <= date && rule.date_to >= date));
    const forcedClosed = rules.some((rule) => rule.kind === "closed_day" || rule.kind === "holiday" || rule.kind === "vacation");
    const opening = rules.find((rule) => rule.kind === "opening_hours" && rule.starts_at && rule.ends_at);
    if (!opening || forcedClosed) return { date, weekday, closed: true, totalSlots: 0, bookedSlots: 0, availableSlots: [] as string[] };
    const breaks = rules.filter((rule) => rule.kind === "break" && rule.starts_at && rule.ends_at);
    const allSlots: string[] = [];
    for (let minute = toMinutes(opening.starts_at!); minute < toMinutes(opening.ends_at!); minute += 20) {
      if (!breaks.some((rule) => minute >= toMinutes(rule.starts_at!) && minute < toMinutes(rule.ends_at!))) allSlots.push(toTime(minute));
    }
    const dayOccupied = occupied.get(date) ?? new Set<string>();
    const availableSlots = allSlots.filter((time) => !dayOccupied.has(time));
    return { date, weekday, closed: false, totalSlots: allSlots.length, bookedSlots: allSlots.length - availableSlots.length, availableSlots };
  });
  return Response.json({ week: isoWeek(start), start, end: addDays(start, 6), days });
}
