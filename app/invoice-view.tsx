"use client";

import { Check, ChevronDown, FileText, Pencil, ShieldCheck } from "lucide-react";
import { useState } from "react";

type Invoice = { id: string; customer: string; period: string; description: string; quantity: number; price: number; status: "Klargøres" | "Klar til Dinero"; registration: string };
const demo: Invoice[] = [
  { id: "inv-1", customer: "Autogården", period: "Juli 2026", description: "Syn · 1. Syn / P-syn · Syns nr. 166869 · Reg. nr. EC20464 · SUZUKI BALENO", quantity: 1, price: 380, status: "Klargøres", registration: "EC20464" },
  { id: "inv-2", customer: "Autohuset", period: "Juli 2026", description: "Syn · 1. Syn / P-syn · Syns nr. 167023 · Reg. nr. EH67875 · OPEL Crossland X", quantity: 1, price: 380, status: "Klar til Dinero", registration: "EH67875" },
  { id: "inv-3", customer: "Bbc Biler", period: "Juli 2026", description: "Syn · Omsyn · Syns nr. 167356 · Reg. nr. CN72849 · SUZUKI VITARA", quantity: 1, price: 380, status: "Klar til Dinero", registration: "CN72849" },
];

export function InvoiceView({ onNotify }: { onNotify: (message: string) => void }) {
  const [invoices, setInvoices] = useState(demo);
  const [selectedId, setSelectedId] = useState(demo[0].id);
  const selected = invoices.find((invoice) => invoice.id === selectedId) ?? invoices[0];
  const update = (changes: Partial<Invoice>) => setInvoices((current) => current.map((invoice) => invoice.id === selected.id ? { ...invoice, ...changes } : invoice));
  const total = invoices.reduce((sum, invoice) => sum + invoice.quantity * invoice.price, 0);
  return <div className="module-view invoice-view">
    <section className="page-heading"><div><p className="eyebrow">Økonomi · Fakturaklargøring</p><h1>Fakturering</h1><p>Gennemgå og ret fakturaer, før de sendes videre til Dinero.</p></div><button className="primary-button" onClick={() => onNotify("Dinero er ikke aktiveret endnu — fakturaerne bliver i klargøring")}>Send godkendte til Dinero</button></section>
    <div className="invoice-toolbar"><div className="month-tabs">{["Januar", "Februar", "Marts", "April", "Maj", "Juni", "Juli", "August", "September"].map((month) => <button key={month} className={month === "Juli" ? "selected" : ""}>{month}</button>)}</div><div className="year-tabs"><button>2025</button><button className="selected">2026</button></div></div>
    <section className="invoice-summary"><div><span>Fakturaer i klargøring</span><strong>{invoices.length}</strong></div><div><span>Samlet ekskl. moms</span><strong>{total.toLocaleString("da-DK", { minimumFractionDigits: 2 })} kr.</strong></div><div><span>Status</span><strong className="summary-ok"><i /> Klar til gennemgang</strong></div></section>
    <div className="invoice-layout"><aside className="invoice-customers"><h2>Erhvervskunder</h2>{invoices.map((invoice) => <button key={invoice.id} className={invoice.id === selectedId ? "selected" : ""} onClick={() => setSelectedId(invoice.id)}><span>{invoice.customer}</span><small>{invoice.status}</small><Check size={15} /></button>)}</aside><section className="invoice-editor"><div className="invoice-editor-head"><div><span>Faktura · {selected.period}</span><h2>{selected.customer}</h2></div><em className={selected.status === "Klar til Dinero" ? "ready" : "draft"}>{selected.status}</em></div><div className="invoice-line-head"><span>Beskrivelse</span><span>Antal</span><span>Pris</span><span>Total inkl. moms</span></div><div className="invoice-line"><label><span>Beskrivelse</span><textarea value={selected.description} onChange={(event) => update({ description: event.target.value })} /></label><input type="number" min="1" value={selected.quantity} onChange={(event) => update({ quantity: Number(event.target.value) })} /><label className="price-field"><input type="number" min="0" value={selected.price} onChange={(event) => update({ price: Number(event.target.value) })} /><span>kr.</span></label><strong>{(selected.quantity * selected.price * 1.25).toLocaleString("da-DK", { minimumFractionDigits: 2 })} kr.</strong></div><div className="invoice-editor-foot"><span><ShieldCheck size={16} /> Ændringer gemmes i klargøringen</span><button className="secondary-button" onClick={() => update({ status: "Klar til Dinero" })}><Check size={15} /> Markér klar til Dinero</button></div></section></div>
  </div>;
}
