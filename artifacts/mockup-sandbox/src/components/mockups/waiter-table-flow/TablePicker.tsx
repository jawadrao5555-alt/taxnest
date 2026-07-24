import { useState } from "react";

type TableInfo = {
  id: number;
  num: string;
  status: "free" | "eating" | "ready";
  waiter?: string;
  items?: { name: string; qty: number; price: number; note?: string }[];
  since?: string;
};

const TABLES: TableInfo[] = [
  { id: 1, num: "T-1", status: "free" },
  {
    id: 2, num: "T-2", status: "ready", waiter: "Bilal", since: "12 min",
    items: [
      { name: "Chicken Karahi (Full)", qty: 1, price: 1850 },
      { name: "Garlic Naan", qty: 4, price: 120 },
      { name: "Mint Raita", qty: 2, price: 80, note: "Kam mirch" },
      { name: "Pepsi 1.5L", qty: 1, price: 250 },
    ],
  },
  { id: 3, num: "T-3", status: "eating", waiter: "Ahmed", since: "25 min" },
  { id: 4, num: "T-4", status: "free" },
  {
    id: 5, num: "T-5", status: "ready", waiter: "Ahmed", since: "3 min",
    items: [
      { name: "Beef Burger Deal", qty: 2, price: 950 },
      { name: "Fries (Large)", qty: 1, price: 350 },
    ],
  },
  { id: 6, num: "T-6", status: "free" },
  { id: 7, num: "T-7", status: "eating", waiter: "Bilal", since: "40 min" },
  { id: 8, num: "T-8", status: "free" },
];

export function TablePicker() {
  const [selected, setSelected] = useState<TableInfo | null>(null);

  const cartTotal = selected?.items?.reduce((s, i) => s + i.qty * i.price, 0) ?? 0;
  const tax = Math.round(cartTotal * 0.16);

  return (
    <div className="min-h-screen bg-gray-100 p-6 font-sans">
      <div className="max-w-6xl mx-auto">
        <div className="mb-4 flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-gray-900">Tables — Naya Flow (Preview)</h1>
            <p className="text-sm text-gray-500 mt-0.5">
              Waiter order bhejta hai → table <span className="font-semibold text-purple-700">"Order Tayyar"</span> ban jata hai → cashier table par CLICK kare → bill cart mein load → Pay.
              Upar wala Waiter box KHATAM.
            </p>
          </div>
          <span className="px-3 py-1.5 rounded-lg bg-purple-600 text-white text-xs font-semibold">F3 — Table Picker</span>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {/* Table grid */}
          <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <div className="flex items-center gap-4 mb-3 text-xs">
              <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-white border-2 border-gray-300 inline-block"></span> Khali</span>
              <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-amber-400 inline-block"></span> Khana chal raha hai</span>
              <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded bg-purple-600 inline-block"></span> Order Tayyar — click = bill</span>
            </div>
            <div className="grid grid-cols-4 gap-3">
              {TABLES.map((t) => (
                <button
                  key={t.id}
                  onClick={() => t.status === "ready" && setSelected(t)}
                  className={
                    "relative rounded-xl border-2 p-3 h-28 flex flex-col items-center justify-center transition text-center " +
                    (t.status === "free"
                      ? "border-gray-200 bg-white text-gray-500 hover:border-gray-300"
                      : t.status === "eating"
                      ? "border-amber-300 bg-amber-50 text-amber-800 cursor-default"
                      : "border-purple-500 bg-purple-600 text-white hover:bg-purple-700 cursor-pointer")
                  }
                >
                  <span className="text-lg font-bold">{t.num}</span>
                  {t.status === "free" && <span className="text-[11px] mt-1">Khali</span>}
                  {t.status === "eating" && (
                    <>
                      <span className="text-[11px] mt-1 font-semibold">Khana chal raha</span>
                      <span className="text-[10px] opacity-70">{t.waiter} · {t.since}</span>
                    </>
                  )}
                  {t.status === "ready" && (
                    <>
                      <span className="text-[11px] mt-1 font-bold uppercase tracking-wide">Order Tayyar</span>
                      <span className="text-[10px] opacity-90">
                        {t.items!.reduce((s, i) => s + i.qty, 0)} items · Rs {t.items!.reduce((s, i) => s + i.qty * i.price, 0).toLocaleString()}
                      </span>
                      <span className="text-[10px] opacity-80">Waiter: {t.waiter}</span>
                      <span className="absolute -top-2 -right-2 bg-emerald-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-sm">BILL</span>
                    </>
                  )}
                </button>
              ))}
            </div>
            <p className="text-[11px] text-gray-400 mt-3">
              Ek hi order do cashiers ek sath NAHI utha sakte — pehla click table ko lock kar leta hai (jaisa abhi hai, hifazat barqarar).
            </p>
          </div>

          {/* Cart after click */}
          <div className="bg-white rounded-xl border border-gray-200 shadow-sm flex flex-col">
            <div className="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
              <h2 className="text-sm font-bold text-gray-900">Current Order</h2>
              {selected && (
                <span className="text-[11px] font-semibold text-purple-700 bg-purple-50 px-2 py-0.5 rounded">
                  {selected.num} · Waiter: {selected.waiter}
                </span>
              )}
            </div>
            {!selected ? (
              <div className="flex-1 flex flex-col items-center justify-center text-center p-6 text-gray-400">
                <span className="text-3xl mb-2">👈</span>
                <p className="text-sm font-medium">Purple "Order Tayyar" table par click karein</p>
                <p className="text-xs mt-1">Order khud cart mein aa jayega</p>
              </div>
            ) : (
              <>
                <div className="flex-1 p-3 space-y-2">
                  {selected.items!.map((i, idx) => (
                    <div key={idx} className="flex items-center justify-between text-sm border-b border-gray-50 pb-2">
                      <div>
                        <p className="font-medium text-gray-800">{i.name}</p>
                        {i.note && <p className="text-[11px] text-amber-600">Note: {i.note}</p>}
                      </div>
                      <div className="text-right">
                        <p className="text-gray-500 text-xs">{i.qty} × {i.price.toLocaleString()}</p>
                        <p className="font-semibold text-gray-900">Rs {(i.qty * i.price).toLocaleString()}</p>
                      </div>
                    </div>
                  ))}
                </div>
                <div className="p-3 border-t border-gray-100 space-y-1 text-sm">
                  <div className="flex justify-between text-gray-500"><span>Subtotal</span><span>Rs {cartTotal.toLocaleString()}</span></div>
                  <div className="flex justify-between text-gray-500"><span>Tax (16%)</span><span>Rs {tax.toLocaleString()}</span></div>
                  <div className="flex justify-between font-bold text-gray-900 text-base"><span>TOTAL</span><span>Rs {(cartTotal + tax).toLocaleString()}</span></div>
                  <button className="w-full mt-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-lg text-sm">
                    PAY — F8
                  </button>
                  <p className="text-[10px] text-gray-400 text-center pt-1">
                    Bill par waiter ({selected.waiter}) ka record khud save hota hai
                  </p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
