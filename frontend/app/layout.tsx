import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "GEEK Group — Enterprise HRIS",
  description: "Multi-company Philippine HRIS by GEEK Group",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
