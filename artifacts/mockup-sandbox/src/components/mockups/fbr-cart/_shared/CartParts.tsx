// Shared building blocks for the FBR cart mockups.
// Classes are copied 1:1 from resources/views/fbr-pos/universal.blade.php /
// resources/views/pos/universal.blade.php so the mockups match the real screens.

export type Accent = {
  icon: string;        // header cart icon
  badgeBg: string;     // order-type badge
  emptyBg: string;     // empty-state circle
  emptyIcon: string;   // empty-state icon
  recall: string;      // recall button classes
  editChip: React.CSSProperties; // header Edit chip
};

export const BLUE: Accent = {
  icon: "text-blue-600",
  badgeBg: "bg-blue-100 text-blue-700",
  emptyBg: "bg-blue-100",
  emptyIcon: "text-blue-400",
  recall: "text-blue-600 bg-blue-50 border-blue-200 hover:bg-blue-100",
  editChip: { background: "#dbeafe", color: "#2563eb" },
};

export const PURPLE: Accent = {
  icon: "text-purple-600",
  badgeBg: "bg-purple-100 text-purple-700",
  emptyBg: "bg-purple-100",
  emptyIcon: "text-purple-400",
  recall: "text-purple-600 bg-purple-50 border-purple-200 hover:bg-purple-100",
  editChip: { background: "#f3e8ff", color: "#7c3aed" },
};

export function CartIcon({ className }: { className: string }) {
  return (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.6} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
    </svg>
  );
}

export function Header({ a, withEdit }: { a: Accent; withEdit?: boolean }) {
  return (
    <div className="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100">
      <CartIcon className={`w-5 h-5 ${a.icon}`} />
      <span className="text-sm font-bold text-gray-900 flex-1">Current Order</span>
      {withEdit && (
        <span className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold" style={a.editChip}>
          <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
          Edit
        </span>
      )}
      <span className={`text-[10px] px-2 py-0.5 rounded-full font-semibold ${a.badgeBg}`}>TAKEAWAY</span>
    </div>
  );
}

export function EmptyState({ a }: { a: Accent }) {
  return (
    <div className="flex-1 min-h-0 overflow-y-auto">
      <div className="flex flex-col items-center justify-center h-full text-gray-400 py-16 px-6 text-center">
        <div className={`w-24 h-24 rounded-full ${a.emptyBg} flex items-center justify-center mb-5`}>
          <CartIcon className={`w-12 h-12 ${a.emptyIcon}`} />
        </div>
        <p className="text-base font-bold text-gray-700">Your cart is empty</p>
        <p className="text-xs mt-1.5 text-gray-400 max-w-[220px]">Tap a product on the left, or scan a barcode to start a new sale</p>
      </div>
    </div>
  );
}

export function Footer({ a, empty, total, subtotal }: { a: Accent; empty: boolean; total: string; subtotal: string }) {
  const dis = empty ? "opacity-30 pointer-events-none" : "";
  return (
    <div className="border-t border-gray-200 bg-gray-50/80 backdrop-blur-sm">
      <div className="px-3 py-1.5">
        <textarea rows={1} readOnly placeholder="Order Notes... (press N to focus, ⏎/Esc to exit)"
          className="w-full text-xs bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-gray-700 resize-none placeholder-gray-400" />
      </div>
      <div className="px-3 py-1.5 flex items-center gap-1.5">
        <button className="text-[10px] font-semibold px-2 py-0.5 rounded-lg bg-gray-100 text-gray-500">+ Discount</button>
        <span className="text-[8px] text-gray-400">Limit: 100%</span>
      </div>
      <div className="px-3 py-2 space-y-1">
        <div className="flex justify-between text-xs text-gray-500"><span>Subtotal</span><span>Rs. {subtotal}</span></div>
        <div className="flex justify-between text-xs text-gray-500"><span>Tax (0%)</span><span>Rs. 0</span></div>
        <div className="flex items-baseline justify-between pt-2 mt-1 border-t" style={{ borderColor: "rgba(148,163,184,.16)" }}>
          <span className="text-sm font-bold uppercase tracking-wider text-gray-500">TOTAL</span>
          <span className="text-2xl font-black" style={{ color: empty ? "#111827" : "#059669" }}>Rs. {total}</span>
        </div>
      </div>
      <div className="px-3 pb-3 space-y-2">
        <div className="grid grid-cols-3 gap-2">
          <button className={`py-2 text-xs font-bold text-red-600 bg-red-50 rounded-xl border border-red-200 flex items-center justify-center gap-0.5 ${dis}`}>
            Clear <kbd className="text-[8px] bg-red-200/50 px-1 rounded font-mono">F4</kbd>
          </button>
          <button className={`py-2 text-xs font-bold text-amber-600 bg-amber-50 rounded-xl border border-amber-200 flex items-center justify-center gap-1 ${dis}`}>
            Hold <kbd className="text-[8px] bg-amber-200/50 px-1 rounded font-mono">F5</kbd>
          </button>
          <button className={`py-2 text-xs font-bold rounded-xl border flex items-center justify-center gap-0.5 ${a.recall}`}>
            Recall <kbd className="text-[8px] bg-black/5 px-1 rounded font-mono">F3</kbd>
          </button>
        </div>
        <button className={`w-full py-2.5 rounded-xl text-sm font-bold text-white bg-amber-500 shadow-sm flex items-center justify-center gap-2 ${dis}`}>
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" /></svg>
          <span>Save Provisional</span>
          <kbd className="text-[9px] bg-amber-700/40 px-1.5 py-0.5 rounded font-mono">F9</kbd>
        </button>
        <button className={`w-full py-4 rounded-2xl text-base font-extrabold text-white ${dis}`}
          style={{ background: "linear-gradient(135deg, #16a34a 0%, #059669 60%, #047857 100%)", boxShadow: "0 1px 2px rgba(0,0,0,.08)", letterSpacing: ".01em" }}>
          <span className="flex items-center justify-center gap-2">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            PAY Rs. {total}
            <kbd className="text-[9px] bg-green-500/30 px-1.5 rounded font-mono">F8</kbd>
          </span>
        </button>
      </div>
    </div>
  );
}

export function CartRow({ name, price, qty, lineTotal, active }: { name: string; price: string; qty: string; lineTotal: string; active?: boolean }) {
  return (
    <div className={`px-3 py-2.5 relative border-b border-gray-50 ${active ? "bg-purple-50/40" : ""}`}>
      <div className="flex items-center gap-2.5">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-bold text-gray-900 truncate">{name}</p>
          <p className="text-[11px] text-gray-400 mt-0.5">Rs. {price} / unit</p>
        </div>
        <div className="flex items-center gap-0.5 bg-gray-100 rounded-xl p-0.5">
          <button className="w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 shadow-sm bg-transparent">
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" d="M20 12H4" /></svg>
          </button>
          <span className="w-14 h-10 flex items-center justify-center text-lg font-extrabold bg-white text-gray-900 rounded-lg shadow-inner">{qty}</span>
          <button className="w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 shadow-sm bg-transparent">
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" d="M12 4v16m8-8H4" /></svg>
          </button>
        </div>
        <div className="text-right min-w-[60px]">
          <p className="text-sm font-extrabold text-gray-900">Rs.{lineTotal}</p>
        </div>
        <button className="p-1.5 text-red-400 rounded-lg">
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
        </button>
      </div>
      {active && (
        <div className="flex items-center gap-1.5 mt-1.5 justify-end">
          <span className="text-[11px] font-extrabold px-2 py-1 rounded-md ring-1 bg-white text-gray-600 ring-gray-300">TAX</span>
          <span className="text-[9px] font-bold px-1.5 py-1 rounded-md bg-gray-100 text-gray-400">Disc</span>
          <span className="text-[9px] font-bold px-1.5 py-1 rounded-md bg-blue-50 text-blue-500">FBR</span>
        </div>
      )}
    </div>
  );
}
