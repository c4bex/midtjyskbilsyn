"use client";

import { CalendarClock, Check, ChevronLeft, Clock3, MapPin, ShieldCheck, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";

type ManagedBooking = { id: string; date: string; time: string; inspection: string; bookingTypeId: string | null; plate: string; status: string; canChange: boolean };
type Day = { date: string; availableSlots: string[]; availableCount: number };
const dayLabel = (date: string) => new Intl.DateTimeFormat("da-DK", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${date}T12:00:00`));
const todayInDenmark = () => new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Copenhagen" }).format(new Date());
const addDays = (date: string, amount: number) => { const [year, month, day] = date.split("-").map(Number); const value = new Date(Date.UTC(year, month - 1, day + amount)); return value.toISOString().slice(0, 10); };

export default function ManageBookingPage() {
  const token = useRef("");
  const [booking, setBooking] = useState<ManagedBooking | null>(null);
  const [days, setDays] = useState<Day[]>([]);
  const [selectedDate, setSelectedDate] = useState("");
  const [selectedTime, setSelectedTime] = useState("");
  const [mode, setMode] = useState<"overview" | "change" | "cancelled" | "changed">("overview");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const rawToken = new URLSearchParams(window.location.search).get("token") ?? ""; token.current = rawToken;
    const request = rawToken
      ? fetch(`/api/public/bookings/manage?token=${encodeURIComponent(rawToken)}`, { cache: "no-store" })
      : Promise.resolve(new Response(JSON.stringify({ error: "Linket mangler eller er ugyldigt" }), { status: 400, headers: { "content-type": "application/json" } }));
    request.then(async (response) => {
      const data = await response.json(); if (!response.ok) throw new Error(data.error ?? "Bookingen kunne ikke hentes"); return data;
    }).then((data) => setBooking(data.booking)).catch((reason) => setError(reason instanceof Error ? reason.message : "Bookingen kunne ikke hentes")).finally(() => setLoading(false));
  }, []);

  const loadAvailability = async () => {
    if (!booking?.bookingTypeId) { setError("Bookingtypen kan ikke ændres online"); return; }
    setLoading(true); setError(""); setSelectedDate(""); setSelectedTime(""); const today = todayInDenmark();
    try {
      const response = await fetch(`/api/public/availability?locationId=ikast&bookingTypeId=${encodeURIComponent(booking.bookingTypeId)}&from=${today}&to=${addDays(today, 30)}`, { cache: "no-store" });
      const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error ?? data.message ?? "Ledige tider kunne ikke hentes");
      setDays((data.days ?? []).filter((day: Day) => day.availableSlots.length > 0).slice(0, 8)); setMode("change");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ledige tider kunne ikke hentes"); } finally { setLoading(false); }
  };
  const update = async (action: "cancel" | "reschedule") => {
    if (action === "cancel" && !window.confirm("Er du sikker på, at tiden skal afbestilles?")) return;
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/public/bookings/manage", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ token: token.current, action, date: selectedDate || undefined, time: selectedTime || undefined }) });
      const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error ?? data.message ?? "Ændringen kunne ikke gemmes");
      if (action === "cancel") setMode("cancelled"); else { setBooking(data.booking); setMode("changed"); }
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ændringen kunne ikke gemmes"); } finally { setLoading(false); }
  };

  return <main className="public-booking"><div className="public-booking-shell"><header className="public-booking-header"><a className="public-logo" href="/booking" aria-label="Midtjysk Bilsyn – booking"><span className="public-logo-art" /></a><span className="public-location"><MapPin size={17} /> Ikast</span></header>
    <section className="public-step public-manage-page"><a className="public-back" href="/booking"><ChevronLeft size={18} /> Tilbage til booking</a>
      {loading && !booking ? <div className="public-loading">Henter din booking…</div> : error && !booking ? <><span className="manage-state-icon error"><X size={27} /></span><h1>Linket virker ikke</h1><p className="public-intro">{error}</p><a className="public-manage-primary" href="/booking">Find min booking</a></> : mode === "cancelled" ? <><span className="manage-state-icon"><Check size={27} /></span><p className="public-eyebrow">AFBESTILLING MODTAGET</p><h1>Din tid er afbestilt</h1><p className="public-intro">Du får en bekræftelse på SMS og eventuelt e-mail.</p><a className="public-manage-primary" href="/booking">Book en ny tid</a></> : mode === "changed" ? <><span className="manage-state-icon"><Check size={27} /></span><p className="public-eyebrow">TIDEN ER ÆNDRET</p><h1>Din nye tid er gemt</h1>{booking && <BookingCard booking={booking} />}<p className="public-manage-note">Du får en bekræftelse på SMS og eventuelt e-mail.</p><button className="public-secondary" onClick={() => setMode("overview")}>Tilbage til oversigt</button></> : booking && mode === "change" ? <><p className="public-eyebrow">ÆNDR TID</p><h1>Vælg en ny tid</h1><p className="public-intro">Din nuværende tid beholdes, indtil du har bekræftet en ny.</p>{error && <p className="public-error" role="alert">{error}</p>}{!loading && days.length === 0 ? <div className="public-empty">Der er ingen ledige tider i perioden. Behold din nuværende tid eller prøv igen senere.</div> : <div className="manage-days">{days.map((day) => <div key={day.date}><button className={selectedDate === day.date ? "selected" : ""} onClick={() => { setSelectedDate(day.date); setSelectedTime(""); }}><strong>{dayLabel(day.date)}</strong><small>{day.availableCount} ledige tider</small></button>{selectedDate === day.date && <div className="manage-times">{day.availableSlots.map((time) => <button key={time} className={selectedTime === time ? "selected" : ""} onClick={() => setSelectedTime(time)}><Clock3 size={15} /> {time}</button>)}</div>}</div>)}</div>}<button className="public-primary" disabled={!selectedDate || !selectedTime || loading} onClick={() => void update("reschedule")}>{loading ? "Gemmer…" : "Bekræft ny tid"}</button><button className="public-secondary" onClick={() => setMode("overview")}>Behold min nuværende tid</button></> : booking ? <><p className="public-eyebrow">DIN BOOKING</p><h1>Se eller ret din tid</h1><p className="public-intro">Her kan du nemt ændre eller afbestille uden at ringe til os.</p><BookingCard booking={booking} />{error && <p className="public-error" role="alert">{error}</p>}{booking.canChange ? <div className="manage-actions"><button className="public-manage-primary" disabled={loading} onClick={() => void loadAvailability()}><CalendarClock size={17} /> Ændr tidspunkt</button><button className="public-cancel" disabled={loading} onClick={() => void update("cancel")}><X size={17} /> Afbestil tid</button></div> : <p className="public-error">Denne booking kan ikke længere ændres online.</p>}<div className="public-privacy"><ShieldCheck size={15} /> Linket er personligt. Del det ikke med andre.</div></> : null}
    </section></div></main>;
}

function BookingCard({ booking }: { booking: ManagedBooking }) {
  return <div className="confirmation-card"><strong>{booking.inspection}</strong><span>{dayLabel(booking.date)} kl. {booking.time}</span><small><MapPin size={13} /> Midtjysk Bilsyn – Ikast</small><b>{booking.plate}</b></div>;
}
