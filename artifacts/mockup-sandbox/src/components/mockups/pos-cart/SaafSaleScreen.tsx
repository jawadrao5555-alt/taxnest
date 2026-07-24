import {
  Banknote,
  Bell,
  ChevronDown,
  ChevronsUpDown,
  Coins,
  CreditCard,
  Flame,
  History,
  Minus,
  Percent,
  Plus,
  Printer,
  Search,
  ShoppingCart,
  Smartphone,
  Trash2,
  User,
  WifiOff,
} from "lucide-react";

const items = [
  { name: "Chicken Biryani", qty: 2, price: 350, total: 700 },
  { name: "Chai", qty: 3, price: 60, total: 180 },
  { name: "Samosa", qty: 2, price: 45, total: 90 },
];

const products = [
  { name: "Chicken Biryani", price: 350, c: "#0A4D5C" },
  { name: "Beef Pulao", price: 400, c: "#155e70" },
  { name: "Chai", price: 60, c: "#0d9488" },
  { name: "Doodh Patti", price: 80, c: "#0f766e" },
  { name: "Samosa", price: 45, c: "#7c5e10" },
  { name: "Pakora (250g)", price: 120, c: "#8a5a00" },
  { name: "Zinger Burger", price: 320, c: "#6d28d9" },
  { name: "Shawarma", price: 220, c: "#5b21b6" },
  { name: "Fries", price: 150, c: "#374151" },
  { name: "Cold Drink 1.5L", price: 180, c: "#1f2937" },
  { name: "Mineral Water", price: 60, c: "#334155" },
  { name: "Kheer", price: 130, c: "#525252" },
  { name: "Chicken Karahi (Half)", price: 850, c: "#0A4D5C" },
  { name: "Naan", price: 30, c: "#7c5e10" },
  { name: "Raita", price: 50, c: "#0f766e" },
];

function Kbd({ children, dark = false }: { children: string; dark?: boolean }) {
  return dark ? (
    <span className="text-[10px] font-bold rounded px-1.5 py-0.5 bg-neutral-200 text-neutral-600 border border-neutral-300">
      {children}
    </span>
  ) : (
    <span className="text-[10px] font-bold rounded px-1.5 py-0.5 bg-black/25 text-white/90 border border-white/25">
      {children}
    </span>
  );
}

function Toggle({ on, color }: { on: boolean; color: string }) {
  return (
    <span
      className="inline-flex w-9 h-5 rounded-full p-0.5 transition-none"
      style={{ background: on ? color : "#a3a3a3", justifyContent: on ? "flex-end" : "flex-start" }}
    >
      <span className="w-4 h-4 rounded-full bg-white block" />
    </span>
  );
}

export function SaafSaleScreen() {
  return (
    <div className="min-h-screen bg-gray-50 font-sans text-gray-800 flex flex-col">
      {/* ===== TOP NAV — Saaf teal, 5 simple pills ===== */}
      <div className="relative z-20 flex items-center gap-3 px-3 h-12 shrink-0" style={{ background: "#0A4D5C" }}>
        <div className="flex items-center gap-2">
          <div className="w-7 h-7 rounded-md bg-white flex items-center justify-center text-xs font-extrabold" style={{ color: "#0A4D5C" }}>
            N
          </div>
          <div className="leading-tight">
            <div className="text-white text-[13px] font-bold leading-none">NestPOS</div>
            <div className="text-white/50 text-[9px] leading-none mt-0.5">SAAF</div>
          </div>
        </div>
        <button className="ml-2 rounded-md bg-white px-3 py-1.5 text-[12px] font-bold" style={{ color: "#0A4D5C" }}>
          + Naya Bill
        </button>
        <div className="hidden lg:flex items-center gap-1">
          {["Home", "Bills", "Products", "Reports", "Settings"].map((l) => (
            <button key={l} className="rounded-md px-2.5 py-1.5 text-[11px] font-semibold text-white/85 hover:bg-white/10">
              {l}
            </button>
          ))}
        </div>

        <div className="flex-1" />

        {/* Mazeed — utility buttons ek pill ke andar (Saaf usool: kam cheezein samne) */}
        <div className="relative">
          <button className="flex items-center gap-1.5 rounded-md bg-white/10 border border-white/15 px-2.5 py-1.5 text-[11px] font-semibold text-white/85">
            Mazeed <ChevronDown className="w-3 h-3" />
          </button>
          {/* dropdown khula dikhaya gaya hai */}
          <div className="absolute top-10 right-0 w-56 rounded-lg bg-white border border-gray-200 shadow-lg p-2 space-y-1">
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-gray-50">
              <span className="flex items-center gap-2 text-[11px] font-bold text-gray-600">
                <WifiOff className="w-3.5 h-3.5 text-gray-400" /> Local Bills
              </span>
              <Kbd dark>F10</Kbd>
            </div>
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-gray-50">
              <span className="flex items-center gap-2 text-[11px] font-bold text-gray-600">
                <Flame className="w-3.5 h-3.5 text-gray-400" /> Failed Bills
              </span>
              <Kbd dark>F11</Kbd>
            </div>
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-gray-50">
              <span className="flex items-center gap-2 text-[11px] font-bold text-gray-600">
                <Printer className="w-3.5 h-3.5 text-gray-400" /> Reprint
              </span>
              <Kbd dark>Alt+R</Kbd>
            </div>
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-gray-50">
              <span className="flex items-center gap-2 text-[11px] font-bold text-gray-600">
                <History className="w-3.5 h-3.5 text-gray-400" /> Held Bills
              </span>
              <Kbd dark>F3</Kbd>
            </div>
            <div className="text-[9px] text-gray-400 px-2 pb-0.5">Saaf mein ye sab "Mazeed" ke andar — screen saaf rehti hai</div>
          </div>
        </div>

        {/* Switches icon (PRA / Auto-Print / Auto-KOT) — band halat mein */}
        <button className="flex items-center gap-1.5 rounded-md bg-white/10 border border-white/15 px-2 py-1.5">
          <ChevronsUpDown className="w-3.5 h-3.5 text-white/85" />
          <span className="flex items-center gap-0.5">
            <span className="w-1.5 h-1.5 rounded-full bg-neutral-300" />
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
            <span className="w-1.5 h-1.5 rounded-full bg-orange-400" />
          </span>
        </button>

        <div className="flex items-center gap-2 ml-1">
          <span className="flex items-center gap-1 text-[10px] font-bold text-emerald-300">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-300" /> NET
          </span>
          <span className="flex items-center gap-1 text-[10px] font-bold text-emerald-300">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-300" /> PRA
          </span>
          <span className="text-white/60 text-[11px] font-semibold">15:12</span>
          <Bell className="w-4 h-4 text-white/60" />
          <div className="flex items-center gap-1.5 rounded-md bg-white/10 px-2 py-1">
            <div className="w-5 h-5 rounded-full bg-white/30 flex items-center justify-center">
              <User className="w-3 h-3 text-white" />
            </div>
            <div className="leading-tight">
              <div className="text-white text-[10px] font-bold leading-none">Zahid Irfan</div>
              <div className="text-white/50 text-[8px] leading-none mt-0.5">ZFC PIZZA POINT</div>
            </div>
          </div>
        </div>
      </div>

      {/* ===== ROW 1: Customer pehle + Order Type ===== */}
      <div className="flex items-center gap-3 px-3 py-2 bg-white border-b border-gray-200 shrink-0">
        <div className="flex items-center gap-2 flex-1 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
          <User className="w-4 h-4 text-gray-400" />
          <span className="text-[13px] text-gray-400">Customer — naam ya number likhein (walk-in ke liye khali chhorein)</span>
          <span className="ml-auto"><Kbd dark>C</Kbd></span>
        </div>
        <div className="flex items-center gap-1.5">
          <button className="rounded-xl px-3 py-2 text-[12px] font-bold border border-gray-200 text-gray-500">Dine-In</button>
          <button className="rounded-xl px-3 py-2 text-[12px] font-bold text-white" style={{ background: "#0A4D5C" }}>
            Takeaway
          </button>
          <button className="rounded-xl px-3 py-2 text-[12px] font-bold border border-gray-200 text-gray-500">Delivery</button>
        </div>
      </div>

      {/* ===== ROW 2: Category + BADA SEARCH ===== */}
      <div className="flex items-center gap-3 px-3 py-2 bg-white border-b border-gray-200 shrink-0">
        <button className="flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2.5 text-[13px] font-semibold text-gray-600 shrink-0">
          Sab Categories <ChevronDown className="w-4 h-4 text-gray-400" />
        </button>
        <div className="flex items-center gap-2 flex-1 rounded-xl border-2 px-4 py-2.5 bg-white" style={{ borderColor: "#0A4D5C" }}>
          <Search className="w-5 h-5" style={{ color: "#0A4D5C" }} />
          <span className="text-[15px] text-gray-400">Barcode scan karein ya product ka naam likhein…</span>
          <span className="ml-auto"><Kbd dark>F2</Kbd></span>
        </div>
      </div>

      {/* ===== MAIN: grid + cart ===== */}
      <div className="flex flex-1 min-h-0">
        {/* Product list */}
        <div className="flex-1 p-3 overflow-hidden flex flex-col min-h-0">
          <div className="grid grid-cols-2 gap-1.5 overflow-hidden">
            {products.map((p) => (
              <button
                key={p.name}
                className="rounded-lg bg-white border border-gray-200 pl-2 pr-2 py-1.5 text-left border-l-[3px] flex items-center gap-2 hover:border-teal-600"
                style={{ borderLeftColor: p.c }}
              >
                <span className="text-[12px] font-medium text-gray-800 truncate flex-1">{p.name}</span>
                <span className="text-[12px] font-bold shrink-0" style={{ color: "#0A4D5C" }}>
                  {p.price}
                </span>
              </button>
            ))}
          </div>

          <div className="flex-1" />

          {/* Akhri bill ki jhalak */}
          <div className="mt-2 flex items-center gap-2 rounded-xl bg-white border border-gray-200 px-3 py-2 shrink-0">
            <History className="w-4 h-4 text-gray-400 shrink-0" />
            <span className="text-[12px] text-gray-600">
              <span className="font-bold text-gray-800">Akhri bill:</span> Rs 1,125 · Cash · 15:09 · Bill #POS-2026-01847
            </span>
            <button className="ml-auto flex items-center gap-1.5 rounded-md border border-gray-200 px-2.5 py-1 text-[11px] font-bold text-gray-600">
              <Printer className="w-3 h-3" /> Reprint
            </button>
          </div>
        </div>

        {/* Cart column */}
        <div className="w-[460px] shrink-0 bg-white border-l border-gray-200 flex flex-col">
          <div className="px-4 py-2.5 flex items-center justify-between shrink-0" style={{ background: "#0A4D5C" }}>
            <div className="flex items-center gap-2 text-white font-semibold text-sm">
              <ShoppingCart className="w-4 h-4" /> Mojooda Bill
            </div>
            <span className="text-white/70 text-[11px] font-semibold">3 items</span>
          </div>

          <div className="flex-1 overflow-hidden">
            {items.map((it) => (
              <div key={it.name} className="flex items-center gap-2 px-3 py-2 border-b border-gray-100">
                <div className="flex-1 min-w-0">
                  <div className="text-[12.5px] font-semibold text-gray-800 truncate">{it.name}</div>
                  <div className="text-[10.5px] text-gray-400">Rs {it.price} fi item</div>
                </div>
                <div className="flex items-center gap-1">
                  <button className="w-6 h-6 rounded-md border border-gray-200 flex items-center justify-center">
                    <Minus className="w-3 h-3 text-gray-500" />
                  </button>
                  <span className="w-7 text-center text-[12px] font-bold">{it.qty}</span>
                  <button className="w-6 h-6 rounded-md border border-gray-200 flex items-center justify-center">
                    <Plus className="w-3 h-3 text-gray-500" />
                  </button>
                </div>
                <div className="w-16 text-right text-[12.5px] font-bold text-gray-800">Rs {it.total}</div>
                <button className="w-6 h-6 rounded-md flex items-center justify-center">
                  <Trash2 className="w-3.5 h-3.5 text-gray-300" />
                </button>
              </div>
            ))}
          </div>

          <div className="px-3 py-1.5 border-t border-gray-100 shrink-0 flex items-center gap-1.5">
            <div className="flex-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-[11px] text-gray-400">
              Order Notes… (N dabayen)
            </div>
            <button className="shrink-0 flex items-center gap-1 rounded-md border border-gray-300 px-2.5 py-1.5 text-[11px] font-bold text-gray-600">
              <Percent className="w-3 h-3" />
              Discount
            </button>
          </div>

          {/* Bada Total band */}
          <div className="px-4 py-2.5 flex items-end justify-between shrink-0" style={{ background: "#0A4D5C" }}>
            <div className="text-white/80 text-[11px] leading-[18px]">
              <div>Subtotal &nbsp; Rs 970.00</div>
              <div>Tax (16%) &nbsp; Rs 155.20</div>
              <div className="mt-0.5 inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-bold text-white">
                3 items · 7 qty
              </div>
            </div>
            <div className="text-right">
              <div className="text-white/70 text-[10px] font-semibold tracking-widest">TOTAL (CASH)</div>
              <div className="text-white text-[34px] font-extrabold leading-none">Rs 1,125</div>
              <div className="text-white/60 text-[9px] mt-0.5">Card/Digital pe: Rs 1,048 (tax 8%)</div>
            </div>
          </div>

          {/* Payment + footer */}
          <div className="p-3 space-y-1.5 shrink-0 bg-gray-50 border-t border-gray-200">
            <div className="grid grid-cols-3 gap-1.5">
              <button className="py-2.5 rounded-xl text-white flex flex-col items-center gap-0.5" style={{ background: "#0A4D5C" }}>
                <span className="flex items-center gap-1.5 text-[12px] font-bold">
                  <Banknote className="w-4 h-4" /> CASH
                </span>
                <span className="text-[10px] text-white/75 font-semibold">Rs 1,125</span>
                <Kbd>Alt+1</Kbd>
              </button>
              <button className="py-2.5 rounded-xl text-white flex flex-col items-center gap-0.5" style={{ background: "#0d9488" }}>
                <span className="flex items-center gap-1.5 text-[12px] font-bold">
                  <CreditCard className="w-4 h-4" /> CARD
                </span>
                <span className="text-[10px] text-white/75 font-semibold">Rs 1,048</span>
                <Kbd>Alt+2</Kbd>
              </button>
              <button className="py-2.5 rounded-xl text-white flex flex-col items-center gap-0.5" style={{ background: "#0f766e" }}>
                <span className="flex items-center gap-1.5 text-[12px] font-bold">
                  <Smartphone className="w-4 h-4" /> DIGITAL
                </span>
                <span className="text-[10px] text-white/75 font-semibold">Rs 1,048</span>
                <Kbd>Alt+3</Kbd>
              </button>
            </div>

            <div className="grid grid-cols-4 gap-1.5">
              <button className="py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Clear <Kbd dark>F4</Kbd>
              </button>
              <button className="py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Hold <Kbd dark>F5</Kbd>
              </button>
              <button className="py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Recall <Kbd dark>F3</Kbd>
              </button>
              <button className="py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                <Coins className="w-3.5 h-3.5" />
                Drawer
              </button>
            </div>

            <div className="grid grid-cols-3 gap-1.5 items-stretch">
              <button className="py-2 rounded-lg border border-orange-300 bg-orange-50 text-orange-700 text-[11px] font-bold flex items-center justify-center gap-1">
                Send to Kitchen
              </button>
              <button className="py-2 rounded-lg border text-[11px] font-bold flex items-center justify-center gap-1.5" style={{ borderColor: "#0A4D5C", color: "#0A4D5C" }}>
                Provisional <Kbd dark>F9</Kbd>
              </button>
              <button className="py-2 rounded-lg text-white text-[12px] font-extrabold flex items-center justify-center gap-1.5" style={{ background: "#0d9488" }}>
                PAY <Kbd>F8</Kbd>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
