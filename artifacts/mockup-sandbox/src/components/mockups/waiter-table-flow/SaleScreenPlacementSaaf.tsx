import { useState } from "react";
import { Search, LogOut, Store, Users, UtensilsCrossed, ShoppingBag, Bike, Drumstick, Coffee, Utensils, Package, Star, X, ChevronDown } from "lucide-react";

const TABLES = [
  { num: "T-1", status: "free" },
  { num: "T-2", status: "ready", waiter: "Bilal", items: 8, amt: 3130 },
  { num: "T-3", status: "eating", waiter: "Ahmed" },
  { num: "T-4", status: "free" },
  { num: "T-5", status: "ready", waiter: "Ahmed", items: 3, amt: 2250 },
  { num: "T-6", status: "free" },
  { num: "T-7", status: "eating", waiter: "Bilal" },
  { num: "T-8", status: "free" },
] as const;

export function SaleScreenPlacementSaaf() {
  const [open, setOpen] = useState(true);

  return (
    <div className="relative flex flex-col h-screen bg-neutral-50 font-sans text-neutral-900 overflow-hidden">
      {/* Top Nav — Saaf 5-pill style */}
      <header className="flex-none flex items-center justify-between px-4 h-12 bg-[#0A4D5C] text-white z-10">
        <div className="flex items-center gap-5">
          <div className="flex items-center gap-2 font-bold tracking-tight">
            <Store className="w-5 h-5 text-teal-300" />
            <span>NestPOS</span>
          </div>
          <nav className="hidden md:flex items-center gap-1 text-sm">
            <button className="px-3 py-1 rounded-full bg-teal-700 font-medium">Sale</button>
            <button className="px-3 py-1 rounded-full text-teal-100 font-medium">Bills</button>
            <button className="px-3 py-1 rounded-full text-teal-100 font-medium">Reports</button>
            <button className="px-3 py-1 rounded-full text-teal-100 font-medium">Products</button>
            <button className="px-3 py-1 rounded-full text-teal-100 font-medium">Settings</button>
          </nav>
        </div>
        <div className="flex items-center gap-2 text-white">
          <div className="w-7 h-7 rounded bg-teal-700 flex items-center justify-center font-bold text-xs">AT</div>
          <span className="text-sm font-medium">Ali Traders</span>
          <LogOut className="w-4 h-4 text-teal-200" />
        </div>
      </header>

      {/* Main */}
      <main className="flex-1 flex overflow-hidden">
        <section className="flex-1 flex flex-col min-w-0 border-r border-neutral-200 bg-white">
          {/* Row 1: customer + order widgets (Saaf: saada, koi extra button nahi) */}
          <div className="p-2 border-b border-neutral-100 flex gap-2 items-center">
            <div className="flex-1 flex items-center gap-2 bg-neutral-100 px-3 py-2 rounded-md border border-neutral-200">
              <Users className="w-4 h-4 text-neutral-500" />
              <span className="text-sm font-semibold text-neutral-700">Customer: Walk-in</span>
            </div>
            <div className="flex gap-1.5">
              <button className="px-3 py-2 flex items-center gap-1.5 rounded-md text-xs font-bold text-neutral-600 bg-neutral-100">
                <ShoppingBag className="w-3.5 h-3.5" /> Takeaway
              </button>
              {/* NEW: Tables button — Saaf teal */}
              <div className="relative">
                <button
                  onClick={() => setOpen(true)}
                  className="px-3 py-2 flex items-center gap-1.5 rounded-md text-xs font-bold bg-teal-600 text-white ring-4 ring-amber-400 relative"
                >
                  <UtensilsCrossed className="w-3.5 h-3.5" /> Tables (F3)
                  <span className="ml-1 bg-emerald-500 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold">2</span>
                </button>
                <div className="absolute -bottom-9 left-1/2 -translate-x-1/2 whitespace-nowrap bg-amber-400 text-amber-950 text-[11px] font-bold px-2.5 py-1 rounded-md shadow-sm z-20">
                  ▲ Saaf mein bhi ISI jaga — click ya F3
                </div>
              </div>
              <button className="px-3 py-2 flex items-center gap-1.5 rounded-md text-xs font-bold text-neutral-600 bg-neutral-100">
                <Bike className="w-3.5 h-3.5" /> Delivery
              </button>
            </div>
            <button className="px-3 py-2 flex items-center gap-1 rounded-md text-xs font-bold text-teal-700 bg-teal-50 border border-teal-200">
              Mazeed <ChevronDown className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Row 2: category + big search (Saaf: bari search) */}
          <div className="p-2 pt-4 border-b border-neutral-100 flex gap-2 mt-6">
            <select className="h-11 px-2 bg-neutral-100 rounded-md text-sm font-medium text-neutral-600 border-none w-36">
              <option>All Categories</option>
            </select>
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400" />
              <input
                type="text"
                placeholder="Item likhein ya barcode scan karein..."
                className="w-full h-11 pl-10 pr-4 bg-neutral-100 border-none rounded-md text-base placeholder-neutral-500 outline-none"
              />
            </div>
          </div>

          {/* Product grid */}
          <div className="flex-1 overflow-y-auto p-3">
            <div className="grid grid-cols-4 gap-3">
              {[
                { name: "Chicken Tikka", price: 450, icon: Drumstick, bg: "bg-orange-50 text-orange-400" },
                { name: "Seekh Kabab", price: 300, icon: Drumstick, bg: "bg-red-50 text-red-400" },
                { name: "Naan", price: 40, icon: Package, bg: "bg-yellow-50 text-yellow-500" },
                { name: "Biryani Single", price: 350, icon: Utensils, bg: "bg-amber-50 text-amber-500" },
                { name: "Deal 1", price: 999, icon: Star, bg: "bg-teal-50 text-teal-500" },
                { name: "Pepsi 500ml", price: 120, icon: Coffee, bg: "bg-neutral-100 text-neutral-400" },
                { name: "Mineral Water", price: 60, icon: Coffee, bg: "bg-teal-50 text-teal-500" },
                { name: "Chai", price: 80, icon: Coffee, bg: "bg-amber-100 text-amber-600" },
              ].map((p) => (
                <button key={p.name} className="rounded-md border border-neutral-200 bg-white text-left overflow-hidden flex flex-col h-28">
                  <div className={`h-12 flex items-center justify-center ${p.bg}`}>
                    <p.icon className="w-5 h-5" />
                  </div>
                  <div className="p-2 flex flex-col justify-between flex-1">
                    <span className="font-semibold text-xs text-neutral-800 leading-tight">{p.name}</span>
                    <span className="text-teal-700 font-bold text-xs">Rs {p.price}</span>
                  </div>
                </button>
              ))}
            </div>
          </div>
        </section>

        {/* Cart */}
        <aside className="w-[340px] flex-none bg-neutral-50 flex flex-col border-l border-neutral-200">
          <div className="px-4 py-3 border-b border-neutral-200 bg-white text-sm font-bold text-neutral-900">Current Order</div>
          <div className="flex-1 flex items-center justify-center text-neutral-400 text-sm p-6 text-center">
            Cart khali hai —<br />table click karne par yahan order aayega
          </div>
          <div className="bg-white border-t border-neutral-200 p-4">
            <div className="flex items-center justify-between mb-3">
              <span className="font-semibold">Total</span>
              <span className="text-2xl font-bold text-teal-700">Rs 0</span>
            </div>
            <button className="w-full h-12 rounded-md bg-teal-600 text-white font-bold">Pay</button>
          </div>
        </aside>
      </main>

      {/* ===== TABLE PICKER MODAL — Saaf teal ===== */}
      {open && (
        <div className="absolute inset-0 z-30 flex items-center justify-center bg-black/50 p-6">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden">
            <div className="flex items-center justify-between px-5 py-3 border-b border-neutral-100">
              <div>
                <h2 className="font-bold text-neutral-900">Tables</h2>
                <p className="text-[11px] text-neutral-500">Teal = Order Tayyar — click karo, bill cart mein</p>
              </div>
              <button onClick={() => setOpen(false)} className="p-2 rounded-md hover:bg-neutral-100 text-neutral-500">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="p-5 grid grid-cols-4 gap-3">
              {TABLES.map((t) => (
                <div
                  key={t.num}
                  className={
                    "relative rounded-xl border-2 p-3 h-24 flex flex-col items-center justify-center text-center " +
                    (t.status === "free"
                      ? "border-neutral-200 bg-white text-neutral-500"
                      : t.status === "eating"
                      ? "border-amber-300 bg-amber-50 text-amber-800"
                      : "border-teal-600 bg-teal-600 text-white cursor-pointer")
                  }
                >
                  <span className="font-bold">{t.num}</span>
                  {t.status === "free" && <span className="text-[10px] mt-0.5">Khali</span>}
                  {t.status === "eating" && <span className="text-[10px] mt-0.5">Khana chal raha · {t.waiter}</span>}
                  {t.status === "ready" && (
                    <>
                      <span className="text-[10px] mt-0.5 font-bold uppercase">Order Tayyar</span>
                      <span className="text-[10px] opacity-90">{t.items} items · Rs {t.amt!.toLocaleString()}</span>
                      <span className="absolute -top-2 -right-2 bg-emerald-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full">BILL</span>
                    </>
                  )}
                </div>
              ))}
            </div>
            <div className="px-5 pb-4 text-[11px] text-neutral-400">
              Saaf style mein bhi box ISI jaga khulta hai — bas rang teal ho jata hai. X daba kar peeche ki screen dekhein.
            </div>
          </div>
        </div>
      )}

      {!open && (
        <button
          onClick={() => setOpen(true)}
          className="absolute bottom-4 left-1/2 -translate-x-1/2 z-30 px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-bold shadow-lg"
        >
          Table box dobara kholein (Tables button ya F3)
        </button>
      )}
    </div>
  );
}
