"use client";

import { CalendarDays, Check, Clock3, ShieldCheck, UserRound } from "lucide-react";
import { useState } from "react";

type Employee = { id: string; name: string; role: string; status: "Aktiv" | "På ferie"; initials: string; hours: string; absence?: string };
const demo: Employee[] = [
  { id: "emp-1", name: "Rasmus Mouritzen", role: "Administrator", status: "Aktiv", initials: "RM", hours: "Man–fre · 07:30–16:30" },
  { id: "emp-2", name: "Mikkel Jensen", role: "Synsinspektør", status: "Aktiv", initials: "MJ", hours: "Man–fre · 08:00–16:20" },
  { id: "emp-3", name: "Line Sørensen", role: "Bogholder", status: "På ferie", initials: "LS", hours: "Man–tor · 08:00–15:30", absence: "Ferie · 10.–14. august" },
];

export function EmployeesView({ onNotify }: { onNotify: (message: string) => void }) {
  const [employees, setEmployees] = useState(demo);
  const [tab, setTab] = useState<"people" | "hours" | "absence">("people");
  const active = employees.filter((employee) => employee.status === "Aktiv").length;
  return <div className="module-view employees-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Bemanding</p><h1>Medarbejdere</h1><p>Styr roller, arbejdstider, ferie og fravær samlet ét sted.</p></div><button className="primary-button" onClick={() => onNotify("Ny medarbejder oprettes i næste trin")}>+ Ny medarbejder</button></section>
    <div className="employee-tabs"><button className={tab === "people" ? "selected" : ""} onClick={() => setTab("people")}><UserRound size={15} /> Medarbejdere</button><button className={tab === "hours" ? "selected" : ""} onClick={() => setTab("hours")}><Clock3 size={15} /> Arbejdstider</button><button className={tab === "absence" ? "selected" : ""} onClick={() => setTab("absence")}><CalendarDays size={15} /> Ferie og fravær</button></div>
    <section className="employee-summary"><div><span>Medarbejdere</span><strong>{employees.length}</strong></div><div><span>Aktive i dag</span><strong>{active}</strong></div><div><span>Fravær denne uge</span><strong>{employees.length - active}</strong></div></section>
    {tab === "people" && <section className="employee-card"><div className="employee-table-head"><span>Navn</span><span>Rolle</span><span>Arbejdstid</span><span>Status</span></div>{employees.map((employee) => <article className="employee-row" key={employee.id}><span className="employee-name"><i>{employee.initials}</i><strong>{employee.name}</strong></span><span>{employee.role}</span><span>{employee.hours}</span><em className={employee.status === "Aktiv" ? "active" : "away"}>{employee.status}</em></article>)}</section>}
    {tab === "hours" && <section className="employee-card schedule-card"><h2>Faste arbejdstider og fridage</h2><p>Disse regler bruges senere til at vise bemanding og advare, hvis en booking ligger uden tilgængelig medarbejder.</p>{["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag"].map((day) => <div className="schedule-row" key={day}><strong>{day}</strong><span>08:00 – 16:20</span><label><input type="checkbox" defaultChecked /> Arbejdsdag</label></div>)}<div className="schedule-row day-off"><strong>Lørdag / søndag</strong><span>Fast fridag</span><Check size={16} /></div></section>}
    {tab === "absence" && <section className="employee-card absence-card"><h2>Ferie og fravær</h2><p>Planlagt fravær påvirker bemandingsoverblikket, men ændrer ikke åbningstider automatisk endnu.</p>{employees.filter((employee) => employee.absence).map((employee) => <article key={employee.id}><span className="employee-avatar">{employee.initials}</span><div><strong>{employee.name}</strong><small>{employee.absence}</small></div><button className="secondary-button" onClick={() => onNotify("Fraværsperioden redigeres i næste trin")}>Rediger</button></article>)}<button className="secondary-button" onClick={() => onNotify("Ny ferie eller fraværsperiode oprettes i næste trin")}>+ Tilføj ferie eller fravær</button></section>}
    <section className="settings-note"><ShieldCheck size={18} /><div><strong>Adgang styres af roller</strong><p>Administratorer kan ændre medarbejdere og fravær. Synsinspektører får kun de funktioner, de skal bruge.</p></div></section>
  </div>;
}
