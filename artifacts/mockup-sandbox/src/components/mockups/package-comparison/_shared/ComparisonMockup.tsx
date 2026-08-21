import { Fragment, useEffect, useState } from "react";
import { ArrowLeftRight, Check, Info, TableProperties } from "lucide-react";

import {
  BRANCH_NOTE,
  INCLUDED_ALL,
  MOCK_NOTE,
  PLANS,
  SECTIONS,
  type PlanKey,
  type Row,
} from "./data";
import type { Skin } from "./skins";

/**
 * Landing fonts (same stack as resources/views/pos/landing.blade.php) so the
 * mockup reads like the real marketing page. Scoped under .tn-cmp.
 */
const FONT_CSS = `
@import url('https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700|jetbrains-mono:400,700&display=swap');
.tn-cmp { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
.tn-cmp .tn-serif { font-family: 'Playfair Display', Georgia, serif; }
.tn-cmp .tn-mono { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; }
`;

function useIsPhone(): boolean {
  const [phone, setPhone] = useState<boolean>(() =>
    typeof window === "undefined"
      ? false
      : window.matchMedia("(max-width: 639px)").matches,
  );

  useEffect(() => {
    const mq = window.matchMedia("(max-width: 639px)");
    const onChange = () => setPhone(mq.matches);
    onChange();
    mq.addEventListener("change", onChange);
    return () => mq.removeEventListener("change", onChange);
  }, []);

  return phone;
}

interface Metrics {
  featureW: string;
  planW: string;
  pad: string;
  label: string;
  hint: string;
  value: string;
  planName: string;
  planPrice: string;
}

function metricsFor(dense: boolean): Metrics {
  return dense
    ? {
        featureW: "w-[148px] min-w-[148px]",
        planW: "w-[96px] min-w-[96px]",
        pad: "px-2 py-2",
        label: "text-[12px]",
        hint: "text-[10px]",
        value: "text-[11px]",
        planName: "text-[13px]",
        planPrice: "text-[8px]",
      }
    : {
        featureW: "w-[300px] min-w-[300px]",
        planW: "w-[136px] min-w-[136px]",
        pad: "px-4 py-3",
        label: "text-sm",
        hint: "text-[11px]",
        value: "text-[13px]",
        planName: "text-xl",
        planPrice: "text-[10px]",
      };
}

function RowLabel({
  row,
  metrics,
}: {
  row: Row;
  metrics: Metrics;
}) {
  return (
    <>
      <span
        className={`block font-medium leading-snug text-gray-900 ${metrics.label}`}
      >
        {row.label}
      </span>
      {row.hint ? (
        <span
          className={`mt-0.5 block leading-snug text-gray-500 ${metrics.hint}`}
        >
          {row.hint}
        </span>
      ) : null}
    </>
  );
}

function RowValue({
  row,
  index,
  skin,
  metrics,
  dense,
}: {
  row: Row;
  index: number;
  skin: Skin;
  metrics: Metrics;
  dense: boolean;
}) {
  if (row.kind === "limit") {
    const text = row.values[index];
    return (
      <span
        className={`tn-mono ${metrics.value} ${skin.limitValue(text === "Unlimited")}`}
      >
        {text}
      </span>
    );
  }
  return row.values[index] ? skin.tick(dense) : skin.cross(dense);
}

/* ── Andaz 1: poori table — features column jama, packages scroll ───────── */

function FullTable({ skin, dense }: { skin: Skin; dense: boolean }) {
  const m = metricsFor(dense);

  return (
    <div className={skin.panel}>
      <div className="overflow-x-auto">
        <table className="w-full border-separate border-spacing-0 text-left">
          <thead>
            <tr>
              <th
                className={`sticky left-0 z-20 ${skin.headCorner} ${m.featureW} ${m.pad}`}
              >
                <span
                  className={`tn-mono ${m.hint} font-bold uppercase tracking-widest ${
                    skin.key === "numaya" ? "text-white/70" : "text-gray-500"
                  }`}
                >
                  Features
                </span>
              </th>
              {PLANS.map((plan) => (
                <th
                  key={plan.key}
                  className={`${skin.headPlan(!!plan.popular)} ${m.planW} ${m.pad} text-center`}
                >
                  {plan.popular ? (
                    <span className={skin.popularTag}>Popular</span>
                  ) : null}
                  <span
                    className={`tn-serif block leading-tight ${m.planName} ${skin.planName(!!plan.popular)}`}
                  >
                    {plan.name}
                  </span>
                  <span
                    className={`tn-mono mt-1 block ${m.planPrice} ${skin.planPrice(!!plan.popular)}`}
                  >
                    {plan.price}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {SECTIONS.map((section) => (
              <Fragment key={section.title}>
                <tr>
                  <td
                    className={`sticky left-0 z-10 ${skin.groupCell} ${m.pad}`}
                  >
                    <span className={skin.groupText}>{section.title}</span>
                  </td>
                  <td className={skin.groupFill} colSpan={PLANS.length} />
                </tr>
                {section.rows.map((row, rowIndex) => {
                  const zebra = rowIndex % 2 === 1;
                  return (
                    <tr key={row.label}>
                      <td
                        className={`sticky left-0 z-10 ${skin.featureCell(zebra)} ${m.pad}`}
                      >
                        <RowLabel row={row} metrics={m} />
                      </td>
                      {PLANS.map((plan, index) => (
                        <td
                          key={plan.key}
                          className={`${skin.valueCell(!!plan.popular, zebra)} ${m.pad} text-center`}
                        >
                          <RowValue
                            row={row}
                            index={index}
                            skin={skin}
                            metrics={m}
                            dense={dense}
                          />
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ── Andaz 2: phone par do package ka aamna-samna ───────────────────────── */

function ComparePicker({
  skin,
  label,
  selected,
  onPick,
}: {
  skin: Skin;
  label: string;
  selected: PlanKey;
  onPick: (key: PlanKey) => void;
}) {
  return (
    <div>
      <span className="tn-mono mb-1.5 block text-[9px] font-bold uppercase tracking-widest text-gray-500">
        {label}
      </span>
      <div className="flex flex-wrap gap-1.5">
        {PLANS.map((plan) => (
          <button
            key={plan.key}
            type="button"
            onClick={() => onPick(plan.key)}
            className={`px-2.5 py-1.5 text-[11px] font-semibold ${skin.pill(
              plan.key === selected,
            )}`}
          >
            {plan.name}
          </button>
        ))}
      </div>
    </div>
  );
}

function CompareTwo({ skin, dense }: { skin: Skin; dense: boolean }) {
  const [left, setLeft] = useState<PlanKey>("business");
  const [right, setRight] = useState<PlanKey>("pro");
  const [onlyDiff, setOnlyDiff] = useState(false);
  const m = metricsFor(dense);

  const leftIndex = PLANS.findIndex((p) => p.key === left);
  const rightIndex = PLANS.findIndex((p) => p.key === right);
  const leftPlan = PLANS[leftIndex];
  const rightPlan = PLANS[rightIndex];

  function pickLeft(key: PlanKey) {
    if (key === right) setRight(left);
    setLeft(key);
  }

  function pickRight(key: PlanKey) {
    if (key === left) setLeft(right);
    setRight(key);
  }

  function isDifferent(row: Row): boolean {
    return row.kind === "limit"
      ? row.values[leftIndex] !== row.values[rightIndex]
      : row.values[leftIndex] !== row.values[rightIndex];
  }

  return (
    <div className={skin.panel}>
      <div className="space-y-3 border-b border-b-gray-200 bg-[#FDFBF7] p-3">
        <ComparePicker
          skin={skin}
          label="Pehla package"
          selected={left}
          onPick={pickLeft}
        />
        <ComparePicker
          skin={skin}
          label="Doosra package"
          selected={right}
          onPick={pickRight}
        />
        <button
          type="button"
          onClick={() => setOnlyDiff((v) => !v)}
          className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 text-[11px] font-semibold ${skin.pill(
            onlyDiff,
          )}`}
        >
          <ArrowLeftRight className="h-3.5 w-3.5" />
          Sirf farq dikhayen
        </button>
      </div>

      <table className="w-full border-separate border-spacing-0 text-left">
        <thead>
          <tr>
            <th className={`${skin.headCorner} ${m.pad} w-[42%]`}>
              <span
                className={`tn-mono ${m.hint} font-bold uppercase tracking-widest ${
                  skin.key === "numaya" ? "text-white/70" : "text-gray-500"
                }`}
              >
                Feature
              </span>
            </th>
            {[leftPlan, rightPlan].map((plan) => (
              <th
                key={plan.key}
                className={`${skin.headPlan(!!plan.popular)} ${m.pad} text-center`}
              >
                <span
                  className={`tn-serif block leading-tight ${m.planName} ${skin.planName(!!plan.popular)}`}
                >
                  {plan.name}
                </span>
                <span
                  className={`tn-mono mt-1 block ${m.planPrice} ${skin.planPrice(!!plan.popular)}`}
                >
                  {plan.price}
                </span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {SECTIONS.map((section) => {
            const rows = section.rows.filter(
              (row) => !onlyDiff || isDifferent(row),
            );
            if (rows.length === 0) return null;
            return (
              <Fragment key={section.title}>
                <tr>
                  <td className={`${skin.groupCell} ${m.pad}`}>
                    <span className={skin.groupText}>{section.title}</span>
                  </td>
                  <td className={skin.groupFill} colSpan={2} />
                </tr>
                {rows.map((row, rowIndex) => {
                  const zebra = rowIndex % 2 === 1;
                  const diff = isDifferent(row);
                  return (
                    <tr key={row.label}>
                      <td
                        className={`${skin.featureCell(zebra)} ${m.pad} ${
                          diff ? "border-l-2 border-l-[#E7BF3B]" : ""
                        }`}
                      >
                        <RowLabel row={row} metrics={m} />
                      </td>
                      {[leftIndex, rightIndex].map((index) => (
                        <td
                          key={index}
                          className={`${skin.valueCell(
                            !!PLANS[index].popular,
                            zebra,
                          )} ${m.pad} text-center`}
                        >
                          <RowValue
                            row={row}
                            index={index}
                            skin={skin}
                            metrics={m}
                            dense={dense}
                          />
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </Fragment>
            );
          })}
        </tbody>
      </table>
      {onlyDiff ? (
        <p className="border-t border-t-gray-200 bg-white px-3 py-2 text-[10px] text-gray-500">
          Sirf wo rows dikh rahi hain jinme dono packages ka farq hai.
        </p>
      ) : null}
    </div>
  );
}

/* ── Sab packages mein shamil ───────────────────────────────────────────── */

function IncludedAll({ skin, dense }: { skin: Skin; dense: boolean }) {
  return (
    <div className={`${skin.includedPanel} ${dense ? "p-4" : "p-6 sm:p-8"}`}>
      <h3
        className={`tn-serif ${dense ? "text-lg" : "text-2xl"} ${skin.includedTitle}`}
      >
        Sab packages mein shamil
      </h3>
      <p className={`mt-1 text-[12px] ${skin.includedBody}`}>
        Ye cheezein Starter se Unlimited tak har package ke sath aati hain.
      </p>
      <ul
        className={`mt-4 grid gap-x-6 gap-y-2 ${
          dense ? "grid-cols-1" : "sm:grid-cols-2 lg:grid-cols-3"
        }`}
      >
        {INCLUDED_ALL.map((item) => (
          <li key={item} className="flex items-start gap-2">
            <Check
              className={`mt-0.5 h-4 w-4 shrink-0 ${skin.includedCheck}`}
              strokeWidth={3}
            />
            <span className={`text-[13px] leading-snug ${skin.includedItem}`}>
              {item}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ── Phone frame (desktop par phone ka nazara) ──────────────────────────── */

function PhoneFrame({
  title,
  caption,
  children,
}: {
  title: string;
  caption: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <p className="tn-mono mb-1 text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C]">
        {title}
      </p>
      <p className="mb-3 text-[12px] text-gray-600">{caption}</p>
      <div className="mx-auto w-[340px] overflow-hidden rounded-[28px] border-[10px] border-[#052730] bg-white shadow-lg">
        <div className="flex h-6 items-center justify-center bg-[#052730]">
          <span className="h-1.5 w-16 rounded-full bg-white/30" />
        </div>
        <div className="max-h-[540px] space-y-4 overflow-y-auto bg-[#FDFBF7] p-3">
          {children}
        </div>
      </div>
    </div>
  );
}

/* ── Page ───────────────────────────────────────────────────────────────── */

export function ComparisonMockup({ skin }: { skin: Skin }) {
  const phone = useIsPhone();
  const [mobileMode, setMobileMode] = useState<"scroll" | "compare">("scroll");

  return (
    <div className="tn-cmp min-h-screen bg-[#FDFBF7] text-gray-900">
      <style>{FONT_CSS}</style>

      <div className="mx-auto max-w-[1180px] px-4 py-8 sm:px-6 sm:py-12">
        {/* Mockup warning */}
        <div className="flex items-start gap-3 border-l-4 border-l-[#E7BF3B] bg-white px-4 py-3 shadow-sm">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-[#0A4D5C]" />
          <div>
            <p className="tn-mono text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C]">
              Namoona / Mockup — sirf manzoori ke liye
            </p>
            <p className="mt-1 text-[13px] leading-relaxed text-gray-600">
              {MOCK_NOTE}
            </p>
          </div>
        </div>

        {/* Heading */}
        <header className="mt-8">
          <p className="tn-mono text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C]">
            {skin.variantLabel}
          </p>
          <h1 className="tn-serif mt-3 text-3xl leading-tight text-[#052730] sm:text-5xl">
            Kaun sa package aap ke liye?
          </h1>
          <p className="mt-3 max-w-2xl text-[15px] font-light leading-relaxed text-gray-600">
            {skin.variantBlurb}
          </p>
        </header>

        {/* Phone: andaz switcher */}
        {phone ? (
          <div className="mt-6 flex gap-2">
            <button
              type="button"
              onClick={() => setMobileMode("scroll")}
              className={`flex flex-1 items-center justify-center gap-1.5 px-3 py-2 text-[12px] font-semibold ${skin.pill(
                mobileMode === "scroll",
              )}`}
            >
              <TableProperties className="h-3.5 w-3.5" />
              Poori table
            </button>
            <button
              type="button"
              onClick={() => setMobileMode("compare")}
              className={`flex flex-1 items-center justify-center gap-1.5 px-3 py-2 text-[12px] font-semibold ${skin.pill(
                mobileMode === "compare",
              )}`}
            >
              <ArrowLeftRight className="h-3.5 w-3.5" />
              Do ka moqabla
            </button>
          </div>
        ) : null}

        {/* Table */}
        <div className="mt-6">
          {phone && mobileMode === "compare" ? (
            <CompareTwo skin={skin} dense />
          ) : (
            <FullTable skin={skin} dense={phone} />
          )}
        </div>

        <p className="mt-3 flex items-start gap-2 text-[12px] leading-relaxed text-gray-600">
          <span className="mt-[3px] h-1.5 w-1.5 shrink-0 bg-[#E7BF3B]" />
          {BRANCH_NOTE}
        </p>
        {phone && mobileMode === "scroll" ? (
          <p className="mt-1 text-[11px] text-gray-500">
            Tip: features wala column apni jagah rehta hai — packages ko
            daayen-bayen slide karein.
          </p>
        ) : null}

        {/* Sab mein shamil */}
        <div className="mt-10">
          <IncludedAll skin={skin} dense={phone} />
        </div>

        {/* Desktop: phone par kaisa lagega */}
        {!phone ? (
          <section className="mt-16 border-t border-t-gray-200 pt-10">
            <p className="tn-mono text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C]">
              Phone par
            </p>
            <h2 className="tn-serif mt-2 text-2xl text-[#052730] sm:text-3xl">
              Mobile par do andaz
            </h2>
            <p className="mt-2 max-w-2xl text-[14px] font-light text-gray-600">
              Chhoti screen par table na nichurti hai na kati hai. Neeche dono
              tareeqe asal phone ki chaurai par dikhaye gaye hain — inhein
              scroll aur click karke dekha ja sakta hai.
            </p>
            <div className="mt-8 grid gap-10 md:grid-cols-2">
              <PhoneFrame
                title="Andaz 1 — features jama, packages scroll"
                caption="Bayan column apni jagah jama rehta hai, paanchon packages daayen-bayen scroll hote hain."
              >
                <FullTable skin={skin} dense />
                <p className="text-[11px] leading-relaxed text-gray-600">
                  {BRANCH_NOTE}
                </p>
                <IncludedAll skin={skin} dense />
              </PhoneFrame>
              <PhoneFrame
                title="Andaz 2 — do package ka aamna-samna"
                caption="Do packages chunein aur seedha moqabla dekhein — chahein to sirf farq wali rows."
              >
                <CompareTwo skin={skin} dense />
                <p className="text-[11px] leading-relaxed text-gray-600">
                  {BRANCH_NOTE}
                </p>
              </PhoneFrame>
            </div>
          </section>
        ) : null}

        <p className="mt-12 text-center text-[11px] text-gray-400">
          NestPOS — package comparison mockup ({skin.variantLabel}). Design
          only, koi asal data nahi.
        </p>
      </div>
    </div>
  );
}
