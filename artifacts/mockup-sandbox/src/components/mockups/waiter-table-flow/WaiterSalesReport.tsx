const WAITERS = [
  { name: "Ahmed Raza", orders: 34, items: 96, sale: 78450, avg: 2307, top: "Chicken Karahi" },
  { name: "Bilal Khan", orders: 28, items: 71, sale: 61200, avg: 2185, top: "Beef Burger Deal" },
  { name: "Usman Ali", orders: 19, items: 44, sale: 35900, avg: 1889, top: "BBQ Platter" },
  { name: "Hamza Tariq", orders: 11, items: 25, sale: 18750, avg: 1704, top: "Fries (Large)" },
];

const CASHIERS = [
  { name: "Faisal (Cashier)", bills: 52, sale: 121300 },
  { name: "Adeel (Cashier)", bills: 40, sale: 73000 },
];

export function WaiterSalesReport() {
  const maxSale = Math.max(...WAITERS.map((w) => w.sale));
  const totalSale = WAITERS.reduce((s, w) => s + w.sale, 0);

  return (
    <div className="min-h-screen bg-gray-100 p-6 font-sans">
      <div className="max-w-5xl mx-auto">
        <div className="mb-4">
          <h1 className="text-xl font-bold text-gray-900">Reports — Sales by Waiter (Preview)</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Har waiter ka poora hisaab — kitne orders liye, kitni sale banayi. Aaj / Hafta / Mahina filter ke sath. (Sirf Admin/Manager ko nazar aayega)
          </p>
        </div>

        {/* Filter bar */}
        <div className="flex items-center gap-2 mb-4">
          <button className="px-3 py-1.5 rounded-lg bg-purple-600 text-white text-xs font-semibold">Aaj</button>
          <button className="px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-gray-600 text-xs font-semibold">Is Hafta</button>
          <button className="px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-gray-600 text-xs font-semibold">Is Mahina</button>
          <span className="ml-auto text-xs text-gray-400">24 Jul 2026</span>
        </div>

        {/* KPI cards */}
        <div className="grid grid-cols-3 gap-3 mb-4">
          <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <p className="text-xs text-gray-500 font-medium">Waiter Orders (Aaj)</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">92</p>
          </div>
          <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <p className="text-xs text-gray-500 font-medium">Waiter Sale (Aaj)</p>
            <p className="text-2xl font-bold text-teal-700 mt-1">Rs {totalSale.toLocaleString()}</p>
          </div>
          <div className="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
            <p className="text-xs text-gray-500 font-medium">Sab Se Behtar</p>
            <p className="text-2xl font-bold text-purple-700 mt-1">Ahmed Raza</p>
          </div>
        </div>

        {/* Waiter table */}
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-4">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                <th className="text-left px-4 py-2.5 font-semibold">Waiter</th>
                <th className="text-right px-4 py-2.5 font-semibold">Orders</th>
                <th className="text-right px-4 py-2.5 font-semibold">Items</th>
                <th className="text-right px-4 py-2.5 font-semibold">Sale</th>
                <th className="text-right px-4 py-2.5 font-semibold">Avg / Order</th>
                <th className="text-left px-4 py-2.5 font-semibold">Top Item</th>
                <th className="text-left px-4 py-2.5 font-semibold w-40">Hissa</th>
              </tr>
            </thead>
            <tbody>
              {WAITERS.map((w, i) => (
                <tr key={w.name} className="border-t border-gray-100">
                  <td className="px-4 py-2.5 font-semibold text-gray-800">
                    {i === 0 && <span className="mr-1.5 text-amber-500">★</span>}{w.name}
                  </td>
                  <td className="px-4 py-2.5 text-right text-gray-700">{w.orders}</td>
                  <td className="px-4 py-2.5 text-right text-gray-500">{w.items}</td>
                  <td className="px-4 py-2.5 text-right font-bold text-gray-900">Rs {w.sale.toLocaleString()}</td>
                  <td className="px-4 py-2.5 text-right text-gray-500">Rs {w.avg.toLocaleString()}</td>
                  <td className="px-4 py-2.5 text-gray-500">{w.top}</td>
                  <td className="px-4 py-2.5">
                    <div className="h-2.5 rounded bg-gray-100 overflow-hidden">
                      <div className="h-full bg-purple-600 rounded" style={{ width: `${(w.sale / maxSale) * 100}%` }} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Cashier comparison */}
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
          <h2 className="text-sm font-bold text-gray-900 mb-2">Sales by Cashier (pehle se mojood — sath dikhega)</h2>
          <div className="grid grid-cols-2 gap-3">
            {CASHIERS.map((c) => (
              <div key={c.name} className="border border-gray-100 rounded-lg p-3 flex items-center justify-between">
                <div>
                  <p className="text-sm font-semibold text-gray-800">{c.name}</p>
                  <p className="text-xs text-gray-400">{c.bills} bills final kiye</p>
                </div>
                <p className="text-base font-bold text-teal-700">Rs {c.sale.toLocaleString()}</p>
              </div>
            ))}
          </div>
          <p className="text-[11px] text-gray-400 mt-3">
            Waiter = order kisne LIYA · Cashier = bill kisne FINAL kiya — dono alag alag record hotay hain, koi cheez chhup nahi sakti.
          </p>
        </div>
      </div>
    </div>
  );
}
