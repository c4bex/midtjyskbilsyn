"use client";

import { AlertTriangle, CalendarCheck2, CalendarOff, Check, Clock3, Plus, Save, SlidersHorizontal, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type Rule = { id: string; kind: string; weekday: number | null; starts_at: string | null; ends_at: string | null; date_from: string | null; date_to: string | null; label: string };
type Pause = { startsAt: string; endsAt: string };
type Day = { weekday: number; name: string; closed: boolean; startsAt: string; endsAt: string; breaks: Pause[] };
type InspectionType = { id: number; name: string; required_slots: number; is_active: boolean };
type CalendarProfile = { id: number; name: string; description: string | null; capacity_per_slot: number | null };
type PlanningBuffer = { id: number; date: string; starts_at: string; ends_at: string; reason: string; is_fixed: boolean };
type V2Slot = { time: string; publicState: string; internalState: string; visualType: string; sourceLabel?: string; profileName: string; staffOnDuty: number; bookedCount: number; canBookInternally: boolean };
type V2Profile = { id: number; name: string; staffing_level: string; public_capacity_per_start: number; auto_buffer_enabled: boolean; open_slots_per_cycle: number; buffer_slots_per_cycle: number; buffer_internal_bookable: boolean; suggest_buffer_move: boolean; buffer_move_direction: string; max_buffer_move_minutes: number; reset_pattern_after_closure: boolean };
type RecurringBuffer = { id: number; name: string; weekdays: string | number[]; start_local_time: string; duration_minutes: number; internal_bookable: boolean };
type V2Override = { id: number; starts_at: string; ends_at: string; override_type: string; reason: string; buffer_type?: string | null };
type V2Plan = { location: { name: string }; date: string; slots: V2Slot[]; publicAvailableSlots: string[]; internalAvailableSlots: string[]; bufferCount: number; closedCount: number; conflicts: V2Slot[]; profiles: string[] };
type PlanningData = { inspectionTypes: InspectionType[]; profiles: CalendarProfile[]; buffers: PlanningBuffer[]; day: { profileId: number | null; conflictStatus: "ok" | "warning" | "red"; staffedInspectors: number; capacityPerSlot: number; bufferCount: number; availableCapacity: number }; capacityPlannerV2?: { enabled: boolean; plan: V2Plan | null; profiles: V2Profile[]; recurringBuffers: RecurringBuffer[]; overrides: V2Override[] } };
type HolidaySuggestion = { key: string; name: string; date: string; weekday: string; weekend: boolean; past: boolean; alreadyClosed: boolean; closedBy: string | null };
const names = ["Mandag", "Tirsdag", "Onsdag", "Torsdag", "Fredag", "Lørdag", "Søndag"];
const blankDays = names.map((name, index) => ({ weekday: index + 1, name, closed: index > 4, startsAt: "08:00", endsAt: "16:20", breaks: [] as Pause[] }));
const currentDate = new Date().toISOString().slice(0, 10);
const danishDate = (date: string) => new Intl.DateTimeFormat("da-DK", { day: "numeric", month: "short", year: "numeric", timeZone: "Europe/Copenhagen" }).format(new Date(`${date}T12:00:00Z`));

export function AvailabilityView({ onNotify }: { onNotify: (message: string) => void }) {
  const [days, setDays] = useState<Day[]>(blankDays);
  const [closures, setClosures] = useState<Rule[]>([]);
  const [savingDay, setSavingDay] = useState<number | null>(null);
  const [closureForm, setClosureForm] = useState({ kind: "vacation" as "vacation" | "holiday", dateFrom: currentDate, dateTo: currentDate, label: "Sommerferie" });
  const [planningDate, setPlanningDate] = useState(currentDate);
  const [planning, setPlanning] = useState<PlanningData | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [customerPreview, setCustomerPreview] = useState(false);
  const [editingV2Profile, setEditingV2Profile] = useState<V2Profile | null>(null);
  const [recurringForm, setRecurringForm] = useState({ name: "Fast kontrolbuffer", weekdays: [1, 2, 3, 4, 5], startLocalTime: "10:00", durationMinutes: 20, internalBookable: true });
  const [overrideForm, setOverrideForm] = useState({ startsAt: "10:00", endsAt: "10:20", overrideType: "FORCE_BUFFER", reason: "Manuel buffer" });
  const [settingsView, setSettingsView] = useState<"day" | "buffers" | "advanced">("day");
  const [holidayYear, setHolidayYear] = useState(Number(currentDate.slice(0, 4)));
  const [holidaySuggestions, setHolidaySuggestions] = useState<HolidaySuggestion[]>([]);
  const [holidaysLoading, setHolidaysLoading] = useState(true);
  const [holidaysApplying, setHolidaysApplying] = useState(false);

  const applyRules = useCallback((rules: Rule[]) => {
    setDays(blankDays.map((day) => {
      const opening = rules.find((rule) => rule.weekday === day.weekday && rule.kind === "opening_hours");
      const pauses = rules.filter((rule) => rule.weekday === day.weekday && rule.kind === "break" && rule.starts_at && rule.ends_at).map((rule) => ({ startsAt: rule.starts_at!.slice(0, 5), endsAt: rule.ends_at!.slice(0, 5) }));
      const closed = rules.some((rule) => rule.weekday === day.weekday && rule.kind === "closed_day") || !opening;
      return { ...day, closed, startsAt: opening?.starts_at?.slice(0, 5) ?? day.startsAt, endsAt: opening?.ends_at?.slice(0, 5) ?? day.endsAt, breaks: pauses };
    }));
    setClosures(rules.filter((rule) => rule.kind === "holiday" || rule.kind === "vacation"));
  }, []);

  const reload = useCallback(() => fetch("/api/availability", { cache: "no-store" })
    .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ rules: Rule[] }>; })
    .then((data) => { applyRules(data.rules); setLoadError(false); })
    .catch(() => { setLoadError(true); onNotify("Åbningstiderne kunne ikke hentes"); }), [applyRules, onNotify]);

  useEffect(() => {
    let active = true;
    fetch("/api/availability", { cache: "no-store" })
      .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<{ rules: Rule[] }>; })
      .then((data) => { if (active) { applyRules(data.rules); setLoadError(false); } })
      .catch(() => { if (active) { setLoadError(true); onNotify("Åbningstiderne kunne ikke hentes"); } });
    return () => { active = false; };
  }, [applyRules, onNotify]);

  const loadHolidaySuggestions = useCallback(async () => {
    try {
      const response = await fetch(`/api/availability/holiday-suggestions?year=${holidayYear}`, { cache: "no-store" });
      const data = await response.json() as { holidays?: HolidaySuggestion[]; error?: string };
      if (!response.ok || !data.holidays) throw new Error(data.error ?? "Helligdagene kunne ikke hentes");
      setHolidaySuggestions(data.holidays);
    } catch (error) {
      setHolidaySuggestions([]);
      onNotify(error instanceof Error ? error.message : "Helligdagene kunne ikke hentes");
    } finally {
      setHolidaysLoading(false);
    }
  }, [holidayYear, onNotify]);

  useEffect(() => {
    let active = true;
    fetch(`/api/availability/holiday-suggestions?year=${holidayYear}`, { cache: "no-store" })
      .then(async (response) => {
        const data = await response.json() as { holidays?: HolidaySuggestion[]; error?: string };
        if (!response.ok || !data.holidays) throw new Error(data.error ?? "Helligdagene kunne ikke hentes");
        if (active) setHolidaySuggestions(data.holidays);
      })
      .catch((error: unknown) => { if (active) { setHolidaySuggestions([]); onNotify(error instanceof Error ? error.message : "Helligdagene kunne ikke hentes"); } })
      .finally(() => { if (active) setHolidaysLoading(false); });
    return () => { active = false; };
  }, [holidayYear, onNotify]);

  const reloadPlanning = useCallback(() => fetch(`/api/planning?date=${planningDate}`, { cache: "no-store" })
    .then((response) => { if (!response.ok) throw new Error(); return response.json() as Promise<PlanningData>; })
    .then((data) => setPlanning(data))
    .catch(() => onNotify("Kapacitetsplanen kunne ikke hentes")), [onNotify, planningDate]);

  useEffect(() => { void reloadPlanning(); }, [reloadPlanning]);

  const updateDay = (weekday: number, patch: Partial<Day>) => setDays((current) => current.map((day) => day.weekday === weekday ? { ...day, ...patch } : day));

  const addPause = (day: Day) => {
    const suggestion = day.breaks.length === 0 ? { startsAt: "09:00", endsAt: "09:20" } : day.breaks.length === 1 ? { startsAt: "12:00", endsAt: "12:40" } : { startsAt: "14:00", endsAt: "14:20" };
    updateDay(day.weekday, { breaks: [...day.breaks, suggestion] });
  };

  const updatePause = (day: Day, index: number, patch: Partial<Pause>) => updateDay(day.weekday, { breaks: day.breaks.map((pause, pauseIndex) => pauseIndex === index ? { ...pause, ...patch } : pause) });
  const removePause = (day: Day, index: number) => updateDay(day.weekday, { breaks: day.breaks.filter((_, pauseIndex) => pauseIndex !== index) });

  const saveDay = async (day: Day) => {
    setSavingDay(day.weekday);
    try {
      const response = await fetch("/api/availability", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(day) });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Dagen kunne ikke gemmes");
      onNotify(`${day.name} er gemt`);
      await Promise.all([reload(), loadHolidaySuggestions()]);
    } catch (error) { onNotify(error instanceof Error ? error.message : "Dagen kunne ikke gemmes"); }
    finally { setSavingDay(null); }
  };

  const addClosure = async () => {
    try {
      const response = await fetch("/api/availability", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(closureForm) });
      const data = await response.json() as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Lukkedagen kunne ikke gemmes");
      onNotify("Lukkeperioden er tilføjet");
      await Promise.all([reload(), loadHolidaySuggestions()]);
    } catch (error) { onNotify(error instanceof Error ? error.message : "Lukkedagen kunne ikke gemmes"); }
  };

  const deleteClosure = async (id: string) => {
    const response = await fetch(`/api/availability/${id}`, { method: "DELETE" });
    if (response.ok) { onNotify("Lukkeperioden er fjernet"); await Promise.all([reload(), loadHolidaySuggestions()]); } else onNotify("Lukkeperioden kunne ikke fjernes");
  };

  const applyHolidaySuggestions = async (dates: string[]) => {
    if (dates.length === 0) return;
    setHolidaysApplying(true);
    try {
      const response = await fetch("/api/availability/holiday-suggestions/apply", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ year: holidayYear, dates }) });
      const data = await response.json() as { createdCount?: number; error?: string };
      if (!response.ok) throw new Error(data.error ?? "Helligdagene kunne ikke tilføjes");
      const count = data.createdCount ?? 0;
      onNotify(count === 1 ? "Helligdagen er nu lukket" : `${count} helligdage er nu lukkede`);
      await Promise.all([reload(), loadHolidaySuggestions()]);
    } catch (error) {
      onNotify(error instanceof Error ? error.message : "Helligdagene kunne ikke tilføjes");
    } finally {
      setHolidaysApplying(false);
    }
  };

  const saveInspectionType = async (type: InspectionType) => {
    const response = await fetch(`/api/planning/inspection-types/${type.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ requiredSlots: type.required_slots, isActive: type.is_active }) });
    if (!response.ok) { onNotify("Synstypen kunne ikke gemmes"); return; }
    onNotify(`${type.name} er gemt`); await reloadPlanning();
  };

  const saveV2Profile = async () => {
    if (!editingV2Profile) return;
    const response = await fetch(`/api/planning/v2/profiles/${editingV2Profile.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ name: editingV2Profile.name, staffingLevel: editingV2Profile.staffing_level, publicCapacityPerStart: editingV2Profile.public_capacity_per_start, autoBufferEnabled: editingV2Profile.auto_buffer_enabled, openSlotsPerCycle: editingV2Profile.open_slots_per_cycle, bufferSlotsPerCycle: editingV2Profile.buffer_slots_per_cycle, bufferInternalBookable: editingV2Profile.buffer_internal_bookable, suggestBufferMove: false, bufferMoveDirection: editingV2Profile.buffer_move_direction, maxBufferMoveMinutes: editingV2Profile.max_buffer_move_minutes, resetPatternAfterClosure: false }) });
    if (!response.ok) { const data = await response.json().catch(() => ({})) as { message?: string; error?: string }; onNotify(data.error ?? data.message ?? "Profilen kunne ikke gemmes"); return; }
    setEditingV2Profile(null); onNotify("Kapacitetsprofilen er gemt"); await reloadPlanning();
  };

  const addRecurringBuffer = async () => {
    const response = await fetch("/api/planning/v2/recurring-buffers", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ location: "ikast", ...recurringForm }) });
    const data = await response.json().catch(() => ({})) as { error?: string; message?: string };
    if (!response.ok) { onNotify(data.error ?? data.message ?? "Den faste buffer kunne ikke gemmes"); return; }
    onNotify("Den faste buffer er gemt for serien"); await reloadPlanning();
  };

  const deleteRecurringBuffer = async (id: number) => {
    const response = await fetch(`/api/planning/v2/recurring-buffers/${id}`, { method: "DELETE" });
    if (!response.ok) { onNotify("Den faste buffer kunne ikke fjernes"); return; }
    onNotify("Bufferserien er fjernet; eksisterende bookinger er urørte"); await reloadPlanning();
  };

  const addOverride = async () => {
    const response = await fetch("/api/planning/v2/overrides", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ location: "ikast", date: planningDate, ...overrideForm, locked: true }) });
    const data = await response.json().catch(() => ({})) as { error?: string; message?: string };
    if (!response.ok) { onNotify(data.error ?? data.message ?? "Den manuelle ændring kunne ikke gemmes"); return; }
    onNotify("Den manuelle ændring er gemt for dagen"); await reloadPlanning();
  };

  const deleteOverride = async (id: number) => {
    const response = await fetch(`/api/planning/v2/overrides/${id}`, { method: "DELETE" });
    const data = await response.json().catch(() => ({})) as { error?: string; message?: string };
    if (!response.ok) { onNotify(data.error ?? data.message ?? "Den manuelle ændring kunne ikke fjernes"); return; }
    onNotify("Den manuelle ændring er fjernet"); await reloadPlanning();
  };

  const pendingHolidays = holidaySuggestions.filter((holiday) => !holiday.alreadyClosed && !holiday.past);

  return (
    <div className="module-view">
      <section className="page-heading"><div><p className="eyebrow">Administration</p><h1>Åbningstider</h1><p>Styr normal uge, pauser, ferie og lukkedage.</p></div></section>
      {loadError && <div className="module-error"><span>Åbningstiderne kunne ikke hentes. Felterne nedenfor er derfor ikke sikre at gemme.</span><button onClick={() => void reload()}>Prøv igen</button></div>}
      <section className="capacity-settings-nav"><div className="capacity-settings-title"><div><h2>Hvad vil du indstille?</h2><p>Vælg ét område. Du ser kun de felter, der hører til opgaven.</p></div><label>Valgt dag<input type="date" value={planningDate} onChange={(event) => setPlanningDate(event.target.value)} /></label></div><div><button className={settingsView === "day" ? "selected" : ""} onClick={() => setSettingsView("day")}><Clock3 size={18} /><span><strong>Dag og åbningstider</strong><small>Se dagen og ret den normale uge</small></span></button><button className={settingsView === "buffers" ? "selected" : ""} onClick={() => setSettingsView("buffers")}><CalendarOff size={18} /><span><strong>Buffere</strong><small>Faste eller enkelte buffertider</small></span></button><button className={settingsView === "advanced" ? "selected" : ""} onClick={() => setSettingsView("advanced")}><SlidersHorizontal size={18} /><span><strong>Avanceret</strong><small>Profiler og længde på synstyper</small></span></button></div></section>
      {settingsView === "day" && <section className="hours-card">
        <div className="hours-heading"><div><span className="aside-icon"><Clock3 size={18} /></span><div><h2>Normal uge</h2><p>Bookingtider beregnes automatisk i intervaller på 20 minutter.</p></div></div><span className="saved-note"><Check size={14} /> Gemmes i databasen</span></div>
        <div className="hours-table-head multi-break-head"><span>Dag</span><span>Åben</span><span>Åbningstid</span><span>Pauser</span><span /></div>
        <div className="hours-list">
          {days.map((day) => <div className={`hours-row ${day.closed ? "closed" : ""}`} key={day.weekday}>
            <strong>{day.name}</strong>
            <label className="switch"><input type="checkbox" checked={!day.closed} onChange={(event) => updateDay(day.weekday, { closed: !event.target.checked })} /><span /></label>
            <div className="opening-time-pair"><label>Fra<input aria-label={`${day.name} åbner`} type="time" step="1200" value={day.startsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { startsAt: event.target.value })} /></label><span>–</span><label>Til<input aria-label={`${day.name} lukker`} type="time" step="1200" value={day.endsAt} disabled={day.closed} onChange={(event) => updateDay(day.weekday, { endsAt: event.target.value })} /></label></div>
            <div className="day-pauses">{day.breaks.map((pause, index) => <div className="pause-row" key={`${index}-${pause.startsAt}`}><span>Pause {index + 1}</span><input aria-label={`${day.name} pause ${index + 1} fra`} type="time" step="1200" value={pause.startsAt} disabled={day.closed} onChange={(event) => updatePause(day, index, { startsAt: event.target.value })} /><em>–</em><input aria-label={`${day.name} pause ${index + 1} til`} type="time" step="1200" value={pause.endsAt} disabled={day.closed} onChange={(event) => updatePause(day, index, { endsAt: event.target.value })} /><button aria-label={`Fjern pause ${index + 1} fra ${day.name}`} disabled={day.closed} onClick={() => removePause(day, index)}><Trash2 size={14} /></button></div>)}{!day.closed && day.breaks.length < 8 && <button className="add-pause" onClick={() => addPause(day)}><Plus size={14} /> Tilføj pause</button>}{day.closed && <span className="closed-day-note">Dagen er lukket</span>}</div>
            <button className="save-day" disabled={loadError || savingDay === day.weekday} onClick={() => void saveDay(day)}><Save size={15} /> {savingDay === day.weekday ? "Gemmer" : "Gem"}</button>
          </div>)}
        </div>
      </section>}

      {settingsView === "advanced" && planning && <section className="planning-card"><div className="hours-heading"><div><span className="aside-icon"><SlidersHorizontal size={18} /></span><div><h2>Længde på synstyper</h2><p>Ét interval er 20 minutter. Toldsyn bruger som standard to sammenhængende intervaller.</p></div></div></div><div><h3>Synstyper</h3><p className="planning-help">Ændr kun dette, hvis selve tidsforbruget på en synstype ændres.</p>{planning.inspectionTypes.map((type) => <div className="planning-type-row" key={type.id}><span><strong>{type.name}</strong><small>{type.required_slots * 20} minutter i kalenderen</small></span><input aria-label={`${type.name} antal tider`} type="number" min="1" max="12" value={type.required_slots} onChange={(event) => setPlanning((current) => current && ({ ...current, inspectionTypes: current.inspectionTypes.map((item) => item.id === type.id ? { ...item, required_slots: Number(event.target.value) } : item) }))} /><button className="save-day" onClick={() => void saveInspectionType(type)}><Save size={14} /> Gem</button></div>)}</div></section>}

      {planning?.capacityPlannerV2?.enabled && planning.capacityPlannerV2.plan && <section className="capacity-v2-card">
        <div className="hours-heading"><div><span className="aside-icon amber"><SlidersHorizontal size={18} /></span><div><h2>{settingsView === "day" ? "Sådan ser dagen ud" : settingsView === "buffers" ? "Administrér buffere" : "Kapacitetsprofiler"}</h2><p>{settingsView === "day" ? "Grøn er almindelig kundetid, gul er intern buffer, og grå er lukket." : settingsView === "buffers" ? "Opret faste buffere eller en enkelt ændring på den valgte dag." : "Disse standardprofiler vælger systemet automatisk ud fra bemandingen."}</p></div></div>{settingsView === "day" && <button className="secondary-button" onClick={() => setCustomerPreview((value) => !value)}>{customerPreview ? "Vis intern plan" : "Vis dagen som kunden"}</button>}</div>
        {settingsView !== "advanced" && <div className="capacity-v2-summary"><span><strong>{planning.capacityPlannerV2.plan.location.name}</strong> afdeling</span><span><strong>{planning.capacityPlannerV2.plan.profiles.join(" / ")}</strong> aktiv profil</span><span><strong>{planning.capacityPlannerV2.plan.publicAvailableSlots.length}</strong> ledige kundetider</span><span><strong>{planning.capacityPlannerV2.plan.bufferCount}</strong> buffere</span><span><strong>{planning.capacityPlannerV2.plan.conflicts.length}</strong> konflikter</span></div>}
        {planning.capacityPlannerV2.plan.conflicts.length > 0 && <div className="planning-warning"><AlertTriangle size={16} /> Eksisterende bookinger er bevaret, men dagen har bemandingskonflikter.</div>}
        {settingsView !== "advanced" && <div className="capacity-v2-legend"><span><i className="normal" /> Almindelig kundetid</span><span><i className="buffer" /> Intern buffer</span><span><i className="booked" /> Booket</span><span><i className="closed" /> Lukket/passeret</span></div>}
        {settingsView !== "advanced" && <div className="capacity-v2-grid">{planning.capacityPlannerV2.plan.slots.map((slot) => { const hidden = customerPreview && slot.publicState !== "OPEN"; return <article key={slot.time} className={`capacity-v2-slot ${hidden ? "customer-hidden" : slot.visualType.toLowerCase().replace("_", "-")}`}><strong>{slot.time}</strong><span>{customerPreview ? (slot.publicState === "OPEN" ? "Ledig" : "Ikke synlig") : slot.visualType === "AUTO_BUFFER" ? "Automatisk buffer" : slot.visualType === "RECURRING_BUFFER" ? "Fast buffer" : slot.visualType === "MANUAL_BUFFER" || slot.visualType === "MOVED_BUFFER" ? "Manuel buffer" : slot.visualType === "BOOKED" ? "Booket" : slot.visualType === "CLOSED" ? "Lukket" : "Kundetid"}</span>{!customerPreview && <small>{slot.sourceLabel ?? `${slot.staffOnDuty} på arbejde`}{slot.internalState === "BUFFER" && slot.canBookInternally ? " · Kan bookes internt" : ""}</small>}</article>; })}</div>}
        {settingsView === "advanced" && <div className="capacity-v2-profiles"><h3>Systemets tre standardprofiler</h3><p className="planning-help">Systemet vælger automatisk profil efter antal medarbejdere på arbejde. Du behøver normalt ikke ændre dem.</p>{planning.capacityPlannerV2.profiles.map((profile) => <button key={profile.id} className="capacity-profile-row" onClick={() => setEditingV2Profile({ ...profile })}><span><strong>{profile.name}</strong><small>{profile.staffing_level === "ONE" ? `${profile.open_slots_per_cycle} kundetider efterfulgt af ${profile.buffer_slots_per_cycle} buffer` : profile.staffing_level === "ZERO" ? "Lukket ved nul bemanding" : "Alle blokke offentligt ledige"}</small></span><span>Rediger</span></button>)}</div>}
        {settingsView === "advanced" && editingV2Profile && <div className="capacity-profile-editor"><h3>Rediger {editingV2Profile.name}</h3><p className="planning-help">Mønstret forankres i dagens klokkeslæt. Pauser, passerede tider og interne bookinger flytter derfor ikke buffertiderne.</p><label>Navn<input value={editingV2Profile.name} onChange={(event) => setEditingV2Profile({ ...editingV2Profile, name: event.target.value })} /></label><label>Kundetider før buffer<input type="number" min="0" max="12" value={editingV2Profile.open_slots_per_cycle} onChange={(event) => setEditingV2Profile({ ...editingV2Profile, open_slots_per_cycle: Number(event.target.value) })} /></label><label>Buffertider efter kundetider<input type="number" min="0" max="12" value={editingV2Profile.buffer_slots_per_cycle} onChange={(event) => setEditingV2Profile({ ...editingV2Profile, buffer_slots_per_cycle: Number(event.target.value) })} /></label><label><input type="checkbox" checked={editingV2Profile.auto_buffer_enabled} onChange={(event) => setEditingV2Profile({ ...editingV2Profile, auto_buffer_enabled: event.target.checked })} /> Brug automatisk buffer</label><label><input type="checkbox" checked={editingV2Profile.buffer_internal_bookable} onChange={(event) => setEditingV2Profile({ ...editingV2Profile, buffer_internal_bookable: event.target.checked })} /> Buffer kan bookes internt</label><div><button className="secondary-button" onClick={() => setEditingV2Profile(null)}>Annuller</button><button className="primary-button" onClick={() => void saveV2Profile()}><Save size={15} /> Gem profil</button></div></div>}
        {settingsView === "buffers" && <div className="capacity-v2-tools"><section><span className="setup-step">1</span><h3>Fast buffer</h3><p>Brug denne, når samme buffer skal gentages hver uge.</p><div className="capacity-v2-tool-form"><label>Navn<input value={recurringForm.name} onChange={(event) => setRecurringForm({ ...recurringForm, name: event.target.value })} placeholder="Fx kontrolbuffer" /></label><label>Starter kl.<input type="time" step="1200" value={recurringForm.startLocalTime} onChange={(event) => setRecurringForm({ ...recurringForm, startLocalTime: event.target.value })} /></label><label>Varighed<select value={recurringForm.durationMinutes} onChange={(event) => setRecurringForm({ ...recurringForm, durationMinutes: Number(event.target.value) })}><option value={20}>20 min.</option><option value={40}>40 min.</option><option value={60}>60 min.</option></select></label><div className="weekday-pills"><strong>Gentag på</strong>{names.map((name, index) => <label key={name}><input type="checkbox" checked={recurringForm.weekdays.includes(index + 1)} onChange={(event) => setRecurringForm({ ...recurringForm, weekdays: event.target.checked ? [...recurringForm.weekdays, index + 1].sort() : recurringForm.weekdays.filter((day) => day !== index + 1) })} />{name.slice(0, 3)}</label>)}</div><button className="primary-button" onClick={() => void addRecurringBuffer()}><Plus size={14} /> Opret fast buffer</button></div><div className="capacity-v2-rule-list">{planning.capacityPlannerV2.recurringBuffers.map((buffer) => <article key={buffer.id}><span><strong>{buffer.name}</strong><small>{buffer.start_local_time.slice(0, 5)} · {buffer.duration_minutes} min.</small></span><button aria-label={`Fjern ${buffer.name}`} onClick={() => void deleteRecurringBuffer(buffer.id)}><Trash2 size={14} /></button></article>)}</div></section><section><span className="setup-step">2</span><h3>Enkelt ændring på {danishDate(planningDate)}</h3><p>Brug denne, når ændringen kun gælder den valgte dag.</p><div className="capacity-v2-tool-form"><label>Fra<input type="time" step="1200" value={overrideForm.startsAt} onChange={(event) => setOverrideForm({ ...overrideForm, startsAt: event.target.value })} /></label><label>Til<input type="time" step="1200" value={overrideForm.endsAt} onChange={(event) => setOverrideForm({ ...overrideForm, endsAt: event.target.value })} /></label><label>Hvad skal ske?<select value={overrideForm.overrideType} onChange={(event) => setOverrideForm({ ...overrideForm, overrideType: event.target.value })}><option value="FORCE_BUFFER">Gør til buffer</option><option value="FORCE_PUBLIC_OPEN">Åbn som kundetid</option><option value="FORCE_INTERNAL_ONLY">Kun intern booking</option><option value="FORCE_CLOSED">Luk helt</option></select></label><label>Begrundelse<input value={overrideForm.reason} onChange={(event) => setOverrideForm({ ...overrideForm, reason: event.target.value })} placeholder="Hvorfor ændres tiden?" /></label><button className="primary-button" onClick={() => void addOverride()}><Save size={14} /> Gem denne dag</button></div><div className="capacity-v2-rule-list">{planning.capacityPlannerV2.overrides.map((override) => <article key={override.id}><span><strong>{override.starts_at.slice(11, 16)} – {override.ends_at.slice(11, 16)}</strong><small>{override.reason}</small></span>{override.buffer_type !== "MOVED" && <button aria-label={`Fjern ${override.reason}`} onClick={() => void deleteOverride(override.id)}><Trash2 size={14} /></button>}</article>)}</div></section></div>}
      </section>}

      {settingsView === "day" && <div className="closure-layout">
        <section className="closure-card holiday-suggestions">
          <div className="hours-heading holiday-suggestions-heading"><div><span className="aside-icon amber"><CalendarCheck2 size={18} /></span><div><h2>Forslag til danske helligdage</h2><p>Systemet finder dagene automatisk. I vælger selv, hvilke dage der skal lukkes.</p></div></div><div className="holiday-actions"><label>År<select aria-label="År for helligdage" value={holidayYear} onChange={(event) => { setHolidaysLoading(true); setHolidayYear(Number(event.target.value)); }}>{Array.from({ length: 5 }, (_, index) => Number(currentDate.slice(0, 4)) + index).map((year) => <option value={year} key={year}>{year}</option>)}</select></label><button className="primary-button" disabled={holidaysLoading || holidaysApplying || pendingHolidays.length === 0} onClick={() => void applyHolidaySuggestions(pendingHolidays.map((holiday) => holiday.date))}><Check size={15} /> {holidaysApplying ? "Tilføjer…" : `Luk alle forslag (${pendingHolidays.length})`}</button></div></div>
          <div className="holiday-help">Listen indeholder de officielle danske helligdage. Grundlovsdag, 1. maj samt 24. og 31. december kan stadig tilføjes manuelt nedenfor.</div>
          {holidaysLoading ? <p className="holiday-loading">Finder helligdage…</p> : <div className="holiday-grid">{holidaySuggestions.map((holiday) => {
            const status = holiday.past ? "Passeret" : holiday.alreadyClosed ? "Allerede lukket" : "Forslag";
            return <article className={`holiday-suggestion ${holiday.past || holiday.alreadyClosed ? "resolved" : "pending"}`} key={holiday.key}><span><strong>{holiday.name}</strong><small>{holiday.weekday} · {danishDate(holiday.date)}</small>{holiday.alreadyClosed && holiday.closedBy && <em>{holiday.closedBy}</em>}</span>{holiday.past || holiday.alreadyClosed ? <span className="holiday-status"><Check size={13} /> {status}</span> : <button disabled={holidaysApplying} onClick={() => void applyHolidaySuggestions([holiday.date])}><Plus size={13} /> Luk dagen</button>}</article>;
          })}</div>}
        </section>
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
      </div>}
    </div>
  );
}
