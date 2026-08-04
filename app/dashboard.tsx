"use client";

import {
  Bell,
  CalendarDays,
  CarFront,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleHelp,
  Clock3,
  FileText,
  Gauge,
  LayoutDashboard,
  Menu,
  Plus,
  Search,
  Settings,
  ShieldCheck,
  Users,
  X,
} from "lucide-react";
import { useState } from "react";

type Booking = {
  time: string;
  duration: number;
  customer: string;
  plate: string;
  vehicle: string;
  type: string;
  status: "bekræftet" | "ankommet" | "afventer";
  column: number;
};

const nav = [
  { label: "Overblik", icon: LayoutDashboard },
  { label: "Booking", icon: CalendarDays, active: true },
  { label: "Kunder", icon: Users },
  { label: "Køretøjer", icon: CarFront },
  { label: "Fakturering", icon: FileText, badge: "4" },
];

const days = [
  { weekday: "Mandag", date: "3. aug.", count: 9 },
  { weekday: "Tirsdag", date: "4. aug.", count: 11, today: true },
  { weekday: "Onsdag", date: "5. aug.", count: 8 },
  { weekday: "Torsdag", date: "6. aug.", count: 10 },
  { weekday: "Fredag", date: "7. aug.", count: 7 },
];

const bookings: Booking[] = [
  { time: "07:30", duration: 60, customer: "Maja Holm", plate: "AB 12 345", vehicle: "VW Golf", type: "Periodisk syn", status: "bekræftet", column: 0 },
  { time: "08:00", duration: 45, customer: "Jysk VVS ApS", plate: "CF 45 821", vehicle: "Ford Transit", type: "Periodisk syn", status: "ankommet", column: 1 },
  { time: "08:30", duration: 45, customer: "Thomas Dahl", plate: "DL 76 119", vehicle: "Tesla Model 3", type: "Omsyn", status: "bekræftet", column: 2 },
  { time: "09:15", duration: 45, customer: "Anne Skov", plate: "EH 22 604", vehicle: "Peugeot 208", type: "Periodisk syn", status: "afventer", column: 3 },
  { time: "09:45", duration: 60, customer: "Murerfirma Lund", plate: "FA 91 037", vehicle: "Mercedes Sprinter", type: "Varebilssyn", status: "bekræftet", column: 4 },
  { time: "10:15", duration: 45, customer: "Søren Bech", plate: "GB 18 530", vehicle: "Skoda Enyaq", type: "Periodisk syn", status: "bekræftet", column: 0 },
  { time: "10:45", duration: 45, customer: "Lone Madsen", plate: "HR 63 044", vehicle: "Toyota Yaris", type: "Omsyn", status: "bekræftet", column: 1 },
  { time: "11:30", duration: 45, customer: "Fjord Transport", plate: "JK 37 995", vehicle: "Iveco Daily", type: "Varebilssyn", status: "afventer", column: 2 },
  { time: "12:30", duration: 45, customer: "Emil Nygaard", plate: "KT 40 188", vehicle: "Volvo XC40", type: "Periodisk syn", status: "bekræftet", column: 3 },
  { time: "13:15", duration: 45, customer: "Line Friis", plate: "LP 88 271", vehicle: "Kia Niro", type: "Periodisk syn", status: "bekræftet", column: 4 },
  { time: "14:00", duration: 45, customer: "Niels Bak", plate: "MR 51 620", vehicle: "Audi A4", type: "Omsyn", status: "bekræftet", column: 0 },
  { time: "14:30", duration: 60, customer: "Hedens Montage", plate: "ND 70 416", vehicle: "Renault Master", type: "Varebilssyn", status: "bekræftet", column: 1 },
];

const times = ["07", "08", "09", "10", "11", "12", "13", "14", "15", "16"];

const offsetFor = (time: string) => {
  const [hours, minutes] = time.split(":").map(Number);
  return (hours - 7) * 72 + (minutes / 60) * 72;
};

export function Dashboard() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [view, setView] = useState<"uge" | "dag">("uge");
  const [modalOpen, setModalOpen] = useState(false);
  const [notice, setNotice] = useState("");

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
          <small>Senest kontrolleret 08:02</small>
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
              <h1>Godmorgen, Rasmus</h1>
              <p>Her er dagens aftaler og den kommende uge.</p>
            </div>
            <button className="primary-button" onClick={() => setModalOpen(true)}><Plus size={18} /> Ny booking</button>
          </section>

          <section className="metrics" aria-label="Dagens nøgletal">
            <article>
              <span className="metric-icon blue"><CalendarDays size={20} /></span>
              <div><small>Bookinger i dag</small><strong>11</strong><p><b>8</b> bekræftet · 3 afventer</p></div>
            </article>
            <article>
              <span className="metric-icon green"><Gauge size={20} /></span>
              <div><small>Kapacitet</small><strong>78%</strong><p>3 ledige tider i dag</p></div>
              <div className="mini-ring" style={{ "--value": "78%" } as React.CSSProperties}><span /></div>
            </article>
            <article>
              <span className="metric-icon amber"><Clock3 size={20} /></span>
              <div><small>Venter på handling</small><strong>4</strong><p>Fakturaer skal klargøres</p></div>
              <button className="arrow-button" aria-label="Gå til fakturering" onClick={() => flash("Fakturaklargøring åbnes i næste etape")}><ChevronRight size={18} /></button>
            </article>
            <article>
              <span className="metric-icon slate"><CarFront size={20} /></span>
              <div><small>Gennemført i dag</small><strong>3</strong><p>Seneste syn kl. 09:18</p></div>
            </article>
          </section>

          <section className="calendar-card">
            <div className="calendar-toolbar">
              <div className="calendar-title"><h2>Bookingoversigt</h2><span>45 aftaler denne uge</span></div>
              <div className="calendar-controls">
                <div className="segmented" role="group" aria-label="Kalendervisning">
                  <button className={view === "dag" ? "selected" : ""} onClick={() => setView("dag")}>Dag</button>
                  <button className={view === "uge" ? "selected" : ""} onClick={() => setView("uge")}>Uge</button>
                </div>
                <button className="date-nav" aria-label="Forrige uge" onClick={() => flash("Forrige uge valgt")}><ChevronLeft size={18} /></button>
                <button className="today-button" onClick={() => flash("Kalenderen står på denne uge")}>I dag</button>
                <button className="date-nav" aria-label="Næste uge" onClick={() => flash("Næste uge valgt")}><ChevronRight size={18} /></button>
              </div>
            </div>

            <div className={`calendar-scroll ${view === "dag" ? "day-view" : ""}`}>
              <div className="calendar-grid">
                <div className="time-head">August</div>
                {days.map((day) => (
                  <div className={`day-head ${day.today ? "today" : ""}`} key={day.weekday}>
                    <div><strong>{day.weekday}</strong><span>{day.date}</span></div><em>{day.count}</em>
                  </div>
                ))}
                <div className="timeline">
                  {times.map((time) => <span key={time} style={{ top: `${(Number(time) - 7) * 72}px` }}>{time}:00</span>)}
                </div>
                {days.map((day, index) => (
                  <div className={`day-column ${day.today ? "today-column" : ""}`} key={day.weekday}>
                    {times.map((time) => <div className="hour-line" key={time} style={{ top: `${(Number(time) - 7) * 72}px` }} />)}
                    {index === 1 && <div className="now-line" style={{ top: `${offsetFor("09:42")}px` }}><i /></div>}
                    {bookings.filter((booking) => booking.column === index).map((booking) => (
                      <button
                        className={`booking ${booking.status}`}
                        key={`${booking.time}-${booking.plate}`}
                        style={{ top: `${offsetFor(booking.time) + 4}px`, height: `${Math.max(booking.duration * 0.92, 42)}px` }}
                        onClick={() => flash(`${booking.customer} · ${booking.plate}`)}
                      >
                        <span className="booking-time">{booking.time}</span>
                        <strong>{booking.customer}</strong>
                        <span>{booking.plate} · {booking.vehicle}</span>
                        <small>{booking.type}</small>
                      </button>
                    ))}
                  </div>
                ))}
              </div>
            </div>
            <div className="legend">
              <span><i className="confirmed-dot" /> Bekræftet</span><span><i className="arrived-dot" /> Ankommet</span><span><i className="pending-dot" /> Afventer svar</span>
              <button onClick={() => flash("Åbningstider: 07:00–16:30")}>Vis åbningstider <ChevronRight size={15} /></button>
            </div>
          </section>
        </div>
      </main>

      {notice && <div className="toast" role="status">{notice}</div>}

      {modalOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={() => setModalOpen(false)}>
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="new-booking-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-heading"><div><span>Ny aftale</span><h2 id="new-booking-title">Opret booking</h2></div><button aria-label="Luk" onClick={() => setModalOpen(false)}><X size={20} /></button></div>
            <div className="form-grid">
              <label className="full">Kunde<input placeholder="Søg på navn eller telefon" /></label>
              <label>Dato<input type="date" defaultValue="2026-08-04" /></label>
              <label>Tid<input type="time" defaultValue="10:30" /></label>
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
