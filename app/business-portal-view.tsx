"use client";

import { Building2, Check, KeyRound, Phone, ReceiptText, ShieldCheck, Trash2, UserRound } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type PortalUser = { id: string; name: string; email: string; phone?: string | null; role: "admin" | "employee" | "read_only"; active: boolean; lastLoginAt?: string | null };
type Company = {
  id: number;
  name: string;
  customerNumber?: string | null;
  portalActive: boolean;
  smsActive: boolean;
  defaultDepartment?: string | null;
  requisitionRequirement: "hidden" | "optional" | "required";
  changeCutoffMinutes: number;
  bookingHorizonDays: number;
  activeUsers: number;
  users: PortalUser[];
};

const roleLabel: Record<PortalUser["role"], string> = { admin: "Administrator", employee: "Medarbejder", read_only: "Læseadgang" };

export function BusinessPortalView({ onNotify }: { onNotify: (message: string) => void }) {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [userForm, setUserForm] = useState({ name: "", email: "", phone: "", password: "", role: "employee" });
  const selected = companies.find((company) => company.id === selectedId) ?? null;

  const loadCompanies = useCallback(async () => {
    setLoading(true); setError("");
    try {
      const response = await fetch("/api/business-portal/companies", { cache: "no-store" });
      if (!response.ok) throw new Error("Branchekundeportalen kunne ikke hentes");
      const data = await response.json() as { companies?: Company[] };
        const next = data.companies ?? [];
        setCompanies(next);
        setSelectedId((current) => next.some((company) => company.id === current) ? current : next[0]?.id ?? null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Branchekundeportalen kunne ikke hentes"); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { const timer = window.setTimeout(() => void loadCompanies(), 0); return () => window.clearTimeout(timer); }, [loadCompanies]);

  const updateSelected = (changes: Partial<Company>) => {
    if (!selected) return;
    setCompanies((current) => current.map((company) => company.id === selected.id ? { ...company, ...changes } : company));
  };

  const save = async () => {
    if (!selected) return;
    setSaving(true);
    try {
      const response = await fetch("/api/business-portal", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          type: "company",
          customerId: selected.id,
          portalActive: selected.portalActive,
          smsActive: selected.smsActive,
          defaultDepartment: selected.defaultDepartment || "Ikast",
          allowedDepartments: [selected.defaultDepartment || "Ikast"],
          allowedInspectionTypes: [],
          requisitionRequirement: selected.requisitionRequirement,
          changeCutoffMinutes: selected.changeCutoffMinutes,
          bookingHorizonDays: selected.bookingHorizonDays,
        }),
      });
      if (!response.ok) throw new Error("Indstillingerne kunne ikke gemmes");
      onNotify("Branchekundeportal gemt");
    } catch (reason) {
      onNotify(reason instanceof Error ? reason.message : "Indstillingerne kunne ikke gemmes");
    } finally {
      setSaving(false);
    }
  };

  const createUser = async () => {
    if (!selected || !userForm.name || !userForm.email || userForm.password.length < 6) {
      onNotify("Udfyld navn, e-mail og en adgangskode på mindst 6 tegn");
      return;
    }
    const response = await fetch("/api/business-portal", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ type: "user", customerId: selected.id, ...userForm }) });
    if (!response.ok) { onNotify("Portalbrugeren kunne ikke oprettes"); return; }
    setUserForm({ name: "", email: "", phone: "", password: "", role: "employee" });
    await loadCompanies();
    onNotify("Portalbruger oprettet");
  };

  const sendPasswordReset = async (user: PortalUser) => {
    if (!selected) return;
    const response = await fetch("/api/business-portal", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ type: "user_password_reset", customerId: selected.id, userId: user.id }) });
    const data = await response.json().catch(() => ({}));
    onNotify(response.ok ? data.message ?? `Nulstillingslink sendt til ${user.email}` : data.error ?? "Nulstillingslinket kunne ikke sendes");
  };

  const deleteUser = async (user: PortalUser) => {
    if (!selected || !window.confirm(`Vil du slette ${user.name} fra ${selected.name}?`)) return;
    const response = await fetch(`/api/business-portal/users/${encodeURIComponent(user.id)}`, { method: "DELETE" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { onNotify(data.error ?? "Portalbrugeren kunne ikke slettes"); return; }
    await loadCompanies();
    onNotify(`${user.name} er slettet fra portalen`);
  };

  return <section className="portal-view">
    <header className="portal-heading">
      <div><span className="portal-icon"><Building2 size={20} /></span><div><p className="eyebrow">KUNDER OG ADGANG</p><h1>Branchekundeportal</h1><p>Giv erhvervskunder en enkel vej til egne bookinger – med regler, der passer til jer.</p></div></div>
      <span className="soft-status"><Check size={14} /> Klar til opsætning</span>
    </header>
    {loading ? <div className="portal-empty">Henter erhvervskunder…</div> : error ? <div className="portal-empty error"><p>{error}. Ingen portalstatus vises som aktiv, før data er hentet.</p><button className="secondary-button" onClick={() => void loadCompanies()}>Prøv igen</button></div> : companies.length === 0 ? <div className="portal-empty">Der er endnu ingen erhvervskunder at sætte op.</div> : <div className="portal-layout">
      <aside className="portal-companies"><h2>Erhvervskunder</h2><p>Vælg en virksomhed for at styre portaladgang.</p>{companies.map((company) => <button key={company.id} className={company.id === selectedId ? "selected" : ""} onClick={() => setSelectedId(company.id)}><span><Building2 size={17} /><strong>{company.name}</strong><small>{company.activeUsers} aktive brugere</small></span><em className={company.portalActive ? "on" : ""}>{company.portalActive ? "Aktiv" : "Slukket"}</em></button>)}</aside>
      {selected && <div className="portal-editor"><div className="portal-editor-head"><div><h2>{selected.name}</h2><p>{selected.customerNumber ? `Kundenr. ${selected.customerNumber}` : "Erhvervskunde"}</p></div><label className="switch-row"><input type="checkbox" checked={selected.portalActive} onChange={(event) => updateSelected({ portalActive: event.target.checked })} /><span>Portal aktiv</span></label></div>
        <div className="portal-security"><ShieldCheck size={18} /><span><strong>Sikker adskillelse</strong><small>Alle brugere kan se virksomhedens bookinger. Medarbejdere kan booke og ændre, læseadgang kan kun se, og kun administratorer kan se fakturaoplysninger.</small></span></div>
        <div className="portal-form-grid"><label>Standardafdeling<input value={selected.defaultDepartment ?? ""} onChange={(event) => updateSelected({ defaultDepartment: event.target.value })} placeholder="Ikast" /></label><label>Ændring senest (min.)<input type="number" min={0} value={selected.changeCutoffMinutes} onChange={(event) => updateSelected({ changeCutoffMinutes: Number(event.target.value) })} /></label><label>Booking frem i tiden (dage)<input type="number" min={1} max={365} value={selected.bookingHorizonDays} onChange={(event) => updateSelected({ bookingHorizonDays: Number(event.target.value) })} /></label><label>Rekvisitionsnummer<select value={selected.requisitionRequirement} onChange={(event) => updateSelected({ requisitionRequirement: event.target.value as Company["requisitionRequirement"] })}><option value="hidden">Skjul feltet</option><option value="optional">Valgfrit</option><option value="required">Påkrævet</option></select></label></div>
        <label className="switch-row sms-switch"><input type="checkbox" checked={selected.smsActive} onChange={(event) => updateSelected({ smsActive: event.target.checked })} /><span>Send bookingbekræftelser og påmindelser til den portalbruger, der booker</span></label>
        <div className="portal-user-box"><h3>Opret portalbruger</h3><p>Opret alle virksomhedens kontaktpersoner her. Mobilnummeret er valgfrit og bruges kun til SMS om brugerens egne bookinger.</p><div className="portal-form-grid"><label>Navn<input value={userForm.name} onChange={(event) => setUserForm({ ...userForm, name: event.target.value })} /></label><label>E-mail<input type="email" value={userForm.email} onChange={(event) => setUserForm({ ...userForm, email: event.target.value })} /></label><label>Mobilnummer (valgfrit)<input type="tel" value={userForm.phone} onChange={(event) => setUserForm({ ...userForm, phone: event.target.value })} placeholder="Fx 20 12 34 56" /></label><label>Adgangskode <span>(mindst 6 tegn)</span><input type="password" minLength={6} value={userForm.password} onChange={(event) => setUserForm({ ...userForm, password: event.target.value })} /></label><label>Rolle<select value={userForm.role} onChange={(event) => setUserForm({ ...userForm, role: event.target.value })}><option value="admin">Administrator · booking og faktura</option><option value="employee">Medarbejder · booking</option><option value="read_only">Læseadgang · kun overblik</option></select></label></div><button className="secondary-button" onClick={() => void createUser()}>Opret bruger</button>
          <div className="portal-user-list"><h4>Virksomhedens brugere</h4>{selected.users.length === 0 ? <p>Der er endnu ikke oprettet portalbrugere.</p> : selected.users.map((user) => <article key={user.id}><span className="portal-user-avatar"><UserRound size={15} /></span><span><strong>{user.name}</strong><small>{user.email}{user.phone ? ` · ${user.phone}` : " · Intet SMS-nummer"}</small></span><em className={user.role}>{user.role === "admin" ? <ReceiptText size={12} /> : user.phone ? <Phone size={12} /> : null}{roleLabel[user.role]}</em><span className="portal-user-actions"><button title="Send nyt password-link" aria-label={`Send nyt password-link til ${user.name}`} onClick={() => void sendPasswordReset(user)}><KeyRound size={13} /> Nyt password</button><button className="danger" title="Slet bruger" aria-label={`Slet ${user.name}`} onClick={() => void deleteUser(user)}><Trash2 size={13} /> Slet</button></span></article>)}</div>
        </div><div className="portal-actions"><small>Ændringer gemmes med revisionsspor.</small><button className="primary-button" onClick={save} disabled={saving}>{saving ? "Gemmer…" : "Gem indstillinger"}</button></div>
      </div>}
    </div>}
  </section>;
}
