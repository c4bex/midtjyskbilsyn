import type { Metadata } from "next";
import { Dashboard } from "./dashboard";
import { AuthGate } from "./auth-gate";

export const metadata: Metadata = {
  title: "Driftsoverblik",
  description: "Booking, kunder, køretøjer og daglig drift samlet ét sted.",
};

export default function Home() {
  return <AuthGate><Dashboard /></AuthGate>;
}
