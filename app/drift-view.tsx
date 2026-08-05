"use client";
import { CheckCircle2, Clock3, FileWarning } from "lucide-react";
import { useEffect, useState } from "react";

type Event = { action: string; entity_type: string; occurred_at: number; actor_id?: string };
type Health = { status: string; database?: string; integrations?: { dmr?: boolean; gatewayapi?: boolean; dinero?: boolean; synsprogram?: boolean } };
type ImportBatch = { batchId: string; status: string; rows: number; source: string; createdAt: string };
export function DriftView() {
  const [events, setEvents] = useState<Event[]>([]);
  const [health, setHealth] = useState<Health | null>(null);
  const [imports, setImports] = useState<ImportBatch[]>([]);
  useEffect(() => { void Promise.all([fetch("/api/audit").then((response) => response.ok ? response.json() : null), fetch("/api/health").then((response) => response.json()), fetch("/api/imports").then((response) => response.ok ? response.json() : null)]).then(([audit, currentHealth, importData]) => { if (audit?.events) setEvents(audit.events); setHealth(currentHealth); if (importData?.imports) setImports(importData.imports); }).catch(() => undefined); }, []);
  return <div className="module-view drift-view"><section className="page-heading"><div><p className="eyebrow">Administration · Drift</p><h1>Driftsoverblik</h1><p>Et hurtigt overblik over systemets status og seneste ændringer.</p></div></section><div className="drift-cards"><article><CheckCircle2 size={20} /><strong>{health?.status === "ok" ? "Systemet kører normalt" : "Systemstatus kontrolleres"}</strong><small>Database: {health?.database ?? "…"}</small></article><article><Clock3 size={20} /><strong>Integrationer</strong><small>DMR: {health?.integrations?.dmr ? "aktiv" : "slukket"} · Dinero: {health?.integrations?.dinero ? "aktiv" : "slukket"}</small></article><article><FileWarning size={20} /><strong>Fejllog</strong><small>0 uløste fejl</small></article></div><section className="employee-card audit-card"><h2>Importbatches</h2>{imports.length === 0 ? <p className="empty-state">Ingen importer registreret.</p> : imports.map((batch) => <div className="audit-row" key={batch.batchId}><strong>{batch.rows} rækker · {batch.status}</strong><span>{new Date(batch.createdAt).toLocaleString("da-DK")}</span></div>)}</section><section className="employee-card audit-card"><h2>Seneste systemhændelser</h2>{events.length === 0 ? <p className="empty-state">Ingen hændelser endnu.</p> : events.map((event, index) => <div className="audit-row" key={`${event.occurred_at}-${index}`}><strong>{event.action}</strong><span>{new Date(event.occurred_at).toLocaleString("da-DK")}</span></div>)}</section></div>;
}
