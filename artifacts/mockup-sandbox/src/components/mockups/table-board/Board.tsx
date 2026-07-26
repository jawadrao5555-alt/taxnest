function Tile({
  num,
  time,
  sub,
  tone,
}: {
  num: string;
  time: string;
  sub: string;
  tone: "free" | "reserved" | "occupied" | "waiter";
}) {
  const cls =
    tone === "occupied"
      ? "border-red-300 bg-red-50 text-red-700"
      : tone === "reserved"
        ? "border-amber-300 bg-amber-50 text-amber-700"
        : tone === "waiter"
          ? "border-purple-400 bg-purple-50 text-purple-700"
          : "border-green-300 bg-green-50 text-green-700";
  return (
    <button
      type="button"
      className={`rounded-lg border-2 px-1.5 py-1 text-left transition hover:scale-[1.02] ${cls}`}
    >
      <span className="flex items-center justify-between gap-1">
        <span className="text-[11px] font-black">{num}</span>
        <span className="text-[9px] font-bold whitespace-nowrap">{time}</span>
      </span>
      <span className="block text-[9px] truncate font-medium opacity-90">{sub}</span>
    </button>
  );
}

function CartRow({ name, qty, amt }: { name: string; qty: string; amt: string }) {
  return (
    <div className="flex items-center justify-between px-3 py-2 border-b border-gray-100">
      <div className="min-w-0">
        <p className="text-xs font-semibold text-gray-800 truncate">{name}</p>
        <p className="text-[10px] text-gray-400">{qty}</p>
      </div>
      <p className="text-xs font-bold text-gray-900">{amt}</p>
    </div>
  );
}

export function Board() {
  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-8 font-sans">
      <div className="flex items-start gap-10">
        {/* Cart column — sale screen ka right side */}
        <div className="w-[400px] bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
          <div className="px-3 py-2 bg-teal-800 text-white flex items-center justify-between">
            <span className="text-xs font-black tracking-wide">CART — Dine In · T-5</span>
            <span className="text-[10px] font-bold bg-white/20 rounded-full px-2 py-0.5">3 items</span>
          </div>
          <CartRow name="Chicken Karahi (Full)" qty="1 × Rs 1,800" amt="Rs 1,800" />
          <CartRow name="Garlic Naan" qty="4 × Rs 90" amt="Rs 360" />
          <CartRow name="Mint Margarita" qty="2 × Rs 250" amt="Rs 500" />
          <div className="px-3 py-2 flex items-center justify-between bg-gray-50 border-b border-gray-200">
            <span className="text-xs font-bold text-gray-500">TOTAL</span>
            <span className="text-base font-black text-gray-900">Rs 2,660</span>
          </div>
          <div className="grid grid-cols-2 gap-2 p-2">
            <div className="py-2.5 rounded-xl text-center text-xs font-black text-white bg-green-600">CASH</div>
            <div className="py-2.5 rounded-xl text-center text-xs font-black text-white bg-teal-600">CARD</div>
          </div>

          {/* ═══ TABLE BOARD — cart ke bilkul neeche ═══ */}
          <div className="border-t-2 border-gray-200 bg-gray-50">
            <div className="w-full flex items-center gap-2 px-3 py-1.5">
              <svg className="w-3.5 h-3.5 text-teal-700 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M5 10v9m14-9v9M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z" />
              </svg>
              <span className="text-[11px] font-black text-gray-700 tracking-wide">TABLES</span>
              <span className="min-w-[16px] px-1 py-0.5 bg-red-100 text-red-700 text-[9px] rounded-full font-black text-center">3</span>
              <span className="min-w-[16px] px-1 py-0.5 bg-amber-100 text-amber-700 text-[9px] rounded-full font-black text-center">1</span>
              <span className="min-w-[16px] px-1 py-0.5 bg-purple-100 text-purple-700 text-[9px] rounded-full font-black text-center animate-pulse">1</span>
              <span className="flex-1" />
              <svg className="w-3.5 h-3.5 text-gray-400 rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
              </svg>
            </div>
            <div className="px-2 pb-2">
              <p className="text-[9px] font-bold text-gray-400 uppercase mt-1 px-1">Ground Floor</p>
              <div className="grid grid-cols-3 gap-1.5 mt-1">
                <Tile num="T-1" time="" sub="Khali" tone="free" />
                <Tile num="T-2" time="8 min" sub="Reserved" tone="reserved" />
                <Tile num="T-3" time="24 min" sub="Ali • Rs 3,150" tone="occupied" />
                <Tile num="T-4" time="" sub="Khali" tone="free" />
                <Tile num="T-5" time="12 min" sub="Aap ka order • Rs 2,660" tone="occupied" />
                <Tile num="T-6" time="31 min" sub="Usman • Rs 5,400" tone="occupied" />
              </div>
              <p className="text-[9px] font-bold text-gray-400 uppercase mt-2 px-1">First Floor</p>
              <div className="grid grid-cols-3 gap-1.5 mt-1">
                <Tile num="T-7" time="4 min" sub="Waiter Bilal • Rs 1,980" tone="waiter" />
                <Tile num="T-8" time="" sub="Khali" tone="free" />
                <Tile num="T-9" time="" sub="Khali" tone="free" />
              </div>
            </div>
          </div>
        </div>

        {/* Annotations */}
        <div className="w-[420px] space-y-3 pt-2">
          <h2 className="text-xl font-black text-gray-900">Table Board — Cart ke Neeche</h2>
          <p className="text-sm text-gray-600 leading-relaxed">
            Sale screen pe cart ke bilkul neeche ab yeh <b>TABLES</b> patti hamesha maujood hai.
            Ek click se khulti/band hoti hai (setting yaad rehti hai).
          </p>
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <span className="w-4 h-4 rounded border-2 border-green-300 bg-green-50 flex-shrink-0" />
              <span className="text-xs text-gray-700"><b>Green</b> = table khali, naya order le sakte hain</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-4 h-4 rounded border-2 border-amber-300 bg-amber-50 flex-shrink-0" />
              <span className="text-xs text-gray-700"><b>Amber</b> = reserve hai (customer aane wala hai)</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-4 h-4 rounded border-2 border-red-300 bg-red-50 flex-shrink-0" />
              <span className="text-xs text-gray-700"><b>Red</b> = order chal raha hai — staff ka naam + raqam + waqt nazar aata hai</span>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-4 h-4 rounded border-2 border-purple-400 bg-purple-50 flex-shrink-0" />
              <span className="text-xs text-gray-700"><b>Purple</b> = waiter ka order tayyar, cashier ke intezar mein</span>
            </div>
          </div>
          <div className="bg-teal-50 border border-teal-200 rounded-xl p-3">
            <p className="text-xs text-teal-900 leading-relaxed">
              <b>Auto-refresh:</b> board har 25 second mein khud taza hota hai — kisi bhi
              counter se order lage, sab screens pe nazar aa jata hai. Upar chhote badges
              batate hain kitne table occupied / reserved / waiter-wale hain.
            </p>
          </div>
          <div className="bg-gray-50 border border-gray-200 rounded-xl p-3">
            <p className="text-xs text-gray-700 leading-relaxed">
              Kisi bhi tile pe click karo to seedha koi kaam <b>nahin</b> hota — pehle ek
              chhota <b>Action Menu</b> khulta hai (agla preview dekhein). Isi se ghalti se
              bill final hone ke haadse khatam.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
