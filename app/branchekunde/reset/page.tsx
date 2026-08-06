"use client";

import { FormEvent, useState } from "react";
import { useSearchParams, useRouter } from "next/navigation";

export default function BranchekundeResetPage() {
  const params = useSearchParams(); const router = useRouter(); const [password, setPassword] = useState(""); const [confirmation, setConfirmation] = useState(""); const [message, setMessage] = useState(""); const [error, setError] = useState("");
  const submit = async (event: FormEvent) => { event.preventDefault(); setError(""); const response = await fetch("/api/portal/reset-password", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ email: params.get("email") ?? "", token: params.get("token") ?? "", password, password_confirmation: confirmation }) }); const data = await response.json(); if (!response.ok) { setError(data.error ?? "Linket er ugyldigt."); return; } setMessage(data.message); setTimeout(() => router.push("/branchekunde"), 1200); };
  return <main className="business-portal"><form className="business-login" onSubmit={submit}><span className="business-logo">MB</span><p className="public-eyebrow">BRANCHEKUNDEPORTAL</p><h1>Ny adgangskode</h1><p>Vælg en ny adgangskode på mindst 8 tegn.</p><label>Ny adgangskode<input required minLength={8} type="password" value={password} onChange={e => setPassword(e.target.value)} /></label><label>Gentag adgangskode<input required minLength={8} type="password" value={confirmation} onChange={e => setConfirmation(e.target.value)} /></label>{error && <div className="business-error">{error}</div>}{message && <div className="business-success">{message}</div>}<button className="business-primary">Gem adgangskode</button></form></main>;
}
