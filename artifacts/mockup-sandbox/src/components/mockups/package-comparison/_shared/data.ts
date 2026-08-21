/**
 * NestPOS (PRA POS) package comparison — MOCKUP DATA ONLY.
 *
 * Numbers mirror the owner-approved package matrix for the upcoming
 * comparison table (bills / team / branches / counters + feature gates).
 * Nothing here is wired to pricing_plans — this file exists purely so the
 * mockup looks like the real thing while the design is being approved.
 */

export type PlanKey = "starter" | "business" | "pro" | "promax" | "unlimited";

export interface Plan {
  key: PlanKey;
  name: string;
  price: string;
  popular?: boolean;
}

export const PLANS: Plan[] = [
  { key: "starter", name: "Starter", price: "Rs 14,999/saal" },
  { key: "business", name: "Business", price: "Rs 24,999/saal", popular: true },
  { key: "pro", name: "Pro", price: "Rs 34,999/saal" },
  { key: "promax", name: "Pro Max", price: "Rs 49,999/saal" },
  { key: "unlimited", name: "Unlimited", price: "Rs 69,999/saal" },
];

export interface LimitRow {
  kind: "limit";
  label: string;
  hint?: string;
  values: string[];
}

export interface FeatureRow {
  kind: "feature";
  label: string;
  hint?: string;
  values: boolean[];
}

export type Row = LimitRow | FeatureRow;

export interface Section {
  title: string;
  rows: Row[];
}

const Y = true;
const N = false;

export const SECTIONS: Section[] = [
  {
    title: "Hadood — limits",
    rows: [
      {
        kind: "limit",
        label: "Bills per month",
        hint: "PRA fiscal bills",
        values: ["2,000", "5,000", "10,000", "Unlimited", "Unlimited"],
      },
      {
        kind: "limit",
        label: "Team accounts",
        hint: "Cashier, manager, waiter, kitchen logins",
        values: ["1", "5", "10", "20", "Unlimited"],
      },
      {
        kind: "limit",
        label: "Branches",
        hint: "Shamil se ooper har branch Rs 10,000 saalana",
        values: ["1", "1", "2", "3", "5"],
      },
      {
        kind: "limit",
        label: "Counters (terminals)",
        hint: "Ek branch mein billing counters",
        values: ["1", "3", "Unlimited", "Unlimited", "Unlimited"],
      },
    ],
  },
  {
    title: "Features",
    rows: [
      {
        kind: "feature",
        label: "Restaurant / Kitchen",
        hint: "Tables, KOT, kitchen display",
        values: [N, Y, Y, Y, Y],
      },
      { kind: "feature", label: "Deals & combos", values: [N, Y, Y, Y, Y] },
      {
        kind: "feature",
        label: "Analytics dashboard",
        values: [N, Y, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "Report exports",
        hint: "CSV & PDF",
        values: [N, Y, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "Excel import / export",
        hint: "Products bulk upload",
        values: [N, Y, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "Offline + Desktop app",
        hint: "Internet band ho to bhi billing",
        values: [N, Y, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "Delivery Riders",
        hint: "Rider khata & settlements",
        values: [N, N, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "QR Menu",
        hint: "Customer scan kar ke menu dekhe",
        values: [N, N, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "WhatsApp Bill",
        values: [N, N, Y, Y, Y],
      },
      {
        kind: "feature",
        label: "Staff Hazri",
        hint: "Attendance report",
        values: [N, N, N, Y, Y],
      },
      {
        kind: "feature",
        label: "Rider Live Tracking",
        hint: "Map par rider ki live location",
        values: [N, N, N, N, Y],
      },
      {
        kind: "feature",
        label: "Custom Access",
        hint: "Har member ke liye features chunein",
        values: [N, N, N, N, Y],
      },
      {
        kind: "feature",
        label: "Caller ID",
        hint: "Call par customer popup",
        values: [N, N, N, N, Y],
      },
    ],
  },
];

export const BRANCH_NOTE =
  "Branches: package mein shamil branches ke ooper har extra branch Rs 10,000 saalana.";

export const INCLUDED_ALL: string[] = [
  "PRA fiscal receipts + QR code",
  "Customer khata (udhaar)",
  "Loyalty points",
  "Inventory + unlimited products",
  "Thermal printing (80mm & 58mm)",
  "Android mobile app",
  "Urdu / Roman Urdu / English",
];

export const MOCK_NOTE =
  "Ye sirf design ka namoona hai — table abhi asal plan settings se nahi juri. Numbers packages ke mutabiq rakhe gaye hain sirf dikhane ke liye.";
