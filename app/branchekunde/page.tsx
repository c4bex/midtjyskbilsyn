"use client";

import { Building2, CalendarDays, ChevronRight, LogOut, Plus, Search, X } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";

type Booking = { id: string; date: string; time: string; inspection: string; status: string; plate: string; vehicle: string; requisitionNumber?: string | null; contactName?: string | null; note?: string | null };
type Session = { user: { name: string; email: string; role: string }; company: { name: string }; settings: { booking_horizon_days: number; requisition_requirement: "hidden" | "optional" | "required"; change_cutoff_minutes: number } };
type Form = { plate: string; vehicle: string; date: string; time: string; inspection: string; requisitionNumber: string; contactName: string; customerNote: string };

const initialForm: Form = { plate: "", vehicle: "", date: new Date().toISOString().slice(0, 10), time: "", inspection: "Periodisk syn", requisitionNumber: "", contactName: "", customerNote: "" };
const formatDate = (date: string) => new Intl.DateTimeFormat("da-DK", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${date}T12:00:00`));

export default function BranchekundePage() {
  const [session, setSession] = useState<Session | null>(null);
  const [checking, setChecking] = useState(true);
  const [login, setLogin] = useState({ email: "", password: "" });
  const [loginError, setLoginError] = useState("");
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [form, setForm] = useState<Form>(initialForm);
  const [days, setDays] = useState<Array<{ date: string; availableSlots: string[] }>>([]);
  const [editing, setEditing] = useState<string | null>(null);
  const [notice, setNotice] = useState("");
  const [saving, setSaving] = useState(false);

  const loadDashboard = async () => {
    const response = await fetch("/api/portal/dashboard", { cache: "no-store" });
    if (!response.ok) return;
    const data = await response.json() as { bookings: Booking[] };
    setBookings(data.bookings ?? []);
  };

  useEffect(() => { fetch("/api/portal/session", { cache: "no-store" }).then(async (response) => { if (!response.ok) return; const data = await response.json() as Session & { authenticated: boolean }; if (data.authenticated) { setSession(data); await loadDashboard(); } }).finally(() => setChecking(false)); }, []);

  useEffect(() => {
    const normalized = form.plate.replace(/[^A-ZÆØÅ0-9]/gi, "").toUpperCase();
    if (normalized.length !== 7) return;
    const controller = new AbortController();
    fetch(`/api/public/vehicle-lookup?plate=${encodeURIComponent(form.plate)}`, { signal: controller.signal, cache: "no-store" }).then(async (response) => {
      const data = await response.json() as { found?: boolean; vehicle?: { make?: string | null; model?: string | null } };
      if (data.found) {
        const vehicle = [data.vehicle?.make, data.vehicle?.model].filter(Boolean).join(" ");
        setForm((current) => ({ ...current, vehicle: vehicle || current.vehicle }));
      }
    }).catch(() => undefined);
    return () => controller.abort();
  }, [form.plate]);

  const submitLogin = async (event: FormEvent) => {
    event.preventDefault(); setLoginError("");
    const response = await fetch("/api/portal/login", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(login) });
    const data = await response.json() as Session & { error?: string };
    if (!response.ok) { setLoginError(data.error ?? "Kunne ikke logge ind"); return; }
    setSession(data); await loadDashboard();
  };

  const loadAvailability = async (date = form.date) => {
    const to = new Date(`${date}T12:00:00`); to.setDate(to.getDate() + 14);
    const response = await fetch(`/api/portal/availability?inspection=${encodeURIComponent(form.inspection)}&from=${date}&to=${to.toISOString().slice(0, 10)}`, { cache: "no-store" });
    if (response.ok) setDays((await response.json() as { days: Array<{ date: string; availableSlots: string[] }> }).days ?? []);
  };

  const saveBooking = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true);
    const response = await fetch(editing ? `/api/portal/bookings/${editing}` : "/api/portal/bookings", { method: editing ? "PATCH" : "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(form) });
    const data = await response.json() as { error?: string };
    setSaving(false);
    if (!response.ok) { setNotice(data.error ?? "Bookingen kunne ikke gemmes"); return; }
    setNotice(editing ? "Bookingen er ændret" : "Bookingen er oprettet"); setEditing(null); setForm(initialForm); await loadDashboard();
  };

  const cancel = async (id: string) => { if (!window.confirm("Vil du aflyse denne booking?")) return; const response = await fetch(`/api/portal/bookings/${id}`, { method: "DELETE" }); if (response.ok) { setNotice("Bookingen er aflyst"); await loadDashboard(); } else setNotice((await response.json() as { error?: string }).error ?? "Bookingen kunne ikke aflyses"); };
  const logout = async () => { await fetch("/api/portal/logout", { method: "POST" }); setSession(null); setBookings([]); };

  if (checking) return <main className="business-portal"><div className="business-portal-card">Henter portal…</div></main>;
  if (!session) return <main className="business-portal"><form className="business-login" onSubmit={submitLogin}><span className="business-logo"><Building2 size={20} /></span><p className="public-eyebrow">BRANCHEKUNDEPORTAL</p><h1>Log ind</h1><p>Book og administrér tider for din virksomhed.</p><label>E-mail<input type="email" required value={login.email} onChange={(event) => setLogin({ ...login, email: event.target.value })} /></label><label>Adgangskode<input type="password" required value={login.password} onChange={(event) => setLogin({ ...login, password: event.target.value })} /></label>{loginError && <div className="business-error">{loginError}</div>}<button className="business-primary">Log ind <ChevronRight size={17} /></button><small>Kontakt Midtjysk Bilsyn, hvis du mangler adgang.</small></form></main>;

  return <main className="business-portal"><div className="business-portal-shell"><header className="business-portal-header"><div><span className="business-logo"><Building2 size={18} /></span><div><strong>{session.company.name}</strong><small>Branchekundeportal</small></div></div><div><span className="business-user">{session.user.name}</span><button className="business-icon-button" onClick={() => void logout()} aria-label="Log ud"><LogOut size={17} /></button></div></header><section className="business-portal-body"><div className="business-portal-heading"><div><p className="public-eyebrow">OVERBLIK</p><h1>Velkommen, {session.user.name.split(" ")[0]}</h1><p>Her kan du booke og følge virksomhedens synstider.</p></div><button className="business-primary" onClick={() => { setEditing(null); setForm(initialForm); }}><Plus size={17} /> Ny booking</button></div><div className="business-portal-grid"><section className="business-booking-panel"><div className="business-panel-head"><div><h2>{editing ? "Ændr booking" : "Book en ny tid"}</h2><p>Vælg køretøj og en ledig tid i samme kalender som Midtjysk Bilsyn.</p></div></div><form onSubmit={saveBooking} className="business-booking-form"><div className="business-field-grid"><label>Nummerplade<input required value={form.plate} onChange={(event) => setForm({ ...form, plate: event.target.value.toUpperCase() })} placeholder="AB 12 345" /></label><label>Mærke og model<input value={form.vehicle} onChange={(event) => setForm({ ...form, vehicle: event.target.value })} placeholder="Udfyldes fra DMR eller manuelt" /></label><label>Dato<input type="date" required value={form.date} onChange={(event) => { setForm({ ...form, date: event.target.value }); void loadAvailability(event.target.value); }} /></label><label>Synstype<select value={form.inspection} onChange={(event) => { setForm({ ...form, inspection: event.target.value }); void loadAvailability(); }}><option>Periodisk syn</option><option>Omsyn</option><option>Registreringssyn</option><option>Toldsyn</option></select></label></div><div className="business-time-picker"><div className="business-panel-head"><div><h3>Ledige tider</h3><p>Vælg en grøn tid</p></div><button type="button" onClick={() => void loadAvailability()}><Search size={15} /> Opdater</button></div><div className="business-time-grid">{days.flatMap((day) => day.availableSlots.map((time) => <button type="button" key={`${day.date}-${time}`} className={form.date === day.date && form.time === time ? "selected" : ""} onClick={() => setForm({ ...form, date: day.date, time })}><span>{formatDate(day.date)}</span><strong>{time}</strong></button>))}</div></div><div className="business-field-grid"><label>Rekvisitionsnummer<input value={form.requisitionNumber} onChange={(event) => setForm({ ...form, requisitionNumber: event.target.value })} placeholder="Valgfrit eller påkrævet" /></label><label>Kontaktperson<input value={form.contactName} onChange={(event) => setForm({ ...form, contactName: event.target.value })} /></label></div><label>Bemærkning<textarea value={form.customerNote} onChange={(event) => setForm({ ...form, customerNote: event.target.value })} maxLength={1000} /></label><div className="business-form-actions"><button type="button" className="business-secondary" onClick={() => { setEditing(null); setForm(initialForm); }}><X size={15} /> Ryd</button><button className="business-primary" disabled={saving || !form.time}>{saving ? "Gemmer…" : editing ? "Gem ændring" : "Bekræft booking"}</button></div></form></section><aside className="business-upcoming"><div className="business-panel-head"><div><h2>Kommende bookinger</h2><p>{bookings.filter((booking) => booking.status !== "cancelled").length} bookinger i alt</p></div><CalendarDays size={20} /></div>{bookings.filter((booking) => booking.status !== "cancelled").slice(0, 12).map((booking) => <article className="business-booking-card" key={booking.id}><div><strong>{booking.plate}</strong><span>{booking.inspection} · {booking.vehicle || "Køretøj"}</span><small>{formatDate(booking.date)} kl. {booking.time}</small></div><div><button onClick={() => { setEditing(booking.id); setForm({ plate: booking.plate, vehicle: booking.vehicle, date: booking.date, time: booking.time, inspection: booking.inspection, requisitionNumber: booking.requisitionNumber ?? "", contactName: booking.contactName ?? "", customerNote: booking.note ?? "" }); void loadAvailability(booking.date); }}>Redigér</button><button onClick={() => void cancel(booking.id)}>Aflys</button></div></article>)}{bookings.length === 0 && <p className="business-empty">Ingen bookinger endnu.</p>}</aside></div></section>{notice && <div className="business-notice">{notice}</div>}</div></main>;
}
