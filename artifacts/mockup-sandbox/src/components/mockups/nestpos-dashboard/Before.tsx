export function Before() {
  return (
    <div className="min-h-screen bg-gray-100 font-['Inter',sans-serif] text-gray-900">
      {/* ─── Top Nav (current: dense) ─── */}
      <div className="bg-[#4c1d95] text-white sticky top-0 z-30">
        <div className="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 min-w-0">
            <div className="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center font-black text-sm">N</div>
            <div className="leading-tight">
              <div className="text-[13px] font-extrabold whitespace-nowrap">NestPOS</div>
              <div className="text-[9px] text-white/60 whitespace-nowrap">ZFC PIZZA POINT</div>
            </div>
          </div>
          <div className="flex items-center gap-1.5 text-[11px]">
            <span className="px-3 py-1.5 rounded-lg bg-white text-purple-800 font-bold whitespace-nowrap">New Sale</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/10 whitespace-nowrap hidden sm:block">Transactions</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/10 whitespace-nowrap hidden sm:block">Products</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/10 whitespace-nowrap hidden md:block">Tables</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/10 whitespace-nowrap hidden md:block">KDS</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/10 whitespace-nowrap hidden lg:block">Deliveries</span>
            <span className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center">💡</span>
            <span className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center">🔔</span>
            <span className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center">🖥</span>
            <span className="px-2.5 py-1.5 rounded-lg bg-white/20 font-bold whitespace-nowrap">Menu ▾</span>
          </div>
        </div>
      </div>

      <div className="relative max-w-7xl mx-auto px-4 py-4">
        {/* open dropdown overlay — 25+ links */}
        <div className="absolute right-4 top-1 w-64 bg-white rounded-xl shadow-2xl border border-gray-200 z-20 py-2 text-[11px]">
          {[
            ["OVERVIEW", ["Dashboard", "Transactions", "Products", "Customers"]],
            ["RESTAURANT", ["Tables", "Kitchen Display (KDS)", "Deliveries Board", "Riders"]],
            ["REPORTS", ["Reports", "Tax Reports", "Day Close"]],
            ["INVENTORY", ["Inventory Dashboard", "Stock", "Movements", "Low Stock"]],
            ["RECIPES", ["Ingredients", "Recipes"]],
            ["SETTINGS", ["Customize POS", "POS Features", "Receipt Settings", "PRA Settings", "Team Accounts", "Billing & Plan", "Business Profile", "My Profile"]],
          ].map(([sec, items]) => (
            <div key={sec as string}>
              <div className="px-4 pt-2 pb-0.5 text-[9px] font-black text-gray-400 tracking-wider">{sec as string}</div>
              {(items as string[]).map((it) => (
                <div key={it} className="px-4 py-1 text-gray-700 hover:bg-gray-50">{it}</div>
              ))}
            </div>
          ))}
        </div>

        {/* amber notification */}
        <div className="mb-3 bg-amber-50 border border-amber-200 rounded-xl p-3 flex items-center gap-3">
          <div className="w-7 h-7 bg-amber-500 rounded-lg flex items-center justify-center text-white text-xs">🔔</div>
          <p className="flex-1 text-xs text-amber-900"><b>Announcement</b> · Ramzan timing update: support 3pm–1am available hoga.</p>
          <span className="text-amber-500 text-sm">✕</span>
        </div>

        {/* purple CTA banner */}
        <div className="mb-3 rounded-2xl bg-purple-600 p-4 text-white relative">
          <span className="absolute top-2 right-2 w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-xs">✕</span>
          <div className="flex items-center justify-between gap-3 pr-6 flex-wrap">
            <div>
              <div className="flex items-center gap-1.5 mb-0.5">
                <span className="px-1.5 py-0.5 rounded-full bg-white/20 text-[8px] font-bold uppercase tracking-wider">New</span>
                <span className="text-sm font-extrabold">PRA POS Universal v2 — One Screen, All Features</span>
              </div>
              <p className="text-[11px] text-white/85">Customize from 9 industry presets (Restaurant, Cafe, Quick Service, Retail, Pharmacy…). Toggle KOT, KDS, recipes, inventory, loyalty &amp; more.</p>
            </div>
            <div className="flex gap-2">
              <span className="px-4 py-2 rounded-lg bg-white text-purple-700 text-xs font-bold">Customize POS →</span>
              <span className="px-4 py-2 rounded-lg bg-white/10 border border-white/30 text-xs font-bold">Open POS</span>
            </div>
          </div>
        </div>

        {/* opening cash */}
        <div className="mb-3 rounded-2xl bg-white border border-amber-300 p-4">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-amber-500 flex items-center justify-center text-white">💰</div>
            <div className="flex-1">
              <p className="text-sm font-bold">Din ka Aghaz — Opening Cash</p>
              <p className="text-xs text-gray-500">Subah drawer mein jitna cash (khulla/change) rakha hai woh enter karein — raat ko day close par hisaab khud-ba-khud milega.</p>
            </div>
          </div>
          <div className="mt-3 flex items-end gap-2">
            <div className="flex-1">
              <div className="text-[10px] font-semibold text-gray-600 mb-1">Opening cash (Rs)</div>
              <div className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-400">e.g. 5000</div>
            </div>
            <span className="px-4 py-2 rounded-lg bg-teal-600 text-white text-xs font-bold">Save Opening</span>
          </div>
        </div>

        {/* Profit & BI */}
        <div className="mb-3 rounded-2xl border border-emerald-200 bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
            <div className="flex items-center gap-2">
              <div className="w-9 h-9 rounded-xl bg-emerald-600 flex items-center justify-center text-white">📈</div>
              <div>
                <h3 className="text-sm font-extrabold">Profit &amp; BI</h3>
                <p className="text-[10px] text-gray-500">Today · cost coverage: <span className="font-semibold text-amber-600">62%</span> of products have cost set</p>
              </div>
            </div>
            <div className="inline-flex rounded-xl border border-gray-200 p-1 text-[11px] font-semibold">
              <span className="px-3 py-1.5 rounded-lg bg-emerald-500 text-white">Today</span>
              <span className="px-3 py-1.5 text-gray-600">Week</span>
              <span className="px-3 py-1.5 text-gray-600">Month</span>
            </div>
          </div>
          <div className="grid grid-cols-5 gap-2">
            {[["Sales", "Rs. 84,300", "text-gray-900"], ["Cost", "Rs. 41,200", "text-amber-600"], ["Profit", "Rs. 43,100", "text-white"], ["Margin", "51%", "text-emerald-600"], ["Orders", "112", "text-purple-600"]].map(([l, v, c], i) => (
              <div key={l as string} className={`rounded-xl p-3 border ${i === 2 ? "bg-emerald-600 border-emerald-600" : "bg-white border-gray-100"}`}>
                <p className={`text-[9px] uppercase tracking-wider font-bold ${i === 2 ? "text-white/80" : "text-gray-500"}`}>{l as string}</p>
                <p className={`text-lg font-black mt-1 ${c as string}`}>{v as string}</p>
              </div>
            ))}
          </div>
          <div className="mt-3 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">⚠ Add cost prices to your products to see accurate profit. <u>Open Products →</u></div>
          <div className="grid grid-cols-3 gap-3 mt-3">
            <div className="rounded-xl border border-gray-100 p-3">
              <p className="text-[10px] font-bold text-gray-700 mb-2">● TOP SOLD</p>
              {[["Chicken Tikka Pizza L", "34"], ["Zinger Burger", "27"], ["Fries Regular", "25"], ["Malai Boti Pizza M", "18"]].map(([n, q]) => (
                <div key={n} className="flex justify-between py-1 text-[11px] border-b border-gray-50"><span>{n}</span><span className="text-purple-600 font-bold">{q} sold</span></div>
              ))}
            </div>
            <div className="rounded-xl border border-gray-100 p-3">
              <p className="text-[10px] font-bold text-gray-700 mb-2">● MOST PROFITABLE</p>
              {[["Chicken Tikka Pizza L", "9,860"], ["Malai Boti Pizza M", "5,340"], ["Zinger Burger", "4,050"], ["Cold Drink 1.5L", "2,210"]].map(([n, q]) => (
                <div key={n} className="flex justify-between py-1 text-[11px] border-b border-gray-50"><span>{n}</span><span className="text-emerald-600 font-bold">Rs. {q}</span></div>
              ))}
            </div>
            <div className="rounded-xl border border-gray-100 p-3">
              <p className="text-[10px] font-bold text-gray-700 mb-2">● LOW MARGIN (&lt; 15%)</p>
              {[["Water Bottle", "8%"], ["Cold Drink Can", "11%"], ["Sauce Extra", "13%"]].map(([n, q]) => (
                <div key={n} className="flex justify-between py-1 text-[11px] border-b border-gray-50"><span>⚠ {n}</span><span className="text-red-500 font-bold">{q}</span></div>
              ))}
            </div>
          </div>
        </div>

        {/* dashboard style: more KPI cards */}
        <div className="grid grid-cols-4 gap-3 mb-3">
          {[["Today's Sales", "Rs. 84,300", "bg-purple-600"], ["This Month", "Rs. 21,40,650", "bg-indigo-600"], ["Total Bills", "112", "bg-teal-600"], ["PRA Synced", "108 / 112", "bg-emerald-600"]].map(([l, v, c]) => (
          <div key={l as string} className="rounded-2xl bg-white border border-gray-200 p-4">
              <div className={`w-8 h-8 rounded-lg ${c as string} mb-2 flex items-center justify-center text-white text-xs`}>▦</div>
              <p className="text-[10px] text-gray-500 font-semibold uppercase">{l as string}</p>
              <p className="text-base font-black">{v as string}</p>
            </div>
          ))}
        </div>

        {/* quick actions */}
        <div className="rounded-2xl bg-white border border-gray-200 p-4 mb-3">
          <p className="text-xs font-extrabold mb-3">Quick Actions</p>
          <div className="grid grid-cols-6 gap-2 text-[10px] font-bold text-center">
            {["New Sale", "Day Close", "Reports", "Tax Reports", "Products", "Customers"].map((a) => (
              <div key={a} className="rounded-xl border border-gray-200 py-3 px-1">{a}</div>
            ))}
          </div>
        </div>

        {/* recent transactions + drafts */}
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-2 rounded-2xl bg-white border border-gray-200 p-4">
            <p className="text-xs font-extrabold mb-2">Recent Transactions</p>
            {[["POS-2026-04512", "Rs. 1,850", "PRA ✓"], ["POS-2026-04511", "Rs. 3,240", "PRA ✓"], ["L-00341 (Local)", "Rs. 920", "Provisional"], ["POS-2026-04510", "Rs. 5,610", "PRA ✓"]].map(([n, v, s]) => (
              <div key={n} className="flex justify-between py-1.5 text-[11px] border-b border-gray-50"><span className="font-mono">{n}</span><span>{v}</span><span className="text-emerald-600 font-bold">{s}</span></div>
            ))}
          </div>
          <div className="rounded-2xl bg-white border border-gray-200 p-4">
            <p className="text-xs font-extrabold mb-2">Saved Drafts (3)</p>
            {["Table 4 — Rs. 2,140", "Walk-in — Rs. 860", "Table 9 — Rs. 4,505"].map((d) => (
              <div key={d} className="flex justify-between py-1.5 text-[11px] border-b border-gray-50"><span>{d}</span><span className="text-red-400">🗑</span></div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
