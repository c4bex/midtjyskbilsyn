"use client";

import { Building2, CalendarDays, CarFront, ChevronRight, Search, UserRound, X } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type Customer = {
  id: string; name: string; customerType: "private" | "business";
  vehicles: Array<{ id: string; plate: string; vehicle: string }>;
  history: Array<{ id: string; date: string; time: string; inspection: string; status: string }>;
};

const statusLabel: Record<string, string> = { completed: "Gennemført", confirmed: "Booket", arrived: "Ankommet", awaiting_confirmation: "Afventer", cancelled: "Aflyst" };
const danishDate = (date: string) => new Intl.DateTimeFormat("da-DK", { day: "numeric", month: "short", year: "numeric", timeZone: "Europe/Copenhagen" }).format(new Date(`${date}T12:00:00Z`));

export function CustomersView({ onNotify }: { onNotify: (message: string) => void }) {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<"all" | "private" | "business">("all");
  const [selected, setSelected] = useState<Customer | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    fetch("/api/customers", { cache: "no-store" })
      .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ customers: Customer[] }>; })
      .then((data) => { if (active) setCustomers(data.customers); })
      .catch(() => { if (active) onNotify("Kunderne kunne ikke hentes"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [onNotify]);

  const visible = useMemo(() => customers.filter((customer) => {
    const typeMatch = filter === "all" || customer.customerType === filter;
    const text = `${customer.name} ${customer.vehicles.map((vehicle) => `${vehicle.plate} ${vehicle.vehicle}`).join(" ")}`.toLowerCase();
    return typeMatch && text.includes(query.trim().toLowerCase());
  }), [customers, filter, query]);
  const privateCount = customers.filter((customer) => customer.customerType === "private").length;

  return (
    <div className="module-view">
      <section className="page-heading">
        <div><p className="eyebrow">Kunder og køretøjer</p><h1>Kundehistorik</h1><p>Søg på kunde, registreringsnummer eller bil.</p></div>
      </section>

      <section className="module-summary">
        <div><strong>{customers.length}</strong><span>Kunder i alt</span></div>
        <div><strong>{privateCount}</strong><span>Private</span></div>
        <div><strong>{customers.length - privateCount}</strong><span>Erhverv</span></div>
        <div><strong>{customers.reduce((total, customer) => total + customer.vehicles.length, 0)}</strong><span>Køretøjer</span></div>
      </section>

      <section className="customer-card">
        <div className="module-toolbar">
          <label className="module-search"><Search size={17} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Søg kunde eller bil" /></label>
          <div className="customer-filters" role="group" aria-label="Filtrer kundeliste">
            <button className={filter === "all" ? "selected" : ""} onClick={() => setFilter("all")}>Alle</button>
            <button className={filter === "private" ? "selected" : ""} onClick={() => setFilter("private")}>Private</button>
            <button className={filter === "business" ? "selected" : ""} onClick={() => setFilter("business")}>Erhverv</button>
          </div>
        </div>
        <div className="customer-table-head"><span>Kunde</span><span>Køretøj</span><span>Seneste syn</span><span>Antal syn</span><span /></div>
        <div className="customer-list">
          {loading && <p className="empty-state">Henter kunder…</p>}
          {!loading && visible.length === 0 && <p className="empty-state">Ingen kunder matcher søgningen.</p>}
          {visible.map((customer) => {
            const vehicle = customer.vehicles[0];
            const latest = customer.history[0];
            return (
              <button key={customer.id} className="customer-row" onClick={() => setSelected(customer)}>
                <span className="customer-name"><i className={customer.customerType}>{customer.customerType === "business" ? <Building2 size={15} /> : <UserRound size={15} />}</i><span><strong>{customer.name}</strong><small>{customer.customerType === "business" ? "Erhverv" : "Privat"}</small></span></span>
                <span className="customer-vehicle"><strong>{vehicle?.plate ?? "—"}</strong><small>{vehicle?.vehicle ?? "Intet køretøj"}</small></span>
                <span className="latest-visit">{latest ? <><strong>{danishDate(latest.date)}</strong><small>{latest.inspection}</small></> : "—"}</span>
                <span className="visit-count">{customer.history.length}</span>
                <ChevronRight size={17} />
              </button>
            );
          })}
        </div>
      </section>

      {selected && (
        <div className="detail-backdrop" onMouseDown={() => setSelected(null)}>
          <aside className="customer-detail" onMouseDown={(event) => event.stopPropagation()} aria-label={`Historik for ${selected.name}`}>
            <div className="detail-head"><div><span>{selected.customerType === "business" ? "Erhvervskunde" : "Privatkunde"}</span><h2>{selected.name}</h2></div><button aria-label="Luk historik" onClick={() => setSelected(null)}><X size={19} /></button></div>
            <h3>Køretøjer</h3>
            {selected.vehicles.map((vehicle) => <div className="vehicle-chip" key={vehicle.id}><CarFront size={17} /><span><strong>{vehicle.plate}</strong><small>{vehicle.vehicle}</small></span></div>)}
            <h3>Synshistorik</h3>
            <div className="history-list">
              {selected.history.map((booking) => <article key={booking.id}><span className="history-icon"><CalendarDays size={15} /></span><div><strong>{danishDate(booking.date)} kl. {booking.time}</strong><small>{booking.inspection}</small></div><em>{statusLabel[booking.status] ?? booking.status}</em></article>)}
            </div>
          </aside>
        </div>
      )}
    </div>
  );
}
