import { Banknote, CreditCard, Minus, Plus, ShoppingCart, Smartphone, Trash2 } from "lucide-react";

const items = [
  { name: "Chicken Biryani", qty: 2, price: 350, total: 700 },
  { name: "Chai", qty: 3, price: 60, total: 180 },
  { name: "Samosa", qty: 2, price: 45, total: 90 },
];

function Kbd({ children }: { children: string }) {
  return (
    <span className="text-[10px] font-bold rounded px-1.5 py-0.5 bg-black/20 text-white/90 border border-white/25">
      {children}
    </span>
  );
}

function KbdDark({ children }: { children: string }) {
  return (
    <span className="text-[10px] font-bold rounded px-1.5 py-0.5 bg-neutral-200 text-neutral-600 border border-neutral-300">
      {children}
    </span>
  );
}

export function NayaDesign() {
  return (
    <div className="min-h-screen bg-neutral-100 flex justify-center items-start p-4 font-sans">
      <div className="w-[440px] bg-white rounded-xl border border-neutral-200 shadow-sm flex flex-col overflow-hidden">
        <div className="px-4 py-3 flex items-center justify-between" style={{ background: "#0A4D5C" }}>
          <div className="flex items-center gap-2 text-white font-semibold text-sm">
            <ShoppingCart className="w-4 h-4" /> Current Order
          </div>
          <span className="text-xs bg-white/20 text-white rounded-full px-2 py-0.5">3 items</span>
        </div>

        <div className="flex-1 divide-y divide-neutral-100">
          {items.map((it) => (
            <div key={it.name} className="px-4 py-2.5 flex items-center gap-3">
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-neutral-800 truncate">{it.name}</div>
                <div className="text-xs text-neutral-500">Rs {it.price.toFixed(2)}</div>
              </div>
              <div className="flex items-center gap-1">
                <button className="w-7 h-7 rounded-md border border-neutral-300 flex items-center justify-center text-neutral-600">
                  <Minus className="w-3.5 h-3.5" />
                </button>
                <span className="w-8 text-center text-sm font-semibold text-neutral-800">{it.qty}</span>
                <button className="w-7 h-7 rounded-md border border-neutral-300 flex items-center justify-center text-neutral-600">
                  <Plus className="w-3.5 h-3.5" />
                </button>
              </div>
              <div className="w-20 text-right text-sm font-semibold text-neutral-800">Rs {it.total}</div>
              <button className="text-neutral-400">
                <Trash2 className="w-4 h-4" />
              </button>
            </div>
          ))}
        </div>

        {/* IDEA 2: Bada Total display */}
        <div className="px-4 py-3 flex items-end justify-between" style={{ background: "#0A4D5C" }}>
          <div className="text-white/80 text-xs leading-5">
            <div>Subtotal &nbsp; Rs 970.00</div>
            <div>Tax (16%) &nbsp; Rs 155.20</div>
          </div>
          <div className="text-right">
            <div className="text-white/70 text-[11px] font-semibold tracking-widest">TOTAL (CASH)</div>
            <div className="text-white text-4xl font-extrabold leading-none">Rs 1,125</div>
            <div className="text-white/60 text-[10px] mt-1">Card/Digital pe: Rs 1,048 (tax 8%)</div>
          </div>
        </div>

        {/* IDEA 1: One-tap payment buttons (+ IDEA 3: F-key labels) */}
        <div className="px-4 pt-3 pb-4 space-y-2">
          <div className="grid grid-cols-3 gap-2">
            <button className="py-3 rounded-lg text-white flex flex-col items-center gap-1" style={{ background: "#0d9488" }}>
              <Banknote className="w-6 h-6" />
              <span className="text-sm font-bold leading-none">CASH</span>
              <span className="text-[10px] text-white/75 leading-none">Rs 1,125</span>
              <Kbd>Alt+1</Kbd>
            </button>
            <button className="py-3 rounded-lg text-white flex flex-col items-center gap-1 bg-neutral-700">
              <CreditCard className="w-6 h-6" />
              <span className="text-sm font-bold leading-none">CARD</span>
              <span className="text-[10px] text-white/75 leading-none">Rs 1,048</span>
              <Kbd>Alt+2</Kbd>
            </button>
            <button className="py-3 rounded-lg text-white flex flex-col items-center gap-1 bg-neutral-500">
              <Smartphone className="w-6 h-6" />
              <span className="text-sm font-bold leading-none">DIGITAL</span>
              <span className="text-[10px] text-white/75 leading-none">Rs 1,048</span>
              <Kbd>Alt+3</Kbd>
            </button>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <button className="py-2.5 rounded-lg border border-amber-400 bg-amber-50 text-amber-700 text-xs font-semibold flex items-center justify-center gap-2">
              Save Provisional <KbdDark>F9</KbdDark>
            </button>
            <button className="py-2.5 rounded-lg border border-neutral-300 text-neutral-600 text-xs font-semibold flex items-center justify-center gap-2">
              Hold <KbdDark>F5</KbdDark>
            </button>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <button className="py-2 rounded-lg border border-neutral-200 text-neutral-500 text-xs flex items-center justify-center gap-2">
              Clear <KbdDark>F4</KbdDark>
            </button>
            <button className="py-2 rounded-lg border border-neutral-200 text-neutral-500 text-xs flex items-center justify-center gap-2">
              Recall <KbdDark>F3</KbdDark>
            </button>
          </div>
          <p className="text-[11px] text-neutral-400 text-center pt-1">
            Ek click = bill final. CASH dabao → seedha receipt. Koi modal nahi.
          </p>
        </div>
      </div>
    </div>
  );
}
