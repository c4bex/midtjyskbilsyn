"use client";

import {
  Bell,
  Building2,
  CalendarDays,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Clock3,
  FileText,
  MessageSquare,
  Menu,
  MoreHorizontal,
  Plus,
  Search,
  ScanSearch,
  Settings,
  ShieldCheck,
  UserRound,
  Users,
  X,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { AvailabilityView } from "./availability-view";
import { CustomersView } from "./customers-view";
import { SmsSettingsView } from "./sms-settings-view";
import { InvoiceView } from "./invoice-view";
import { EmployeesView } from "./employees-view";
import { DriftView } from "./drift-view";

type CustomerType = "private" | "business";
type BookingStatus = "confirmed" | "arrived" | "awaiting_confirmation" | "completed";

type Booking = {
  id: string;
  date: string;
  time: string;
  customer: string;
  customerType: CustomerType;
  plate: string;
  vehicle: string;
  inspection: string;
  status: BookingStatus;
};

type CustomerOption = { id: string; name: string; customerType: CustomerType; vehicles: Array<{ id: string; plate: string; vehicle: string }> };
type VehicleLookup = { found: boolean; source: string; vehicle?: { registration: string; make: string | null; model: string | null }; customer?: { name: string; customerType: CustomerType }; lastInspectionDate?: string | null; inspectionDueDate?: string | null; dmr: { enabled: boolean; status: string } };
type WeekDay = { date: string; weekday: number; closed: boolean; totalSlots: number; bookedSlots: number; availableSlots: string[] };
type SmsTemplate = "booking_confirmation" | "booking_reminder" | "booking_changed" | "booking_cancelled";

const nav = [
  { id: "bookings", label: "Dagens bookinger", icon: CalendarDays },
  { id: "customers", label: "Kunder & køretøjer", icon: Users },
  { id: "invoices", label: "Fakturering", icon: FileText, badge: "4" },
];

const initialBookings: Booking[] = [
  { id: "demo-booking-1", date: "2026-08-04", time: "08:00", customer: "Jysk VVS ApS", customerType: "business", plate: "CF 45 821", vehicle: "Ford Transit", inspection: "Periodisk syn", status: "completed" },
  { id: "demo-booking-2", date: "2026-08-04", time: "08:20", customer: "Maja Holm", customerType: "private", plate: "AB 12 345", vehicle: "VW Golf", inspection: "Periodisk syn", status: "completed" },
  { id: "demo-booking-3", date: "2026-08-04", time: "08:40", customer: "Thomas Dahl", customerType: "private", plate: "DL 76 119", vehicle: "Tesla Model 3", inspection: "Omsyn", status: "arrived" },
  { id: "demo-booking-5", date: "2026-08-04", time: "09:20", customer: "Anne Skov", customerType: "private", plate: "EH 22 604", vehicle: "Peugeot 208", inspection: "Periodisk syn", status: "confirmed" },
  { id: "demo-booking-6", date: "2026-08-04", time: "09:40", customer: "Murerfirma Lund", customerType: "business", plate: "FA 91 037", vehicle: "Mercedes Sprinter", inspection: "Varebilssyn", status: "confirmed" },
  { id: "demo-booking-7", date: "2026-08-04", time: "10:00", customer: "Søren Bech", customerType: "private", plate: "GB 18 530", vehicle: "Skoda Enyaq", inspection: "Periodisk syn", status: "awaiting_confirmation" },
  { id: "demo-booking-8", date: "2026-08-04", time: "10:20", customer: "Lone Madsen", customerType: "private", plate: "HR 63 044", vehicle: "Toyota Yaris", inspection: "Omsyn", status: "confirmed" },
  { id: "demo-booking-9", date: "2026-08-04", time: "10:40", customer: "Fjord Transport", customerType: "business", plate: "JK 37 995", vehicle: "Iveco Daily", inspection: "Varebilssyn", status: "confirmed" },
  { id: "demo-booking-10", date: "2026-08-04", time: "11:00", customer: "Emil Nygaard", customerType: "private", plate: "KT 40 188", vehicle: "Volvo XC40", inspection: "Periodisk syn", status: "confirmed" },
  { id: "demo-booking-11", date: "2026-08-04", time: "11:40", customer: "Line Friis", customerType: "private", plate: "LP 88 271", vehicle: "Kia Niro", inspection: "Periodisk syn", status: "confirmed" },
  { id: "demo-booking-12", date: "2026-08-04", time: "12:00", customer: "Niels Bak", customerType: "private", plate: "MR 51 620", vehicle: "Audi A4", inspection: "Omsyn", status: "confirmed" },
];

const dayNames = ["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag", "Lørdag", "Søndag"];
const addDays = (date: string, amount: number) => { const value = new Date(`${date}T12:00:00Z`); value.setUTCDate(value.getUTCDate() + amount); return value.toISOString().slice(0, 10); };
const dayNumber = (date: string) => Number(date.slice(-2));
const monthName = (date: string) => new Intl.DateTimeFormat("da-DK", { month: "short", timeZone: "Europe/Copenhagen" }).format(new Date(`${date}T12:00:00Z`)).replace(".", "");

const statusText: Record<BookingStatus, string> = {
  confirmed: "Bekræftet",
  arrived: "Ankommet",
  awaiting_confirmation: "Afventer",
  completed: "Færdig",
};

const emptyForm = { date: "2026-08-04", time: "11:20", customer: "", customerType: "private" as CustomerType, plate: "", vehicle: "", inspection: "Periodisk syn" };

export function Dashboard() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [activeView, setActiveView] = useState<"bookings" | "customers" | "availability" | "sms" | "invoices" | "employees" | "drift">("bookings");
  const [filter, setFilter] = useState<"alle" | CustomerType>("alle");
  const [modalOpen, setModalOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [bookings, setBookings] = useState(initialBookings);
  const [availableSlots, setAvailableSlots] = useState(["11:20", "14:20"]);
  const [modalSlots, setModalSlots] = useState(["11:20", "14:20"]);
  const [selectedBooking, setSelectedBooking] = useState<Booking | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [customerOptions, setCustomerOptions] = useState<CustomerOption[]>([]);
  const [businessQuery, setBusinessQuery] = useState("");
  const [vehicleLookup, setVehicleLookup] = useState<VehicleLookup | null>(null);
  const [lookupLoading, setLookupLoading] = useState(false);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [smsTemplate, setSmsTemplate] = useState<SmsTemplate>("booking_confirmation");
  const [smsPhone, setSmsPhone] = useState("");
  const [weekStart, setWeekStart] = useState("2026-08-03");
  const [selectedDate, setSelectedDate] = useState("2026-08-04");
  const [weekNumber, setWeekNumber] = useState(32);
  const [weekDays, setWeekDays] = useState<WeekDay[]>([
    { date: "2026-08-03", weekday: 1, closed: false, totalSlots: 23, bookedSlots: 0, availableSlots: [] },
    { date: "2026-08-04", weekday: 2, closed: false, totalSlots: 23, bookedSlots: 21, availableSlots: ["11:20", "14:20"] },
  ]);
  const [weekLoading, setWeekLoading] = useState(true);

  const visibleBookings = filter === "alle" ? bookings : bookings.filter((booking) => booking.customerType === filter);
  const privateCount = bookings.filter((booking) => booking.customerType === "private").length;
  const businessCount = bookings.length - privateCount;
  const matchingBusinesses = customerOptions.filter((customer) => customer.customerType === "business" && `${customer.name} ${customer.vehicles.map((vehicle) => vehicle.plate).join(" ")}`.toLowerCase().includes(businessQuery.toLowerCase())).slice(0, 6);
  const timeChoices = [...new Set([...(selectedBooking && form.time ? [form.time] : []), ...modalSlots])];
  const smsTemplates: Record<SmsTemplate, { label: string; text: string }> = {
    booking_confirmation: { label: "Bookingbekræftelse", text: `Hej ${form.customer || "kunde"}. Din tid hos Midtjysk Bilsyn er ${form.date} kl. ${form.time || "--:--"}. Svar gerne på denne SMS ved spørgsmål.` },
    booking_reminder: { label: "Påmindelse", text: `Påmindelse: Du har tid hos Midtjysk Bilsyn ${form.date} kl. ${form.time || "--:--"}. Husk registreringsnummeret på bilen.` },
    booking_changed: { label: "Booking ændret", text: `Din tid hos Midtjysk Bilsyn er ændret til ${form.date} kl. ${form.time || "--:--"}.` },
    booking_cancelled: { label: "Booking aflyst", text: `Din tid hos Midtjysk Bilsyn ${form.date} kl. ${form.time || "--:--"} er aflyst. Kontakt os, hvis du vil finde en ny tid.` },
  };

  const flash = useCallback((message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 2600);
  }, []);

  const navigate = (view: string) => {
    setActiveView(view as "bookings" | "customers" | "availability" | "sms" | "invoices" | "employees" | "drift");
    setMenuOpen(false);
    setModalOpen(false);
  };

  const reloadBookings = async (date = selectedDate) => {
    try {
      const response = await fetch(`/api/bookings?date=${encodeURIComponent(date)}`, { cache: "no-store" });
      if (!response.ok) throw new Error("Kunne ikke hente bookinger");
      const data = await response.json() as { bookings: Booking[]; availableSlots: string[] };
      setBookings(data.bookings);
      setAvailableSlots(data.availableSlots);
    } catch {
      flash("Kunne ikke hente databasen — viser seneste lokale oversigt");
    }
  };

  useEffect(() => {
    let active = true;
    fetch(`/api/bookings?date=${encodeURIComponent(selectedDate)}`, { cache: "no-store" })
      .then((response) => {
        if (!response.ok) throw new Error("Kunne ikke hente bookinger");
        return response.json() as Promise<{ bookings: Booking[]; availableSlots: string[] }>;
      })
      .then((data) => {
        if (!active) return;
        setBookings(data.bookings);
        setAvailableSlots(data.availableSlots);
      })
      .catch(() => {
        if (!active) return;
        setNotice("Kunne ikke hente databasen — viser seneste lokale oversigt");
        window.setTimeout(() => setNotice(""), 2600);
      });
    return () => { active = false; };
  }, [selectedDate]);

  useEffect(() => {
    let active = true;
    fetch(`/api/calendar/week?start=${weekStart}`, { cache: "no-store" })
      .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ week: number; days: WeekDay[] }>; })
      .then((data) => { if (active) { setWeekNumber(data.week); setWeekDays(data.days); } })
      .catch(() => { if (active) flash("Ugekapaciteten kunne ikke hentes"); })
      .finally(() => { if (active) setWeekLoading(false); });
    return () => { active = false; };
  }, [flash, weekStart]);

  useEffect(() => {
    if (!modalOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setModalOpen(false);
    };
    window.addEventListener("keydown", closeOnEscape);
    return () => window.removeEventListener("keydown", closeOnEscape);
  }, [modalOpen]);

  const changeWeek = (days: number) => {
    setWeekLoading(true);
    setWeekStart((current) => addDays(current, days));
  };

  useEffect(() => {
    let active = true;
    fetch("/api/customers", { cache: "no-store" })
      .then((response) => response.ok ? response.json() as Promise<{ customers: CustomerOption[] }> : Promise.reject())
      .then((data) => { if (active) setCustomerOptions(data.customers); })
      .catch(() => undefined);
    return () => { active = false; };
  }, []);

  const loadSlots = async (date: string) => {
    setSlotsLoading(true);
    try {
      const response = await fetch(`/api/bookings?date=${encodeURIComponent(date)}`, { cache: "no-store" });
      const data = await response.json() as { availableSlots?: string[] };
      if (response.ok) setModalSlots(data.availableSlots ?? []);
    } finally { setSlotsLoading(false); }
  };

  const lookupPlate = async (plate: string) => {
    const normalized = plate.toUpperCase().replace(/[^A-ZÆØÅ0-9]/g, "");
    if (normalized.length < 5) return;
    setLookupLoading(true);
    try {
      const response = await fetch(`/api/vehicles/lookup?plate=${encodeURIComponent(plate)}`, { cache: "no-store" });
      const data = await response.json() as VehicleLookup;
      if (!response.ok) throw new Error();
      setVehicleLookup(data);
      if (data.found && data.vehicle) {
        setForm((current) => ({
          ...current,
          plate: data.vehicle?.registration ?? current.plate,
          vehicle: [data.vehicle?.make, data.vehicle?.model].filter(Boolean).join(" "),
          customer: current.customer || data.customer?.name || "",
          customerType: current.customer ? current.customerType : data.customer?.customerType ?? current.customerType,
        }));
      }
    } catch { setVehicleLookup(null); flash("Nummerpladen kunne ikke slås op"); }
    finally { setLookupLoading(false); }
  };

  const openCreate = (time = availableSlots[0] ?? "08:00", date = selectedDate, slots = availableSlots) => {
    setSelectedBooking(null);
    setForm({ ...emptyForm, date, time });
    setModalSlots(slots);
    setBusinessQuery("");
    setVehicleLookup(null);
    setSmsTemplate("booking_confirmation");
    setSmsPhone("");
    setModalOpen(true);
  };

  const selectDay = (day: WeekDay) => {
    if (day.closed) return flash(`${dayNames[day.weekday - 1]} er lukket`);
    setSelectedDate(day.date);
  };

  const openEdit = (booking: Booking) => {
    setSelectedBooking(booking);
    setForm({ date: booking.date, time: booking.time, customer: booking.customer, customerType: booking.customerType, plate: booking.plate, vehicle: booking.vehicle, inspection: booking.inspection });
    setModalSlots(availableSlots);
    setBusinessQuery(booking.customerType === "business" ? booking.customer : "");
    setVehicleLookup(null);
    setSmsTemplate("booking_confirmation");
    setSmsPhone("");
    setModalOpen(true);
  };

  const chooseBusiness = (customer: CustomerOption) => {
    const vehicle = customer.vehicles[0];
    setBusinessQuery(customer.name);
    setForm((current) => ({ ...current, customer: customer.name, customerType: "business", plate: vehicle?.plate ?? "", vehicle: vehicle?.vehicle ?? "" }));
    setVehicleLookup(null);
    if (vehicle?.plate) void lookupPlate(vehicle.plate);
  };

  const saveBooking = async () => {
    setSaving(true);
    try {
      const response = await fetch(selectedBooking ? `/api/bookings/${selectedBooking.id}` : "/api/bookings", {
        method: selectedBooking ? "PATCH" : "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ ...form, phone: smsPhone }),
      });
      const result = await response.json() as { error?: string };
      if (!response.ok) throw new Error(result.error ?? "Bookingen kunne ikke gemmes");
      setModalOpen(false);
      await reloadBookings();
      flash(selectedBooking ? "Bookingen er opdateret" : "Bookingen er oprettet");
    } catch (error) {
      flash(error instanceof Error ? error.message : "Bookingen kunne ikke gemmes");
    } finally {
      setSaving(false);
    }
  };

  const cancelBooking = async () => {
    if (!selectedBooking) return;
    setSaving(true);
    try {
      const response = await fetch(`/api/bookings/${selectedBooking.id}`, {
        method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ action: "cancel" }),
      });
      if (!response.ok) throw new Error("Bookingen kunne ikke aflyses");
      setModalOpen(false);
      await reloadBookings();
      flash("Bookingen er aflyst, og tiden er ledig igen");
    } catch (error) {
      flash(error instanceof Error ? error.message : "Bookingen kunne ikke aflyses");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="app-shell">
      <aside className={`sidebar ${menuOpen ? "sidebar-open" : ""}`}>
        <div className="brand">
          <div className="brand-logo"><img src="/midtjysk-bilsyn-logo.png" alt="Midtjysk Bilsyn" /></div>
          <button className="mobile-close" aria-label="Luk menu" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <nav aria-label="Primær navigation">
          <p className="nav-label">Drift</p>
          {nav.map((item) => {
            const Icon = item.icon;
            return (
              <button key={item.label} className={`nav-item ${activeView === item.id ? "active" : ""}`} onClick={() => navigate(item.id)}>
                <Icon size={19} strokeWidth={1.8} /><span>{item.label}</span>{item.badge && <em>{item.badge}</em>}
              </button>
            );
          })}
          <p className="nav-label nav-section">Administration</p>
          <button className={`nav-item ${activeView === "employees" ? "active" : ""}`} onClick={() => navigate("employees")}><ShieldCheck size={19} strokeWidth={1.8} /><span>Medarbejdere</span></button>
          <button className={`nav-item ${activeView === "availability" ? "active" : ""}`} onClick={() => navigate("availability")}><Settings size={19} strokeWidth={1.8} /><span>Åbningstider</span></button>
          <button className={`nav-item ${activeView === "drift" ? "active" : ""}`} onClick={() => navigate("drift")}><CheckCircle2 size={19} strokeWidth={1.8} /><span>Driftsoverblik</span></button>
          <button className={`nav-item ${activeView === "sms" ? "active" : ""}`} onClick={() => navigate("sms")}><MessageSquare size={19} strokeWidth={1.8} /><span>SMS-indstillinger</span></button>
        </nav>
        <div className="sidebar-status">
          <div className="status-line"><i /><span>Systemet kører normalt</span></div>
          <small>Senest kontrolleret 10:58</small>
        </div>
        <button className="profile" onClick={() => flash("Profilmenu kommer i næste etape")}>
          <span className="avatar">RM</span><span><strong>Rasmus M.</strong><small>Administrator</small></span><ChevronDown size={16} />
        </button>
      </aside>

      {menuOpen && <button className="scrim" aria-label="Luk menu" onClick={() => setMenuOpen(false)} />}

      <main>
        <header className="topbar">
          <button className="menu-button" aria-label="Åbn menu" onClick={() => setMenuOpen(true)}><Menu size={22} /></button>
          <div className="location"><span>Ikast</span></div>
          <div className="top-actions">
            <button className="icon-button" aria-label="Søg" onClick={() => flash("Søgning bliver tilføjet i næste etape")}><Search size={19} /></button>
            <button className="icon-button notification" aria-label="Notifikationer" onClick={() => flash("Du har 2 nye driftsbeskeder")}><Bell size={19} /><i /></button>
          </div>
        </header>

        <div className="workspace">
          {activeView === "customers" ? <CustomersView onNotify={flash} /> : activeView === "availability" ? <AvailabilityView onNotify={flash} /> : activeView === "sms" ? <SmsSettingsView onNotify={flash} /> : activeView === "invoices" ? <InvoiceView onNotify={flash} /> : activeView === "employees" ? <EmployeesView onNotify={flash} /> : activeView === "drift" ? <DriftView /> : <>
          <section className="page-heading">
            <div>
              <p className="eyebrow">{dayNames[new Date(`${selectedDate}T12:00:00Z`).getUTCDay() === 0 ? 6 : new Date(`${selectedDate}T12:00:00Z`).getUTCDay() - 1]} · {dayNumber(selectedDate)}. {monthName(selectedDate)} 2026</p>
              <h1>Dagens bookinger</h1>
              <p>Hurtigt overblik over hvem og hvad der kommer i dag.</p>
            </div>
            <div className="heading-actions"><div className="heading-stats"><span><strong>{bookings.length}</strong> bookinger</span><span><strong>{availableSlots.length}</strong> ledige</span></div><button className="primary-button" onClick={() => openCreate()}><Plus size={18} /> Ny booking</button></div>
          </section>

          <section className="week-capacity" aria-label={`Kapacitet for uge ${weekNumber}`}>
            <div className="week-capacity-head">
              <div className="week-identity"><span>UGE</span><strong>{weekNumber}</strong></div>
              <div><h2>Ledige tider denne uge</h2><p>{dayNumber(weekStart)}. {monthName(weekStart)} – {dayNumber(addDays(weekStart, 6))}. {monthName(addDays(weekStart, 6))}</p></div>
              <div className="week-navigation"><button aria-label="Forrige uge" onClick={() => changeWeek(-7)}><ChevronLeft size={18} /></button><button onClick={() => { setWeekLoading(true); setWeekStart("2026-08-03"); }}>Denne uge</button><button aria-label="Næste uge" onClick={() => changeWeek(7)}><ChevronRight size={18} /></button></div>
            </div>
            <div className={`capacity-days ${weekLoading ? "loading" : ""}`}>
              {weekDays.map((day) => {
                const available = day.availableSlots.length;
                const fullness = day.totalSlots ? Math.round(day.bookedSlots / day.totalSlots * 100) : 0;
                const today = day.date === "2026-08-04";
                return <button key={day.date} disabled={weekLoading || day.closed} className={`${day.closed ? "closed" : available > 0 ? "available" : "full"} ${today ? "today" : ""} ${selectedDate === day.date ? "selected-day" : ""}`} onClick={() => selectDay(day)} aria-pressed={selectedDate === day.date}>
                  <span className="capacity-day-name">{dayNames[day.weekday - 1]}{today && <em>I dag</em>}</span>
                  <strong>{dayNumber(day.date)}</strong><small>{monthName(day.date)}</small>
                  {day.closed ? <span className="capacity-status">Lukket</span> : <><span className="capacity-status"><b>{available}</b> ledige</span><span className="capacity-bar"><i style={{ width: `${fullness}%` }} /></span></>}
                </button>;
              })}
            </div>
            <div className="week-capacity-foot"><span><i className="green-dot" /> Klik på en grøn dag for at booke</span><span><i className="gray-dot" /> Lukket</span></div>
          </section>

          <div className="day-layout">
            <section className="booking-list-card">
              <div className="list-toolbar">
                <div><h2>{dayNames[new Date(`${selectedDate}T12:00:00Z`).getUTCDay() === 0 ? 6 : new Date(`${selectedDate}T12:00:00Z`).getUTCDay() - 1]} den {dayNumber(selectedDate)}. {monthName(selectedDate)}</h2><span>Sorteret efter tidspunkt</span></div>
                <div className="customer-filters" role="group" aria-label="Filtrer efter kundetype">
                  <button className={filter === "alle" ? "selected" : ""} onClick={() => setFilter("alle")}>Alle <span>{bookings.length}</span></button>
                  <button className={filter === "private" ? "selected" : ""} onClick={() => setFilter("private")}>Private <span>{privateCount}</span></button>
                  <button className={filter === "business" ? "selected" : ""} onClick={() => setFilter("business")}>Erhverv <span>{businessCount}</span></button>
                </div>
              </div>

              <div className="booking-table" role="table" aria-label="Dagens bookinger">
                <div className="table-head" role="row">
                  <span role="columnheader">Tid</span><span role="columnheader">Kunde</span><span role="columnheader">Bil</span><span role="columnheader">Syn</span><span role="columnheader">Status</span><span />
                </div>
                <div className="table-body">
                  {visibleBookings.map((booking) => (
                    <button className="booking-row" role="row" key={booking.id} onClick={() => openEdit(booking)}>
                      <span className="row-time" role="cell">{booking.time}</span>
                      <span className="row-customer" role="cell">
                        <i className={booking.customerType}>{booking.customerType === "business" ? <Building2 size={14} /> : <UserRound size={14} />}</i>
                        <span><strong>{booking.customer}</strong><small>{booking.customerType === "business" ? "Erhverv" : "Privat"}</small></span>
                      </span>
                      <span className="row-vehicle" role="cell"><strong>{booking.plate}</strong><small>{booking.vehicle}</small></span>
                      <span className="row-inspection" role="cell">{booking.inspection}</span>
                      <span role="cell"><em className={`status ${booking.status}`}>{booking.status === "completed" && <Check size={12} />}{statusText[booking.status]}</em></span>
                      <span className="row-action" role="cell"><MoreHorizontal size={18} /></span>
                    </button>
                  ))}
                </div>
              </div>
            </section>

            <aside className="day-aside">
              <section className="available-card">
                <div className="aside-title"><span className="aside-icon"><Clock3 size={18} /></span><div><h2>Ledige tider</h2><p>{selectedDate === "2026-08-04" ? "I dag" : `${dayNumber(selectedDate)}. ${monthName(selectedDate)}`}</p></div></div>
                <div className="available-times">
                  {availableSlots.map((time) => <button key={time} onClick={() => openCreate(time)}>{time} <Plus size={15} /></button>)}
                  {availableSlots.length === 0 && <p className="no-slots">Ingen ledige tider</p>}
                </div>
              </section>
            </aside>
          </div>
          </>}
        </div>
      </main>

      {notice && <div className="toast" role="status">{notice}</div>}

      {modalOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={() => setModalOpen(false)}>
          <section className="modal smart-booking-modal" role="dialog" aria-modal="true" aria-labelledby="new-booking-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-heading"><div><span>{selectedBooking ? "Rediger aftale" : "Ny aftale"}</span><h2 id="new-booking-title">{selectedBooking ? `${selectedBooking.time} · ${selectedBooking.customer}` : "Opret booking"}</h2></div><button aria-label="Luk" onClick={() => setModalOpen(false)}><X size={20} /></button></div>
            <div className="booking-steps">
              <section className="booking-step">
                <div className="step-title"><em>1</em><div><strong>Vælg kunde</strong><span>Privat eller en af dine erhvervskunder</span></div></div>
                <div className="type-choice">
                  <button className={form.customerType === "private" ? "selected" : ""} onClick={() => { setForm({ ...form, customerType: "private", customer: "" }); setBusinessQuery(""); }}><UserRound size={16} /><span><strong>Privatkunde</strong><small>Ny eller eksisterende</small></span></button>
                  <button className={form.customerType === "business" ? "selected business" : "business"} onClick={() => setForm({ ...form, customerType: "business", customer: "" })}><Building2 size={16} /><span><strong>Erhvervskunde</strong><small>Vælg fra kundelisten</small></span></button>
                </div>
                {form.customerType === "private" ? (
                  <label className="smart-field">Kundens navn<input autoFocus={!selectedBooking} value={form.customer} onChange={(event) => setForm({ ...form, customer: event.target.value })} placeholder="Skriv navn" /></label>
                ) : (
                  <div className="business-select">
                    <label className="smart-field">Find erhvervskunde<span className="field-with-icon"><Search size={16} /><input value={businessQuery} onChange={(event) => { setBusinessQuery(event.target.value); setForm({ ...form, customer: "" }); }} placeholder="Søg virksomhed eller nummerplade" /></span></label>
                    <div className="business-options">
                      {matchingBusinesses.map((customer) => <button className={form.customer === customer.name ? "selected" : ""} key={customer.id} onClick={() => chooseBusiness(customer)}><span className="company-avatar">{customer.name.slice(0, 2).toUpperCase()}</span><span><strong>{customer.name}</strong><small>{customer.vehicles.map((vehicle) => `${vehicle.plate} · ${vehicle.vehicle}`).join(" · ")}</small></span>{form.customer === customer.name && <CheckCircle2 size={17} />}</button>)}
                    </div>
                  </div>
                )}
              </section>

              <section className="booking-step">
                <div className="step-title"><em>2</em><div><strong>Køretøj</strong><span>Nummerpladen udfylder bilen automatisk</span></div></div>
                <div className="plate-grid">
                  <label className="smart-field">Registreringsnummer<span className="field-with-button"><input value={form.plate} onChange={(event) => { const plate = event.target.value.toUpperCase(); setForm({ ...form, plate }); setVehicleLookup(null); if (plate.replace(/[^A-ZÆØÅ0-9]/g, "").length === 7) void lookupPlate(plate); }} onBlur={() => void lookupPlate(form.plate)} placeholder="AB 12 345" /><button aria-label="Slå nummerplade op" onClick={() => void lookupPlate(form.plate)}><ScanSearch size={17} /></button></span></label>
                  <label className="smart-field">Mærke og model<input value={form.vehicle} onChange={(event) => setForm({ ...form, vehicle: event.target.value })} placeholder="Udfyldes automatisk" /></label>
                </div>
                {lookupLoading && <div className="lookup-card loading"><span className="lookup-spinner" /><span><strong>Slår nummerpladen op…</strong><small>Først i kundearkivet, senere også i DMR</small></span></div>}
                {!lookupLoading && vehicleLookup?.found && <div className="lookup-card found"><CheckCircle2 size={18} /><span><strong>{vehicleLookup.vehicle?.make} {vehicleLookup.vehicle?.model} fundet</strong><small>Seneste syn: {vehicleLookup.lastInspectionDate ?? "Ikke registreret"} · Næste synsdato hentes fra DMR ved tilkobling</small></span><em>Egne data</em></div>}
                {!lookupLoading && vehicleLookup && !vehicleLookup.found && <div className="lookup-card"><ScanSearch size={18} /><span><strong>Ikke fundet i egne data</strong><small>Mærke, model og synsdato kan hentes automatisk, når DMR-adapteren aktiveres.</small></span><em>DMR ikke tilkoblet</em></div>}
              </section>

              <section className="booking-step time-step">
                <div className="step-title"><em>3</em><div><strong>Vælg en ledig tid</strong><span>Kun tider der kan bookes vises</span></div></div>
                <div className="date-and-type">
                  <label className="smart-field">Dato<input type="date" value={form.date} onChange={(event) => { const date = event.target.value; setForm({ ...form, date, time: "" }); void loadSlots(date); }} /></label>
                  <label className="smart-field">Synstype<select value={form.inspection} onChange={(event) => setForm({ ...form, inspection: event.target.value })}><option>Periodisk syn</option><option>Omsyn</option><option>Varebilssyn</option><option>Motorcykelsyn</option></select></label>
                </div>
                <div className="slot-heading"><span>{slotsLoading ? "Henter ledige tider…" : `${modalSlots.length} ledige tider`}</span><small>20 min. pr. booking</small></div>
                <div className="booking-slots">
                  {timeChoices.map((time) => <button className={form.time === time ? "selected" : ""} key={time} onClick={() => setForm({ ...form, time })}><Clock3 size={14} />{time}{form.time === time && <Check size={13} />}</button>)}
                  {!slotsLoading && timeChoices.length === 0 && <p>Ingen ledige tider denne dag.</p>}
                </div>
              </section>
            </div>
            <section className="sms-panel" aria-label="SMS til kunden">
              <div className="sms-panel-heading"><div><span className="sms-label">SMS</span><div><strong>Send besked til kunden</strong><small>GatewayAPI klargjort — afsendelse aktiveres senere</small></div></div><span className="sms-status">Ikke aktiveret</span></div>
              <div className="sms-controls"><label className="smart-field">Telefonnummer<input value={smsPhone} onChange={(event) => setSmsPhone(event.target.value)} placeholder="+45 20 12 34 56" /></label><label className="smart-field">Skabelon<select value={smsTemplate} onChange={(event) => setSmsTemplate(event.target.value as SmsTemplate)}>{Object.entries(smsTemplates).map(([key, template]) => <option key={key} value={key}>{template.label}</option>)}</select></label></div>
              <div className="sms-preview"><span>Forhåndsvisning</span><p>{smsTemplates[smsTemplate].text}</p><small>{smsTemplates[smsTemplate].text.length}/1600 tegn</small></div>
              <button className="sms-send" disabled type="button" onClick={() => flash("GatewayAPI er ikke aktiveret endnu")}>Send SMS</button>
            </section>
            <div className="modal-actions">
              {selectedBooking && <button className="danger-button" disabled={saving} onClick={() => void cancelBooking()}>Aflys booking</button>}
              <button className="secondary-button" disabled={saving} onClick={() => setModalOpen(false)}>Luk</button>
              <button className="primary-button" disabled={saving} onClick={() => void saveBooking()}>{saving ? "Gemmer…" : selectedBooking ? "Gem ændringer" : "Opret booking"}</button>
            </div>
          </section>
        </div>
      )}
    </div>
  );
}
