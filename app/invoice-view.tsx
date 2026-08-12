"use client";

import { Check, RefreshCw, ShieldCheck } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";

type InvoiceStatus = "Klargøres" | "Klar til Dinero" | "APPROVED" | "requires_action";
type InvoiceCheck = { severity: "error" | "warning" | "info"; message: string };
type Invoice = { id: string; customer: string; period: string; description: string; quantity: number; price: number; status: InvoiceStatus; checks: InvoiceCheck[] };
type InvoiceApi = { invoices: Array<{ id: string; customer_name: string; period: string; description: string; quantity: number; unit_price_ore: number; status: InvoiceStatus; checks?: InvoiceCheck[] }>; dineroReady?: boolean };

export function InvoiceView({ onNotify }: { onNotify: (message: string) => void }) {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [selectedId, setSelectedId] = useState("");
  const [selectedPeriod, setSelectedPeriod] = useState("");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [dineroReady, setDineroReady] = useState(false);

  const load = useCallback(async () => {
    setLoading(true); setLoadError("");
    try {
      const response = await fetch("/api/invoices", { cache: "no-store" });
      if (!response.ok) throw new Error("Fakturaklargøringen kunne ikke hentes");
      const data = await response.json() as InvoiceApi;
      const loaded = data.invoices.map((invoice) => ({ id: String(invoice.id), customer: invoice.customer_name, period: invoice.period, description: invoice.description, quantity: Number(invoice.quantity), price: invoice.unit_price_ore / 100, status: invoice.status, checks: invoice.checks ?? [] }));
      setInvoices(loaded); setDineroReady(Boolean(data.dineroReady));
      setSelectedPeriod((current) => current && loaded.some((invoice) => invoice.period === current) ? current : loaded[0]?.period ?? "");
      setSelectedId((current) => loaded.some((invoice) => invoice.id === current) ? current : loaded[0]?.id ?? "");
    } catch (error) { setInvoices([]); setLoadError(error instanceof Error ? error.message : "Fakturaklargøringen kunne ikke hentes"); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);

  const periods = useMemo(() => [...new Set(invoices.map((invoice) => invoice.period))], [invoices]);
  const filtered = invoices.filter((invoice) => invoice.period === selectedPeriod);
  const selected = filtered.find((invoice) => invoice.id === selectedId) ?? filtered[0];
  const total = filtered.reduce((sum, invoice) => sum + invoice.quantity * invoice.price, 0);
  const update = (changes: Partial<Invoice>) => { if (selected) setInvoices((current) => current.map((invoice) => invoice.id === selected.id ? { ...invoice, ...changes } : invoice)); };
  const hasBlockingErrors = selected?.checks.some((check) => check.severity === "error") ?? false;

  const save = async () => {
    if (!selected) return; setSaving(true);
    try {
      const response = await fetch("/api/invoices", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ id: selected.id, description: selected.description, quantity: selected.quantity, unitPriceOre: Math.round(selected.price * 100), status: selected.status === "requires_action" ? "requires_action" : "Klargøres", reason: reason || undefined }) });
      const data = await response.json().catch(() => ({})) as { error?: string; message?: string };
      if (!response.ok) throw new Error(data.error || data.message || "Fakturaen kunne ikke gemmes");
      setReason(""); onNotify("Fakturaændringen er gemt"); await load();
    } catch (error) { onNotify(error instanceof Error ? error.message : "Fakturaen kunne ikke gemmes"); }
    finally { setSaving(false); }
  };
  const approve = async () => {
    if (!selected || hasBlockingErrors) { onNotify("Ret de blokerende fejl, før fakturaen godkendes"); return; }
    setSaving(true);
    try {
      const response = await fetch("/api/invoices/approve", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ ids: [Number(selected.id)] }) });
      const data = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(data.error || "Fakturaen kan ikke godkendes endnu");
      onNotify("Fakturaen er godkendt og låst"); await load();
    } catch (error) { onNotify(error instanceof Error ? error.message : "Fakturaen kan ikke godkendes endnu"); }
    finally { setSaving(false); }
  };

  if (loading) return <div className="module-view invoice-view"><p className="eyebrow">Økonomi · Fakturaklargøring</p><h1>Fakturering</h1><div className="module-loading">Indlæser fakturaklargøring…</div></div>;
  if (loadError) return <div className="module-view invoice-view"><section className="page-heading"><div><p className="eyebrow">Økonomi · Fakturaklargøring</p><h1>Fakturering</h1></div></section><div className="module-error"><span>{loadError}. Ingen demonstrationsfakturaer vises.</span><button className="secondary-button" onClick={() => void load()}><RefreshCw size={15} /> Prøv igen</button></div></div>;
  if (!selected) return <div className="module-view invoice-view"><h1>Fakturering</h1><p>Der er ingen fakturaer i den valgte periode.</p>{periods.length > 0 && <select value={selectedPeriod} onChange={(event) => setSelectedPeriod(event.target.value)}>{periods.map((period) => <option key={period}>{period}</option>)}</select>}</div>;

  return <div className="module-view invoice-view">
    <section className="page-heading"><div><p className="eyebrow">Økonomi · Fakturaklargøring</p><h1>Fakturering · {selectedPeriod}</h1><p>Gennemgå, ret og godkend. Eksport bliver først mulig, når Dinero er aktiveret.</p></div><span className={`integration-badge ${dineroReady ? "active" : ""}`}><i /> Dinero {dineroReady ? "aktiveret" : "ikke aktiveret"}</span></section>
    <div className="invoice-toolbar"><label>Fakturaperiode<select value={selectedPeriod} onChange={(event) => { setSelectedPeriod(event.target.value); const first = invoices.find((invoice) => invoice.period === event.target.value); setSelectedId(first?.id ?? ""); }}>{periods.map((period) => <option key={period}>{period}</option>)}</select></label></div>
    <section className="invoice-summary"><div><span>Fakturaer i perioden</span><strong>{filtered.length}</strong></div><div><span>Samlet ekskl. moms</span><strong>{total.toLocaleString("da-DK", { minimumFractionDigits: 2 })} kr.</strong></div><div><span>Status</span><strong className={hasBlockingErrors ? "" : "summary-ok"}><i /> {hasBlockingErrors ? "Kræver rettelser" : "Klar til gennemgang"}</strong></div></section>
    <div className="invoice-layout"><aside className="invoice-customers"><h2>Erhvervskunder</h2>{filtered.map((invoice) => <button key={invoice.id} className={invoice.id === selected.id ? "selected" : ""} onClick={() => setSelectedId(invoice.id)}><span>{invoice.customer}</span><small>{invoice.status === "APPROVED" ? "Godkendt" : invoice.status}</small><Check size={15} /></button>)}</aside><section className="invoice-editor"><div className="invoice-editor-head"><div><span>Faktura · {selected.period}</span><h2>{selected.customer}</h2></div><em className={selected.status === "APPROVED" ? "ready" : "draft"}>{selected.status === "APPROVED" ? "Godkendt og låst" : "Kladde"}</em></div>{selected.checks.length > 0 && <div className="invoice-checks">{selected.checks.map((check, index) => <span className={check.severity} key={`${check.message}-${index}`}>{check.message}</span>)}</div>}<div className="invoice-line-head"><span>Beskrivelse</span><span>Antal</span><span>Pris</span><span>Total inkl. moms</span></div><div className="invoice-line"><label><span>Beskrivelse</span><textarea disabled={selected.status === "APPROVED"} value={selected.description} onChange={(event) => update({ description: event.target.value })} /></label><input aria-label="Antal" disabled={selected.status === "APPROVED"} type="number" min="1" value={selected.quantity} onChange={(event) => update({ quantity: Number(event.target.value) })} /><label className="price-field"><input aria-label="Pris" disabled={selected.status === "APPROVED"} type="number" min="0" value={selected.price} onChange={(event) => update({ price: Number(event.target.value) })} /><span>kr.</span></label><strong>{(selected.quantity * selected.price * 1.25).toLocaleString("da-DK", { minimumFractionDigits: 2 })} kr.</strong></div><label className="invoice-reason">Begrundelse ved rettelse<input disabled={selected.status === "APPROVED"} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Påkrævet hvis beløb eller tekst ændres" /></label><div className="invoice-editor-foot"><span><ShieldCheck size={16} /> Godkendelse låser fakturaen. Der sendes intet til Dinero endnu.</span><button className="secondary-button" disabled={saving || selected.status === "APPROVED"} onClick={() => void save()}>{saving ? "Gemmer…" : "Gem ændringer"}</button><button className="primary-button" disabled={saving || selected.status === "APPROVED" || hasBlockingErrors} onClick={() => void approve()}><Check size={15} /> Godkend</button></div></section></div>
  </div>;
}
