// FBR POS — Naya Split Screen — PRA jaisi: bari billing + products ek hi nazar mein
import { Trash2, Plus, ScanBarcode, Banknote, CreditCard, Printer, RotateCcw, ChevronDown, LayoutPanelLeft } from "lucide-react";

const tiles = [
  { n: "COMFORT 0021", p: 1200 },
  { n: "JOGGER X-100", p: 2500 },
  { n: "LADIES PUMP 07", p: 1800 },
  { n: "CHAPPAL SOFT", p: 850 },
  { n: "SCHOOL SHOE B2", p: 1450 },
  { n: "SANDAL GOLD", p: 2200 },
  { n: "LOAFER BROWN", p: 3200 },
  { n: "SPORTS AIR 9", p: 4500 },
  { n: "SNEAKER NB-90", p: 5500 },
  { n: "BOOT FORMAL F", p: 4200 },
  { n: "KHUSSA VELVET", p: 1650 },
  { n: "MOCCASIN BRN", p: 2900 },
];

function CartRow({ name, qty, price, chip }: { name: string; qty: number; price: number; chip?: string }) {
  return (
    <div className="border border-gray-200 rounded-lg px-2.5 py-2 mb-1.5 bg-white">
      <div className="flex items-center gap-2">
        <span className="text-[13px] font-semibold text-gray-800 truncate flex-1">{name}</span>
        <div className="flex items-center gap-1">
          <button className="w-6 h-6 rounded bg-gray-100 text-gray-700 text-sm font-bold">−</button>
          <input className="w-10 h-6 border border-blue-400 ring-1 ring-blue-100 rounded text-center text-[12px] font-semibold" value={qty} readOnly />
          <button className="w-6 h-6 rounded bg-gray-100 text-gray-700 text-sm font-bold">+</button>
        </div>
        <span className="w-[74px] text-right text-[13px] font-bold text-gray-900">Rs {price.toLocaleString()}</span>
        <button className="text-red-400"><Trash2 className="w-4 h-4" /></button>
      </div>
      {chip && (
        <span className="mt-1 inline-block text-[9px] font-bold px-1.5 py-0.5 rounded bg-orange-50 text-orange-600 border border-orange-200">{chip}</span>
      )}
    </div>
  );
}

export function NayaSplit() {
  return (
    <div className="min-h-screen bg-gray-100 font-sans text-gray-900">
      {/* top nav */}
      <div className="h-11 bg-blue-900 flex items-center px-3 gap-2">
        <span className="text-white font-bold text-sm">NestPOS · FBR</span>
        <span className="text-blue-200 text-[11px]">X-WAY SHOES</span>
        <div className="ml-4 flex items-center gap-1.5">
          {["+ New Sale", "Local F10", "Failed F11"].map((b) => (
            <button key={b} className="h-7 px-2 rounded bg-blue-800 text-blue-100 text-[10px] font-semibold">{b}</button>
          ))}
          <button className="h-7 px-2 rounded bg-blue-800 text-blue-100 text-[10px] font-semibold flex items-center gap-1"><RotateCcw className="w-3 h-3" />Reprint Alt+R</button>
        </div>
        {/* SPLIT TOGGLE — active hai, highlighted */}
        <button className="h-7 px-2 rounded bg-blue-500 text-white text-[10px] font-bold flex items-center gap-1 ml-1 ring-2 ring-white/30">
          <LayoutPanelLeft className="w-3.5 h-3.5" />Split
        </button>
        <div className="ml-auto flex items-center gap-2">
          <span className="text-[10px] bg-emerald-500/20 text-emerald-200 px-2 py-0.5 rounded-full">FBR Sync ✓</span>
          <div className="w-6 h-6 rounded-full bg-blue-700 text-white text-[10px] flex items-center justify-center">MT</div>
        </div>
      </div>

      {/* 2-row bar */}
      <div className="bg-white px-2.5 pt-1.5 flex items-center gap-2">
        <input className="flex-1 max-w-[400px] h-9 border border-gray-300 rounded-lg px-3 text-[12px]" placeholder="Customer phone / naam (optional — Enter = walk-in)" />
        <div className="ml-auto flex gap-1.5">
          {["Fit", "Keys F1"].map((b) => (
            <button key={b} className="h-8 px-2.5 rounded-lg border border-gray-200 bg-gray-50 text-[10px] font-semibold text-gray-500">{b}</button>
          ))}
        </div>
      </div>
      <div className="bg-white border-b border-gray-200 px-2.5 py-1.5 flex items-center gap-2 shadow-sm">
        <button className="h-9 px-3 rounded-lg border border-gray-300 bg-white text-[11px] font-semibold text-gray-600 flex items-center gap-1">All <ChevronDown className="w-3.5 h-3.5" /></button>
        <div className="relative flex-1">
          <ScanBarcode className="w-4 h-4 absolute left-3 top-2.5 text-blue-600" />
          <input className="w-full h-9 border-2 border-blue-400 ring-2 ring-blue-100 rounded-lg pl-9 pr-3 text-[12px]" placeholder="Barcode / Article # SCAN karein — ya naam ka pehla harf likhein" autoFocus />
        </div>
        <button className="h-9 px-3 rounded-lg bg-amber-500 text-white text-[11px] font-bold">Hold F5</button>
      </div>

      {/* SPLIT SCREEN — left 60% products, right 40% full billing */}
      <div className="flex" style={{ height: "calc(100vh - 122px)" }}>

        {/* LEFT — product grid 60% */}
        <div className="flex-1 overflow-y-auto p-3 flex flex-col">
          <div className="grid grid-cols-4 gap-2 flex-1">
            {tiles.map((t) => (
              <div key={t.n} className="bg-white rounded-xl border border-gray-200 p-3 relative shadow-sm">
                <div className="text-[12px] font-semibold leading-tight min-h-[32px]">{t.n}</div>
                <div className="text-[14px] font-bold text-blue-800 mt-1">Rs {t.p.toLocaleString()}</div>
                <button className="absolute bottom-2.5 right-2.5 w-8 h-8 rounded-lg bg-blue-600 hover:bg-blue-700 text-white flex items-center justify-center shadow">
                  <Plus className="w-4 h-4" />
                </button>
              </div>
            ))}
          </div>
          {/* Akhri Bills strip at bottom */}
          <div className="pt-2 mt-auto">
            <div className="text-[10px] font-bold text-gray-400 mb-1">AKHRI BILLS — ek click = dobara print</div>
            <div className="flex gap-1.5 flex-wrap">
              {[
                { n: "00011", a: "1,417" },
                { n: "00010", a: "1,417" },
                { n: "00009", a: "2,360" },
                { n: "00008", a: "4,890" },
              ].map((b) => (
                <button key={b.n} className="h-8 px-2.5 rounded-lg border border-gray-200 bg-white text-[10px] font-semibold text-gray-600 flex items-center gap-1.5">
                  <Printer className="w-3 h-3 text-blue-600" /># {b.n} · Rs {b.a}
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* DIVIDER */}
        <div className="w-px bg-gray-200" />

        {/* RIGHT — full billing panel 40% min-w-[440px] */}
        <div style={{ width: "440px" }} className="bg-gray-50 flex flex-col">
          {/* cart header */}
          <div className="px-3 pt-2.5 pb-1.5 border-b border-gray-200">
            <span className="text-[12px] font-bold text-gray-700">Current Order</span>
            <span className="ml-2 text-[11px] text-gray-400">· 3 items</span>
          </div>

          {/* cart rows — scrollable */}
          <div className="flex-1 overflow-y-auto px-3 py-2">
            <CartRow name="COMFORT 0021" qty={1} price={1200} />
            <CartRow name="JOGGER X-100" qty={2} price={5000} chip="-5% disc" />
            <CartRow name="LADIES PUMP 07" qty={1} price={1800} />
          </div>

          {/* sticky bottom: total + pay */}
          <div className="px-3 pb-3 pt-2 border-t border-gray-200">
            {/* bada total band */}
            <div className="rounded-xl bg-blue-900 text-white p-3 mb-2.5">
              <div className="flex justify-between text-[11px] text-blue-200 mb-0.5">
                <span>Subtotal</span><span>Rs 8,000</span>
              </div>
              <div className="flex justify-between text-[11px] text-blue-200">
                <span>Tax 18% + FBR fee</span><span>Rs 1,441</span>
              </div>
              <div className="border-t border-blue-700 mt-2 pt-2 flex justify-between items-center">
                <span className="text-[13px] font-bold">TOTAL</span>
                <span className="text-[26px] font-extrabold leading-none">Rs 9,441</span>
              </div>
            </div>

            {/* one-tap finalize */}
            <div className="grid grid-cols-2 gap-2 mb-2">
              <button className="h-14 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[14px] flex flex-col items-center justify-center gap-0.5 shadow-sm">
                <div className="flex items-center gap-1.5"><Banknote className="w-4 h-4" />CASH</div>
                <span className="text-[9px] font-normal opacity-75">Alt+1</span>
              </button>
              <button className="h-14 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-[14px] flex flex-col items-center justify-center gap-0.5 shadow-sm">
                <div className="flex items-center gap-1.5"><CreditCard className="w-4 h-4" />CARD</div>
                <span className="text-[9px] font-normal opacity-75">Alt+2</span>
              </button>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <button className="h-9 rounded-lg border border-gray-300 bg-white text-[11px] font-semibold text-gray-600">Save Provisional</button>
              <button className="h-9 rounded-lg border border-blue-300 bg-blue-50 text-blue-700 text-[11px] font-bold">PAY F8 (method+note)</button>
            </div>
            <p className="text-[10px] text-emerald-600 mt-1.5 text-center font-semibold">Ek tap = bill final + print · Receipt 10s mein band</p>
          </div>
        </div>
      </div>
    </div>
  );
}
