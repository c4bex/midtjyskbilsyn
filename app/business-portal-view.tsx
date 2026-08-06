"use client";

import { Building2, Check, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";

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
};

export function BusinessPortalView({ onNotify }: { onNotify: (message: string) => void }) {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [userForm, setUserForm] = useState({ name: "", email: "", password: "", role: "employee" });
  const selected = companies.find((company) => company.id === selectedId) ?? null;

  useEffect(() => {
    fetch("/api/business-portal/companies")
      .then((response) => response.ok ? response.json() : Promise.reject(new Error("Branchekundeportalen kunne ikke hentes")))
      .then((data: { companies?: Company[] }) => {
        const next = data.companies ?? [];
        setCompanies(next);
        setSelectedId(next[0]?.id ?? null);
      })
      .catch((reason: Error) => setError(reason.message))
      .finally(() => setLoading(false));
  }, []);

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
    if (!selected || !userForm.name || !userForm.email || userForm.password.length < 8) {
      onNotify("Udfyld navn, e-mail og en adgangskode på mindst 8 tegn");
      return;
    }
    const response = await fetch("/api/business-portal", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ type: "user", customerId: selected.id, ...userForm }) });
    if (!response.ok) { onNotify("Portalbrugeren kunne ikke oprettes"); return; }
    setUserForm({ name: "", email: "", password: "", role: "employee" });
    onNotify("Portalbruger oprettet");
  };

  return <section className="portal-view">
    <header className="portal-heading">
      <div><span className="portal-icon"><Building2 size={20} /></span><div><p className="eyebrow">KUNDER OG ADGANG</p><h1>Branchekundeportal</h1><p>Giv erhvervskunder en enkel vej til egne bookinger – med regler, der passer til jer.</p></div></div>
      <span className="soft-status"><Check size={14} /> Klar til opsætning</span>
    </header>
    {loading ? <div className="portal-empty">Henter erhvervskunder…</div> : error ? <div className="portal-empty error">{error}</div> : companies.length === 0 ? <div className="portal-empty">Der er endnu ingen erhvervskunder at sætte op.</div> : <div className="portal-layout">
      <aside className="portal-companies"><h2>Erhvervskunder</h2><p>Vælg en virksomhed for at styre portaladgang.</p>{companies.map((company) => <button key={company.id} className={company.id === selectedId ? "selected" : ""} onClick={() => setSelectedId(company.id)}><span><Building2 size={17} /><strong>{company.name}</strong><small>{company.activeUsers} aktive brugere</small></span><em className={company.portalActive ? "on" : ""}>{company.portalActive ? "Aktiv" : "Slukket"}</em></button>)}</aside>
      {selected && <div className="portal-editor"><div className="portal-editor-head"><div><h2>{selected.name}</h2><p>{selected.customerNumber ? `Kundenr. ${selected.customerNumber}` : "Erhvervskunde"}</p></div><label className="switch-row"><input type="checkbox" checked={selected.portalActive} onChange={(event) => updateSelected({ portalActive: event.target.checked })} /><span>Portal aktiv</span></label></div>
        <div className="portal-security"><ShieldCheck size={18} /><span><strong>Sikker adskillelse</strong><small>Kunden kan kun se og ændre egne bookinger. Ingen adgang til interne noter eller andre kunder.</small></span></div>
        <div className="portal-form-grid"><label>Standardafdeling<input value={selected.defaultDepartment ?? ""} onChange={(event) => updateSelected({ defaultDepartment: event.target.value })} placeholder="Ikast" /></label><label>Ændring senest (min.)<input type="number" min={0} value={selected.changeCutoffMinutes} onChange={(event) => updateSelected({ changeCutoffMinutes: Number(event.target.value) })} /></label><label>Booking frem i tiden (dage)<input type="number" min={1} max={365} value={selected.bookingHorizonDays} onChange={(event) => updateSelected({ bookingHorizonDays: Number(event.target.value) })} /></label><label>Rekvisitionsnummer<select value={selected.requisitionRequirement} onChange={(event) => updateSelected({ requisitionRequirement: event.target.value as Company["requisitionRequirement"] })}><option value="hidden">Skjul feltet</option><option value="optional">Valgfrit</option><option value="required">Påkrævet</option></select></label></div>
        <label className="switch-row sms-switch"><input type="checkbox" checked={selected.smsActive} onChange={(event) => updateSelected({ smsActive: event.target.checked })} /><span>SMS-bekræftelser for denne virksomhed</span></label>
        <div className="portal-user-box"><h3>Opret portalbruger</h3><p>Brugeren får kun adgang til denne virksomheds bookinger.</p><div className="portal-form-grid"><label>Navn<input value={userForm.name} onChange={(event) => setUserForm({ ...userForm, name: event.target.value })} /></label><label>E-mail<input type="email" value={userForm.email} onChange={(event) => setUserForm({ ...userForm, email: event.target.value })} /></label><label>Adgangskode<input type="password" minLength={8} value={userForm.password} onChange={(event) => setUserForm({ ...userForm, password: event.target.value })} /></label><label>Rolle<select value={userForm.role} onChange={(event) => setUserForm({ ...userForm, role: event.target.value })}><option value="admin">Administrator</option><option value="employee">Medarbejder</option><option value="read_only">Læseadgang</option></select></label></div><button className="secondary-button" onClick={() => void createUser()}>Opret bruger</button></div><div className="portal-actions"><small>Ændringer gemmes med revisionsspor.</small><button className="primary-button" onClick={save} disabled={saving}>{saving ? "Gemmer…" : "Gem indstillinger"}</button></div>
      </div>}
    </div>}
  </section>;
}
