"use client";

import { CalendarDays, Check, Clock3, ShieldCheck, UserRound, UsersRound } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type Employee = { id: string; name: string; role: string; active: boolean; bookingCapacity: boolean; initials: string };
type WorkTime = { start: string; end: string; working: boolean };
type Absence = { id: string; employeeId: string; kind: string; dateFrom: string; dateTo: string; note?: string };
type EmployeeResponse = {
  employees: Array<{ id: string; name: string; role: string; active: boolean; bookingCapacity: boolean }>;
  absences: Array<{ id: string; employee_id: string; kind: string; date_from: string; date_to: string; note?: string }>;
  workRules: Array<{ employee_id: string; weekday: number; starts_at: string | null; ends_at: string | null; working: boolean | number }>;
};

const days = ["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag", "Lørdag", "Søndag"];
const defaultTimes = (): Record<number, WorkTime> => Object.fromEntries(days.map((_, index) => [index + 1, { start: "08:00", end: index === 4 ? "15:40" : "16:00", working: index < 5 }]));
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
const demo: Employee[] = [
  { id: "1", name: "Peter Hartz Jensen", role: "Synsinspektør", active: true, bookingCapacity: true, initials: "PH" },
  { id: "2", name: "Rasmus Havn Mouritzen", role: "Teknisk ansvarlig / Ejer", active: true, bookingCapacity: true, initials: "RH" },
  { id: "3", name: "Pernille Havn Mouritzen", role: "Bogholder / blæksprut", active: true, bookingCapacity: false, initials: "PM" },
];

export function EmployeesView({ onNotify }: { onNotify: (message: string) => void }) {
  const [employees, setEmployees] = useState(demo);
  const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
  const [selectedEmployeeId, setSelectedEmployeeId] = useState("1");
  const [tab, setTab] = useState<"people" | "hours" | "absence">("people");
  const [absenceForm, setAbsenceForm] = useState({ employeeId: "1", kind: "Ferie", dateFrom: "", dateTo: "", note: "" });
  const [absences, setAbsences] = useState<Absence[]>([]);
  const [workTimes, setWorkTimes] = useState<Record<string, Record<number, WorkTime>>>({});
  const [savingTimes, setSavingTimes] = useState(false);

  useEffect(() => {
    void fetch("/api/employees", { cache: "no-store" }).then((response) => response.ok ? response.json() as Promise<EmployeeResponse> : Promise.reject()).then((data) => {
      const loaded = data.employees.map((employee) => ({ ...employee, initials: initials(employee.name) }));
      setEmployees(loaded);
      if (loaded.length > 0) {
        setSelectedEmployeeId((current) => loaded.some((employee) => employee.id === current) ? current : loaded[0].id);
        setAbsenceForm((current) => ({ ...current, employeeId: loaded.some((employee) => employee.id === current.employeeId) ? current.employeeId : loaded[0].id }));
      }
      setAbsences(data.absences.map((item) => ({ id: String(item.id), employeeId: String(item.employee_id), kind: item.kind, dateFrom: item.date_from, dateTo: item.date_to, note: item.note })));
      const loadedTimes: Record<string, Record<number, WorkTime>> = {};
      for (const employee of loaded) loadedTimes[employee.id] = defaultTimes();
      for (const rule of data.workRules) loadedTimes[String(rule.employee_id)][rule.weekday] = { start: rule.starts_at?.slice(0, 5) ?? "08:00", end: rule.ends_at?.slice(0, 5) ?? "16:00", working: Boolean(rule.working) };
      setWorkTimes(loadedTimes);
    }).catch(() => onNotify("Medarbejderplanen kunne ikke hentes"));
  }, [onNotify]);

  const selectedEmployee = employees.find((employee) => employee.id === selectedEmployeeId) ?? employees[0];
  const selectedTimes = workTimes[selectedEmployeeId] ?? defaultTimes();
  const todayWeekday = ((new Date().getDay() + 6) % 7) + 1;
  const capacityToday = employees.filter((employee) => employee.active && employee.bookingCapacity && (workTimes[employee.id]?.[todayWeekday]?.working ?? false)).length;
  const workSummary = (employee: Employee) => {
    const rules = workTimes[employee.id];
    if (!rules) return "Arbejdsplan ikke hentet";
    const working = days.filter((_, index) => rules[index + 1]?.working);
    if (working.length === 0) return "Ingen faste arbejdsdage";
    return `${working.length} arbejdsdage · ${working.map((day) => day.slice(0, 3)).join(", ")}`;
  };
  const weekCapacity = useMemo(() => days.map((day, index) => ({ day, count: employees.filter((employee) => employee.active && employee.bookingCapacity && (workTimes[employee.id]?.[index + 1]?.working ?? false)).length })), [employees, workTimes]);

  const saveAbsence = async () => {
    if (!absenceForm.dateFrom || !absenceForm.dateTo) { onNotify("Vælg både fra- og til-dato"); return; }
    const response = await fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "absence", ...absenceForm }) });
    if (!response.ok) { onNotify("Fraværet kunne ikke gemmes"); return; }
    const data = await response.json() as { id: string };
    setAbsences((current) => [...current, { id: data.id, ...absenceForm }]);
    setAbsenceForm((current) => ({ ...current, dateFrom: "", dateTo: "", note: "" }));
    onNotify("Ferie/fravær er gemt og påvirker bookingkapaciteten");
  };

  const saveEmployee = async () => {
    if (!editingEmployee) return;
    const response = await fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "employee_update", employeeId: editingEmployee.id, displayName: editingEmployee.name, role: editingEmployee.role, active: editingEmployee.active, bookingCapacity: editingEmployee.bookingCapacity }) });
    if (!response.ok) { onNotify("Medarbejderen kunne ikke gemmes"); return; }
    setEmployees((current) => current.map((item) => item.id === editingEmployee.id ? editingEmployee : item));
    setEditingEmployee(null);
    onNotify("Medarbejderen og bookingrollen er opdateret");
  };

  const saveWorkTimes = async () => {
    if (!selectedEmployee) return;
    setSavingTimes(true);
    try {
      const responses = await Promise.all(days.map((_, index) => {
        const value = selectedTimes[index + 1];
        return fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "work_rule", employeeId: selectedEmployee.id, weekday: index + 1, startsAt: value.working ? value.start : null, endsAt: value.working ? value.end : null, working: value.working }) });
      }));
      if (responses.some((response) => !response.ok)) throw new Error();
      onNotify(`${selectedEmployee.name}s arbejdsplan er gemt`);
    } catch { onNotify("Arbejdsplanen kunne ikke gemmes"); }
    finally { setSavingTimes(false); }
  };

  const updateTime = (weekday: number, change: Partial<WorkTime>) => setWorkTimes((current) => ({ ...current, [selectedEmployeeId]: { ...(current[selectedEmployeeId] ?? defaultTimes()), [weekday]: { ...(current[selectedEmployeeId]?.[weekday] ?? defaultTimes()[weekday]), ...change } } }));
  const active = employees.filter((employee) => employee.active).length;

  return <div className="module-view employees-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Bemanding</p><h1>Medarbejdere</h1><p>Arbejdsplanen bestemmer automatisk, hvor mange biler der kan bookes.</p></div></section>
    <div className="employee-tabs"><button className={tab === "people" ? "selected" : ""} onClick={() => setTab("people")}><UserRound size={15} /> Medarbejdere</button><button className={tab === "hours" ? "selected" : ""} onClick={() => setTab("hours")}><Clock3 size={15} /> Arbejdsplan</button><button className={tab === "absence" ? "selected" : ""} onClick={() => setTab("absence")}><CalendarDays size={15} /> Ferie og fravær</button></div>
    <section className="employee-summary"><div><span>Aktive medarbejdere</span><strong>{active}</strong></div><div><span>Bookingkapacitet i dag</span><strong>{capacityToday}</strong></div><div><span>Planlagt fravær</span><strong>{absences.length}</strong></div></section>

    {tab === "people" && <section className="employee-card"><div className="employee-table-head"><span>Navn</span><span>Rolle</span><span>Arbejdsplan</span><span>Status</span><span></span></div>{employees.map((employee) => <article className="employee-row" key={employee.id}><span className="employee-name"><i>{employee.initials}</i><span><strong>{employee.name}</strong>{employee.bookingCapacity && <small>Åbner bookingtider</small>}</span></span><span>{employee.role}</span><span>{workSummary(employee)}</span><em className={employee.active ? "active" : "away"}>{employee.active ? "Aktiv" : "Inaktiv"}</em><button className="secondary-button" onClick={() => setEditingEmployee(employee)}>Rediger</button></article>)}{editingEmployee && <div className="employee-edit capacity-edit"><input value={editingEmployee.name} onChange={(event) => setEditingEmployee({ ...editingEmployee, name: event.target.value, initials: initials(event.target.value) })} /><input value={editingEmployee.role} onChange={(event) => setEditingEmployee({ ...editingEmployee, role: event.target.value })} /><select value={editingEmployee.active ? "active" : "inactive"} onChange={(event) => setEditingEmployee({ ...editingEmployee, active: event.target.value === "active" })}><option value="active">Aktiv</option><option value="inactive">Inaktiv</option></select><label className="capacity-toggle"><input type="checkbox" checked={editingEmployee.bookingCapacity} onChange={(event) => setEditingEmployee({ ...editingEmployee, bookingCapacity: event.target.checked })} /> Åbner tider til booking</label><button className="primary-button" onClick={() => void saveEmployee()}>Gem</button><button className="secondary-button" onClick={() => setEditingEmployee(null)}>Annuller</button></div>}</section>}

    {tab === "hours" && <><section className="employee-card schedule-card"><div className="schedule-heading"><div><h2>Fast arbejdsplan</h2><p>Vælg medarbejder og markér præcis de dage, personen arbejder.</p></div><select value={selectedEmployeeId} onChange={(event) => setSelectedEmployeeId(event.target.value)}>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select></div>{selectedEmployee && <div className={`capacity-info ${selectedEmployee.bookingCapacity ? "counts" : ""}`}><UsersRound size={18} /><div><strong>{selectedEmployee.bookingCapacity ? "Denne medarbejder åbner ekstra bookingpladser" : "Denne medarbejder påvirker ikke antallet af tider"}</strong><small>Indstillingen ændres under fanen Medarbejdere.</small></div></div>}{days.map((day, index) => { const times = selectedTimes[index + 1]; return <div className={`schedule-row ${times.working ? "" : "day-off"}`} key={day}><strong>{day}</strong><span><input type="time" value={times.start} disabled={!times.working} onChange={(event) => updateTime(index + 1, { start: event.target.value })} /> – <input type="time" value={times.end} disabled={!times.working} onChange={(event) => updateTime(index + 1, { end: event.target.value })} /></span><label><input type="checkbox" checked={times.working} onChange={(event) => updateTime(index + 1, { working: event.target.checked })} /> {times.working ? "På arbejde" : "Fast fridag"}</label></div>; })}<button className="primary-button" disabled={savingTimes} onClick={() => void saveWorkTimes()}>{savingTimes ? "Gemmer…" : "Gem arbejdsplan"}</button></section><section className="employee-card capacity-week"><h2>Bookingkapacitet pr. dag</h2><p>Antal samtidige biler systemet kan tage imod ud fra den faste plan.</p><div>{weekCapacity.map((item) => <article key={item.day}><span>{item.day.slice(0, 3)}</span><strong>{item.count}</strong><small>{item.count === 1 ? "bil ad gangen" : "biler ad gangen"}</small></article>)}</div></section></>}

    {tab === "absence" && <section className="employee-card absence-card"><h2>Ferie og fravær</h2><p>Fravær trækker automatisk medarbejderen ud af bookingkapaciteten i perioden.</p><div className="absence-form"><select value={absenceForm.employeeId} onChange={(event) => setAbsenceForm((current) => ({ ...current, employeeId: event.target.value }))}>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select><select value={absenceForm.kind} onChange={(event) => setAbsenceForm((current) => ({ ...current, kind: event.target.value }))}><option>Ferie</option><option>Sygdom</option><option>Andet fravær</option></select><input type="date" value={absenceForm.dateFrom} onChange={(event) => setAbsenceForm((current) => ({ ...current, dateFrom: event.target.value }))} /><input type="date" value={absenceForm.dateTo} onChange={(event) => setAbsenceForm((current) => ({ ...current, dateTo: event.target.value }))} /><input placeholder="Note (valgfri)" value={absenceForm.note} onChange={(event) => setAbsenceForm((current) => ({ ...current, note: event.target.value }))} /><button className="primary-button" onClick={() => void saveAbsence()}>Gem fravær</button></div>{absences.length === 0 && <p className="empty-state">Ingen ferie eller fravær registreret.</p>}{absences.map((absence) => { const employee = employees.find((item) => item.id === absence.employeeId); return <article key={absence.id}><span className="employee-avatar">{employee?.initials ?? ""}</span><div><strong>{employee?.name ?? "Medarbejder"}</strong><small>{absence.kind} · {absence.dateFrom} – {absence.dateTo}</small></div></article>; })}</section>}
    <section className="settings-note"><ShieldCheck size={18} /><div><strong>Kapaciteten følger bemandingen</strong><p>Er to godkendte medarbejdere på arbejde samtidigt, kan samme tidspunkt bookes to gange. Ferie og fridage reducerer automatisk antallet igen.</p></div></section>
  </div>;
}
