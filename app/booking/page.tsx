"use client";

import { CalendarClock, Check, ChevronLeft, ChevronRight, Clock3, MapPin, ShieldCheck } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type BookingType = { id: string; name: string; requiredSlots: number };
type Day = { date: string; availableSlots: string[]; availableCount: number };
type PublicVehicleLookup = { found: boolean; status: "connected" | "unavailable" | "not_checked"; vehicle?: { make?: string | null; model?: string | null } };

const dayLabel = (date: string) => new Intl.DateTimeFormat("da-DK", { weekday: "long", day: "numeric", month: "long" }).format(new Date(`${date}T12:00:00`));
const todayInDenmark = () => new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Copenhagen" }).format(new Date());
const addDays = (date: string, amount: number) => { const [year, month, day] = date.split("-").map(Number); const value = new Date(Date.UTC(year, month - 1, day + amount)); return value.toISOString().slice(0, 10); };

function BookingHeader() {
  return <header className="public-booking-header"><a className="public-logo" href="/booking" aria-label="Midtjysk Bilsyn – booking"><span className="public-logo-art" /></a><span className="public-location"><MapPin size={17} /> Ikast</span></header>;
}

export default function PublicBookingPage() {
  const today = todayInDenmark();
  const [step, setStep] = useState(1);
  const [manageLookup, setManageLookup] = useState(false);
  const [types, setTypes] = useState<BookingType[]>([]);
  const [configLoading, setConfigLoading] = useState(true);
  const [maximumBookingDays, setMaximumBookingDays] = useState(60);
  const [selectedType, setSelectedType] = useState<BookingType | null>(null);
  const [days, setDays] = useState<Day[]>([]);
  const [selectedDay, setSelectedDay] = useState<Day | null>(null);
  const [selectedTime, setSelectedTime] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [confirmation, setConfirmation] = useState<{ id: string; date: string; time: string; manageUrl: string } | null>(null);
  const [vehicleLookup, setVehicleLookup] = useState<PublicVehicleLookup | null>(null);
  const [vehicleLookupLoading, setVehicleLookupLoading] = useState(false);
  const [form, setForm] = useState({ plate: "", name: "", phone: "", email: "" });
  const [lookup, setLookup] = useState({ plate: "", phone: "" });

  useEffect(() => {
    fetch("/api/public/config", { cache: "no-store" }).then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error ?? "Bookingtyperne kunne ikke hentes");
      setTypes(data.bookingTypes ?? []);
      setMaximumBookingDays(Number(data.settings?.maximumBookingDays) || 60);
    }).catch((reason) => setError(reason instanceof Error ? reason.message : "Bookingsiden kunne ikke indlæses"))
      .finally(() => setConfigLoading(false));
  }, []);

  const loadAvailability = async (type: BookingType) => {
    setSelectedType(type); setSelectedTime(""); setSelectedDay(null); setError(""); setLoading(true);
    try {
      const response = await fetch(`/api/public/availability?locationId=ikast&bookingTypeId=${encodeURIComponent(type.id)}&from=${today}&to=${addDays(today, Math.min(30, maximumBookingDays))}`, { cache: "no-store" });
      const data = await response.json().catch(() => ({})) as { days?: Day[]; error?: string; message?: string };
      if (!response.ok) throw new Error(data.error ?? data.message ?? "Ledige tider kunne ikke hentes");
      setDays(data.days ?? []);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Ledige tider kunne ikke hentes"); setDays([]); }
    finally { setLoading(false); }
  };
  const firstDays = useMemo(() => days.filter((day) => day.availableSlots.length > 0).slice(0, 5), [days]);
  const lookupVehicle = async (plate: string) => {
    const normalized = plate.toUpperCase().replace(/[^A-ZÆØÅ0-9]/g, "");
    if (normalized.length < 5) { setVehicleLookup(null); return; }
    setVehicleLookupLoading(true);
    try { const response = await fetch(`/api/public/vehicle-lookup?plate=${encodeURIComponent(plate)}`, { cache: "no-store" }); const data = await response.json() as PublicVehicleLookup; setVehicleLookup(response.ok ? data : { found: false, status: "unavailable" }); }
    catch { setVehicleLookup({ found: false, status: "unavailable" }); } finally { setVehicleLookupLoading(false); }
  };
  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); if (!selectedType || !selectedDay || !selectedTime) return;
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/public/bookings", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ customer: form.name, phone: form.phone, email: form.email || undefined, plate: form.plate, date: selectedDay.date, time: selectedTime, inspection: selectedType.name }) });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message = data.error ?? data.message ?? "Tiden blev desværre taget. Vælg en anden tid.";
        if (response.status === 409) { await loadAvailability(selectedType); setStep(2); }
        throw new Error(message);
      }
      setConfirmation({ id: data.booking?.id ?? "", date: selectedDay.date, time: selectedTime, manageUrl: data.booking?.manageUrl ?? "/booking" }); setStep(4);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Bookingen kunne ikke gennemføres"); } finally { setLoading(false); }
  };
  const findBooking = async (event: React.FormEvent) => {
    event.preventDefault(); setLoading(true); setError("");
    try {
      const response = await fetch("/api/public/bookings/lookup", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(lookup) });
      const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.error ?? "Bookingen blev ikke fundet");
      window.location.assign(data.manageUrl);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Bookingen blev ikke fundet"); setLoading(false); }
  };

  return <main className="public-booking"><div className="public-booking-shell"><BookingHeader />
    {confirmation ? <section className="public-confirmation"><span className="confirmation-icon"><Check size={30} /></span><p className="public-eyebrow">BOOKING GENNEMFØRT</p><h1>Din tid er booket</h1><p>Vi glæder os til at se dig hos Midtjysk Bilsyn.</p><div className="confirmation-card"><strong>{selectedType?.name}</strong><span>{dayLabel(confirmation.date)} kl. {confirmation.time}</span><small><MapPin size={13} /> Midtjysk Bilsyn – Ikast</small><b>{form.plate.toUpperCase()}</b></div><a className="public-manage-primary" href={confirmation.manageUrl}><CalendarClock size={17} /> Se, ændr eller afbestil din tid</a><p className="public-manage-note">Dit personlige link sendes også på SMS{form.email ? " og e-mail" : ""}.</p><button className="public-secondary" onClick={() => window.location.reload()}>Book en ny tid</button></section>
      : manageLookup ? <section className="public-step"><button className="public-back" onClick={() => { setManageLookup(false); setError(""); }}><ChevronLeft size={18} /> Tilbage til ny booking</button><p className="public-eyebrow">DIN BOOKING</p><h1>Se, ændr eller afbestil</h1><p className="public-intro">Indtast nummerpladen og det mobilnummer, der blev brugt ved booking.</p><form className="public-form" onSubmit={findBooking}><label>Registreringsnummer<input autoFocus required value={lookup.plate} onChange={(event) => setLookup({ ...lookup, plate: event.target.value.toUpperCase() })} placeholder="AB12345" /></label><label>Mobilnummer<input required type="tel" value={lookup.phone} onChange={(event) => setLookup({ ...lookup, phone: event.target.value })} placeholder="+45 20 12 34 56" /></label>{error && <p className="public-error">{error}</p>}<button className="public-primary" disabled={loading}>{loading ? "Finder din booking…" : "Find min booking"}</button></form><div className="public-privacy"><ShieldCheck size={15} /> Oplysningerne skal matche din booking.</div></section>
      : <><div className="public-progress"><span className="active">1</span><i /><span className={step >= 2 ? "active" : ""}>2</span><i /><span className={step >= 3 ? "active" : ""}>3</span><i /><span>4</span></div>
        {step === 1 && <section className="public-step"><p className="public-eyebrow">MIDTJYSK BILSYN · IKAST</p><h1>Hvad skal vi hjælpe dig med?</h1><p className="public-intro">Vælg den type syn, du har brug for.</p>{error && <p className="public-error" role="alert">{error}</p>}{configLoading ? <div className="public-loading">Henter bookingtyper…</div> : types.length === 0 ? <div className="public-empty">Der er ingen bookingtyper tilgængelige lige nu. Prøv igen senere.</div> : <div className="public-type-grid">{types.map((type) => <button key={type.id} onClick={() => { void loadAvailability(type); setStep(2); }}><strong>{type.name}</strong><small>{type.requiredSlots > 1 ? `${type.requiredSlots} sammenhængende tider` : "20 minutter"}</small><ChevronRight size={17} /></button>)}</div>}<div className="public-existing"><span>Har du allerede en tid?</span><button onClick={() => { setManageLookup(true); setError(""); }}><CalendarClock size={17} /> Se, ændr eller afbestil</button></div></section>}
        {step === 2 && <section className="public-step"><button className="public-back" onClick={() => setStep(1)}><ChevronLeft size={18} /> Tilbage til valg af syn</button><p className="public-eyebrow">{selectedType?.name}</p><h1>Hvornår passer det?</h1><p className="public-intro">Vælg en af de nærmeste ledige tider.</p>{error && <p className="public-error">{error}</p>}{loading ? <div className="public-loading">Henter ledige tider…</div> : firstDays.length === 0 ? <div className="public-empty">Der er ingen ledige tider i perioden. Prøv igen senere.</div> : <><div className="public-nearest">{firstDays.map((day) => <button key={day.date} className={selectedDay?.date === day.date ? "selected" : ""} onClick={() => { setSelectedDay(day); setSelectedTime(day.availableSlots[0] ?? ""); }}><strong>{dayLabel(day.date)}</strong><small>{day.availableCount} ledige tider</small></button>)}</div>{selectedDay && <div className="public-times"><h2>Ledige tider {dayLabel(selectedDay.date)}</h2><div>{selectedDay.availableSlots.map((time) => <button key={time} className={selectedTime === time ? "selected" : ""} onClick={() => { setSelectedTime(time); setStep(3); }}><Clock3 size={16} /> {time}</button>)}</div></div>}</>}</section>}
        {step === 3 && <section className="public-step"><button className="public-back" onClick={() => setStep(2)}><ChevronLeft size={18} /> Tilbage til tider</button><p className="public-eyebrow">{selectedType?.name} · {selectedDay && dayLabel(selectedDay.date)} kl. {selectedTime}</p><h1>Få din tid bekræftet</h1><p className="public-intro">Registreringsnummeret skal udfyldes først.</p><form className="public-form" onSubmit={submit}><label>Registreringsnummer<input autoFocus required value={form.plate} onChange={(event) => { const plate = event.target.value.toUpperCase(); setForm({ ...form, plate }); setVehicleLookup(null); if (plate.replace(/[^A-ZÆØÅ0-9]/g, "").length === 7) void lookupVehicle(plate); }} onBlur={() => void lookupVehicle(form.plate)} placeholder="AB12345" /></label>{vehicleLookupLoading && <div className="public-vehicle-status">Kontrollerer nummerpladen…</div>}{!vehicleLookupLoading && vehicleLookup?.found && <div className="public-vehicle-status found">{[vehicleLookup.vehicle?.make, vehicleLookup.vehicle?.model].filter(Boolean).join(" ")} fundet i DMR.</div>}{!vehicleLookupLoading && vehicleLookup && !vehicleLookup.found && <div className="public-vehicle-status">Nummerpladen blev ikke fundet i DMR. Du kan stadig fortsætte manuelt.</div>}<label>Navn<input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Dit navn" /></label><label>Mobilnummer<input required type="tel" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} placeholder="+45 20 12 34 56" /></label><label>E-mail <span>(valgfrit)</span><input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} placeholder="din@email.dk" /></label>{error && <p className="public-error">{error}</p>}<button className="public-primary" disabled={loading}>{loading ? "Booker…" : "Bekræft booking"}</button></form><div className="public-privacy"><ShieldCheck size={15} /> Dine oplysninger bruges kun til at håndtere din booking.</div></section>}
      </>}
  </div></main>;
}
