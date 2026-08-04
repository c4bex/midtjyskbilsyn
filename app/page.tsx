import type { Metadata } from "next";
import { Dashboard } from "./dashboard";

export const metadata: Metadata = {
  title: "Driftsoverblik",
  description: "Booking, kunder, køretøjer og daglig drift samlet ét sted.",
};

export default function Home() {
  return <Dashboard />;
}
