import {
  Banknote,
  Bell,
  ChevronDown,
  ChevronsUpDown,
  ClipboardList,
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

function NayaTag() {
  return (
    <span className="text-[9px] font-extrabold tracking-wide rounded-sm px-1 py-[1px] bg-amber-400 text-amber-950 uppercase">
      Naya
    </span>
  );
}

function NavPill({ icon, label, kbd }: { icon: React.ReactNode; label: string; kbd: string }) {
  return (
    <button className="flex items-center gap-1.5 rounded-md bg-white/10 border border-white/15 px-2 py-1 text-[11px] font-semibold text-white/85">
      {icon}
      {label}
      <span className="text-[9px] font-bold rounded px-1 py-[1px] bg-black/30 text-white/80 border border-white/20">{kbd}</span>
    </button>
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

export function PooriSaleScreen() {
  return (
    <div className="min-h-screen bg-neutral-100 font-sans text-neutral-800 flex flex-col">
      {/* ===== TOP BLACK NAV — ab utility buttons + toggles yahan ===== */}
      <div className="relative z-20 flex items-center gap-3 px-3 h-12 shrink-0" style={{ background: "#101418" }}>
        <div className="flex items-center gap-2">
          <div className="w-7 h-7 rounded-md flex items-center justify-center text-white text-xs font-extrabold" style={{ background: "#0d9488" }}>
            N
          </div>
          <div className="leading-tight">
            <div className="text-white text-[13px] font-bold leading-none">NestPOS</div>
            <div className="text-white/50 text-[9px] leading-none mt-0.5">ENTERPRISE</div>
          </div>
        </div>
        <button className="ml-2 rounded-md px-3 py-1.5 text-[12px] font-bold text-white" style={{ background: "#0d9488" }}>
          + New Sale
        </button>

        <div className="flex-1" />

        {/* Naye compact utility buttons (pehle sale screen ki alag line thi) */}
        <div className="flex items-center gap-1.5">
          <NayaTag />
          <NavPill icon={<WifiOff className="w-3 h-3" />} label="Local" kbd="F10" />
          <NavPill icon={<Flame className="w-3 h-3" />} label="Failed" kbd="F11" />
          <NavPill icon={<Printer className="w-3 h-3" />} label="Reprint" kbd="Alt+R" />
          <NavPill icon={<History className="w-3 h-3" />} label="Held" kbd="F3" />
        </div>

        {/* Naya switches icon + dropdown (PRA / Auto-Print / Auto-KOT) */}
        <div className="relative flex items-center gap-1.5 ml-1">
          <NayaTag />
          <button className="flex items-center gap-1.5 rounded-md bg-white/10 border border-white/15 px-2 py-1.5">
            <ChevronsUpDown className="w-3.5 h-3.5 text-white/85" />
            <span className="flex items-center gap-0.5">
              <span className="w-1.5 h-1.5 rounded-full bg-neutral-400" />
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
              <span className="w-1.5 h-1.5 rounded-full bg-orange-500" />
            </span>
          </button>
          {/* dropdown khula hua dikhaya gaya hai */}
          <div className="absolute top-10 right-0 w-64 rounded-lg bg-white border border-neutral-200 shadow-lg p-2 space-y-1">
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-neutral-50">
              <span className="text-[11px] font-bold text-neutral-600">PRA REPORTING</span>
              <span className="flex items-center gap-1.5 text-[10px] font-bold text-neutral-400">
                OFF <Toggle on={false} color="#0d9488" />
              </span>
            </div>
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-neutral-50">
              <span className="text-[11px] font-bold text-neutral-600">AUTO-PRINT</span>
              <span className="flex items-center gap-1.5 text-[10px] font-bold text-emerald-600">
                ON <Toggle on color="#059669" />
              </span>
            </div>
            <div className="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-neutral-50">
              <span className="text-[11px] font-bold text-neutral-600">AUTO-KOT</span>
              <span className="flex items-center gap-1.5 text-[10px] font-bold text-orange-600">
                ON <Toggle on color="#ea580c" />
              </span>
            </div>
            <div className="text-[9px] text-neutral-400 px-2 pb-0.5">Icon pe click se khulta hai — screen pe ab ye line nahi</div>
          </div>
        </div>

        <div className="flex items-center gap-2 ml-2">
          <span className="flex items-center gap-1 text-[10px] font-bold text-emerald-400">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" /> NET
          </span>
          <span className="flex items-center gap-1 text-[10px] font-bold text-emerald-400">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" /> PRA
          </span>
          <span className="text-white/60 text-[11px] font-semibold">15:12</span>
          <Bell className="w-4 h-4 text-white/60" />
          <div className="flex items-center gap-1.5 rounded-md bg-white/10 px-2 py-1">
            <div className="w-5 h-5 rounded-full bg-neutral-500 flex items-center justify-center">
              <User className="w-3 h-3 text-white" />
            </div>
            <div className="leading-tight">
              <div className="text-white text-[10px] font-bold leading-none">Zahid Irfan</div>
              <div className="text-white/50 text-[8px] leading-none mt-0.5">ZFC PIZZA POINT</div>
            </div>
          </div>
        </div>
      </div>

      {/* ===== ROW 1: Customer (pehla hi rehta hai) + Order Type ===== */}
      <div className="flex items-center gap-3 px-3 py-2 bg-white border-b border-neutral-200 shrink-0">
        <div className="flex items-center gap-2 flex-1 rounded-lg border border-neutral-300 bg-neutral-50 px-3 py-2">
          <User className="w-4 h-4 text-neutral-400" />
          <span className="text-[13px] text-neutral-400">Customer — naam ya number likhein (walk-in ke liye khali chhorein)</span>
          <span className="ml-auto"><Kbd dark>C</Kbd></span>
        </div>
        <div className="flex items-center gap-1.5">
          <button className="rounded-lg px-3 py-2 text-[12px] font-bold border border-neutral-300 text-neutral-500">Dine-In</button>
          <button className="rounded-lg px-3 py-2 text-[12px] font-bold text-white" style={{ background: "#0A4D5C" }}>
            Takeaway
          </button>
          <button className="rounded-lg px-3 py-2 text-[12px] font-bold border border-neutral-300 text-neutral-500">Delivery</button>
        </div>
      </div>

      {/* ===== ROW 2: Category + BADA SEARCH (steps/toggles/buttons ki lines khatam) ===== */}
      <div className="flex items-center gap-3 px-3 py-2 bg-white border-b border-neutral-200 shrink-0">
        <button className="flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2.5 text-[13px] font-semibold text-neutral-600 shrink-0">
          Sab Categories <ChevronDown className="w-4 h-4 text-neutral-400" />
        </button>
        <div className="flex items-center gap-2 flex-1 rounded-lg border-2 px-4 py-2.5 bg-white" style={{ borderColor: "#0d9488" }}>
          <Search className="w-5 h-5" style={{ color: "#0d9488" }} />
          <span className="text-[15px] text-neutral-400">Scan barcode ya product ka naam likhein…</span>
          <span className="ml-auto flex items-center gap-1.5">
            <NayaTag />
            <Kbd dark>F2</Kbd>
          </span>
        </div>
      </div>

      {/* ===== MAIN: grid + cart ===== */}
      <div className="flex flex-1 min-h-0">
        {/* Product grid */}
        <div className="flex-1 p-3 overflow-hidden">
          <div className="grid grid-cols-2 gap-1.5">
            {products.map((p) => (
              <button
                key={p.name}
                className="rounded-md bg-white border border-neutral-200 pl-2 pr-2 py-1.5 text-left border-l-[3px] flex items-center gap-2"
                style={{ borderLeftColor: p.c }}
              >
                <span className="text-[12px] font-medium text-neutral-800 truncate flex-1">{p.name}</span>
                <span className="text-[12px] font-bold shrink-0" style={{ color: "#0A4D5C" }}>
                  {p.price}
                </span>
              </button>
            ))}
          </div>
        </div>

        {/* Cart column */}
        <div className="w-[460px] shrink-0 bg-white border-l border-neutral-200 flex flex-col">
          {/* Header — yellow duplicate line KHATAM, sirf ye header */}
          <div className="px-4 py-2.5 flex items-center justify-between shrink-0" style={{ background: "#0A4D5C" }}>
            <div className="flex items-center gap-2 text-white font-semibold text-sm">
              <ShoppingCart className="w-4 h-4" /> Current Order
            </div>
            <span className="text-[10px] font-bold bg-white/20 text-white rounded-full px-2 py-0.5 tracking-wide">TAKEAWAY</span>
          </div>

          <div className="flex-1 min-h-0 divide-y divide-neutral-100 overflow-hidden">
            {items.map((it) => (
              <div key={it.name} className="px-3 py-2 flex items-center gap-2">
                <div className="flex-1 min-w-0">
                  <div className="text-[13px] font-medium text-neutral-800 truncate">{it.name}</div>
                  <div className="text-[11px] text-neutral-500">Rs {it.price.toFixed(2)}</div>
                </div>
                <div className="flex items-center gap-1">
                  <button className="w-6 h-6 rounded-md border border-neutral-300 flex items-center justify-center text-neutral-600">
                    <Minus className="w-3 h-3" />
                  </button>
                  <span className="w-7 text-center text-[13px] font-semibold">{it.qty}</span>
                  <button className="w-6 h-6 rounded-md border border-neutral-300 flex items-center justify-center text-neutral-600">
                    <Plus className="w-3 h-3" />
                  </button>
                </div>
                <div className="w-16 text-right text-[13px] font-semibold">Rs {it.total}</div>
                <button className="text-neutral-400">
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              </div>
            ))}
          </div>

          <div className="px-3 py-1.5 border-t border-neutral-100 shrink-0 flex items-center gap-1.5">
            <div className="flex-1 rounded-md border border-neutral-200 px-2.5 py-1.5 text-[11px] text-neutral-400">
              Order Notes… (N dabayen)
            </div>
            <button className="relative shrink-0 flex items-center gap-1 rounded-md border border-neutral-300 px-2.5 py-1.5 text-[11px] font-bold text-neutral-600">
              <Percent className="w-3 h-3" />
              Discount
              <span className="absolute -top-1.5 -right-1"><NayaTag /></span>
            </button>
          </div>

          {/* Bada Total band */}
          <div className="px-4 py-2.5 flex items-end justify-between shrink-0" style={{ background: "#0A4D5C" }}>
            <div className="text-white/80 text-[11px] leading-[18px]">
              <div>Subtotal &nbsp; Rs 970.00</div>
              <div>Tax (16%) &nbsp; Rs 155.20</div>
              <div className="mt-0.5 inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-bold text-white">
                3 items · 7 qty <NayaTag />
              </div>
            </div>
            <div className="text-right">
              <div className="text-white/70 text-[10px] font-semibold tracking-widest">TOTAL (CASH)</div>
              <div className="text-white text-[34px] font-extrabold leading-none">Rs 1,125</div>
              <div className="text-white/60 text-[9px] mt-0.5">Card/Digital pe: Rs 1,048 (tax 8%)</div>
            </div>
          </div>

          {/* One-tap payment + footer rows */}
          <div className="px-3 pt-2 pb-3 space-y-1.5 shrink-0">
            <div className="grid grid-cols-3 gap-1.5">
              <button className="py-2 rounded-lg text-white flex flex-col items-center gap-0.5" style={{ background: "#0d9488" }}>
                <Banknote className="w-5 h-5" />
                <span className="text-[12px] font-bold leading-none">CASH</span>
                <span className="text-[9px] text-white/75 leading-none">Rs 1,125</span>
                <Kbd>Alt+1</Kbd>
              </button>
              <button className="py-2 rounded-lg text-white flex flex-col items-center gap-0.5 bg-neutral-700">
                <CreditCard className="w-5 h-5" />
                <span className="text-[12px] font-bold leading-none">CARD</span>
                <span className="text-[9px] text-white/75 leading-none">Rs 1,048</span>
                <Kbd>Alt+2</Kbd>
              </button>
              <button className="py-2 rounded-lg text-white flex flex-col items-center gap-0.5 bg-neutral-500">
                <Smartphone className="w-5 h-5" />
                <span className="text-[12px] font-bold leading-none">DIGITAL</span>
                <span className="text-[9px] text-white/75 leading-none">Rs 1,048</span>
                <Kbd>Alt+3</Kbd>
              </button>
            </div>

            <div className="grid grid-cols-4 gap-1.5">
              <button className="py-1.5 rounded-lg border border-neutral-200 text-neutral-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Clear <Kbd dark>F4</Kbd>
              </button>
              <button className="py-1.5 rounded-lg border border-neutral-200 text-neutral-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Hold <Kbd dark>F5</Kbd>
              </button>
              <button className="py-1.5 rounded-lg border border-neutral-200 text-neutral-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                Recall <Kbd dark>F3</Kbd>
              </button>
              <button className="relative py-1.5 rounded-lg border border-neutral-200 text-neutral-500 text-[11px] font-semibold flex items-center justify-center gap-1.5">
                <Coins className="w-3.5 h-3.5" />
                Drawer
                <span className="absolute -top-1.5 -right-1"><NayaTag /></span>
              </button>
            </div>

            {/* Send to Kitchen ab neeche — upar wali yellow line khatam */}
            <div className="grid grid-cols-3 gap-1.5 items-stretch">
              <button className="py-2 rounded-lg border border-orange-400 bg-orange-50 text-orange-700 text-[11px] font-bold flex items-center justify-center gap-1 relative">
                <ClipboardList className="w-3.5 h-3.5" />
                Kitchen
                <span className="absolute -top-1.5 -right-1"><NayaTag /></span>
              </button>
              <button className="py-2 rounded-lg border border-amber-400 bg-amber-50 text-amber-700 text-[11px] font-bold flex items-center justify-center gap-1.5">
                Provisional <Kbd dark>F9</Kbd>
              </button>
              <button className="py-2 rounded-lg text-white text-[12px] font-extrabold flex items-center justify-center gap-1.5" style={{ background: "#0A4D5C" }}>
                PAY <Kbd>F8</Kbd>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
