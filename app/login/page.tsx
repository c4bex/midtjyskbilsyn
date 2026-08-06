"use client";

import { FormEvent, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import {
  ArrowRight,
  BarChart3,
  CalendarDays,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  Users,
} from "lucide-react";

const features = [
  { icon: CalendarDays, title: "Booking & planlægning", copy: "Effektiv håndtering af tider og ressourcer." },
  { icon: Users, title: "Kundeoverblik", copy: "Fuld indsigt i kunder, aftaler og historik." },
  { icon: BarChart3, title: "Driftsstyring", copy: "Optimér hverdagen med data og overblik." },
];

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email, password, remember }),
      });
      if (response.ok) {
        router.push("/");
      } else if (response.status === 401 || response.status === 422) {
        setPassword("");
        setError("Forkert e-mail eller adgangskode.");
      } else {
        setError("Loginserveren svarer ikke. Prøv igen om et øjeblik.");
      }
    } catch {
      setError("Loginserveren svarer ikke. Prøv igen om et øjeblik.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="login-page">
      <section className="login-hero" aria-label="Om Midtjysk Bilsyn">
        <Link className="login-brand" href="/" aria-label="Midtjysk Bilsyn – forsiden">
          <img src="/midtjysk-bilsyn-logo.png" alt="Midtjysk Bilsyn" />
        </Link>
        <div className="login-hero-copy">
          <p className="login-kicker">MIDTJYSK BILSYN</p>
          <h1>Velkommen til<br /><span>Midtjysk Bilsyn Platform</span></h1>
          <div className="login-rule" />
          <p className="login-intro">Din digitale platform til booking, planlægning og overblik over dine kunder og aktiviteter.</p>
        </div>
        <div className="login-features">
          {features.map(({ icon: Icon, title, copy }) => (
            <article className="login-feature" key={title}>
              <span className="login-feature-icon"><Icon size={21} strokeWidth={1.8} /></span>
              <span><strong>{title}</strong><small>{copy}</small></span>
            </article>
          ))}
        </div>
        <p className="login-footer">© 2026 <span>Midtjysk Bilsyn</span>. Alle rettigheder forbeholdes.</p>
      </section>

      <section className="login-panel">
        <form className="login-card" onSubmit={submit}>
          <div className="login-card-brand"><img src="/midtjysk-bilsyn-logo.png" alt="Midtjysk Bilsyn" /></div>
          <p className="login-card-kicker">SIKKER ADGANG</p>
          <h2>Log ind</h2>
          <p className="login-card-intro">Adgang til Midtjysk Bilsyns bookingsystem</p>

          <label className="login-field">
            <span>E-mail</span>
            <span className="login-input-wrap">
              <Mail className="login-field-icon" size={18} aria-hidden="true" />
              <input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Indtast din e-mail" autoComplete="email" aria-invalid={Boolean(error)} />
            </span>
          </label>
          <label className="login-field">
            <span>Adgangskode</span>
            <span className="login-input-wrap">
              <LockKeyhole className="login-field-icon" size={18} aria-hidden="true" />
              <input required type={showPassword ? "text" : "password"} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Indtast din adgangskode" autoComplete="current-password" aria-invalid={Boolean(error)} />
              <button className="login-password-toggle" type="button" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? "Skjul adgangskode" : "Vis adgangskode"}>
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </span>
          </label>

          <div className="login-options">
            <label className="login-checkbox"><input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} /><span>Husk mig</span></label>
            <button className="login-forgot" type="button" onClick={() => setError("Kontakt systemadministratoren for at nulstille adgangskoden.")}>Glemt adgangskode?</button>
          </div>
          {error && <p className="login-error" role="alert">{error}</p>}
          <button className="login-submit" type="submit" disabled={loading}>{loading ? "Logger ind…" : <>Log ind <ArrowRight size={19} /></>}</button>
        </form>
      </section>
    </main>
  );
}
