"use client";

import {
  Bell,
  Building2,
  CalendarDays,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleHelp,
  Clock3,
  FileText,
  Menu,
  MoreHorizontal,
  Plus,
  Search,
  Settings,
  ShieldCheck,
  UserRound,
  Users,
  X,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { AvailabilityView } from "./availability-view";
import { CustomersView } from "./customers-view";

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

const weeks = [
  { day: "Man", date: "3", count: 9 },
  { day: "Tir", date: "4", count: 21, active: true },
  { day: "Ons", date: "5", count: 8 },
  { day: "Tor", date: "6", count: 10 },
  { day: "Fre", date: "7", count: 7 },
];

const statusText: Record<BookingStatus, string> = {
  confirmed: "Bekræftet",
  arrived: "Ankommet",
  awaiting_confirmation: "Afventer",
  completed: "Færdig",
};

const emptyForm = { date: "2026-08-04", time: "11:20", customer: "", customerType: "private" as CustomerType, plate: "", vehicle: "", inspection: "Periodisk syn" };

export function Dashboard() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [activeView, setActiveView] = useState<"bookings" | "customers" | "availability">("bookings");
  const [filter, setFilter] = useState<"alle" | CustomerType>("alle");
  const [modalOpen, setModalOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const [bookings, setBookings] = useState(initialBookings);
  const [availableSlots, setAvailableSlots] = useState(["11:20", "14:20"]);
  const [selectedBooking, setSelectedBooking] = useState<Booking | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const visibleBookings = filter === "alle" ? bookings : bookings.filter((booking) => booking.customerType === filter);
  const privateCount = bookings.filter((booking) => booking.customerType === "private").length;
  const businessCount = bookings.length - privateCount;
  const completedCount = bookings.filter((booking) => booking.status === "completed").length;

  const flash = useCallback((message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 2600);
  }, []);

  const navigate = (view: string) => {
    if (view === "invoices") return flash("Fakturering åbnes i en kommende etape");
    setActiveView(view as "bookings" | "customers" | "availability");
    setMenuOpen(false);
    setModalOpen(false);
  };

  const reloadBookings = async () => {
    try {
      const response = await fetch("/api/bookings?date=2026-08-04", { cache: "no-store" });
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
    fetch("/api/bookings?date=2026-08-04", { cache: "no-store" })
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
  }, []);

  const openCreate = (time = availableSlots[0] ?? "08:00") => {
    setSelectedBooking(null);
    setForm({ ...emptyForm, time });
    setModalOpen(true);
  };

  const openEdit = (booking: Booking) => {
    setSelectedBooking(booking);
    setForm({ date: booking.date, time: booking.time, customer: booking.customer, customerType: booking.customerType, plate: booking.plate, vehicle: booking.vehicle, inspection: booking.inspection });
    setModalOpen(true);
  };

  const saveBooking = async () => {
    setSaving(true);
    try {
      const response = await fetch(selectedBooking ? `/api/bookings/${selectedBooking.id}` : "/api/bookings", {
        method: selectedBooking ? "PATCH" : "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(form),
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
          <div className="brand-mark"><span>MB</span></div>
          <div><strong>Midtjysk</strong><span>Bilsyn</span></div>
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
          <button className="nav-item" onClick={() => flash("Medarbejdere åbnes i en kommende etape")}><ShieldCheck size={19} strokeWidth={1.8} /><span>Medarbejdere</span></button>
          <button className={`nav-item ${activeView === "availability" ? "active" : ""}`} onClick={() => navigate("availability")}><Settings size={19} strokeWidth={1.8} /><span>Åbningstider</span></button>
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
          <div className="location"><span>Afdeling</span><button>Herning <ChevronDown size={15} /></button></div>
          <div className="top-actions">
            <button className="icon-button" aria-label="Søg" onClick={() => flash("Søgning bliver tilføjet i næste etape")}><Search size={19} /></button>
            <button className="icon-button notification" aria-label="Notifikationer" onClick={() => flash("Du har 2 nye driftsbeskeder")}><Bell size={19} /><i /></button>
            <button className="icon-button" aria-label="Hjælp" onClick={() => flash("Hjælpecenter kommer senere")}><CircleHelp size={19} /></button>
          </div>
        </header>

        <div className="workspace">
          {activeView === "customers" ? <CustomersView onNotify={flash} /> : activeView === "availability" ? <AvailabilityView onNotify={flash} /> : <>
          <section className="page-heading">
            <div>
              <p className="eyebrow">Tirsdag · 4. august 2026</p>
              <h1>Dagens bookinger</h1>
              <p>Hurtigt overblik over hvem og hvad der kommer i dag.</p>
            </div>
            <button className="primary-button" onClick={() => openCreate()}><Plus size={18} /> Ny booking</button>
          </section>

          <section className="day-summary" aria-label="Dagens nøgletal">
            <div><span>Bookinger</span><strong>{bookings.length}</strong></div>
            <div><span className="summary-icon private"><UserRound size={15} /></span><p><strong>{privateCount}</strong><small>Private</small></p></div>
            <div><span className="summary-icon business"><Building2 size={15} /></span><p><strong>{businessCount}</strong><small>Erhverv</small></p></div>
            <div><span className="summary-icon available"><Clock3 size={15} /></span><p><strong>{availableSlots.length}</strong><small>Ledige tider</small></p></div>
            <button onClick={() => flash("Ugeoversigten åbnes i næste etape")}>Se hele ugen <ChevronRight size={16} /></button>
          </section>

          <section className="week-strip" aria-label="Ugens dage">
            <button className="week-arrow" aria-label="Forrige uge" onClick={() => flash("Forrige uge valgt")}><ChevronLeft size={18} /></button>
            {weeks.map((item) => (
              <button key={item.day} className={item.active ? "active" : ""} onClick={() => flash(`${item.day} ${item.date}. august valgt`)}>
                <span>{item.day}</span><strong>{item.date}</strong><small>{item.count} bookinger</small>
              </button>
            ))}
            <button className="week-arrow" aria-label="Næste uge" onClick={() => flash("Næste uge valgt")}><ChevronRight size={18} /></button>
          </section>

          <div className="day-layout">
            <section className="booking-list-card">
              <div className="list-toolbar">
                <div><h2>Tirsdag den 4. august</h2><span>Sorteret efter tidspunkt</span></div>
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
                <div className="aside-title"><span className="aside-icon"><Clock3 size={18} /></span><div><h2>Ledige tider</h2><p>I dag</p></div></div>
                <div className="available-times">
                  {availableSlots.slice(0, 4).map((time) => <button key={time} onClick={() => openCreate(time)}>{time} <Plus size={15} /></button>)}
                  {availableSlots.length === 0 && <p className="no-slots">Ingen ledige tider</p>}
                </div>
                <button className="secondary-wide" onClick={() => flash("Alle ledige tider vises i næste etape")}>Se alle ledige tider</button>
              </section>

              <section className="progress-card">
                <div className="aside-title"><span className="aside-icon green"><Check size={18} /></span><div><h2>Dagens fremdrift</h2><p>{completedCount} af {bookings.length} gennemført</p></div></div>
                <div className="progress-track"><span style={{ width: `${bookings.length ? Math.round(completedCount / bookings.length * 100) : 0}%` }} /></div>
                <div className="progress-legend"><span><i /> {completedCount} færdige</span><span><i /> {bookings.length - completedCount} tilbage</span></div>
              </section>
            </aside>
          </div>
          </>}
        </div>
      </main>

      {notice && <div className="toast" role="status">{notice}</div>}

      {modalOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={() => setModalOpen(false)}>
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="new-booking-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-heading"><div><span>{selectedBooking ? "Rediger aftale" : "Ny aftale"}</span><h2 id="new-booking-title">{selectedBooking ? `${selectedBooking.time} · ${selectedBooking.customer}` : "Opret booking"}</h2></div><button aria-label="Luk" onClick={() => setModalOpen(false)}><X size={20} /></button></div>
            <div className="form-grid">
              <label className="full">Kundetype<select value={form.customerType} onChange={(event) => setForm({ ...form, customerType: event.target.value as CustomerType })}><option value="private">Privatkunde</option><option value="business">Erhvervskunde</option></select></label>
              <label className="full">Kunde<input value={form.customer} onChange={(event) => setForm({ ...form, customer: event.target.value })} placeholder="Navn eller virksomhed" /></label>
              <label>Dato<input type="date" value={form.date} onChange={(event) => setForm({ ...form, date: event.target.value })} /></label>
              <label>Tid<input type="time" step="1200" value={form.time} onChange={(event) => setForm({ ...form, time: event.target.value })} /></label>
              <label>Registreringsnummer<input value={form.plate} onChange={(event) => setForm({ ...form, plate: event.target.value })} placeholder="AB 12 345" /></label>
              <label>Bil<input value={form.vehicle} onChange={(event) => setForm({ ...form, vehicle: event.target.value })} placeholder="F.eks. VW Golf" /></label>
              <label className="full">Synstype<select value={form.inspection} onChange={(event) => setForm({ ...form, inspection: event.target.value })}><option>Periodisk syn</option><option>Omsyn</option><option>Varebilssyn</option><option>Motorcykelsyn</option></select></label>
            </div>
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
