"use client";

import { CalendarDays, Clock3, ShieldCheck, UserRound, UsersRound } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type PermissionMeta = { label: string; group: string };
type EmployeeStatus = "ACTIVE" | "UPCOMING" | "INACTIVE" | "TERMINATED" | "ARCHIVED";
type Employee = { id: string; name: string; role: string; jobTitle?: string; status?: EmployeeStatus; active: boolean; archived?: boolean; bookingCapacity: boolean; initials: string; permissions: string[]; email?: string | null; loginStatus?: string; departments?: string[] };
type WorkTime = { start: string; end: string; working: boolean; cycleWeeks: number; cycleWeek: number };
type Absence = { id: string; employeeId: string; kind: string; dateFrom: string; dateTo: string; note?: string };
type EmployeeResponse = {
  permissionCatalog: Record<string, PermissionMeta>;
  employees: Array<{ id: string; name: string; initials?: string; role: string; jobTitle?: string; status?: EmployeeStatus; active: boolean; archived?: boolean; bookingCapacity: boolean; permissions?: string[]; email?: string | null; loginStatus?: string; departments?: string[] }>;
  departments?: Array<{ id: number; name: string }>;
  absences: Array<{ id: string; employee_id: string; kind: string; date_from: string; date_to: string; note?: string }>;
  workRules: Array<{ employee_id: string; weekday: number; starts_at: string | null; ends_at: string | null; working: boolean | number; cycle_weeks?: number; cycle_week?: number }>;
};

const days = ["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag", "Lørdag", "Søndag"];
const defaultTimes = (): Record<number, WorkTime> => Object.fromEntries(days.map((_, index) => [index + 1, { start: "08:00", end: index === 4 ? "15:40" : "16:00", working: index < 5, cycleWeeks: 1, cycleWeek: 1 }]));
const initials = (name: string) => { const parts = name.split(/\s+/).filter(Boolean); return (parts.length > 1 ? `${parts[0][0]}${parts[parts.length - 1][0]}` : (parts[0] ?? "MB").slice(0, 2)).toUpperCase(); };
const demo: Employee[] = [
  { id: "1", name: "Peter Hartz Jensen", role: "Synsinspektør", active: true, bookingCapacity: true, initials: "PH", permissions: [] },
  { id: "2", name: "Rasmus Havn Mouritzen", role: "Teknisk ansvarlig / Ejer", active: true, bookingCapacity: true, initials: "RH", permissions: [] },
  { id: "3", name: "Pernille Havn Mouritzen", role: "Bogholder / blæksprut", active: true, bookingCapacity: false, initials: "PM", permissions: [] },
];

export function EmployeesView({ onNotify }: { onNotify: (message: string) => void }) {
  const [employees, setEmployees] = useState<Employee[]>(demo);
  const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);
  const [selectedEmployeeId, setSelectedEmployeeId] = useState("1");
  const [tab, setTab] = useState<"people" | "hours" | "absence" | "access">("people");
  const [permissionCatalog, setPermissionCatalog] = useState<Record<string, PermissionMeta>>({});
  const [permissionDraft, setPermissionDraft] = useState<Record<string, string[]>>({});
  const [savingPermissions, setSavingPermissions] = useState(false);
  const [absenceForm, setAbsenceForm] = useState({ employeeId: "1", kind: "Ferie", dateFrom: "", dateTo: "", note: "" });
  const [absences, setAbsences] = useState<Absence[]>([]);
  const [workTimes, setWorkTimes] = useState<Record<string, Record<number, WorkTime>>>({});
  const [savingTimes, setSavingTimes] = useState(false);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | EmployeeStatus>("all");
  const [showArchived, setShowArchived] = useState(false);
  const [showCreate, setShowCreate] = useState(false);
  const [savingCreate, setSavingCreate] = useState(false);
  const [createForm, setCreateForm] = useState({ name: "", initials: "", jobTitle: "", role: "Synsinspektør", status: "ACTIVE" as EmployeeStatus, bookingCapacity: true, email: "" });

  useEffect(() => {
    void fetch("/api/employees", { cache: "no-store" }).then((response) => response.ok ? response.json() as Promise<EmployeeResponse> : Promise.reject()).then((data) => {
      const loaded = data.employees.map((employee) => ({ ...employee, permissions: employee.permissions ?? [], initials: employee.initials ?? initials(employee.name) }));
      setEmployees(loaded);
      setPermissionCatalog(data.permissionCatalog);
      setPermissionDraft(Object.fromEntries(loaded.map((employee) => [employee.id, employee.permissions])));
      if (loaded.length > 0) {
        setSelectedEmployeeId((current) => loaded.some((employee) => employee.id === current) ? current : loaded[0].id);
        setAbsenceForm((current) => ({ ...current, employeeId: loaded.some((employee) => employee.id === current.employeeId) ? current.employeeId : loaded[0].id }));
      }
      setAbsences(data.absences.map((item) => ({ id: String(item.id), employeeId: String(item.employee_id), kind: item.kind, dateFrom: item.date_from, dateTo: item.date_to, note: item.note })));
      const loadedTimes: Record<string, Record<number, WorkTime>> = {};
      for (const employee of loaded) loadedTimes[employee.id] = defaultTimes();
      for (const rule of data.workRules) loadedTimes[String(rule.employee_id)][rule.weekday] = { start: rule.starts_at?.slice(0, 5) ?? "08:00", end: rule.ends_at?.slice(0, 5) ?? "16:00", working: Boolean(rule.working), cycleWeeks: Number(rule.cycle_weeks ?? 1), cycleWeek: Number(rule.cycle_week ?? 1) };
      setWorkTimes(loadedTimes);
    }).catch(() => onNotify("Medarbejderplanen kunne ikke hentes"));
  }, [onNotify]);

  const selectedEmployee = employees.find((employee) => employee.id === selectedEmployeeId) ?? employees[0];
  const filteredEmployees = employees.filter((employee) => {
    if (!showArchived && (employee.archived || employee.status === "ARCHIVED")) return false;
    if (statusFilter !== "all" && employee.status !== statusFilter) return false;
    const needle = search.trim().toLowerCase();
    return !needle || [employee.name, employee.initials, employee.role, employee.jobTitle, employee.email, ...(employee.departments ?? [])].filter(Boolean).join(" ").toLowerCase().includes(needle);
  });
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
    const response = await fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "employee_update", employeeId: editingEmployee.id, displayName: editingEmployee.name, initials: editingEmployee.initials, jobTitle: editingEmployee.jobTitle, role: editingEmployee.role, status: editingEmployee.status ?? (editingEmployee.active ? "ACTIVE" : "INACTIVE"), active: editingEmployee.active, bookingCapacity: editingEmployee.bookingCapacity }) });
    if (!response.ok) { onNotify("Medarbejderen kunne ikke gemmes"); return; }
    setEmployees((current) => current.map((item) => item.id === editingEmployee.id ? editingEmployee : item));
    setEditingEmployee(null);
    onNotify("Medarbejderen og bookingrollen er opdateret");
  };

  const createEmployee = async () => {
    if (!createForm.name.trim()) { onNotify("Skriv medarbejderens fulde navn"); return; }
    setSavingCreate(true);
    try {
      const response = await fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "employee_create", displayName: createForm.name, initials: createForm.initials || initials(createForm.name), jobTitle: createForm.jobTitle || createForm.role, role: createForm.role, status: createForm.status, active: createForm.status === "ACTIVE" || createForm.status === "UPCOMING", bookingCapacity: createForm.bookingCapacity, email: createForm.email || null }) });
      if (!response.ok) { const error = await response.json().catch(() => ({})) as { message?: string; error?: string }; throw new Error(error.message || error.error || "Medarbejderen kunne ikke oprettes"); }
      const data = await response.json() as { id: string };
      const newEmployee: Employee = { id: data.id, name: createForm.name.trim(), initials: createForm.initials || initials(createForm.name), role: createForm.role, jobTitle: createForm.jobTitle || createForm.role, status: createForm.status, active: createForm.status === "ACTIVE" || createForm.status === "UPCOMING", bookingCapacity: createForm.bookingCapacity, permissions: [], email: createForm.email || null, loginStatus: "NONE" };
      setEmployees((current) => [...current, newEmployee]);
      setSelectedEmployeeId(data.id);
      setShowCreate(false);
      setCreateForm({ name: "", initials: "", jobTitle: "", role: "Synsinspektør", status: "ACTIVE", bookingCapacity: true, email: "" });
      onNotify("Medarbejderen er oprettet uden systemadgang");
    } catch (error) { onNotify(error instanceof Error ? error.message : "Medarbejderen kunne ikke oprettes"); }
    finally { setSavingCreate(false); }
  };

  const savePermissions = async () => {
    if (!selectedEmployee) return;
    setSavingPermissions(true);
    try {
      const response = await fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "employee_permissions", employeeId: selectedEmployee.id, permissions: Object.fromEntries(Object.keys(permissionCatalog).map((key) => [key, permissionDraft[selectedEmployee.id]?.includes(key) ?? false])) }) });
      if (!response.ok) throw new Error();
      const permissions = permissionDraft[selectedEmployee.id] ?? [];
      setEmployees((current) => current.map((employee) => employee.id === selectedEmployee.id ? { ...employee, permissions } : employee));
      onNotify(`${selectedEmployee.name}s rettigheder er gemt`);
    } catch { onNotify("Rettighederne kunne ikke gemmes"); }
    finally { setSavingPermissions(false); }
  };

  const togglePermission = (key: string) => setPermissionDraft((current) => ({ ...current, [selectedEmployeeId]: (current[selectedEmployeeId] ?? []).includes(key) ? (current[selectedEmployeeId] ?? []).filter((item) => item !== key) : [...(current[selectedEmployeeId] ?? []), key] }));
  const applyPermissionPreset = (preset: "booking" | "bookkeeper" | "admin") => {
    const keys = Object.keys(permissionCatalog);
    const allowed = preset === "booking" ? ["bookings.read", "bookings.write", "customers.read", "customers.write"] : preset === "bookkeeper" ? ["bookings.read", "customers.read", "invoices.write"] : keys;
    setPermissionDraft((current) => ({ ...current, [selectedEmployeeId]: allowed.filter((key) => keys.includes(key)) }));
  };

  const saveWorkTimes = async () => {
    if (!selectedEmployee) return;
    setSavingTimes(true);
    try {
      const responses = await Promise.all(days.map((_, index) => {
        const value = selectedTimes[index + 1];
        return fetch("/api/employees", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "work_rule", employeeId: selectedEmployee.id, weekday: index + 1, startsAt: value.working ? value.start : null, endsAt: value.working ? value.end : null, working: value.working, cycleWeeks: value.cycleWeeks, cycleWeek: value.cycleWeek }) });
      }));
      if (responses.some((response) => !response.ok)) throw new Error();
      onNotify(`${selectedEmployee.name}s arbejdsplan er gemt`);
    } catch { onNotify("Arbejdsplanen kunne ikke gemmes"); }
    finally { setSavingTimes(false); }
  };

  const updateTime = (weekday: number, change: Partial<WorkTime>) => setWorkTimes((current) => ({ ...current, [selectedEmployeeId]: { ...(current[selectedEmployeeId] ?? defaultTimes()), [weekday]: { ...(current[selectedEmployeeId]?.[weekday] ?? defaultTimes()[weekday]), ...change } } }));
  const active = employees.filter((employee) => employee.active).length;

  return <div className="module-view employees-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Bemanding</p><h1>Medarbejdere</h1><p>Profiler, adgang og arbejdsplan samlet ét sted.</p></div>{tab === "people" && <button className="primary-button" onClick={() => setShowCreate(true)}>＋ Opret medarbejder</button>}</section>
    <div className="employee-tabs"><button className={tab === "people" ? "selected" : ""} onClick={() => setTab("people")}><UserRound size={15} /> Medarbejdere</button><button className={tab === "hours" ? "selected" : ""} onClick={() => setTab("hours")}><Clock3 size={15} /> Arbejdsplan</button><button className={tab === "absence" ? "selected" : ""} onClick={() => setTab("absence")}><CalendarDays size={15} /> Ferie og fravær</button><button className={tab === "access" ? "selected" : ""} onClick={() => setTab("access")}><ShieldCheck size={15} /> Adgang</button></div>
    <section className="employee-summary"><div><span>Aktive medarbejdere</span><strong>{active}</strong></div><div><span>Bookingkapacitet i dag</span><strong>{capacityToday}</strong></div><div><span>Planlagt fravær</span><strong>{absences.length}</strong></div></section>

    {tab === "people" && <>
      <section className="employee-toolbar"><input aria-label="Søg medarbejdere" placeholder="Søg navn, initialer, e-mail eller rolle" value={search} onChange={(event) => setSearch(event.target.value)} /><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as "all" | EmployeeStatus)}><option value="all">Alle statusser</option><option value="ACTIVE">Aktive</option><option value="UPCOMING">Kommende</option><option value="INACTIVE">Inaktive</option><option value="TERMINATED">Fratrådte</option><option value="ARCHIVED">Arkiverede</option></select><label><input type="checkbox" checked={showArchived} onChange={(event) => setShowArchived(event.target.checked)} /> Vis arkiverede</label></section>
      <section className="employee-card"><div className="employee-table-head"><span>Navn</span><span>Stilling</span><span>Arbejdsplan</span><span>Status</span><span></span></div>{filteredEmployees.length === 0 && <p className="empty-state">Ingen medarbejdere matcher søgningen.</p>}{filteredEmployees.map((employee) => <article className="employee-row" key={employee.id}><span className="employee-name"><i>{employee.initials}</i><span><strong>{employee.name}</strong><small>{employee.jobTitle || employee.role}{employee.bookingCapacity ? " · Bookingkapacitet" : ""}</small></span></span><span>{employee.role}</span><span>{workSummary(employee)}</span><em className={employee.status === "ACTIVE" || employee.active ? "active" : "away"}>{employee.status === "UPCOMING" ? "Kommende" : employee.status === "TERMINATED" ? "Fratrådt" : employee.status === "ARCHIVED" ? "Arkiveret" : employee.active ? "Aktiv" : "Inaktiv"}</em><button className="secondary-button" onClick={() => setEditingEmployee(employee)}>Rediger</button></article>)}{editingEmployee && <div className="employee-edit capacity-edit"><label>Navn<input value={editingEmployee.name} onChange={(event) => setEditingEmployee({ ...editingEmployee, name: event.target.value, initials: initials(event.target.value) })} /></label><label>Initialer<input value={editingEmployee.initials} maxLength={8} onChange={(event) => setEditingEmployee({ ...editingEmployee, initials: event.target.value.toUpperCase() })} /></label><label>Stilling<input value={editingEmployee.jobTitle ?? editingEmployee.role} onChange={(event) => setEditingEmployee({ ...editingEmployee, jobTitle: event.target.value })} /></label><label>Systemrolle<select value={editingEmployee.role} onChange={(event) => setEditingEmployee({ ...editingEmployee, role: event.target.value })}><option>Synsinspektør</option><option>Teknisk ansvarlig / Ejer</option><option>Bogholder / blæksprut</option><option>Administrator</option><option>Begrænset adgang</option></select></label><label>Status<select value={editingEmployee.status ?? (editingEmployee.active ? "ACTIVE" : "INACTIVE")} onChange={(event) => { const status = event.target.value as EmployeeStatus; setEditingEmployee({ ...editingEmployee, status, active: status === "ACTIVE" || status === "UPCOMING" }); }}><option value="ACTIVE">Aktiv</option><option value="UPCOMING">Kommende</option><option value="INACTIVE">Inaktiv</option><option value="TERMINATED">Fratrådt</option><option value="ARCHIVED">Arkiveret</option></select></label><label className="capacity-toggle"><input type="checkbox" checked={editingEmployee.bookingCapacity} onChange={(event) => setEditingEmployee({ ...editingEmployee, bookingCapacity: event.target.checked })} /> Tæller i bookingkapacitet</label><button className="primary-button" onClick={() => void saveEmployee()}>Gem</button><button className="secondary-button" onClick={() => setEditingEmployee(null)}>Annuller</button></div>}</section>
      {showCreate && <div className="employee-create-card"><div><h2>Opret medarbejder</h2><p>Opret profilen først. Systemadgang kan tilføjes senere.</p></div><div className="employee-create-grid"><label>Fulde navn<input autoFocus value={createForm.name} onChange={(event) => setCreateForm({ ...createForm, name: event.target.value })} placeholder="Fx Anna Jensen" /></label><label>Initialer<input value={createForm.initials} maxLength={8} onChange={(event) => setCreateForm({ ...createForm, initials: event.target.value.toUpperCase() })} placeholder="AJ" /></label><label>Stilling/funktion<input value={createForm.jobTitle} onChange={(event) => setCreateForm({ ...createForm, jobTitle: event.target.value })} placeholder="Fx Synsinspektør" /></label><label>Systemrolle<select value={createForm.role} onChange={(event) => setCreateForm({ ...createForm, role: event.target.value })}><option>Synsinspektør</option><option>Teknisk ansvarlig / Ejer</option><option>Bogholder / blæksprut</option><option>Administrator</option><option>Begrænset adgang</option></select></label><label>Status<select value={createForm.status} onChange={(event) => setCreateForm({ ...createForm, status: event.target.value as EmployeeStatus })}><option value="ACTIVE">Aktiv</option><option value="UPCOMING">Kommende</option><option value="INACTIVE">Inaktiv</option></select></label><label>Arbejdsmail <span>(valgfri)</span><input type="email" value={createForm.email} onChange={(event) => setCreateForm({ ...createForm, email: event.target.value })} placeholder="navn@midtjyskbilsyn.dk" /></label><label className="capacity-toggle"><input type="checkbox" checked={createForm.bookingCapacity} onChange={(event) => setCreateForm({ ...createForm, bookingCapacity: event.target.checked })} /> Tæller i bookingkapacitet</label></div><div className="employee-create-actions"><button className="secondary-button" onClick={() => setShowCreate(false)}>Annuller</button><button className="primary-button" disabled={savingCreate} onClick={() => void createEmployee()}>{savingCreate ? "Opretter…" : "Opret medarbejder"}</button></div></div>}
    </>}

    {tab === "access" && <section className="employee-card permissions-card"><div className="schedule-heading"><div><h2>Adgang og rettigheder</h2><p>AI-assistenten er altid synlig for interne brugere. Brug hurtigvalg eller afkryds præcis hvad medarbejderen ellers må se og ændre.</p></div><select value={selectedEmployeeId} onChange={(event) => setSelectedEmployeeId(event.target.value)}>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select></div><div className="permission-presets"><span>Hurtigvalg</span><button className="secondary-button" onClick={() => applyPermissionPreset("booking")}>Kun booking og kunder</button><button className="secondary-button" onClick={() => applyPermissionPreset("bookkeeper")}>Bogholder</button><button className="secondary-button" onClick={() => applyPermissionPreset("admin")}>Fuld adgang</button></div>{Object.entries(permissionCatalog).reduce<Array<[string, Array<[string, PermissionMeta]>]>>((groups, entry) => { const [key, meta] = entry; const group = groups.find((item) => item[0] === meta.group); if (group) group[1].push([key, meta]); else groups.push([meta.group, [[key, meta]]]); return groups; }, []).map(([group, items]) => <div className="permission-group" key={group}><h3>{group}</h3>{items.map(([key, meta]) => <label className="permission-row" key={key}><input type="checkbox" disabled={key === "ai.use"} checked={key === "ai.use" || (permissionDraft[selectedEmployeeId]?.includes(key) ?? false)} onChange={() => togglePermission(key)} /><span><strong>{meta.label}{key === "ai.use" ? " · Fast for interne" : ""}</strong><small>{key}</small></span></label>)}</div>)}<button className="primary-button" disabled={savingPermissions || !selectedEmployee} onClick={() => void savePermissions()}>{savingPermissions ? "Gemmer…" : "Gem rettigheder"}</button></section>}

    {tab === "hours" && <><section className="employee-card schedule-card"><div className="schedule-heading"><div><h2>Fast arbejdsplan</h2><p>Vælg medarbejder og markér præcis de dage, personen arbejder.</p></div><select value={selectedEmployeeId} onChange={(event) => setSelectedEmployeeId(event.target.value)}>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select></div>{selectedEmployee && <div className={`capacity-info ${selectedEmployee.bookingCapacity ? "counts" : ""}`}><UsersRound size={18} /><div><strong>{selectedEmployee.bookingCapacity ? "Denne medarbejder åbner ekstra bookingpladser" : "Denne medarbejder påvirker ikke antallet af tider"}</strong><small>Indstillingen ændres under fanen Medarbejdere.</small></div></div>}{days.map((day, index) => { const times = selectedTimes[index + 1]; return <div className={`schedule-row ${times.working ? "" : "day-off"}`} key={day}><strong>{day}</strong><span><input type="time" value={times.start} disabled={!times.working} onChange={(event) => updateTime(index + 1, { start: event.target.value })} /> – <input type="time" value={times.end} disabled={!times.working} onChange={(event) => updateTime(index + 1, { end: event.target.value })} /></span><label><input type="checkbox" checked={times.working} onChange={(event) => updateTime(index + 1, { working: event.target.checked })} /> {times.working ? "På arbejde" : "Fast fridag"}</label><label className="cycle-control">Rul<select value={times.cycleWeeks} disabled={!times.working} onChange={(event) => updateTime(index + 1, { cycleWeeks: Number(event.target.value), cycleWeek: Math.min(times.cycleWeek, Number(event.target.value)) })}><option value="1">Hver uge</option><option value="2">Hver 2. uge</option><option value="3">Hver 3. uge</option></select></label>{times.cycleWeeks > 1 && <label className="cycle-control">Uge i rul<select value={times.cycleWeek} disabled={!times.working} onChange={(event) => updateTime(index + 1, { cycleWeek: Number(event.target.value) })}>{Array.from({ length: times.cycleWeeks }, (_, week) => <option value={week + 1} key={week}>Uge {week + 1}</option>)}</select></label>}</div>; })}<p className="schedule-help">Et rul regnes efter ISO-ugen. Sæt fx to medarbejdere til “Hver 2. uge” og vælg uge 1 og uge 2 for skiftende mandage.</p><button className="primary-button" disabled={savingTimes} onClick={() => void saveWorkTimes()}>{savingTimes ? "Gemmer…" : "Gem arbejdsplan"}</button></section><section className="employee-card capacity-week"><h2>Bookingkapacitet pr. dag</h2><p>Antal samtidige biler systemet kan tage imod ud fra den faste plan.</p><div>{weekCapacity.map((item) => <article key={item.day}><span>{item.day.slice(0, 3)}</span><strong>{item.count}</strong><small>{item.count === 1 ? "bil ad gangen" : "biler ad gangen"}</small></article>)}</div></section></>}

    {tab === "absence" && <section className="employee-card absence-card"><h2>Ferie og fravær</h2><p>Fravær trækker automatisk medarbejderen ud af bookingkapaciteten i perioden.</p><div className="absence-form"><select value={absenceForm.employeeId} onChange={(event) => setAbsenceForm((current) => ({ ...current, employeeId: event.target.value }))}>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select><select value={absenceForm.kind} onChange={(event) => setAbsenceForm((current) => ({ ...current, kind: event.target.value }))}><option>Ferie</option><option>Sygdom</option><option>Andet fravær</option></select><input type="date" value={absenceForm.dateFrom} onChange={(event) => setAbsenceForm((current) => ({ ...current, dateFrom: event.target.value }))} /><input type="date" value={absenceForm.dateTo} onChange={(event) => setAbsenceForm((current) => ({ ...current, dateTo: event.target.value }))} /><input placeholder="Note (valgfri)" value={absenceForm.note} onChange={(event) => setAbsenceForm((current) => ({ ...current, note: event.target.value }))} /><button className="primary-button" onClick={() => void saveAbsence()}>Gem fravær</button></div>{absences.length === 0 && <p className="empty-state">Ingen ferie eller fravær registreret.</p>}{absences.map((absence) => { const employee = employees.find((item) => item.id === absence.employeeId); return <article key={absence.id}><span className="employee-avatar">{employee?.initials ?? ""}</span><div><strong>{employee?.name ?? "Medarbejder"}</strong><small>{absence.kind} · {absence.dateFrom} – {absence.dateTo}</small></div></article>; })}</section>}
    <section className="settings-note"><ShieldCheck size={18} /><div><strong>Kapaciteten følger bemandingen</strong><p>Er to godkendte medarbejdere på arbejde samtidigt, kan samme tidspunkt bookes to gange. Ferie og fridage reducerer automatisk antallet igen.</p></div></section>
  </div>;
}
