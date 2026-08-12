"use client";

import { CalendarDays, CheckCircle2, ChevronRight, FileText, LogOut, Plus, Search, ShieldCheck, X } from "lucide-react";
import { FormEvent, useCallback, useEffect, useRef, useState } from "react";

type Booking = { id: string; date: string; time: string; inspection: string; status: string; plate: string; vehicle: string; requisitionNumber?: string | null; contactName?: string | null; note?: string | null };
type BookingType = { id: string; name: string; requiredSlots: number };
type PortalInvoice = { id: string; period: string; status: string; externalStatus: string; amountOre: number };
type Session = { user: { name: string; email: string; phone?: string | null; role: "admin" | "employee" | "read_only" }; company: { name: string }; settings: { booking_horizon_days: number; requisition_requirement: "hidden" | "optional" | "required"; change_cutoff_minutes: number }; bookingTypes: BookingType[]; permissions: { canManageBookings: boolean; canViewInvoices: boolean } };
type Form = { plate: string; vehicle: string; date: string; time: string; inspection: string; requisitionNumber: string; contactName: string; customerNote: string };

const todayInDenmark = () => new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Copenhagen" }).format(new Date());
const addDays = (date: string, amount: number) => { const [year, month, day] = date.split("-").map(Number); return new Date(Date.UTC(year, month - 1, day + amount)).toISOString().slice(0, 10); };
const emptyForm = (inspection = ""): Form => ({ plate: "", vehicle: "", date: todayInDenmark(), time: "", inspection, requisitionNumber: "", contactName: "", customerNote: "" });
const formatDate = (date: string) => new Intl.DateTimeFormat("da-DK", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${date}T12:00:00`));
const apiError = (data: unknown, fallback: string) => {
  if (!data || typeof data !== "object") return fallback;
  const payload = data as { error?: string; message?: string; errors?: Record<string, string[]> };
  return payload.error ?? Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? fallback;
};

export default function BranchekundePage() {
  const [session, setSession] = useState<Session | null>(null);
  const [checking, setChecking] = useState(true);
  const [login, setLogin] = useState({ email: "", password: "" });
  const [loginError, setLoginError] = useState("");
  const [forgotMode, setForgotMode] = useState(false);
  const [forgotSent, setForgotSent] = useState(false);
  const [bookings, setBookings] = useState<Booking[]>([]);
  const [invoices, setInvoices] = useState<PortalInvoice[]>([]);
  const [billing, setBilling] = useState<{ invoice_email?: string | null; billing_method?: string | null; payment_terms?: string | null } | null>(null);
  const [form, setForm] = useState<Form>(() => emptyForm());
  const [days, setDays] = useState<Array<{ date: string; availableSlots: string[] }>>([]);
  const [availabilityLoading, setAvailabilityLoading] = useState(true);
  const [availabilityError, setAvailabilityError] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [notice, setNotice] = useState("");
  const [saving, setSaving] = useState(false);
  const [authBusy, setAuthBusy] = useState(false);
  const bookingPanelRef = useRef<HTMLElement>(null);
  const plateInputRef = useRef<HTMLInputElement>(null);
  const availabilityRequest = useRef(0);

  const loadDashboard = useCallback(async () => {
    const response = await fetch("/api/portal/dashboard", { cache: "no-store" });
    const data = await response.json().catch(() => ({})) as { bookings?: Booking[]; invoices?: PortalInvoice[]; billing?: typeof billing };
    if (!response.ok) throw new Error(apiError(data, "Portalens overblik kunne ikke hentes"));
    setBookings(data.bookings ?? []);
    setInvoices(data.invoices ?? []);
    setBilling(data.billing ?? null);
  }, []);

  useEffect(() => { fetch("/api/portal/session", { cache: "no-store" }).then(async (response) => { if (!response.ok) return; const data = await response.json() as Session & { authenticated: boolean }; if (data.authenticated) { await loadDashboard(); setSession(data); setForm(emptyForm(data.bookingTypes?.[0]?.name ?? "")); } }).catch((reason) => setLoginError(reason instanceof Error ? reason.message : "Portalen kunne ikke kontaktes. Prøv igen." )).finally(() => setChecking(false)); }, [loadDashboard]);

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
    event.preventDefault(); setLoginError(""); setAuthBusy(true);
    try {
      const response = await fetch("/api/portal/login", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(login) });
      const data = await response.json().catch(() => ({})) as Session & { error?: string };
      if (!response.ok) throw new Error(apiError(data, "Kunne ikke logge ind"));
      await loadDashboard(); setSession(data); setForm(emptyForm(data.bookingTypes?.[0]?.name ?? ""));
    } catch (reason) { setLoginError(reason instanceof Error ? reason.message : "Kunne ikke logge ind"); }
    finally { setAuthBusy(false); }
  };

  const submitForgot = async (event: FormEvent) => { event.preventDefault(); setLoginError(""); setAuthBusy(true); try { const response = await fetch("/api/portal/forgot-password", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ email: login.email }) }); if (!response.ok) throw new Error(); setForgotSent(true); } catch { setLoginError("Kunne ikke sende forespørgslen lige nu. Prøv igen om et øjeblik."); } finally { setAuthBusy(false); } };

  const loadAvailability = useCallback(async (date: string, inspection: string) => {
    if (!inspection) { setDays([]); setAvailabilityLoading(false); setAvailabilityError(false); return; }
    const requestId = ++availabilityRequest.current;
    setAvailabilityLoading(true);
    setAvailabilityError(false);
    const requestedEnd = addDays(date, 14);
    const horizonEnd = addDays(todayInDenmark(), session?.settings.booking_horizon_days ?? 90);
    const rangeEnd = requestedEnd > horizonEnd ? horizonEnd : requestedEnd;
    try {
      const response = await fetch(`/api/portal/availability?inspection=${encodeURIComponent(inspection)}&from=${date}&to=${rangeEnd}`, { cache: "no-store" });
      const data = await response.json().catch(() => ({})) as { days?: Array<{ date: string; availableSlots: string[] }> };
      if (!response.ok) throw new Error(apiError(data, "Tiderne kunne ikke hentes"));
      if (requestId === availabilityRequest.current) setDays(data.days ?? []);
    } catch {
      if (requestId === availabilityRequest.current) { setDays([]); setAvailabilityError(true); }
    } finally {
      if (requestId === availabilityRequest.current) setAvailabilityLoading(false);
    }
  }, [session?.settings.booking_horizon_days]);

  useEffect(() => {
    if (!session?.permissions.canManageBookings) return;
    const timer = window.setTimeout(() => void loadAvailability(form.date, form.inspection), 0);
    return () => window.clearTimeout(timer);
  }, [form.date, form.inspection, loadAvailability, session?.permissions.canManageBookings]);

  const saveBooking = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setNotice("");
    try {
      const response = await fetch(editing ? `/api/portal/bookings/${editing}` : "/api/portal/bookings", { method: editing ? "PATCH" : "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(form) });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(apiError(data, "Bookingen kunne ikke gemmes"));
      const freshForm = emptyForm(session?.bookingTypes?.[0]?.name ?? "");
      const success = editing ? "Bookingen er ændret" : "Bookingen er oprettet";
      setNotice(success); setEditing(null); setForm(freshForm);
      try { await loadDashboard(); await loadAvailability(freshForm.date, freshForm.inspection); }
      catch { setNotice(`${success}, men oversigten kunne ikke opdateres. Genindlæs siden.`); }
    } catch (reason) { setNotice(reason instanceof Error ? reason.message : "Bookingen kunne ikke gemmes"); }
    finally { setSaving(false); }
  };

  const cancel = async (id: string) => { if (!window.confirm("Vil du aflyse denne booking?")) return; try { const response = await fetch(`/api/portal/bookings/${id}`, { method: "DELETE" }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(apiError(data, "Bookingen kunne ikke aflyses")); setNotice("Bookingen er aflyst"); await loadDashboard(); } catch (reason) { setNotice(reason instanceof Error ? reason.message : "Bookingen kunne ikke aflyses"); } };
  const logout = async () => { try { await fetch("/api/portal/logout", { method: "POST" }); } finally { setSession(null); setBookings([]); setInvoices([]); setBilling(null); } };
  const startNewBooking = () => {
    setEditing(null);
    setForm(emptyForm(session?.bookingTypes?.[0]?.name ?? ""));
    window.requestAnimationFrame(() => {
      bookingPanelRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
      window.setTimeout(() => plateInputRef.current?.focus({ preventScroll: true }), 350);
    });
  };

  if (checking) return <main className="business-portal business-auth-page"><div className="business-auth-loading"><span className="business-brand-art" aria-label="Midtjysk Bilsyn" /><p>Henter branchekundeportal…</p></div></main>;
  if (!session) return <main className="business-portal business-auth-page">
    <section className="business-auth-intro" aria-label="Om Midtjysk Bilsyn branchekundeportal">
      <span className="business-brand-art" aria-label="Midtjysk Bilsyn" />
      <div className="business-auth-copy"><p className="business-auth-kicker">BRANCHEKUNDEPORTAL</p><h2>Book syn, når det passer <span>jeres hverdag.</span></h2><p>Et samlet overblik over virksomhedens biler, tider og bookinger hos Midtjysk Bilsyn.</p></div>
      <div className="business-auth-benefits"><span><CalendarDays size={18} /><b>Book og flyt tider</b></span><span><CheckCircle2 size={18} /><b>Fælles bookingoverblik</b></span><span><ShieldCheck size={18} /><b>Sikker virksomhedsadgang</b></span></div>
      <small>© 2026 Midtjysk Bilsyn · Ikast</small>
    </section>
    <section className="business-auth-panel"><form className="business-login" onSubmit={forgotMode ? submitForgot : submitLogin}>
      <span className="business-brand-art business-auth-mobile-brand" aria-label="Midtjysk Bilsyn" />
      <p className="public-eyebrow">BRANCHEKUNDEPORTAL</p><h1>{forgotMode ? "Glemt adgangskode" : "Log ind"}</h1><p>{forgotMode ? "Indtast din e-mail, så sender vi et sikkert nulstillingslink." : "Book og administrér tider for din virksomhed."}</p>
      <label>E-mail<input type="email" autoComplete="email" placeholder="navn@virksomhed.dk" required value={login.email} onChange={(event) => setLogin({ ...login, email: event.target.value })} /></label>
      {!forgotMode && <label>Adgangskode<input type="password" autoComplete="current-password" placeholder="Indtast adgangskode" required value={login.password} onChange={(event) => setLogin({ ...login, password: event.target.value })} /></label>}
      {loginError && <div className="business-error" role="alert">{loginError}</div>}{forgotMode && forgotSent && <div className="business-success" role="status">Hvis kontoen findes, er der sendt et nulstillingslink.</div>}
      {!forgotSent && <button className="business-primary" disabled={authBusy}>{authBusy ? "Arbejder…" : forgotMode ? "Send nulstillingslink" : "Log ind"} <ChevronRight size={17} /></button>}
      {!forgotMode && <button type="button" className="business-link-button" onClick={() => { setForgotMode(true); setLoginError(""); }}>Glemt adgangskode?</button>}{forgotMode && <button type="button" className="business-link-button" onClick={() => { setForgotMode(false); setForgotSent(false); setLoginError(""); }}>Tilbage til login</button>}
      <small className="business-login-help">Har du problemer med adgangen? Kontakt Midtjysk Bilsyn.</small>
    </form></section>
  </main>;

  return <main className="business-portal"><div className="business-portal-shell"><header className="business-portal-header"><div><span className="business-header-brand"><span className="business-brand-art" aria-label="Midtjysk Bilsyn" /></span><div className="business-company"><strong>{session.company.name}</strong><small>Branchekundeportal · {session.user.role === "admin" ? "Administrator" : session.user.role === "read_only" ? "Læseadgang" : "Medarbejder"}</small></div></div><div><span className="business-user">{session.user.name}</span><button className="business-icon-button" onClick={() => void logout()} aria-label="Log ud"><LogOut size={17} /></button></div></header><section className="business-portal-body"><div className="business-portal-heading"><div><p className="public-eyebrow">OVERBLIK</p><h1>Velkommen, {session.user.name.split(" ")[0]}</h1><p>Alle virksomhedens brugere kan følge de samme bookinger.{session.permissions.canViewInvoices ? " Som administrator kan du også se fakturaoplysninger." : ""}</p></div>{session.permissions.canManageBookings && <button className="business-primary" aria-controls="business-booking-panel" onClick={startNewBooking}><Plus size={17} /> Ny booking</button>}</div><div className="business-portal-grid">{session.permissions.canManageBookings && <section id="business-booking-panel" ref={bookingPanelRef} className="business-booking-panel"><div className="business-panel-head"><div><h2>{editing ? "Ændr booking" : "Book en ny tid"}</h2><p>Vælg køretøj og en ledig tid i samme kalender som Midtjysk Bilsyn.</p></div></div>{session.bookingTypes.length === 0 ? <div className="business-time-state error"><strong>Ingen synstyper er åbne for booking</strong><span>Kontakt Midtjysk Bilsyn for at få virksomhedens adgang kontrolleret.</span></div> : <form onSubmit={saveBooking} className="business-booking-form"><div className="business-field-grid"><label>Nummerplade<input ref={plateInputRef} required minLength={5} maxLength={12} value={form.plate} onChange={(event) => setForm({ ...form, plate: event.target.value.toUpperCase() })} placeholder="AB 12 345" /></label><label>Mærke og model<input value={form.vehicle} onChange={(event) => setForm({ ...form, vehicle: event.target.value })} placeholder="Udfyldes fra DMR eller manuelt" /></label><label>Dato<input type="date" min={todayInDenmark()} max={addDays(todayInDenmark(), session.settings.booking_horizon_days)} required value={form.date} onChange={(event) => setForm({ ...form, date: event.target.value, time: "" })} /></label><label>Synstype<select value={form.inspection} onChange={(event) => setForm({ ...form, inspection: event.target.value, time: "" })}>{session.bookingTypes.map((type) => <option key={type.id} value={type.name}>{type.name}</option>)}</select></label></div><div className="business-time-picker"><div className="business-panel-head"><div><h3>Ledige tider</h3><p>{availabilityLoading ? "Henter ledige tider…" : "Vælg en grøn tid"}</p></div><button type="button" disabled={availabilityLoading} onClick={() => void loadAvailability(form.date, form.inspection)}><Search size={15} /> {availabilityLoading ? "Henter…" : "Opdater"}</button></div>{availabilityLoading ? <div className="business-time-state"><span className="notification-loader" /><strong>Henter ledige tider</strong></div> : availabilityError ? <div className="business-time-state error"><strong>Tiderne kunne ikke hentes</strong><span>Tryk på Opdater for at prøve igen.</span></div> : days.every((day) => day.availableSlots.length === 0) ? <div className="business-time-state"><CalendarDays size={22} /><strong>Ingen ledige tider i perioden</strong><span>Vælg en anden dato eller synstype.</span></div> : <div className="business-time-grid">{days.flatMap((day) => day.availableSlots.map((time) => <button type="button" key={`${day.date}-${time}`} className={form.date === day.date && form.time === time ? "selected" : ""} onClick={() => setForm({ ...form, date: day.date, time })}><span>{formatDate(day.date)}</span><strong>{time}</strong></button>))}</div>}</div>{session.settings.requisition_requirement !== "hidden" && <div className="business-field-grid"><label>Rekvisitionsnummer<input required={session.settings.requisition_requirement === "required"} value={form.requisitionNumber} onChange={(event) => setForm({ ...form, requisitionNumber: event.target.value })} placeholder={session.settings.requisition_requirement === "required" ? "Påkrævet" : "Valgfrit"} /></label><label>Kontaktperson<input value={form.contactName} onChange={(event) => setForm({ ...form, contactName: event.target.value })} /></label></div>}<label>Bemærkning<textarea value={form.customerNote} onChange={(event) => setForm({ ...form, customerNote: event.target.value })} maxLength={1000} /></label><div className="business-form-actions"><button type="button" className="business-secondary" onClick={startNewBooking}><X size={15} /> Ryd</button><button className="business-primary" disabled={saving || !form.time}>{saving ? "Gemmer…" : editing ? "Gem ændring" : "Bekræft booking"}</button></div></form>}</section>}<aside className={`business-upcoming ${session.permissions.canManageBookings ? "" : "business-readonly-panel"}`}><div className="business-panel-head"><div><h2>Kommende bookinger</h2><p>{bookings.length} bookinger i alt</p></div><CalendarDays size={20} /></div>{bookings.slice(0, 12).map((booking) => <article className="business-booking-card" key={booking.id}><div><strong>{booking.plate}</strong><span>{booking.inspection} · {booking.vehicle || "Køretøj"}</span><small>{formatDate(booking.date)} kl. {booking.time}</small></div>{session.permissions.canManageBookings && <div><button onClick={() => { setEditing(booking.id); setForm({ plate: booking.plate, vehicle: booking.vehicle, date: booking.date, time: booking.time, inspection: booking.inspection, requisitionNumber: booking.requisitionNumber ?? "", contactName: booking.contactName ?? "", customerNote: booking.note ?? "" }); window.requestAnimationFrame(() => bookingPanelRef.current?.scrollIntoView({ behavior: "smooth", block: "start" })); }}>Redigér</button><button onClick={() => void cancel(booking.id)}>Aflys</button></div>}</article>)}{bookings.length === 0 && <p className="business-empty">Ingen kommende bookinger.</p>}</aside></div>{session.permissions.canViewInvoices && <section className="business-invoices"><div className="business-panel-head"><div><h2>Fakturaoplysninger</h2><p>Kun synligt for virksomhedens administratorer</p></div><FileText size={20} /></div><div className="business-billing-summary"><span><small>Faktura-e-mail</small><strong>{billing?.invoice_email || "Ikke angivet"}</strong></span><span><small>Betalingsform</small><strong>{billing?.billing_method || "Ikke angivet"}</strong></span><span><small>Betalingsbetingelser</small><strong>{billing?.payment_terms || "Ikke angivet"}</strong></span></div><div className="business-invoice-list">{invoices.map((invoice) => <article key={invoice.id}><span><strong>{invoice.period}</strong><small>{invoice.status} · {invoice.externalStatus}</small></span><b>{new Intl.NumberFormat("da-DK", { style: "currency", currency: "DKK" }).format(invoice.amountOre / 100)}</b></article>)}{invoices.length === 0 && <p className="business-empty">Der er endnu ingen fakturaer at vise.</p>}</div></section>}</section>{notice && <div className="business-notice" role="status">{notice}</div>}</div></main>;
}
