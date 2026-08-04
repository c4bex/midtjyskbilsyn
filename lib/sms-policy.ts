import type { CustomerType } from "./sms-types.ts";

export type SmsPlan = {
  confirmation: "immediate" | "not_applicable" | "missing_phone";
  reminder: "scheduled" | "same_day_skipped" | "not_applicable" | "missing_phone";
};

/**
 * SMS-regler for bookingflowet. GatewayAPI-adapteren udfører ikke planen endnu.
 * Datoer sammenlignes i dansk lokal tid, så en booking samme kalenderdag aldrig får reminder.
 */
export const planBookingSms = ({ customerType, phone, startsAt, now = Date.now() }: { customerType: CustomerType; phone?: string | null; startsAt: number; now?: number }): SmsPlan => {
  if (customerType !== "private") return { confirmation: "not_applicable", reminder: "not_applicable" };
  if (!phone?.trim()) return { confirmation: "missing_phone", reminder: "missing_phone" };
  const formatDate = (value: number) => new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Copenhagen", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date(value));
  const sameDay = formatDate(startsAt) === formatDate(now);
  return { confirmation: "immediate", reminder: sameDay ? "same_day_skipped" : "scheduled" };
};

