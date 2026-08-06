"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

export function AuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [ready, setReady] = useState(false);
  useEffect(() => {
    fetch("/api/auth/session", { cache: "no-store" }).then((response) => response.ok ? response.json() : null).then((data) => { if (!data?.authenticated) router.replace("/login"); else setReady(true); }).catch(() => router.replace("/login"));
  }, [router]);
  return ready ? children : <main style={{ minHeight: "100vh", display: "grid", placeItems: "center" }}>Kontrollerer login…</main>;
}
