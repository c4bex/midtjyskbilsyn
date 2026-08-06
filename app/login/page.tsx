"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setLoading(true); setError("");
    try {
      const response = await fetch("/api/auth/login", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ email, password }) });
      if (response.ok) router.push("/");
      else if (response.status === 401 || response.status === 422) setError("Forkert e-mail eller adgangskode.");
      else setError("Loginserveren svarer ikke. Prøv igen om et øjeblik.");
    } catch {
      setError("Loginserveren svarer ikke. Prøv igen om et øjeblik.");
    } finally {
      setLoading(false);
    }
  };
  return <main style={{ minHeight: "100vh", display: "grid", placeItems: "center", padding: 24, background: "#f4f6f8" }}><form onSubmit={submit} style={{ width: "min(420px, 100%)", display: "grid", gap: 16, padding: 32, borderRadius: 18, background: "#fff", boxShadow: "0 20px 60px #14283b18" }}><p className="eyebrow">Midtjysk Bilsyn</p><h1 style={{ margin: 0 }}>Log ind</h1><p style={{ margin: 0, color: "#6d7d8d" }}>Log ind for at åbne driftssystemet.</p><label>E-mail<input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} /></label><label>Adgangskode<input required type="password" value={password} onChange={(event) => setPassword(event.target.value)} /></label>{error && <p role="alert" style={{ color: "#b42318" }}>{error}</p>}<button type="submit" disabled={loading}>{loading ? "Logger ind…" : "Log ind"}</button></form></main>;
}
