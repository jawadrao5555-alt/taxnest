// FBR POS — Abhi (Purana) — current cramped single-row screen (approximation for comparison)
import { Trash2, Percent, StickyNote, Plus, Search } from "lucide-react";

const tiles = [
  { n: "COMFORT 0021", p: 1200 },
  { n: "JOGGER X-100", p: 2500 },
  { n: "LADIES PUMP 07", p: 1800 },
  { n: "CHAPPAL SOFT", p: 850 },
  { n: "SCHOOL SHOE B2", p: 1450 },
  { n: "SANDAL GOLD", p: 2200 },
  { n: "LOAFER BROWN", p: 3200 },
  { n: "SPORTS AIR 9", p: 4500 },
];

function CartRow({ name, qty, price }: { name: string; qty: number; price: number }) {
  return (
    <div className="border border-gray-200 rounded-lg p-2 mb-2 bg-white">
      <div className="flex items-center justify-between">
        <span className="text-[13px] font-semibold text-gray-800 truncate">{name}</span>
        <button className="text-red-500"><Trash2 className="w-4 h-4" /></button>
      </div>
      <div className="flex items-center gap-1.5 mt-1.5">
        <button className="w-6 h-6 rounded bg-gray-100 text-gray-700 text-sm font-bold">−</button>
        <input className="w-10 h-6 border border-gray-300 rounded text-center text-[12px]" value={qty} readOnly />
        <button className="w-6 h-6 rounded bg-gray-100 text-gray-700 text-sm font-bold">+</button>
        <span className="ml-auto text-[13px] font-bold text-gray-900">Rs {price.toLocaleString()}</span>
      </div>
      {/* purana clutter: har item par alag buttons + note box */}
      <div className="flex items-center gap-1.5 mt-1.5">
        <button className="px-2 h-6 rounded border border-blue-300 text-blue-700 text-[10px] font-semibold">TAX</button>
        <button className="px-2 h-6 rounded border border-orange-300 text-orange-600 text-[10px] font-semibold flex items-center gap-0.5"><Percent className="w-3 h-3" />Disc</button>
        <div className="flex-1 flex items-center gap-1 border border-gray-200 rounded px-1.5 h-6 text-gray-400">
          <StickyNote className="w-3 h-3" />
          <span className="text-[10px]">Item note likhein...</span>
        </div>
      </div>
    </div>
  );
}

export function Purana() {
  return (
    <div className="min-h-screen bg-gray-100 font-sans text-gray-900">
      {/* top nav */}
      <div className="h-11 bg-blue-900 flex items-center px-3 gap-3">
        <span className="text-white font-bold text-sm">NestPOS · FBR</span>
        <span className="text-blue-200 text-[11px]">X-WAY SHOES</span>
        <div className="ml-auto flex items-center gap-2">
          <span className="text-[10px] bg-blue-800 text-blue-100 px-2 py-0.5 rounded-full">FBR Sync ✓</span>
          <div className="w-6 h-6 rounded-full bg-blue-700 text-white text-[10px] flex items-center justify-center">MT</div>
        </div>
      </div>

      {/* PURANA: sab kuch AIK hi tang row mein */}
      <div className="bg-white border-b border-gray-200 px-2 py-1.5 flex items-center gap-1.5 overflow-hidden">
        <input className="w-36 h-8 border border-gray-300 rounded px-2 text-[11px]" placeholder="Customer phone" />
        <div className="relative w-44">
          <Search className="w-3.5 h-3.5 absolute left-2 top-2.5 text-gray-400" />
          <input className="w-full h-8 border border-gray-300 rounded pl-7 pr-2 text-[11px]" placeholder="Search products..." />
        </div>
        {["Quick", "Rush", "Fit", "Keys F1", "Local F10", "Failed F11"].map((b) => (
          <button key={b} className="h-8 px-2 rounded border border-gray-300 bg-gray-50 text-[10px] font-semibold text-gray-600 whitespace-nowrap">{b}</button>
        ))}
        <button className="h-8 px-2.5 rounded bg-amber-500 text-white text-[10px] font-bold whitespace-nowrap">Hold F5</button>
        <button className="h-8 px-3 rounded bg-blue-700 text-white text-[10px] font-bold whitespace-nowrap">PAY F8</button>
      </div>

      <div className="flex" style={{ height: "calc(100vh - 78px)" }}>
        {/* grid */}
        <div className="flex-1 p-3 overflow-hidden">
          <div className="grid grid-cols-4 gap-2">
            {tiles.map((t) => (
              <div key={t.n} className="bg-white rounded-lg border border-gray-200 p-2.5 relative">
                <div className="text-[12px] font-semibold leading-tight h-8">{t.n}</div>
                <div className="text-[13px] font-bold text-blue-800 mt-1">Rs {t.p.toLocaleString()}</div>
                <button className="absolute bottom-2 right-2 w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center"><Plus className="w-4 h-4" /></button>
              </div>
            ))}
          </div>
          <p className="text-[11px] text-gray-400 mt-3">Search sirf naam ke andar match karti hai — barcode / article # scan ka fast-path NAHI</p>
        </div>

        {/* cart */}
        <div className="w-[320px] bg-gray-50 border-l border-gray-200 p-2.5 flex flex-col">
          <div className="text-[12px] font-bold text-gray-700 mb-2">Current Order</div>
          <div className="flex-1 overflow-hidden">
            <CartRow name="COMFORT 0021" qty={1} price={1200} />
            <CartRow name="JOGGER X-100" qty={2} price={5000} />
            {/* Smart Upsell — PRA se nikal chuka, yahan ab bhi hai */}
            <div className="border border-dashed border-purple-300 bg-purple-50 rounded-lg p-2 mb-2">
              <div className="text-[10px] font-bold text-purple-700">Suggested Add-on</div>
              <div className="text-[11px] text-purple-800 mt-0.5">Shoe Polish — Rs 250 <button className="ml-1 text-[10px] underline">Add karein?</button></div>
            </div>
          </div>
          <div className="border-t border-gray-200 pt-2 space-y-1 text-[12px]">
            <div className="flex justify-between text-gray-500"><span>Subtotal</span><span>Rs 6,200</span></div>
            <div className="flex justify-between text-gray-500"><span>Tax 18%</span><span>Rs 1,116</span></div>
            <div className="flex justify-between font-bold text-[14px]"><span>TOTAL</span><span>Rs 7,317</span></div>
          </div>
          <div className="grid grid-cols-2 gap-1.5 mt-2">
            <button className="h-10 rounded-lg border border-gray-300 bg-white text-[11px] font-semibold text-gray-600">Save Provisional</button>
            <button className="h-10 rounded-lg bg-blue-700 text-white text-[12px] font-bold">PAY F8</button>
          </div>
          <p className="text-[10px] text-gray-400 mt-1.5 text-center">Har bill par pehle Pay MODAL khulta hai — phir method chuno</p>
        </div>
      </div>
    </div>
  );
}
