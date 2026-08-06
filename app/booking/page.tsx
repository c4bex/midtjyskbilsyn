"use client";

import { Check, ChevronLeft, ChevronRight, Clock3, MapPin, ShieldCheck } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type BookingType = { id: string; name: string; requiredSlots: number };
type Day = { date: string; availableSlots: string[]; availableCount: number };

const fallbackTypes: BookingType[] = [
  { id: "1", name: "Periodisk syn", requiredSlots: 1 },
  { id: "2", name: "Omsyn", requiredSlots: 1 },
  { id: "3", name: "Varebilssyn", requiredSlots: 1 },
  { id: "4", name: "Motorcykelsyn", requiredSlots: 1 },
  { id: "5", name: "Toldsyn", requiredSlots: 2 },
];

const dayLabel = (date: string) => new Intl.DateTimeFormat("da-DK", { weekday: "long", day: "numeric", month: "long" }).format(new Date(`${date}T12:00:00`));
const dateValue = (date: Date) => date.toISOString().slice(0, 10);
const addDays = (date: string, amount: number) => { const value = new Date(`${date}T12:00:00`); value.setDate(value.getDate() + amount); return dateValue(value); };

export default function PublicBookingPage() {
  const today = dateValue(new Date());
  const [step, setStep] = useState(1);
  const [types, setTypes] = useState<BookingType[]>(fallbackTypes);
  const [selectedType, setSelectedType] = useState<BookingType | null>(null);
  const [days, setDays] = useState<Day[]>([]);
  const [selectedDay, setSelectedDay] = useState<Day | null>(null);
  const [selectedTime, setSelectedTime] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [confirmation, setConfirmation] = useState<{ id: string; date: string; time: string } | null>(null);
  const [form, setForm] = useState({ plate: "", name: "", phone: "", email: "" });

  useEffect(() => { fetch("/api/public/config").then((response) => response.ok ? response.json() : Promise.reject()).then((data) => setTypes(data.bookingTypes ?? fallbackTypes)).catch(() => undefined); }, []);

  const loadAvailability = async (type: BookingType) => {
    setSelectedType(type); setSelectedTime(""); setSelectedDay(null); setError(""); setLoading(true);
    const end = addDays(today, 30);
    try {
      const response = await fetch(`/api/public/availability?locationId=ikast&bookingTypeId=${encodeURIComponent(type.id)}&from=${today}&to=${end}`, { cache: "no-store" });
      if (!response.ok) throw new Error();
      const data = await response.json() as { days?: Day[] };
      setDays(data.days ?? []);
    } catch {
      setDays(Array.from({ length: 5 }, (_, index) => ({ date: addDays(today, index), availableSlots: index === 0 ? ["14:20", "15:20"] : ["08:20", "08:40", "09:20", "10:40"], availableCount: 4 })));
    } finally { setLoading(false); }
  };

  const firstDays = useMemo(() => days.filter((day) => day.availableSlots.length > 0).slice(0, 5), [days]);
  const chooseDay = (day: Day) => { setSelectedDay(day); setSelectedTime(day.availableSlots[0] ?? ""); };
  const chooseTime = (time: string) => { setSelectedTime(time); if (selectedDay) setStep(3); };
  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); if (!selectedType || !selectedDay || !selectedTime) return;
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/public/bookings", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ customer: form.name, phone: form.phone, email: form.email || undefined, plate: form.plate, date: selectedDay.date, time: selectedTime, inspection: selectedType.name }) });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error ?? "Tiden blev desværre taget. Vælg en anden tid.");
      setConfirmation({ id: data.booking?.id ?? "", date: selectedDay.date, time: selectedTime }); setStep(4);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Bookingen kunne ikke gennemføres"); } finally { setLoading(false); }
  };

  return <main className="public-booking"><div className="public-booking-shell"><header className="public-booking-header"><div className="public-logo"><span>MB</span><strong>Midtjysk<br /><small>Bilsyn</small></strong></div><span className="public-location"><MapPin size={15} /> Ikast</span></header>
    {confirmation ? <section className="public-confirmation"><span className="confirmation-icon"><Check size={30} /></span><p className="public-eyebrow">BOOKING GENNEMFØRT</p><h1>Din tid er booket</h1><p>Vi glæder os til at se dig hos Midtjysk Bilsyn.</p><div className="confirmation-card"><strong>{selectedType?.name}</strong><span>{dayLabel(confirmation.date)} kl. {confirmation.time}</span><small><MapPin size={13} /> Midtjysk Bilsyn – Ikast</small><b>{form.plate.toUpperCase()}</b></div><button className="public-secondary" onClick={() => window.location.reload()}>Tilbage til booking</button></section> : <><div className="public-progress"><span className="active">1</span><i /><span className={step >= 2 ? "active" : ""}>2</span><i /><span className={step >= 3 ? "active" : ""}>3</span><i /><span>4</span></div>{step === 1 && <section className="public-step"><p className="public-eyebrow">MIDTJYSK BILSYN · IKAST</p><h1>Hvad skal vi hjælpe dig med?</h1><p className="public-intro">Vælg den type syn, du har brug for.</p><div className="public-type-grid">{types.map((type) => <button key={type.id} onClick={() => { void loadAvailability(type); setStep(2); }}><strong>{type.name}</strong><small>{type.requiredSlots > 1 ? "2 sammenhængende tider" : "20 minutter"}</small><ChevronRight size={17} /></button>)}</div></section>}{step === 2 && <section className="public-step"><button className="public-back" onClick={() => setStep(1)}><ChevronLeft size={16} /> Tilbage</button><p className="public-eyebrow">{selectedType?.name}</p><h1>Hvornår passer det?</h1><p className="public-intro">Vælg en af de nærmeste ledige tider.</p>{loading ? <div className="public-loading">Henter ledige tider…</div> : firstDays.length === 0 ? <div className="public-empty">Der er ingen ledige tider i perioden. Prøv igen senere.</div> : <><div className="public-nearest">{firstDays.map((day) => <button key={day.date} className={selectedDay?.date === day.date ? "selected" : ""} onClick={() => chooseDay(day)}><strong>{dayLabel(day.date)}</strong><small>{day.availableCount} ledige tider</small></button>)}</div>{selectedDay && <div className="public-times"><h2>Ledige tider {dayLabel(selectedDay.date)}</h2><div>{selectedDay.availableSlots.map((time) => <button key={time} className={selectedTime === time ? "selected" : ""} onClick={() => chooseTime(time)}><Clock3 size={16} /> {time}</button>)}</div></div>}</>}</section>}{step === 3 && <section className="public-step"><button className="public-back" onClick={() => setStep(2)}><ChevronLeft size={16} /> Tilbage</button><p className="public-eyebrow">{selectedType?.name} · {selectedDay && dayLabel(selectedDay.date)} kl. {selectedTime}</p><h1>Få din tid bekræftet</h1><p className="public-intro">Registreringsnummeret skal udfyldes først.</p><form className="public-form" onSubmit={submit}><label>Registreringsnummer<input autoFocus required value={form.plate} onChange={(event) => setForm({ ...form, plate: event.target.value.toUpperCase() })} placeholder="AB12345" /></label><label>Navn<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Dit navn" /></label><label>Mobilnummer<input required type="tel" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} placeholder="+45 20 12 34 56" /></label><label>E-mail <span>(valgfrit)</span><input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} placeholder="din@email.dk" /></label>{error && <p className="public-error">{error}</p>}<button className="public-primary" disabled={loading}>{loading ? "Booker…" : "Bekræft booking"}</button></form><div className="public-privacy"><ShieldCheck size={15} /> Dine oplysninger bruges kun til at håndtere din booking.</div></section>}</>}</div></main>;
}
