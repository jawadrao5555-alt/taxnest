export function After() {
  return (
    <div className="min-h-screen bg-[#f7f9fa] font-['Inter',sans-serif] text-gray-900">
      {/* ─── Top Nav (clean: 5 groups) ─── */}
      <div className="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3 min-w-0">
            <div className="w-9 h-9 rounded-xl bg-[#0A4D5C] flex items-center justify-center font-black text-white">N</div>
            <div className="leading-tight">
              <div className="text-sm font-extrabold">NestPOS</div>
              <div className="text-[10px] text-gray-400">ZFC PIZZA POINT</div>
            </div>
          </div>
          <div className="flex items-center gap-6 text-[13px] font-semibold text-gray-500">
            <span className="text-[#0A4D5C] border-b-2 border-[#0A4D5C] pb-1">Home</span>
            <span>Bills</span>
            <span>Products</span>
            <span>Reports</span>
            <span>Settings</span>
          </div>
          <span className="px-5 py-2.5 rounded-xl bg-[#0A4D5C] text-white text-[13px] font-bold whitespace-nowrap">+ Naya Bill</span>
        </div>
      </div>

      <div className="max-w-5xl mx-auto px-6 py-10">
        {/* greeting */}
        <div className="mb-8">
          <h1 className="text-2xl font-extrabold">Assalam-o-Alaikum 👋</h1>
          <p className="text-sm text-gray-500 mt-1">Monday, 21 July 2026 · Aaj ka din mubarak ho</p>
        </div>

        {/* 4 clean KPIs */}
        <div className="grid grid-cols-4 gap-4 mb-8">
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-[11px] text-gray-400 font-semibold uppercase tracking-wide">Aaj ki Sales</p>
            <p className="text-2xl font-black mt-2">Rs. 84,300</p>
            <p className="text-[11px] text-emerald-600 font-semibold mt-1">↑ 12% kal se zyada</p>
          </div>
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-[11px] text-gray-400 font-semibold uppercase tracking-wide">Aaj ke Bills</p>
            <p className="text-2xl font-black mt-2">112</p>
            <p className="text-[11px] text-gray-400 mt-1">108 PRA synced ✓</p>
          </div>
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-[11px] text-gray-400 font-semibold uppercase tracking-wide">Aaj ka Profit</p>
            <p className="text-2xl font-black mt-2 text-[#0A4D5C]">Rs. 43,100</p>
            <p className="text-[11px] text-gray-400 mt-1">Sirf aapko nazar aata hai</p>
          </div>
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-[11px] text-gray-400 font-semibold uppercase tracking-wide">Opening Cash</p>
            <p className="text-2xl font-black mt-2">Rs. 5,000</p>
            <p className="text-[11px] text-gray-400 mt-1">Day close par hisaab milega</p>
          </div>
        </div>

        {/* two panels */}
        <div className="grid grid-cols-2 gap-4 mb-8">
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-sm font-extrabold mb-4">Aaj ke Top Items</p>
            {[["Chicken Tikka Pizza L", "34 sold"], ["Zinger Burger", "27 sold"], ["Fries Regular", "25 sold"], ["Malai Boti Pizza M", "18 sold"], ["Cold Drink 1.5L", "15 sold"]].map(([n, q], i) => (
              <div key={n} className="flex items-center justify-between py-2.5 text-[13px] border-b border-gray-50 last:border-0">
                <span className="flex items-center gap-3"><span className="w-6 h-6 rounded-lg bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500">{i + 1}</span>{n}</span>
                <span className="text-gray-400 font-semibold">{q}</span>
              </div>
            ))}
          </div>
          <div className="rounded-2xl bg-white border border-gray-200 p-5">
            <p className="text-sm font-extrabold mb-4">Roz ke Kaam</p>
            <div className="grid grid-cols-2 gap-3">
              {[["🧾", "Day Close"], ["📊", "Reports"], ["🍕", "Products"], ["👤", "Customers"]].map(([ic, l]) => (
                <div key={l} className="rounded-xl border border-gray-200 p-4 text-center">
                  <div className="text-xl mb-1.5">{ic}</div>
                  <div className="text-[12px] font-bold text-gray-700">{l}</div>
                </div>
              ))}
            </div>
            <p className="text-[11px] text-gray-400 mt-4 text-center">Baaki sab kuch (Inventory, Riders, Tax Reports…) upar <b>Reports</b> aur <b>Settings</b> mein mehfooz hai</p>
          </div>
        </div>

        {/* one soft banner only when needed */}
        <div className="rounded-2xl bg-white border border-gray-200 p-4 flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-[#0A4D5C] flex items-center justify-center text-white text-sm">✓</div>
          <p className="text-[13px] text-gray-600 flex-1">PRA reporting theek chal rahi hai — aaj ke 108 bills sync ho chuke hain.</p>
          <span className="text-[12px] font-bold text-[#0A4D5C]">Day Close karein →</span>
        </div>
      </div>
    </div>
  );
}
