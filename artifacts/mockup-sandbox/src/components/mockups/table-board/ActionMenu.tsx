function TableIcon({ cls }: { cls: string }) {
  return (
    <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${cls}`}>
      <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M5 10v9m14-9v9M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z" />
      </svg>
    </div>
  );
}

function CloseX() {
  return (
    <button className="text-gray-400 hover:text-gray-600 flex-shrink-0">
      <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  );
}

export function ActionMenu() {
  return (
    <div className="min-h-screen bg-gray-900/80 flex items-center justify-center p-8 font-sans">
      <div className="flex items-start gap-8">
        {/* Cashier order wala menu */}
        <div className="w-80">
          <p className="text-center text-[11px] font-black text-white/80 uppercase tracking-wider mb-2">
            Aam order (cashier ka)
          </p>
          <div className="bg-white rounded-2xl shadow-2xl w-full overflow-hidden">
            <div className="p-4 border-b border-gray-100 flex items-start gap-3">
              <TableIcon cls="bg-red-100 text-red-600" />
              <div className="min-w-0 flex-1">
                <p className="text-base font-black text-gray-900">T-5</p>
                <p className="text-[11px] text-gray-500 truncate">Ali Raza • 3 items • Rs 2,660 • 12 min</p>
              </div>
              <CloseX />
            </div>
            <div className="p-3 space-y-2">
              <button className="w-full py-2.5 rounded-xl text-sm font-bold text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100 transition">
                Bill Kholo / Edit karo
              </button>
              <button className="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-green-600 hover:bg-green-700 transition">
                FINAL karo — Rs 2,660
              </button>
              <button className="w-full py-2 rounded-xl text-xs font-bold text-orange-700 bg-orange-50 border border-orange-200 hover:bg-orange-100 transition">
                ↻ KOT Dobara Bhejo
              </button>
              <button className="w-full py-2 rounded-xl text-xs font-bold text-red-600 bg-red-50 border border-red-200 hover:bg-red-100 transition">
                Order Cancel + Table Khali
              </button>
            </div>
          </div>
        </div>

        {/* Waiter order wala menu */}
        <div className="w-80">
          <p className="text-center text-[11px] font-black text-white/80 uppercase tracking-wider mb-2">
            Waiter ka order (purple)
          </p>
          <div className="bg-white rounded-2xl shadow-2xl w-full overflow-hidden">
            <div className="p-4 border-b border-gray-100 flex items-start gap-3">
              <TableIcon cls="bg-purple-100 text-purple-600" />
              <div className="min-w-0 flex-1">
                <p className="text-base font-black text-gray-900">T-7</p>
                <p className="text-[11px] text-gray-500 truncate">Waiter Bilal • 4 items • Rs 1,980 • 4 min</p>
              </div>
              <CloseX />
            </div>
            <div className="p-3 space-y-2">
              <button className="w-full py-2.5 rounded-xl text-sm font-bold text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100 transition">
                Bill Kholo / Edit karo
              </button>
              <button className="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-green-600 hover:bg-green-700 transition">
                FINAL karo — Rs 1,980
              </button>
              <button className="w-full py-2 rounded-xl text-xs font-bold text-orange-700 bg-orange-50 border border-orange-200 hover:bg-orange-100 transition">
                ↻ KOT Dobara Bhejo
              </button>
              <p className="text-[10px] text-purple-500 text-center pt-1">
                Waiter ka order — cancel sirf waiter/admin side se
              </p>
            </div>
          </div>
        </div>

        {/* Khali / reserved variants */}
        <div className="w-80 space-y-5">
          <div>
            <p className="text-center text-[11px] font-black text-white/80 uppercase tracking-wider mb-2">
              Khali table
            </p>
            <div className="bg-white rounded-2xl shadow-2xl w-full overflow-hidden">
              <div className="p-4 border-b border-gray-100 flex items-start gap-3">
                <TableIcon cls="bg-green-100 text-green-600" />
                <div className="min-w-0 flex-1">
                  <p className="text-base font-black text-gray-900">T-1</p>
                  <p className="text-[11px] text-gray-500">Khali • 4 seats</p>
                </div>
                <CloseX />
              </div>
              <div className="p-3">
                <button className="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-teal-600 hover:bg-teal-700 transition">
                  Naya Order — Table Reserve karo
                </button>
              </div>
            </div>
          </div>
          <div>
            <p className="text-center text-[11px] font-black text-white/80 uppercase tracking-wider mb-2">
              Reserved table
            </p>
            <div className="bg-white rounded-2xl shadow-2xl w-full overflow-hidden">
              <div className="p-4 border-b border-gray-100 flex items-start gap-3">
                <TableIcon cls="bg-amber-100 text-amber-600" />
                <div className="min-w-0 flex-1">
                  <p className="text-base font-black text-gray-900">T-2</p>
                  <p className="text-[11px] text-gray-500">Reserved • 8 min</p>
                </div>
                <CloseX />
              </div>
              <div className="p-3">
                <button className="w-full py-2.5 rounded-xl text-sm font-bold text-amber-700 bg-amber-50 border border-amber-300 hover:bg-amber-100 transition">
                  Reserve Khatam — Table Khali karo
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
