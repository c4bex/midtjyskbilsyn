"use client";

import {
  Bell,
  Building2,
  CalendarDays,
  CarFront,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleHelp,
  Clock3,
  FileText,
  LayoutDashboard,
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
import { useState } from "react";

type CustomerType = "privat" | "erhverv";
type BookingStatus = "bekræftet" | "ankommet" | "afventer" | "færdig";

type Booking = {
  time: string;
  customer: string;
  customerType: CustomerType;
  plate: string;
  vehicle: string;
  inspection: string;
  status: BookingStatus;
};

const nav = [
  { label: "Overblik", icon: LayoutDashboard },
  { label: "Booking", icon: CalendarDays, active: true },
  { label: "Kunder", icon: Users },
  { label: "Køretøjer", icon: CarFront },
  { label: "Fakturering", icon: FileText, badge: "4" },
];

const bookings: Booking[] = [
  { time: "08:00", customer: "Jysk VVS ApS", customerType: "erhverv", plate: "CF 45 821", vehicle: "Ford Transit", inspection: "Periodisk syn", status: "færdig" },
  { time: "08:20", customer: "Maja Holm", customerType: "privat", plate: "AB 12 345", vehicle: "VW Golf", inspection: "Periodisk syn", status: "færdig" },
  { time: "08:40", customer: "Thomas Dahl", customerType: "privat", plate: "DL 76 119", vehicle: "Tesla Model 3", inspection: "Omsyn", status: "ankommet" },
  { time: "09:20", customer: "Anne Skov", customerType: "privat", plate: "EH 22 604", vehicle: "Peugeot 208", inspection: "Periodisk syn", status: "bekræftet" },
  { time: "09:40", customer: "Murerfirma Lund", customerType: "erhverv", plate: "FA 91 037", vehicle: "Mercedes Sprinter", inspection: "Varebilssyn", status: "bekræftet" },
  { time: "10:00", customer: "Søren Bech", customerType: "privat", plate: "GB 18 530", vehicle: "Skoda Enyaq", inspection: "Periodisk syn", status: "afventer" },
  { time: "10:20", customer: "Lone Madsen", customerType: "privat", plate: "HR 63 044", vehicle: "Toyota Yaris", inspection: "Omsyn", status: "bekræftet" },
  { time: "10:40", customer: "Fjord Transport", customerType: "erhverv", plate: "JK 37 995", vehicle: "Iveco Daily", inspection: "Varebilssyn", status: "bekræftet" },
  { time: "11:00", customer: "Emil Nygaard", customerType: "privat", plate: "KT 40 188", vehicle: "Volvo XC40", inspection: "Periodisk syn", status: "bekræftet" },
  { time: "11:40", customer: "Line Friis", customerType: "privat", plate: "LP 88 271", vehicle: "Kia Niro", inspection: "Periodisk syn", status: "bekræftet" },
  { time: "12:00", customer: "Niels Bak", customerType: "privat", plate: "MR 51 620", vehicle: "Audi A4", inspection: "Omsyn", status: "bekræftet" },
];

const weeks = [
  { day: "Man", date: "3", count: 9 },
  { day: "Tir", date: "4", count: 11, active: true },
  { day: "Ons", date: "5", count: 8 },
  { day: "Tor", date: "6", count: 10 },
  { day: "Fre", date: "7", count: 7 },
];

const statusText: Record<BookingStatus, string> = {
  bekræftet: "Bekræftet",
  ankommet: "Ankommet",
  afventer: "Afventer",
  færdig: "Færdig",
};

export function Dashboard() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [filter, setFilter] = useState<"alle" | CustomerType>("alle");
  const [modalOpen, setModalOpen] = useState(false);
  const [notice, setNotice] = useState("");

  const visibleBookings = filter === "alle" ? bookings : bookings.filter((booking) => booking.customerType === filter);
  const privateCount = bookings.filter((booking) => booking.customerType === "privat").length;
  const businessCount = bookings.length - privateCount;

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 2600);
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
              <button key={item.label} className={`nav-item ${item.active ? "active" : ""}`} onClick={() => flash(`${item.label} åbnes i en kommende etape`)}>
                <Icon size={19} strokeWidth={1.8} /><span>{item.label}</span>{item.badge && <em>{item.badge}</em>}
              </button>
            );
          })}
          <p className="nav-label nav-section">Administration</p>
          <button className="nav-item" onClick={() => flash("Medarbejdere åbnes i en kommende etape")}><ShieldCheck size={19} strokeWidth={1.8} /><span>Medarbejdere</span></button>
          <button className="nav-item" onClick={() => flash("Indstillinger åbnes i en kommende etape")}><Settings size={19} strokeWidth={1.8} /><span>Indstillinger</span></button>
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
          <section className="page-heading">
            <div>
              <p className="eyebrow">Tirsdag · 4. august 2026</p>
              <h1>Dagens bookinger</h1>
              <p>Hurtigt overblik over hvem og hvad der kommer i dag.</p>
            </div>
            <button className="primary-button" onClick={() => setModalOpen(true)}><Plus size={18} /> Ny booking</button>
          </section>

          <section className="day-summary" aria-label="Dagens nøgletal">
            <div><span>Bookinger</span><strong>{bookings.length}</strong></div>
            <div><span className="summary-icon private"><UserRound size={15} /></span><p><strong>{privateCount}</strong><small>Private</small></p></div>
            <div><span className="summary-icon business"><Building2 size={15} /></span><p><strong>{businessCount}</strong><small>Erhverv</small></p></div>
            <div><span className="summary-icon available"><Clock3 size={15} /></span><p><strong>2</strong><small>Ledige tider</small></p></div>
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
                  <button className={filter === "privat" ? "selected" : ""} onClick={() => setFilter("privat")}>Private <span>{privateCount}</span></button>
                  <button className={filter === "erhverv" ? "selected" : ""} onClick={() => setFilter("erhverv")}>Erhverv <span>{businessCount}</span></button>
                </div>
              </div>

              <div className="booking-table" role="table" aria-label="Dagens bookinger">
                <div className="table-head" role="row">
                  <span role="columnheader">Tid</span><span role="columnheader">Kunde</span><span role="columnheader">Bil</span><span role="columnheader">Syn</span><span role="columnheader">Status</span><span />
                </div>
                <div className="table-body">
                  {visibleBookings.map((booking) => (
                    <button className="booking-row" role="row" key={`${booking.time}-${booking.plate}`} onClick={() => flash(`${booking.customer} · ${booking.plate}`)}>
                      <span className="row-time" role="cell">{booking.time}</span>
                      <span className="row-customer" role="cell">
                        <i className={booking.customerType}>{booking.customerType === "erhverv" ? <Building2 size={14} /> : <UserRound size={14} />}</i>
                        <span><strong>{booking.customer}</strong><small>{booking.customerType === "erhverv" ? "Erhverv" : "Privat"}</small></span>
                      </span>
                      <span className="row-vehicle" role="cell"><strong>{booking.plate}</strong><small>{booking.vehicle}</small></span>
                      <span className="row-inspection" role="cell">{booking.inspection}</span>
                      <span role="cell"><em className={`status ${booking.status}`}>{booking.status === "færdig" && <Check size={12} />}{statusText[booking.status]}</em></span>
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
                  <button onClick={() => setModalOpen(true)}>11:20 <Plus size={15} /></button>
                  <button onClick={() => setModalOpen(true)}>14:20 <Plus size={15} /></button>
                </div>
                <button className="secondary-wide" onClick={() => flash("Alle ledige tider vises i næste etape")}>Se alle ledige tider</button>
              </section>

              <section className="progress-card">
                <div className="aside-title"><span className="aside-icon green"><Check size={18} /></span><div><h2>Dagens fremdrift</h2><p>3 af 11 gennemført</p></div></div>
                <div className="progress-track"><span /></div>
                <div className="progress-legend"><span><i /> 3 færdige</span><span><i /> 8 tilbage</span></div>
              </section>
            </aside>
          </div>
        </div>
      </main>

      {notice && <div className="toast" role="status">{notice}</div>}

      {modalOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={() => setModalOpen(false)}>
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="new-booking-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-heading"><div><span>Ny aftale</span><h2 id="new-booking-title">Opret booking</h2></div><button aria-label="Luk" onClick={() => setModalOpen(false)}><X size={20} /></button></div>
            <div className="form-grid">
              <label className="full">Kundetype<select defaultValue="privat"><option value="privat">Privatkunde</option><option value="erhverv">Erhvervskunde</option></select></label>
              <label className="full">Kunde<input placeholder="Søg på navn eller telefon" /></label>
              <label>Dato<input type="date" defaultValue="2026-08-04" /></label>
              <label>Tid<input type="time" defaultValue="11:20" /></label>
              <label>Registreringsnummer<input placeholder="AB 12 345" /></label>
              <label>Synstype<select defaultValue="periodisk"><option value="periodisk">Periodisk syn</option><option value="omsyn">Omsyn</option><option value="varebil">Varebilssyn</option></select></label>
            </div>
            <div className="modal-actions"><button className="secondary-button" onClick={() => setModalOpen(false)}>Annuller</button><button className="primary-button" onClick={() => { setModalOpen(false); flash("Demo: Bookingen er klar til at blive gemt"); }}>Opret booking</button></div>
          </section>
        </div>
      )}
    </div>
  );
}
