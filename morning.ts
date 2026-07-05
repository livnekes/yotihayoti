#!/usr/bin/env -S npx tsx
// Morning (greeninvoice) — search invoices by description term, export CSV/PDF.
// Usage: npx tsx --env-file=.env morning.ts [term] [fromDate] [--format csv|pdf|both]
// Env: MORNING_API_KEY, MORNING_API_SECRET
import { writeFileSync } from "node:fs";

const BASE = "https://api.greeninvoice.co.il/api/v1";
const TOKEN_URL = "https://api.morning.co/idp/v1/oauth/token";

const KEY = process.env.MORNING_API_KEY;
const SECRET = process.env.MORNING_API_SECRET;
if (!KEY || !SECRET) {
  console.error("set MORNING_API_KEY and MORNING_API_SECRET");
  process.exit(1);
}

// args: positional [term] [fromDate], flag --format
const argv = process.argv.slice(2);
const fmtIdx = argv.indexOf("--format");
const format = fmtIdx >= 0 ? argv.splice(fmtIdx, 2)[1] : "both";
if (!["csv", "pdf", "both"].includes(format)) {
  console.error(`bad --format "${format}" (csv|pdf|both)`);
  process.exit(1);
}
const MATCH = argv[0] ?? "יום פתוח";
// fromDate is REQUIRED or the API silently caps at ~1868 docs; 2020-01-01 unlocks the full set.
const FROM = argv[1] ?? "2020-01-01";

type Row = { number: string; date: string; client: string; amount: number; description: string };

async function getToken(): Promise<string> {
  const res = await fetch(TOKEN_URL, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      grant_type: "client_credentials",
      client_id: KEY!,
      client_secret: SECRET!,
    }),
  });
  const auth = await res.json();
  if (!auth.accessToken) {
    console.error("auth failed — API response:", JSON.stringify(auth));
    process.exit(1);
  }
  return auth.accessToken;
}

async function fetchRows(token: string): Promise<Row[]> {
  const rows: Row[] = [];
  let page = 1;
  while (true) {
    const res = await fetch(`${BASE}/documents/search`, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
      body: JSON.stringify({ pageSize: 100, page, fromDate: FROM }),
    });
    const data = await res.json();

    for (const it of data.items ?? []) {
      if (typeof it.description === "string" && it.description.includes(MATCH)) {
        rows.push({
          number: it.number,
          date: it.documentDate,
          client: it.client?.name ?? "",
          amount: it.amount,
          description: it.description,
        });
      }
    }
    const pages = data.pages ?? 1;
    if (page >= pages) break;
    page++;
  }
  return rows;
}

// RFC-4180 CSV: quote every field, double internal quotes. ponytail: covers commas/quotes/newlines.
function csvCell(v: unknown): string {
  return `"${String(v ?? "").replace(/"/g, '""')}"`;
}

function writeCsv(rows: Row[], file: string) {
  const header = "number,date,client,amount,description";
  const lines = rows.map((r) =>
    [r.number, r.date, r.client, r.amount, r.description].map(csvCell).join(","),
  );
  writeFileSync(file, [header, ...lines].join("\n") + "\n");
}

async function writePdf(rows: Row[], file: string) {
  const { default: PDFDocument } = await import("pdfkit");
  const doc = new PDFDocument({ margin: 30, size: "A4", layout: "landscape" });
  const { createWriteStream } = await import("node:fs");
  const done = new Promise<void>((resolve) => doc.on("end", () => resolve()));
  doc.pipe(createWriteStream(file));

  doc.fontSize(14).text(`Invoices matching "${MATCH}"  (${rows.length})`, { align: "left" });
  doc.moveDown(0.5).fontSize(8);
  for (const r of rows) {
    doc.text(`${r.number}  ${r.date}  ${r.client}  ${r.amount}  ${r.description}`);
  }
  doc.end();
  await done;
}

const token = await getToken();
const rows = await fetchRows(token);

if (format === "csv" || format === "both") {
  writeCsv(rows, "yom_patuach.csv");
  console.error(`Wrote ${rows.length} invoices to ./yom_patuach.csv`);
}
if (format === "pdf" || format === "both") {
  await writePdf(rows, "yom_patuach.pdf");
  console.error(`Wrote ${rows.length} invoices to ./yom_patuach.pdf`);
}
