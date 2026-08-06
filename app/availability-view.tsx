"use client";

import { AlertTriangle, CalendarOff, Check, Clock3, Plus, Save, SlidersHorizontal, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type Rule = { id: string; kind: string; weekday: number | null; starts_at: string | null; ends_at: string | null; date_from: string | null; date_to: string | null; label: string };
type Day = { weekday: number; name: string; closed: boolean; startsAt: string; endsAt: string; breakStartsAt: string; breakEndsAt: string };
type InspectionType = { id: number; name: string; required_slots: number; is_active: boolean };
type CalendarProfile = { id: number; name: string; description: string | null; capacity_per_slot: number | null };
type PlanningBuffer = { id: number; date: string; starts_at: string; ends_at: string; reason: string; is_fixed: boolean };
type PlanningData = { inspectionTypes: InspectionType[]; profiles: CalendarProfile[]; buffers: PlanningBuffer[]; day: { profileId: number | null; conflictStatus: "ok" | "warning" | "red"; staffedInspectors: number; capacityPerSlot: number; bufferCount: number; availableCapacity: number } };
const names = ["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag", "Lørdag", "Søndag"];
const blankDays = names.map((name, index) => ({ weekday: index + 1, name, closed: index > 4, startsAt: "08:00", endsAt: "16:20", breakStartsAt: "12:20", breakEndsAt: "13:00" }));
const danishDate = (date: string) => new Intl.DateTimeFormat("da-DK", { day: "numeric", month: "short", year: "numeric", timeZone: "Europe/Copenhagen" }).format(new Date(`${date}T12:00:00Z`));

export function AvailabilityView({ onNotify }: { onNotify: (message: string) => void }) {
  const [days, setDays] = useState<Day[]>(blankDays);
  const [closures, setClosures] = useState<Rule[]>([]);
  const [savingDay, setSavingDay] = useState<number | null>(null);
  const [closureForm, setClosureForm] = useState({ kind: "vacation" as "vacation" | "holiday", dateFrom: "2026-08-10", dateTo: "2026-08-14", label: "Sommerferie" });
  const [planningDate, setPlanningDate] = useState("2026-08-04");
  const [planning, setPlanning] = useState<PlanningData | null>(null);
  const [planningProfile, setPlanningProfile] = useState("");
  const [bufferForm, setBufferForm] = useState({ startsAt: "10:00", endsAt: "10:20", reason: "Ekstra luft i kalenderen" });

  const applyRules = useCallback((rules: Rule[]) => {
    setDays(blankDays.map((day) => {
      const opening = rules.find((rule) => rule.weekday === day.weekday && rule.kind === "opening_hours");
      const pause = rules.find((rule) => rule.weekday === day.weekday && rule.kind === "break");
      const closed = rules.some((rule) => rule.weekday === day.weekday && rule.kind === "closed_day") || !opening;
      return { ...day, closed, startsAt: opening?.starts_at ?? day.startsAt, endsAt: opening?.ends_at ?? day.endsAt, breakStartsAt: pause?.starts_at ?? day.breakStartsAt, breakEndsAt: pause?.ends_at ?? day.breakEndsAt };
    }));
    setClosures(rules.filter((rule) => rule.kind === "holiday" || rule.kind === "vacation"));
  }, []);

  const reload = useCallback(() => fetch("/api/availability", { cache: "no-store" })
    .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ rules: Rule[] }>; })
    .then((data) => applyRules(data.rules))
    .catch(() => onNotify("Åbningstiderne kunne ikke hentes")), [applyRules, onNotify]);

  useEffect(() => {
    let active = true;
    fetch("/api/availability", { cache: "no-store" })
      .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ rules: Rule[] }>; })
      .then((data) => { if (active) applyRules(data.rules); })
      .catch(() => { if (active) onNotify("Åbningstiderne kunne ikke hentes"); });
    return () => { active = false; };
  }, [applyRules, onNotify]);

  const reloadPlanning = useCallback(() => fetch(`/api/planning?date=${planningDate}`, { cache: "no-store" })
    .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<PlanningData>; })
    .then((data) => { setPlanning(data); setPlanningProfile(data.day.profileId ? String(data.day.profileId) : ""); })
    .catch(() => onNotify("Kapacitetsplanen kunne ikke hentes")), [onNotify, planningDate]);

  useEffect(() => { void reloadPlanning(); }, [reloadPlanning]);

  const updateDay = (weekday: number, patch: Partial<Day>) => setDays((current) => current.map((day) => day.weekday === weekday ? { ...day, ...patch } : day));

  const saveDay = async (day: Day) => {
    setSavingDay(day.weekday);
    try {
      const response = await fetch("/api/availability", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(day) });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Dagen kunne ikke gemmes");
      onNotify(`${day.name} er gemt`);
      await reload();
    } catch (error) { onNotify(error instanceof Error ? error.message : "Dagen kunne ikke gemmes"); }
    finally { setSavingDay(null); }
  };

  const addClosure = async () => {
    try {
      const response = await fetch("/api/availability", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(closureForm) });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Lukkedagen kunne ikke gemmes");
      onNotify("Lukkeperioden er tilføjet");
      await reload();
    } catch (error) { onNotify(error instanceof Error ? error.message : "Lukkedagen kunne ikke gemmes"); }
  };

  const deleteClosure = async (id: string) => {
    const response = await fetch(`/api/availability/${id}`, { method: "DELETE" });
    if (response.ok) { onNotify("Lukkeperioden er fjernet"); await reload(); } else onNotify("Lukkeperioden kunne ikke fjernes");
  };

  const savePlanningDay = async () => {
    const response = await fetch(`/api/planning/days/${planningDate}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ profileId: planningProfile ? Number(planningProfile) : null, mode: "manual" }) });
    if (!response.ok) { onNotify("Dagens profil kunne ikke gemmes"); return; }
    onNotify("Dagens kapacitetsprofil er gemt"); await reloadPlanning();
  };

  const saveInspectionType = async (type: InspectionType) => {
    const response = await fetch(`/api/planning/inspection-types/${type.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ requiredSlots: type.required_slots, isActive: type.is_active }) });
    if (!response.ok) { onNotify("Synstypen kunne ikke gemmes"); return; }
    onNotify(`${type.name} er gemt`); await reloadPlanning();
  };

  const addBuffer = async () => {
    const response = await fetch("/api/planning", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ date: planningDate, ...bufferForm }) });
    const data = await response.json() as { conflicts?: string[]; error?: string };
    if (!response.ok) { onNotify(data.error ?? "Buffertiden kunne ikke gemmes"); return; }
    onNotify(data.conflicts?.length ? "Buffertiden er gemt, men kolliderer med eksisterende bookinger" : "Buffertiden er tilføjet"); await reloadPlanning();
  };

  const deleteBuffer = async (id: number) => {
    const response = await fetch(`/api/planning/buffers/${id}`, { method: "DELETE" });
    if (response.ok) { onNotify("Buffertiden er fjernet"); await reloadPlanning(); } else onNotify("Buffertiden kunne ikke fjernes");
  };

  return (
    <div className="module-view">
      <section className="page-heading"><div><p className="eyebrow">Administration</p><h1>Åbningstider</h1><p>Styr normal uge, pauser, ferie og lukkedage.</p></div></section>
      <section className="hours-card">
        <div className="hours-heading"><div><span className="aside-icon"><Clock3 size={18} /></span><div><h2>Normal uge</h2><p>Bookingtider beregnes automatisk i intervaller på 20 minutter.</p></div></div><span className="saved-note"><Check size={14} /> Gemmes i databasen</span></div>
        <div className="hours-table-head"><span>Dag</span><span>Åben</span><span>Åbner</span><span>Lukker</span><span>Pause fra</span><span>Pause til</span><span /></div>
        <div className="hours-list">
          {days.map((day) => <div className={`hours-row ${day.closed ? "closed" : ""}`} key={day.weekday}>
            <strong>{day.name}</strong>
            <label className="switch"><input type="checkbox" checked={!day.closed} onChange={(event) => updateDay(day.weekday, { closed: !event.target.checked })} /><span /></label>
            <input aria-label={`${day.name} åbner`} type="time" step="1200" value={day.startsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { startsAt: event.target.value })} />
            <input aria-label={`${day.name} lukker`} type="time" step="1200" value={day.endsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { endsAt: event.target.value })} />
            <input aria-label={`${day.name} pause fra`} type="time" step="1200" value={day.breakStartsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { breakStartsAt: event.target.value })} />
            <input aria-label={`${day.name} pause til`} type="time" step="1200" value={day.breakEndsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { breakEndsAt: event.target.value })} />
            <button className="save-day" disabled={savingDay === day.weekday} onClick={() => void saveDay(day)}><Save size={15} /> {savingDay === day.weekday ? "Gemmer" : "Gem"}</button>
          </div>)}
        </div>
      </section>

      {planning && <section className="planning-card">
        <div className="hours-heading"><div><span className="aside-icon"><SlidersHorizontal size={18} /></span><div><h2>Kapacitetsplanlægning</h2><p>Tilpas synstyper, bemanding og buffertider uden at ændre kundernes visning.</p></div></div><span className={`planning-status ${planning.day.conflictStatus}`}><Check size={14} /> {planning.day.conflictStatus === "ok" ? "Passer med bemanding" : "Tjek dagens kapacitet"}</span></div>
        <div className="planning-day-controls"><label>Dag<input type="date" value={planningDate} onChange={(event) => setPlanningDate(event.target.value)} /></label><label>Kalenderprofil<select value={planningProfile} onChange={(event) => setPlanningProfile(event.target.value)}><option value="">Beregnet efter bemanding</option>{planning.profiles.map((profile) => <option value={profile.id} key={profile.id}>{profile.name}</option>)}</select></label><div className="planning-metrics"><span><strong>{planning.day.staffedInspectors}</strong> på arbejde</span><span><strong>{planning.day.capacityPerSlot}</strong> samtidige</span><span><strong>{planning.day.bufferCount}</strong> buffere</span></div><button className="primary-button" onClick={() => void savePlanningDay()}><Save size={15} /> Gem dagens plan</button></div>
        {planning.day.conflictStatus !== "ok" && <div className="planning-warning"><AlertTriangle size={16} /><span>Der er forskel mellem dagens bemanding og den valgte kapacitet. Eksisterende bookinger ændres ikke automatisk.</span></div>}
        <div className="planning-columns"><div><h3>Synstyper</h3><p className="planning-help">Antal sammenhængende bookingintervaller pr. syn.</p>{planning.inspectionTypes.map((type) => <div className="planning-type-row" key={type.id}><span><strong>{type.name}</strong>{type.name === "Toldsyn" && <small>Reserverer automatisk to tider</small>}</span><input aria-label={`${type.name} antal tider`} type="number" min="1" max="12" value={type.required_slots} onChange={(event) => setPlanning((current) => current && ({ ...current, inspectionTypes: current.inspectionTypes.map((item) => item.id === type.id ? { ...item, required_slots: Number(event.target.value) } : item) }))} /><button className="save-day" onClick={() => void saveInspectionType(type)}><Save size={14} /> Gem</button></div>)}</div><div><h3>Ekstra buffertider</h3><p className="planning-help">Interne buffere skjuler tiden for kunder, men påvirker ikke eksisterende bookinger.</p><div className="planning-buffer-form"><label>Fra<input type="time" value={bufferForm.startsAt} onChange={(event) => setBufferForm({ ...bufferForm, startsAt: event.target.value })} /></label><label>Til<input type="time" value={bufferForm.endsAt} onChange={(event) => setBufferForm({ ...bufferForm, endsAt: event.target.value })} /></label><input value={bufferForm.reason} onChange={(event) => setBufferForm({ ...bufferForm, reason: event.target.value })} placeholder="Årsag" /><button className="primary-button" onClick={() => void addBuffer()}><Plus size={15} /> Tilføj buffer</button></div><div className="planning-buffer-list">{planning.buffers.length === 0 && <span className="empty-state">Ingen ekstra buffere denne dag.</span>}{planning.buffers.map((buffer) => <article key={buffer.id}><span><strong>{buffer.starts_at.slice(0, 5)} – {buffer.ends_at.slice(0, 5)}</strong><small>{buffer.reason}</small></span><button aria-label={`Fjern buffer ${buffer.reason}`} onClick={() => void deleteBuffer(buffer.id)}><Trash2 size={15} /></button></article>)}</div></div></div>
      </section>}

      <div className="closure-layout">
        <section className="closure-card">
          <div className="hours-heading"><div><span className="aside-icon amber"><CalendarOff size={18} /></span><div><h2>Ferie og lukkedage</h2><p>Perioder blokerer automatisk nye bookinger.</p></div></div></div>
          <div className="closure-form">
            <label>Type<select value={closureForm.kind} onChange={(event) => setClosureForm({ ...closureForm, kind: event.target.value as "vacation" | "holiday" })}><option value="vacation">Ferie</option><option value="holiday">Helligdag/lukket</option></select></label>
            <label>Fra<input type="date" value={closureForm.dateFrom} onChange={(event) => setClosureForm({ ...closureForm, dateFrom: event.target.value })} /></label>
            <label>Til<input type="date" value={closureForm.dateTo} onChange={(event) => setClosureForm({ ...closureForm, dateTo: event.target.value })} /></label>
            <label className="closure-reason">Årsag<input value={closureForm.label} onChange={(event) => setClosureForm({ ...closureForm, label: event.target.value })} /></label>
            <button className="primary-button" onClick={() => void addClosure()}><Plus size={16} /> Tilføj</button>
          </div>
        </section>
        <section className="closure-card existing-closures">
          <h2>Planlagte lukkeperioder</h2>
          {closures.length === 0 && <p className="empty-state">Ingen planlagte lukkeperioder.</p>}
          {closures.map((closure) => <article key={closure.id}><span className="aside-icon amber"><CalendarOff size={16} /></span><div><strong>{closure.label}</strong><small>{closure.date_from && danishDate(closure.date_from)} – {closure.date_to && danishDate(closure.date_to)}</small></div><button aria-label={`Fjern ${closure.label}`} onClick={() => void deleteClosure(closure.id)}><Trash2 size={16} /></button></article>)}
        </section>
      </div>
    </div>
  );
}
