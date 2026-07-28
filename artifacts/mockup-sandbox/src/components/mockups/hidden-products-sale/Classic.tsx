import { Search, Trash2, Plus, Minus, StickyNote, Percent } from "lucide-react";

export default function Classic() {
  const cartItems = [
    { id: 1, name: "Zinger Burger", qty: 2, price: 550 },
    { id: 2, name: "Chicken Karahi (Full)", qty: 1, price: 1450 },
    { id: 3, name: "Seekh Kabab", qty: 6, price: 120 },
    { id: 4, name: "Fries (Regular)", qty: 1, price: 250 },
    { id: 5, name: "Soft Drink 1.5L", qty: 3, price: 180 },
    { id: 6, name: "Naan", qty: 8, price: 40 },
    { id: 7, name: "Raita", qty: 1, price: 60 },
    { id: 8, name: "Chicken Tikka", qty: 2, price: 380 },
    { id: 9, name: "Garlic Naan", qty: 4, price: 60 },
    { id: 10, name: "Dahi Bhallay", qty: 1, price: 150 },
  ];

  const subtotal = cartItems.reduce((sum, item) => sum + item.qty * item.price, 0);
  const discount = 200;
  const taxRate = 0.16;
  const taxAmount = Math.round((subtotal - discount) * taxRate);
  const total = subtotal - discount + taxAmount;

  const formatPrice = (amount: number) => {
    return `Rs. ${amount.toLocaleString("en-PK")}`;
  };

  return (
    <div className="min-h-screen bg-gray-50 p-4">
      <div className="mx-auto max-w-[1366px]">
        {/* Top Bar */}
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm">
          <div className="flex items-center gap-4">
            {/* Search/Barcode Input */}
            <div className="flex-1 relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
              <input
                type="text"
                placeholder="Item ya barcode scan/type karein..."
                className="w-full pl-11 pr-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base"
              />
            </div>

            {/* Customer Phone */}
            <div className="w-64">
              <input
                type="text"
                placeholder="Customer phone (optional)"
                className="w-full px-4 py-3 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base"
              />
            </div>

            {/* Order Type Pills */}
            <div className="flex gap-2">
              <button className="px-5 py-3 rounded-lg bg-purple-600 text-white font-medium text-sm hover:bg-purple-700 transition-colors">
                Takeaway
              </button>
              <button className="px-5 py-3 rounded-lg bg-gray-100 text-gray-700 font-medium text-sm hover:bg-gray-200 transition-colors">
                Dine In
              </button>
              <button className="px-5 py-3 rounded-lg bg-gray-100 text-gray-700 font-medium text-sm hover:bg-gray-200 transition-colors">
                Delivery
              </button>
            </div>
          </div>
        </div>

        {/* Main Content: Cart Left + Payment Right */}
        <div className="flex gap-4">
          {/* LEFT: Cart Table (~62%) */}
          <div className="flex-[62] rounded-xl bg-white shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
              <h2 className="text-lg font-semibold text-gray-900">Order Items</h2>
            </div>
            
            <div className="divide-y divide-gray-100">
              {cartItems.map((item, index) => (
                <div
                  key={item.id}
                  className={`flex items-center gap-4 px-6 py-4 ${
                    index % 2 === 0 ? "bg-white" : "bg-gray-50/50"
                  } hover:bg-purple-50/30 transition-colors`}
                >
                  {/* Item Name */}
                  <div className="flex-1 min-w-0">
                    <p className="text-base font-medium text-gray-900 truncate">
                      {item.name}
                    </p>
                  </div>

                  {/* Quantity Stepper */}
                  <div className="flex items-center gap-2">
                    <button className="w-8 h-8 rounded-md bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-colors">
                      <Minus className="w-4 h-4 text-gray-700" />
                    </button>
                    <div className="w-12 text-center">
                      <span className="text-base font-semibold text-gray-900">
                        {item.qty}
                      </span>
                    </div>
                    <button className="w-8 h-8 rounded-md bg-purple-100 hover:bg-purple-200 flex items-center justify-center transition-colors">
                      <Plus className="w-4 h-4 text-purple-700" />
                    </button>
                  </div>

                  {/* Unit Price */}
                  <div className="w-28 text-right">
                    <p className="text-sm text-gray-500">@ {formatPrice(item.price)}</p>
                  </div>

                  {/* Line Total */}
                  <div className="w-32 text-right">
                    <p className="text-base font-semibold text-gray-900">
                      {formatPrice(item.qty * item.price)}
                    </p>
                  </div>

                  {/* Delete */}
                  <button className="w-8 h-8 rounded-md hover:bg-red-50 flex items-center justify-center transition-colors">
                    <Trash2 className="w-4 h-4 text-red-500" />
                  </button>
                </div>
              ))}
            </div>
          </div>

          {/* RIGHT: Payment Column (~38%) */}
          <div className="flex-[38] space-y-4">
            {/* Totals Card */}
            <div className="rounded-xl bg-white shadow-sm p-6 space-y-4">
              <div className="flex justify-between items-center">
                <span className="text-sm text-gray-600">Subtotal</span>
                <span className="text-base font-medium text-gray-900">
                  {formatPrice(subtotal)}
                </span>
              </div>

              <div className="flex justify-between items-center">
                <span className="text-sm text-gray-600">Discount</span>
                <span className="text-base font-medium text-red-600">
                  - {formatPrice(discount)}
                </span>
              </div>

              <div className="flex justify-between items-center">
                <span className="text-sm text-gray-600">Tax (PRA 16%)</span>
                <span className="text-base font-medium text-gray-900">
                  {formatPrice(taxAmount)}
                </span>
              </div>

              <div className="pt-4 border-t border-gray-200">
                <div className="flex justify-between items-center">
                  <span className="text-lg font-semibold text-gray-900">TOTAL</span>
                  <span className="text-2xl font-bold text-purple-600">
                    {formatPrice(total)}
                  </span>
                </div>
              </div>
            </div>

            {/* Quick Payment Buttons */}
            <div className="grid grid-cols-2 gap-3">
              <button className="h-16 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-semibold text-base shadow-sm transition-colors flex items-center justify-center gap-2">
                <span>CASH</span>
                <kbd className="px-1.5 py-0.5 text-xs font-mono bg-purple-500 rounded">
                  Alt+1
                </kbd>
              </button>
              <button className="h-16 rounded-xl bg-gray-800 hover:bg-gray-900 text-white font-semibold text-base shadow-sm transition-colors flex items-center justify-center gap-2">
                <span>CARD</span>
                <kbd className="px-1.5 py-0.5 text-xs font-mono bg-gray-700 rounded">
                  Alt+2
                </kbd>
              </button>
            </div>

            {/* Pay Button */}
            <button className="w-full h-14 rounded-xl bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold text-lg shadow-md transition-all flex items-center justify-center gap-3">
              <span>PAY</span>
              <kbd className="px-2 py-1 text-sm font-mono bg-purple-500 rounded">
                F8
              </kbd>
            </button>

            {/* Secondary Actions */}
            <div className="grid grid-cols-3 gap-2">
              <button className="h-11 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium text-sm transition-colors flex items-center justify-center gap-1">
                <span>Clear</span>
                <kbd className="px-1 py-0.5 text-xs font-mono bg-gray-200 rounded">
                  F4
                </kbd>
              </button>
              <button className="h-11 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium text-sm transition-colors flex items-center justify-center gap-1">
                <span>Hold</span>
                <kbd className="px-1 py-0.5 text-xs font-mono bg-gray-200 rounded">
                  F5
                </kbd>
              </button>
              <button className="h-11 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium text-sm transition-colors">
                Recall
              </button>
            </div>

            {/* Toggles */}
            <div className="flex gap-2">
              <button className="flex-1 h-10 rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-700 font-medium text-sm transition-colors flex items-center justify-center gap-2">
                <StickyNote className="w-4 h-4" />
                <span>Note</span>
              </button>
              <button className="flex-1 h-10 rounded-lg border border-gray-200 hover:bg-gray-50 text-gray-700 font-medium text-sm transition-colors flex items-center justify-center gap-2">
                <Percent className="w-4 h-4" />
                <span>Discount</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
