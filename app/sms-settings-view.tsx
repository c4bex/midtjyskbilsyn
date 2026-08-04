"use client";

import { Check, Clock3, MessageSquare, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";

type Props = { onNotify: (message: string) => void };
type Settings = { confirmation: boolean; reminder: boolean; reminderHours: string; sender: string; quietStart: string; quietEnd: string };
const defaults: Settings = { confirmation: true, reminder: true, reminderHours: "24", sender: "MB Bilsyn", quietStart: "20:00", quietEnd: "08:00" };

export function SmsSettingsView({ onNotify }: Props) {
  const [settings, setSettings] = useState<Settings>(() => { try { const stored = typeof window === "undefined" ? null : localStorage.getItem("mb-sms-settings"); return stored ? { ...defaults, ...JSON.parse(stored) as Partial<Settings> } : defaults; } catch { return defaults; } });
  const [queueTotal, setQueueTotal] = useState<number | null>(null);
  useEffect(() => { fetch("/api/sms/queue", { cache: "no-store" }).then((response) => response.ok ? response.json() as Promise<{ total: number }> : Promise.reject()).then((data) => setQueueTotal(data.total)).catch(() => undefined); }, []);
  const update = <K extends keyof Settings>(key: K, value: Settings[K]) => setSettings((current) => ({ ...current, [key]: value }));
  const save = () => { localStorage.setItem("mb-sms-settings", JSON.stringify(settings)); onNotify("SMS-indstillingerne er gemt på denne enhed"); };
  return <div className="module-view sms-settings-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Kommunikation</p><h1>SMS-indstillinger</h1><p>Styr automatiske beskeder til kunderne. GatewayAPI er endnu ikke aktiveret.</p></div><span className="integration-badge"><i /> GatewayAPI slukket</span></section>
    <section className="settings-grid">
      <article className="settings-card"><div className="settings-card-head"><span className="settings-icon yellow"><MessageSquare size={18} /></span><div><h2>Automatiske beskeder</h2><p>Regler for private kunders bookinger</p></div></div>
        <label className="setting-toggle"><span><strong>Bekræftelse ved booking</strong><small>Send straks efter en privat booking er gemt</small></span><input type="checkbox" checked={settings.confirmation} onChange={(event) => update("confirmation", event.target.checked)} /><i /></label>
        <label className="setting-toggle"><span><strong>Påmindelse før syn</strong><small>Send kun når bookingen ligger på en senere dag</small></span><input type="checkbox" checked={settings.reminder} onChange={(event) => update("reminder", event.target.checked)} /><i /></label>
        <label className="smart-field">Påmindelse sendes<select value={settings.reminderHours} disabled={!settings.reminder} onChange={(event) => update("reminderHours", event.target.value)}><option value="48">48 timer før</option><option value="24">24 timer før</option><option value="4">4 timer før</option></select></label>
      </article>
      <article className="settings-card"><div className="settings-card-head"><span className="settings-icon green"><Clock3 size={18} /></span><div><h2>Afsender og tidsrum</h2><p>Hold beskederne korte og genkendelige</p></div></div>
        <label className="smart-field">Afsendernavn<input value={settings.sender} maxLength={11} onChange={(event) => update("sender", event.target.value)} /><small>Maks. 11 tegn. GatewayAPI skal godkende navnet.</small></label>
        <div className="settings-time-grid"><label className="smart-field">Ingen SMS fra<input type="time" value={settings.quietStart} onChange={(event) => update("quietStart", event.target.value)} /></label><label className="smart-field">Ingen SMS til<input type="time" value={settings.quietEnd} onChange={(event) => update("quietEnd", event.target.value)} /></label></div>
      </article>
    </section>
    <section className="settings-note"><ShieldCheck size={18} /><div><strong>Sikker standard</strong><p>API-nøgler og adgang til GatewayAPI håndteres kun på serveren. De gemmes aldrig i browseren.</p><small>Lokal SMS-kø: {queueTotal ?? "…"} poster</small></div></section>
    <div className="settings-actions"><button className="primary-button" onClick={save}><Check size={16} /> Gem indstillinger</button></div>
  </div>;
}
