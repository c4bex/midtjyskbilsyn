"use client";

import { FormEvent, useState } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { ArrowRight, LockKeyhole } from "lucide-react";

export default function BranchekundeResetPage() {
  const params = useSearchParams(); const router = useRouter(); const [password, setPassword] = useState(""); const [confirmation, setConfirmation] = useState(""); const [message, setMessage] = useState(""); const [error, setError] = useState("");
  const submit = async (event: FormEvent) => { event.preventDefault(); setError(""); const response = await fetch("/api/portal/reset-password", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ email: params.get("email") ?? "", token: params.get("token") ?? "", password, password_confirmation: confirmation }) }); const data = await response.json(); if (!response.ok) { setError(data.error ?? "Linket er ugyldigt."); return; } setMessage(data.message); setTimeout(() => router.push("/branchekunde"), 1200); };
  return <main className="business-portal business-auth-page business-reset-page">
    <section className="business-auth-intro" aria-label="Om Midtjysk Bilsyn branchekundeportal"><span className="business-brand-art" aria-label="Midtjysk Bilsyn" /><div className="business-auth-copy"><p className="business-auth-kicker">BRANCHEKUNDEPORTAL</p><h2>Ny adgang.<br /><span>Samme overblik.</span></h2><p>Vælg din nye adgangskode, og fortsæt direkte til virksomhedens bookinger.</p></div><small>© 2026 Midtjysk Bilsyn · Ikast</small></section>
    <section className="business-auth-panel"><form className="business-login" onSubmit={submit}><span className="business-brand-art business-auth-mobile-brand" aria-label="Midtjysk Bilsyn" /><span className="business-reset-icon"><LockKeyhole size={20} /></span><p className="public-eyebrow">BRANCHEKUNDEPORTAL</p><h1>Ny adgangskode</h1><p>Vælg en adgangskode på mindst 6 tegn.</p><label>Ny adgangskode<input required minLength={6} autoComplete="new-password" type="password" value={password} onChange={e => setPassword(e.target.value)} /></label><label>Gentag adgangskode<input required minLength={6} autoComplete="new-password" type="password" value={confirmation} onChange={e => setConfirmation(e.target.value)} /></label>{error && <div className="business-error" role="alert">{error}</div>}{message && <div className="business-success" role="status">{message}</div>}<button className="business-primary">Gem adgangskode <ArrowRight size={17} /></button></form></section>
  </main>;
}
