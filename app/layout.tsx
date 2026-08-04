import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { headers } from "next/headers";
import "./globals.css";

const geistSans = Geist({ variable: "--font-geist-sans", subsets: ["latin"] });
const geistMono = Geist_Mono({ variable: "--font-geist-mono", subsets: ["latin"] });

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host") ?? "localhost:4317";
  const protocol = requestHeaders.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https");
  const base = new URL(`${protocol}://${host}`);

  return {
    metadataBase: base,
    title: { default: "Midtjysk Bilsyn", template: "%s | Midtjysk Bilsyn" },
    description: "Et enkelt og driftssikkert system til den daglige bilsynsdrift.",
    icons: { icon: "/favicon.svg", shortcut: "/favicon.svg" },
    openGraph: {
      title: "Midtjysk Bilsyn — Driften samlet ét sted",
      description: "Booking, kunder, køretøjer og daglig drift i ét overskueligt system.",
      images: [{ url: new URL("/og.png", base), width: 1200, height: 630, alt: "Midtjysk Bilsyn driftssystem" }],
      locale: "da_DK",
      type: "website",
    },
    twitter: { card: "summary_large_image", images: [new URL("/og.png", base)] },
  };
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="da">
      <body className={`${geistSans.variable} ${geistMono.variable}`}>{children}</body>
    </html>
  );
}
