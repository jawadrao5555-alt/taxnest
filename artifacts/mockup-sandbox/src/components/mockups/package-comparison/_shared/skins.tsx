import { Check, X } from "lucide-react";
import type { ReactNode } from "react";

/**
 * Two visual skins for the package comparison mockup. Both stay inside the
 * approved landing palette: teal #052730 / #0A4D5C / #0F6171 primary,
 * gold #E7BF3B only as a micro highlight, flat fills, no gradients.
 */
export interface Skin {
  key: string;
  variantLabel: string;
  variantBlurb: string;

  /** table shell */
  panel: string;
  headCorner: string;
  headPlan: (popular: boolean) => string;
  planName: (popular: boolean) => string;
  planPrice: (popular: boolean) => string;
  popularTag: string;

  /** section band */
  groupCell: string;
  groupFill: string;
  groupText: string;

  /** body */
  featureCell: (zebra: boolean) => string;
  valueCell: (popular: boolean, zebra: boolean) => string;
  limitValue: (unlimited: boolean) => string;
  tick: (dense: boolean) => ReactNode;
  cross: (dense: boolean) => ReactNode;

  /** pickers + "sab mein shamil" block */
  pill: (active: boolean) => string;
  includedPanel: string;
  includedTitle: string;
  includedBody: string;
  includedItem: string;
  includedCheck: string;
}

export const SKIN_SAADA: Skin = {
  key: "saada",
  variantLabel: "Variant 1 — Saada lakeerein",
  variantBlurb:
    "Halki hairlines, safed background, serif package names — landing ke baaqi hisson jaisa saada andaz.",

  panel: "border border-gray-200 bg-white",
  headCorner:
    "bg-white border-b-2 border-b-[#0A4D5C] align-bottom shadow-[6px_0_8px_-7px_rgba(5,39,48,0.35)]",
  headPlan: (popular) =>
    popular
      ? "bg-white align-bottom border-b-2 border-b-[#0A4D5C] border-t-2 border-t-[#E7BF3B]"
      : "bg-white align-bottom border-b-2 border-b-[#0A4D5C]",
  planName: (popular) => (popular ? "text-[#0A4D5C]" : "text-[#052730]"),
  planPrice: () => "text-gray-500",
  popularTag:
    "tn-mono mb-1 inline-block text-[8px] font-bold uppercase tracking-widest text-[#0A4D5C]",

  groupCell:
    "bg-[#FDFBF7] border-b border-b-gray-200 border-r border-r-gray-200 shadow-[6px_0_8px_-7px_rgba(5,39,48,0.35)]",
  groupFill: "bg-[#FDFBF7] border-b border-b-gray-200",
  groupText: "tn-mono text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C]",

  featureCell: () =>
    "bg-white border-b border-b-gray-100 border-r border-r-gray-200 shadow-[6px_0_8px_-7px_rgba(5,39,48,0.35)]",
  valueCell: () => "bg-white border-b border-b-gray-100",
  limitValue: (unlimited) =>
    unlimited ? "font-bold text-[#0A4D5C]" : "text-gray-800",
  tick: (dense) => (
    <Check
      className={`mx-auto text-[#0A4D5C] ${dense ? "h-4 w-4" : "h-5 w-5"}`}
      strokeWidth={3}
    />
  ),
  cross: (dense) => (
    <X
      className={`mx-auto text-gray-400 ${dense ? "h-3.5 w-3.5" : "h-4 w-4"}`}
      strokeWidth={2.5}
    />
  ),

  pill: (active) =>
    active
      ? "border border-[#0A4D5C] bg-[#0A4D5C] text-white"
      : "border border-gray-300 bg-white text-gray-700",
  includedPanel: "border border-gray-200 bg-white",
  includedTitle: "text-[#052730]",
  includedBody: "text-gray-600",
  includedItem: "text-gray-800",
  includedCheck: "text-[#0A4D5C]",
};

export const SKIN_NUMAYA: Skin = {
  key: "numaya",
  variantLabel: "Variant 2 — Numaya headers",
  variantBlurb:
    "Ooper gehra teal band, ek line chhor kar halka tint, tick square boxes mein — table door se hi parh li jati hai.",

  panel: "border border-[#0A4D5C]/20 bg-white shadow-sm",
  headCorner:
    "bg-[#052730] align-bottom shadow-[6px_0_8px_-7px_rgba(5,39,48,0.45)]",
  headPlan: (popular) =>
    popular
      ? "bg-[#0A4D5C] align-bottom border-t-4 border-t-[#E7BF3B]"
      : "bg-[#052730] align-bottom",
  planName: () => "text-white",
  planPrice: (popular) => (popular ? "text-white/75" : "text-white/55"),
  popularTag:
    "tn-mono mb-1 inline-block bg-[#E7BF3B] px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-widest text-[#052730]",

  groupCell:
    "bg-[#0F6171]/10 border-y border-y-[#0A4D5C]/20 border-r-2 border-r-[#0A4D5C]/20 shadow-[6px_0_8px_-7px_rgba(5,39,48,0.45)]",
  groupFill: "bg-[#0F6171]/10 border-y border-y-[#0A4D5C]/20",
  groupText: "tn-mono text-[10px] font-bold uppercase tracking-widest text-[#052730]",

  featureCell: (zebra) =>
    zebra
      ? "bg-[#F3F8F8] border-b border-b-gray-200 border-r-2 border-r-[#0A4D5C]/20 shadow-[6px_0_8px_-7px_rgba(5,39,48,0.45)]"
      : "bg-white border-b border-b-gray-200 border-r-2 border-r-[#0A4D5C]/20 shadow-[6px_0_8px_-7px_rgba(5,39,48,0.45)]",
  valueCell: (popular, zebra) => {
    if (popular) {
      return zebra
        ? "bg-[#0A4D5C]/10 border-b border-b-gray-200"
        : "bg-[#0A4D5C]/5 border-b border-b-gray-200";
    }
    return zebra
      ? "bg-[#F3F8F8] border-b border-b-gray-200"
      : "bg-white border-b border-b-gray-200";
  },
  limitValue: (unlimited) =>
    unlimited ? "font-bold text-[#0A4D5C]" : "font-semibold text-[#052730]",
  tick: (dense) => (
    <span
      className={`mx-auto flex items-center justify-center bg-[#0A4D5C] ${
        dense ? "h-5 w-5" : "h-6 w-6"
      }`}
    >
      <Check
        className={`text-white ${dense ? "h-3.5 w-3.5" : "h-4 w-4"}`}
        strokeWidth={3}
      />
    </span>
  ),
  cross: (dense) => (
    <span
      className={`mx-auto flex items-center justify-center border border-gray-300 bg-gray-50 ${
        dense ? "h-5 w-5" : "h-6 w-6"
      }`}
    >
      <X
        className={`text-gray-400 ${dense ? "h-3 w-3" : "h-3.5 w-3.5"}`}
        strokeWidth={2.5}
      />
    </span>
  ),

  pill: (active) =>
    active
      ? "border border-[#052730] bg-[#052730] text-white"
      : "border border-[#0A4D5C]/25 bg-white text-[#052730]",
  includedPanel: "bg-[#07333E]",
  includedTitle: "text-white",
  includedBody: "text-gray-300",
  includedItem: "text-gray-100",
  includedCheck: "text-[#2EA0B3]",
};
