import { Minus, Plus, ShoppingCart, Trash2 } from "lucide-react";

const items = [
  { name: "Chicken Biryani", qty: 2, price: 350, total: 700 },
  { name: "Chai", qty: 3, price: 60, total: 180 },
  { name: "Samosa", qty: 2, price: 45, total: 90 },
];

export function AbhiWala() {
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
            <div key={it.name} className="px-4 py-3 flex items-center gap-3">
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

        <div className="border-t border-neutral-200 px-4 py-3 space-y-1.5">
          <div className="flex justify-between text-xs text-neutral-500">
            <span>Subtotal</span>
            <span>Rs 970.00</span>
          </div>
          <div className="flex justify-between text-xs text-neutral-500">
            <span>Tax (16%)</span>
            <span>Rs 155.20</span>
          </div>
          <div className="flex justify-between text-sm font-bold text-neutral-800 pt-1 border-t border-neutral-100">
            <span>Total</span>
            <span>Rs 1,125</span>
          </div>
        </div>

        <div className="px-4 pb-4 space-y-2">
          <div className="grid grid-cols-3 gap-2">
            <button className="py-2 rounded-lg border border-neutral-300 text-xs font-medium text-neutral-600">Clear</button>
            <button className="py-2 rounded-lg border border-neutral-300 text-xs font-medium text-neutral-600">Hold</button>
            <button className="py-2 rounded-lg border border-neutral-300 text-xs font-medium text-neutral-600">Recall</button>
          </div>
          <div className="grid grid-cols-5 gap-2">
            <button className="col-span-2 py-3 rounded-lg border border-amber-400 bg-amber-50 text-amber-700 text-sm font-semibold">
              Save Provisional
            </button>
            <button className="col-span-3 py-3 rounded-lg text-white text-base font-bold" style={{ background: "#0d9488" }}>
              PAY
            </button>
          </div>
          <p className="text-[11px] text-neutral-400 text-center pt-1">
            PAY dabao → modal khulta hai → phir Cash/Card select karo → phir Complete
          </p>
        </div>
      </div>
    </div>
  );
}
