"use client";

import { AlertTriangle, CheckCircle2, Clock3, FileWarning, RefreshCw } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type Event = { action: string; entity_type: string; occurred_at: number; actor_id?: string };
type Health = {
  status: "ok" | "degraded" | "down";
  checkedAt?: string;
  database?: string;
  modules?: Record<string, "ok" | "degraded" | "down">;
  issues?: Array<{ module: string; message: string }>;
  unresolvedErrors?: number;
  integrations?: { dmr?: boolean; gatewayapi?: boolean; dinero?: boolean; synsprogram?: boolean };
};
type ImportBatch = { batchId: string; status: string; rows: number; source: string; createdAt: string };

const moduleLabels: Record<string, string> = {
  availability: "Bookingkapacitet", employees: "Medarbejdere", businessPortal: "Branchekundeportal",
  invoicing: "Fakturering", sms: "SMS",
};

export function DriftView() {
  const [events, setEvents] = useState<Event[]>([]);
  const [health, setHealth] = useState<Health | null>(null);
  const [imports, setImports] = useState<ImportBatch[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");

  const load = useCallback(async () => {
    setLoading(true); setLoadError("");
    try {
      const [auditResponse, healthResponse, importsResponse] = await Promise.all([fetch("/api/audit", { cache: "no-store" }), fetch("/api/health", { cache: "no-store" }), fetch("/api/imports", { cache: "no-store" })]);
      const currentHealth = await healthResponse.json() as Health;
      setHealth(currentHealth);
      if (auditResponse.ok) setEvents(((await auditResponse.json()) as { events?: Event[] }).events ?? []);
      if (importsResponse.ok) setImports(((await importsResponse.json()) as { imports?: ImportBatch[] }).imports ?? []);
      if (!auditResponse.ok || !importsResponse.ok) setLoadError("Nogle driftsdata kunne ikke hentes. Systemstatus nedenfor er stadig opdateret.");
    } catch {
      setHealth({ status: "down", database: "ukendt", issues: [{ module: "api", message: "Driftsserveren svarer ikke" }], unresolvedErrors: 1 });
      setLoadError("Driftsserveren svarer ikke. Kontrollér at backend er startet, og prøv igen.");
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);
  const statusLabel = loading ? "Kontrollerer systemet…" : health?.status === "ok" ? "Systemet kører normalt" : health?.status === "degraded" ? "Systemet kører med fejl" : "Systemet er ikke tilgængeligt";
  const StatusIcon = health?.status === "ok" ? CheckCircle2 : AlertTriangle;

  return <div className="module-view drift-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Drift</p><h1>Driftsoverblik</h1><p>Aktuel status for database, bookingmotor og centrale moduler.</p></div><button className="secondary-button" disabled={loading} onClick={() => void load()}><RefreshCw size={15} /> {loading ? "Kontrollerer…" : "Kontrollér igen"}</button></section>
    {loadError && <div className="module-error" role="alert">{loadError}</div>}
    <div className="drift-cards">
      <article className={`health-${health?.status ?? "loading"}`}><StatusIcon size={20} /><strong>{statusLabel}</strong><small>Database: {health?.database ?? "…"}</small></article>
      <article><Clock3 size={20} /><strong>Integrationer</strong><small>DMR: {health?.integrations?.dmr ? "aktiv" : "slukket"} · Dinero: {health?.integrations?.dinero ? "aktiv" : "slukket"}</small></article>
      <article><FileWarning size={20} /><strong>Fejllog</strong><small>{health?.unresolvedErrors ?? 0} aktuelle fejl</small></article>
    </div>
    <section className="employee-card audit-card"><h2>Modulstatus</h2>{Object.keys(health?.modules ?? {}).length === 0 ? <p className="empty-state">Ingen modulstatus tilgængelig.</p> : Object.entries(health?.modules ?? {}).map(([module, status]) => <div className="audit-row" key={module}><strong>{moduleLabels[module] ?? module}</strong><span>{status === "ok" ? "OK" : status === "degraded" ? "Kræver opsætning" : "Fejl"}</span></div>)}{health?.issues?.map((issue, index) => <div className="module-error" key={`${issue.module}-${index}`}><strong>{moduleLabels[issue.module] ?? issue.module}:</strong> {issue.message}</div>)}</section>
    <section className="employee-card audit-card"><h2>Importbatches</h2>{imports.length === 0 ? <p className="empty-state">Ingen importer registreret.</p> : imports.map((batch) => <div className="audit-row" key={batch.batchId}><strong>{batch.rows} rækker · {batch.status}</strong><span>{new Date(batch.createdAt).toLocaleString("da-DK")}</span></div>)}</section>
    <section className="employee-card audit-card"><h2>Seneste systemhændelser</h2>{events.length === 0 ? <p className="empty-state">Ingen hændelser endnu.</p> : events.map((event, index) => <div className="audit-row" key={`${event.occurred_at}-${index}`}><strong>{event.action}</strong><span>{new Date(event.occurred_at).toLocaleString("da-DK")}</span></div>)}</section>
  </div>;
}
