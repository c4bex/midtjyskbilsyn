"use client";

import { Check, Clock3, Copy, ExternalLink, MessageSquare, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";

type Props = { onNotify: (message: string) => void };
type Settings = { privateConfirmation: boolean; privateReminder: boolean; privateChange: boolean; businessEnabled: boolean; autoRetry: boolean; maxRetryAttempts: number; senderId: string; reminderTime: string; quietStart: string; quietEnd: string };
type Template = { code: string; name: string; audience: string; body: string; enabled: boolean; version: number };
const defaults: Settings = { privateConfirmation: true, privateReminder: true, privateChange: true, businessEnabled: false, autoRetry: true, maxRetryAttempts: 3, senderId: "MB Bilsyn", reminderTime: "15:00", quietStart: "21:00", quietEnd: "07:00" };
const statusLabel: Record<string, string> = { held: "Afventer aktivering", SCHEDULED: "Planlagt", QUEUED: "I kø", SENT: "Sendt", DELIVERED: "Leveret", FAILED: "Fejlet", CANCELLED: "Annulleret" };

export function SmsSettingsView({ onNotify }: Props) {
  const [settings, setSettings] = useState<Settings>(defaults);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [messages, setMessages] = useState<Array<{ id: number; kind: string; status: string; customer?: string; recipient_masked?: string; scheduled_at?: string }>>([]);
  const [saving, setSaving] = useState(false);
  const update = <K extends keyof Settings>(key: K, value: Settings[K]) => setSettings((current) => ({ ...current, [key]: value }));
  useEffect(() => {
    Promise.all([fetch("/api/sms/settings", { cache: "no-store" }).then((r) => r.json()), fetch("/api/sms/templates", { cache: "no-store" }).then((r) => r.json()), fetch("/api/sms/messages", { cache: "no-store" }).then((r) => r.json())])
      .then(([settingsData, templateData, messagesData]) => {
        const s = settingsData.settings ?? {};
        setSettings({ ...defaults, privateConfirmation: Boolean(s.private_confirmation), privateReminder: Boolean(s.private_reminder), privateChange: Boolean(s.private_change), businessEnabled: Boolean(s.business_enabled), autoRetry: Boolean(s.auto_retry), maxRetryAttempts: Number(s.max_retry_attempts ?? 3), senderId: s.sender_id ?? defaults.senderId, reminderTime: String(s.reminder_time ?? defaults.reminderTime).slice(0, 5), quietStart: String(s.quiet_start ?? defaults.quietStart).slice(0, 5), quietEnd: String(s.quiet_end ?? defaults.quietEnd).slice(0, 5) });
        setTemplates(templateData.templates ?? []); setMessages(messagesData.messages ?? []);
      }).catch(() => onNotify("SMS-indstillinger kunne ikke hentes"));
  }, [onNotify]);
  const save = async () => {
    setSaving(true);
    const response = await fetch("/api/sms/settings", { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(settings) });
    setSaving(false); onNotify(response.ok ? "SMS-indstillingerne er gemt" : "SMS-indstillingerne kunne ikke gemmes");
  };
  const saveTemplate = async (template: Template) => { const response = await fetch(`/api/sms/templates/${encodeURIComponent(template.code)}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ body: template.body, enabled: template.enabled }) }); if (response.ok) onNotify("SMS-skabelonen er gemt"); };
  const publicUrl = typeof window === "undefined" ? "https://booking.midtjyskbilsyn.dk/booking" : `${window.location.origin}/booking`;
  const businessUrl = typeof window === "undefined" ? "https://booking.midtjyskbilsyn.dk/branchekunde" : `${window.location.origin}/branchekunde`;
  const copyLink = async (url: string) => { await navigator.clipboard?.writeText(url); onNotify("Link kopieret"); };
  return <div className="module-view sms-settings-view">
    <section className="page-heading"><div><p className="eyebrow">Administration · Kommunikation</p><h1>SMS-indstillinger</h1><p>Automatiske beskeder, skabeloner og historik samlet ét sted.</p></div><span className="integration-badge"><i /> GatewayAPI slukket</span></section>
    <section className="settings-grid">
      <article className="settings-card"><div className="settings-card-head"><span className="settings-icon yellow"><MessageSquare size={18} /></span><div><h2>Automatiske beskeder</h2><p>Private er aktive som standard. Erhverv er slået fra.</p></div></div>
        {([["privateConfirmation", "Bekræftelse ved booking", "Send straks efter en privat booking er gemt"], ["privateReminder", "Påmindelse før syn", "Send dagen før · aldrig ved samme dags booking"], ["privateChange", "Ændring af booking", "Send ved flytning eller ændring"]] as const).map(([key, title, hint]) => <label className="setting-toggle" key={key}><span><strong>{title}</strong><small>{hint}</small></span><input type="checkbox" checked={settings[key]} onChange={(event) => update(key, event.target.checked)} /><i /></label>)}
        <label className="smart-field">Påmindelse sendes normalt kl.<input type="time" value={settings.reminderTime} disabled={!settings.privateReminder} onChange={(event) => update("reminderTime", event.target.value)} /></label>
        <label className="setting-toggle"><span><strong>Erhvervskunder</strong><small>Aktiver kun hvis den enkelte kundes aftale tillader SMS</small></span><input type="checkbox" checked={settings.businessEnabled} onChange={(event) => update("businessEnabled", event.target.checked)} /><i /></label>
      </article>
      <article className="settings-card"><div className="settings-card-head"><span className="settings-icon green"><Clock3 size={18} /></span><div><h2>Afsender og tidsrum</h2><p>Beskeder sendes kun i det valgte tidsrum.</p></div></div>
        <label className="smart-field">Afsendernavn<input value={settings.senderId} maxLength={11} onChange={(event) => update("senderId", event.target.value)} /><small>Maks. 11 tegn.</small></label>
        <div className="settings-time-grid"><label className="smart-field">Ingen SMS fra<input type="time" value={settings.quietStart} onChange={(event) => update("quietStart", event.target.value)} /></label><label className="smart-field">Ingen SMS til<input type="time" value={settings.quietEnd} onChange={(event) => update("quietEnd", event.target.value)} /></label></div>
        <label className="setting-toggle"><span><strong>Automatiske genforsøg</strong><small>Holdes klar til aktivering sammen med GatewayAPI</small></span><input type="checkbox" checked={settings.autoRetry} onChange={(event) => update("autoRetry", event.target.checked)} /><i /></label>
      </article>
    </section>
    <section className="settings-note"><ShieldCheck size={18} /><div><strong>Sikker standard</strong><p>GatewayAPI er ikke aktiveret. Ingen SMS forlader systemet, før integrationen er testet og slået til på serveren.</p></div></section>
    <section className="settings-card booking-links-card"><div className="settings-card-head"><span className="settings-icon blue"><ExternalLink size={18} /></span><div><h2>Online bookinglinks</h2><p>Find og kopier de links, I kan bruge på hjemmesiden, i Google og i beskeder.</p></div></div><div className="booking-link-row"><div><strong>Privat booking</strong><small>Aktiv · åben for alle kunder</small><code>{publicUrl}</code></div><button className="secondary-button" onClick={() => void copyLink(publicUrl)}><Copy size={14} /> Kopier</button><a className="secondary-button" href="/booking" target="_blank" rel="noreferrer"><ExternalLink size={14} /> Åbn</a></div><div className="booking-link-row"><div><strong>Branchekunde booking</strong><small>Aktiv · separat login pr. virksomhed</small><code>{businessUrl}</code></div><button className="secondary-button" onClick={() => void copyLink(businessUrl)}><Copy size={14} /> Kopier</button><a className="secondary-button" href="/branchekunde" target="_blank" rel="noreferrer"><ExternalLink size={14} /> Åbn</a></div></section>
    <section className="settings-card sms-template-card"><div className="settings-card-head"><span className="settings-icon blue"><MessageSquare size={18} /></span><div><h2>Skabeloner</h2><p>Redigér teksten uden at ændre bookingflowet. Variabler som <code>{"{{date}}"}</code> udfyldes automatisk.</p></div></div>{templates.map((template) => <div className="sms-template-row" key={template.code}><div><strong>{template.name}</strong><small>{template.audience === "private" ? "Privat" : "Erhverv"} · version {template.version}</small></div><textarea value={template.body} maxLength={1600} onChange={(event) => setTemplates((current) => current.map((item) => item.code === template.code ? { ...item, body: event.target.value } : item))} /><div className="template-actions"><small>{template.body.length}/1600 tegn</small><button className="secondary-button" onClick={() => void saveTemplate(template)}>Gem</button></div></div>)}</section>
    <section className="settings-card"><div className="settings-card-head"><span className="settings-icon gray"><Clock3 size={18} /></span><div><h2>SMS-historik</h2><p>Seneste planlagte beskeder og deres status.</p></div></div><div className="sms-history-list">{messages.length === 0 ? <p className="empty-state">Ingen SMS-poster endnu.</p> : messages.map((message) => <div className="sms-history-row" key={message.id}><strong>{message.customer ?? "Kunde"}</strong><span>{message.recipient_masked ?? "—"}</span><span>{message.kind}</span><em>{statusLabel[message.status] ?? message.status}</em></div>)}</div></section>
    <div className="settings-actions"><button className="primary-button" onClick={() => void save()} disabled={saving}><Check size={16} /> {saving ? "Gemmer…" : "Gem indstillinger"}</button></div>
  </div>;
}
