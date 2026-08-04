"use client";
import { CheckCircle2, Clock3, FileWarning } from "lucide-react";
import { useEffect, useState } from "react";

type Event = { action: string; entity_type: string; occurred_at: number; actor_id?: string };
export function DriftView() {
  const [events, setEvents] = useState<Event[]>([]);
  useEffect(() => { void fetch("/api/audit").then((response) => response.ok ? response.json() : null).then((data) => { if (data?.events) setEvents(data.events); }).catch(() => undefined); }, []);
  return <div className="module-view drift-view"><section className="page-heading"><div><p className="eyebrow">Administration · Drift</p><h1>Driftsoverblik</h1><p>Et hurtigt overblik over systemets status og seneste ændringer.</p></div></section><div className="drift-cards"><article><CheckCircle2 size={20} /><strong>Systemet kører normalt</strong><small>Ingen aktive fejl registreret</small></article><article><Clock3 size={20} /><strong>Senest kontrolleret</strong><small>Live ved åbning af siden</small></article><article><FileWarning size={20} /><strong>Fejllog</strong><small>0 uløste fejl</small></article></div><section className="employee-card audit-card"><h2>Seneste systemhændelser</h2>{events.length === 0 ? <p className="empty-state">Ingen hændelser endnu.</p> : events.map((event, index) => <div className="audit-row" key={`${event.occurred_at}-${index}`}><strong>{event.action}</strong><span>{new Date(event.occurred_at).toLocaleString("da-DK")}</span></div>)}</section></div>;
}
