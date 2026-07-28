import { Search, Trash2, Plus, Minus, Edit3 } from "lucide-react";
import { useState } from "react";

export default function CustomerPanel() {
  const [orderType, setOrderType] = useState<"takeaway" | "dine-in" | "delivery">("takeaway");
  const [showNote, setShowNote] = useState(false);

  const cartItems = [
    { id: 1, name: "Zinger Burger", qty: 2, price: 550 },
    { id: 2, name: "Chicken Karahi", qty: 1, price: 1450 },
    { id: 3, name: "Seekh Kabab", qty: 6, price: 120 },
    { id: 4, name: "Fries", qty: 1, price: 250 },
    { id: 5, name: "Soft Drink 1.5L", qty: 3, price: 180 },
    { id: 6, name: "Naan", qty: 8, price: 40 },
    { id: 7, name: "Raita", qty: 1, price: 60 },
    { id: 8, name: "Chicken Tikka", qty: 2, price: 380 },
    { id: 9, name: "Daal Makhani", qty: 1, price: 320 },
    { id: 10, name: "Mint Chutney", qty: 2, price: 30 },
  ];

  const subtotal = cartItems.reduce((sum, item) => sum + item.qty * item.price, 0);
  const discount = 200;
  const tax = Math.round((subtotal - discount) * 0.16);
  const total = subtotal - discount + tax;
  
  const totalItems = cartItems.length;
  const totalQty = cartItems.reduce((sum, item) => sum + item.qty, 0);

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
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
              <input
                type="text"
                placeholder="Item ya barcode scan/type karein..."
                className="w-full rounded-lg border border-gray-200 py-2.5 pl-11 pr-4 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
              />
            </div>

            {/* Customer Phone */}
            <div className="w-64">
              <input
                type="tel"
                placeholder="Customer phone (optional)"
                className="w-full rounded-lg border border-gray-200 px-4 py-2.5 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
              />
            </div>

            {/* Order Type Pills */}
            <div className="flex gap-2">
              <button
                onClick={() => setOrderType("takeaway")}
                className={`rounded-lg px-4 py-2.5 text-sm font-medium transition-colors ${
                  orderType === "takeaway"
                    ? "bg-purple-600 text-white"
                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                }`}
              >
                Takeaway
              </button>
              <button
                onClick={() => setOrderType("dine-in")}
                className={`rounded-lg px-4 py-2.5 text-sm font-medium transition-colors ${
                  orderType === "dine-in"
                    ? "bg-purple-600 text-white"
                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                }`}
              >
                Dine In
              </button>
              <button
                onClick={() => setOrderType("delivery")}
                className={`rounded-lg px-4 py-2.5 text-sm font-medium transition-colors ${
                  orderType === "delivery"
                    ? "bg-purple-600 text-white"
                    : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                }`}
              >
                Delivery
              </button>
            </div>
          </div>
        </div>

        {/* Main Content */}
        <div className="grid grid-cols-[1fr_400px] gap-4">
          {/* LEFT: Cart */}
          <div className="flex flex-col rounded-xl bg-white shadow-sm">
            <div className="flex-1 p-4">
              <div className="mb-3 flex items-center justify-between border-b border-gray-100 pb-3">
                <h2 className="text-lg font-semibold text-gray-900">Order Items</h2>
                <span className="text-sm text-gray-500">
                  {totalItems} items • {totalQty} pieces
                </span>
              </div>

              <div className="space-y-2">
                {cartItems.map((item) => (
                  <div
                    key={item.id}
                    className="flex items-center gap-4 rounded-lg border border-gray-100 p-3 transition-colors hover:bg-gray-50"
                  >
                    {/* Item Name */}
                    <div className="min-w-0 flex-1">
                      <p className="text-base font-medium text-gray-900">{item.name}</p>
                      <p className="text-sm text-gray-500">{formatPrice(item.price)} each</p>
                    </div>

                    {/* Quantity Controls */}
                    <div className="flex items-center gap-2">
                      <button className="flex h-8 w-8 items-center justify-center rounded-md border border-gray-200 text-gray-600 transition-colors hover:bg-gray-100">
                        <Minus className="h-4 w-4" />
                      </button>
                      <span className="w-12 text-center text-base font-semibold text-gray-900">
                        {item.qty}
                      </span>
                      <button className="flex h-8 w-8 items-center justify-center rounded-md border border-purple-200 text-purple-600 transition-colors hover:bg-purple-50">
                        <Plus className="h-4 w-4" />
                      </button>
                    </div>

                    {/* Line Total */}
                    <div className="w-28 text-right text-base font-semibold text-gray-900">
                      {formatPrice(item.qty * item.price)}
                    </div>

                    {/* Delete */}
                    <button className="flex h-8 w-8 items-center justify-center rounded-md text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                ))}
              </div>
            </div>

            {/* Cart Footer */}
            <div className="border-t border-gray-100 bg-gray-50 px-4 py-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-gray-600">
                  Total Items: {totalItems} • Total Quantity: {totalQty}
                </span>
                <span className="text-lg font-bold text-purple-600">{formatPrice(subtotal)}</span>
              </div>
            </div>
          </div>

          {/* RIGHT: Customer Panel + Payment */}
          <div className="flex flex-col gap-4">
            {/* Customer & Order Info Card */}
            <div className="rounded-xl bg-white p-4 shadow-sm">
              <h3 className="mb-3 text-sm font-semibold text-gray-900">Customer & Order Info</h3>
              
              <div className="space-y-3">
                {/* Phone */}
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Phone</label>
                  <input
                    type="tel"
                    placeholder="03XX-XXXXXXX"
                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
                  />
                </div>

                {/* Name */}
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Name</label>
                  <input
                    type="text"
                    placeholder="Customer name"
                    className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
                  />
                </div>

                {/* Table Number (for dine-in) */}
                {orderType === "dine-in" && (
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-600">Table #</label>
                    <input
                      type="text"
                      placeholder="Table number"
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
                    />
                  </div>
                )}

                {/* Note */}
                <div>
                  <div className="mb-1 flex items-center justify-between">
                    <label className="block text-xs font-medium text-gray-600">Order Note</label>
                    <button
                      onClick={() => setShowNote(!showNote)}
                      className="flex items-center gap-1 text-xs text-purple-600 hover:text-purple-700"
                    >
                      <Edit3 className="h-3 w-3" />
                      {showNote ? "Hide" : "Add"}
                    </button>
                  </div>
                  {showNote && (
                    <textarea
                      placeholder="Special instructions..."
                      rows={2}
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-600/20"
                    />
                  )}
                </div>
              </div>
            </div>

            {/* Payment Card */}
            <div className="rounded-xl bg-white p-4 shadow-sm">
              <h3 className="mb-3 text-sm font-semibold text-gray-900">Payment</h3>

              {/* Totals */}
              <div className="mb-4 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-600">Subtotal</span>
                  <span className="font-medium text-gray-900">{formatPrice(subtotal)}</span>
                </div>
                <div className="flex justify-between">
                  <button className="flex items-center gap-1 text-purple-600 hover:text-purple-700">
                    <span>Discount</span>
                    <kbd className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600">
                      %
                    </kbd>
                  </button>
                  <span className="font-medium text-red-600">-{formatPrice(discount)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600">Tax (PRA 16%)</span>
                  <span className="font-medium text-gray-900">{formatPrice(tax)}</span>
                </div>
                <div className="mt-3 flex justify-between border-t border-gray-200 pt-3">
                  <span className="text-base font-bold text-gray-900">Grand Total</span>
                  <span className="text-xl font-bold text-purple-600">{formatPrice(total)}</span>
                </div>
              </div>

              {/* Payment Buttons - 2x2 Grid */}
              <div className="grid grid-cols-2 gap-2">
                {/* CASH */}
                <button className="flex flex-col items-center justify-center gap-1 rounded-lg bg-purple-600 px-4 py-3 text-white transition-colors hover:bg-purple-700">
                  <span className="text-sm font-semibold">CASH</span>
                  <kbd className="rounded bg-purple-500 px-2 py-0.5 font-mono text-xs">Alt+1</kbd>
                </button>

                {/* CARD */}
                <button className="flex flex-col items-center justify-center gap-1 rounded-lg bg-gray-800 px-4 py-3 text-white transition-colors hover:bg-gray-900">
                  <span className="text-sm font-semibold">CARD</span>
                  <kbd className="rounded bg-gray-700 px-2 py-0.5 font-mono text-xs">Alt+2</kbd>
                </button>

                {/* PAY */}
                <button className="col-span-2 flex items-center justify-center gap-2 rounded-lg bg-green-600 px-4 py-4 text-white transition-colors hover:bg-green-700">
                  <span className="text-base font-bold">PAY</span>
                  <kbd className="rounded bg-green-500 px-2 py-1 font-mono text-xs">F8</kbd>
                </button>
              </div>

              {/* Secondary Actions */}
              <div className="mt-3 grid grid-cols-3 gap-2">
                <button className="rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50">
                  Clear
                  <kbd className="ml-1 rounded bg-gray-100 px-1 py-0.5 font-mono text-xs">F4</kbd>
                </button>
                <button className="rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50">
                  Hold
                  <kbd className="ml-1 rounded bg-gray-100 px-1 py-0.5 font-mono text-xs">F5</kbd>
                </button>
                <button className="rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50">
                  Recall
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
