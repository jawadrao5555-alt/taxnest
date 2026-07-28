import React, { useState } from 'react';
import { Search, Phone, Trash2, Plus, Minus, Tag, StickyNote, RotateCcw, Pause, XCircle, CreditCard, Banknote, ShoppingBag } from 'lucide-react';

export default function BillStyle() {
  const [orderType, setOrderType] = useState('Takeaway');

  const items = [
    { id: 1, name: 'Zinger Burger', price: 550, qty: 2, total: 1100 },
    { id: 2, name: 'Chicken Karahi', price: 1450, qty: 1, total: 1450 },
    { id: 3, name: 'Seekh Kabab', price: 120, qty: 6, total: 720 },
    { id: 4, name: 'Fries', price: 250, qty: 1, total: 250 },
    { id: 5, name: 'Soft Drink 1.5L', price: 180, qty: 3, total: 540 },
    { id: 6, name: 'Naan', price: 40, qty: 8, total: 320 },
    { id: 7, name: 'Raita', price: 60, qty: 1, total: 60 },
  ];

  return (
    <div className="flex flex-col h-screen bg-[#f3f4f6] text-slate-800 font-sans overflow-hidden">
      {/* TOP BAR */}
      <header className="flex items-center justify-between px-6 py-4 bg-white border-b border-slate-200 shadow-sm shrink-0 z-10">
        <div className="flex-1 flex gap-4 max-w-4xl">
          {/* Search Bar */}
          <div className="relative flex-1 group">
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 group-focus-within:text-purple-600 transition-colors">
              <Search size={20} />
            </div>
            <input
              type="text"
              placeholder="Item ya barcode scan/type karein... (Alt+S)"
              className="w-full pl-10 pr-4 py-3 bg-slate-100 border-transparent rounded-xl focus:bg-white focus:border-purple-600 focus:ring-4 focus:ring-purple-600/10 transition-all outline-none font-medium placeholder:font-normal placeholder:text-slate-400"
              autoFocus
            />
          </div>
          
          {/* Phone Input */}
          <div className="relative w-72 group hidden md:block">
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 group-focus-within:text-purple-600 transition-colors">
              <Phone size={18} />
            </div>
            <input
              type="text"
              placeholder="Customer phone (optional)"
              className="w-full pl-9 pr-4 py-3 bg-slate-100 border-transparent rounded-xl focus:bg-white focus:border-purple-600 focus:ring-4 focus:ring-purple-600/10 transition-all outline-none font-medium placeholder:font-normal placeholder:text-slate-400"
            />
          </div>
        </div>

        {/* Order Types */}
        <div className="flex gap-1.5 p-1 bg-slate-100 rounded-xl ml-6">
          {['Takeaway', 'Dine In', 'Delivery'].map(type => (
            <button
              key={type}
              onClick={() => setOrderType(type)}
              className={`px-5 py-2.5 rounded-lg text-sm font-semibold transition-all ${
                orderType === type 
                  ? 'bg-white text-purple-700 shadow-sm' 
                  : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
              }`}
            >
              {type}
            </button>
          ))}
        </div>
      </header>

      {/* MAIN CONTENT */}
      <main className="flex-1 flex p-6 gap-6 min-h-0">
        
        {/* LEFT COLUMN - THERMAL RECEIPT / LEDGER CART */}
        <div className="flex-1 bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col relative overflow-hidden">
          {/* Subtle paper texture overlay */}
          <div className="absolute inset-0 opacity-[0.015] pointer-events-none bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] mix-blend-multiply"></div>
          
          {/* Ledger Header */}
          <div className="pt-6 px-8 pb-4 flex justify-between items-end border-b-2 border-dashed border-slate-300 relative z-10 shrink-0">
            <div>
              <h2 className="text-sm font-bold tracking-widest text-slate-400 uppercase font-mono">Invoice / Cart</h2>
              <div className="text-xl font-bold text-slate-800 mt-1 flex items-center gap-2">
                <ShoppingBag size={20} className="text-purple-600" />
                Current Order
              </div>
            </div>
            <div className="text-right">
              <div className="text-xs font-mono text-slate-400">POS-TERMINAL-01</div>
              <div className="text-sm font-mono font-medium text-slate-600 mt-0.5">{new Date().toLocaleDateString('en-GB')} {new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</div>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto px-8 py-2 relative z-10 no-scrollbar">
            {/* Table headers (mono) */}
            <div className="grid grid-cols-[3fr_1fr_1.5fr_1.5fr_auto] gap-4 py-3 border-b border-slate-100 text-xs font-mono font-semibold text-slate-400 uppercase">
              <div>Item Description</div>
              <div className="text-center">Qty</div>
              <div className="text-right">Price</div>
              <div className="text-right">Total</div>
              <div className="w-8"></div>
            </div>

            {/* Cart Items */}
            <div className="mt-2 space-y-1 pb-8">
              {items.map((item, idx) => (
                <div key={item.id} className="group grid grid-cols-[3fr_1fr_1.5fr_1.5fr_auto] gap-4 py-3 items-center border-b border-dashed border-slate-200 hover:bg-slate-50 transition-colors -mx-2 px-2 rounded-lg">
                  <div className="font-mono text-[15px] font-medium text-slate-800 tracking-tight flex items-center gap-2">
                    <span className="text-slate-400 text-xs w-4">{idx + 1}.</span>
                    {item.name}
                  </div>
                  
                  {/* Qty Stepper */}
                  <div className="flex items-center justify-center gap-2 bg-slate-100 rounded-md py-1 px-1.5">
                    <button className="p-1 rounded bg-white text-slate-500 hover:text-purple-600 shadow-sm border border-slate-200 transition-colors">
                      <Minus size={14} />
                    </button>
                    <span className="w-6 text-center font-mono font-bold text-slate-800 text-sm">{item.qty}</span>
                    <button className="p-1 rounded bg-white text-slate-500 hover:text-purple-600 shadow-sm border border-slate-200 transition-colors">
                      <Plus size={14} />
                    </button>
                  </div>
                  
                  <div className="text-right font-mono text-slate-500 text-[15px]">
                    {item.price.toLocaleString()}
                  </div>
                  
                  <div className="text-right font-mono font-bold text-slate-800 text-[15px]">
                    {(item.qty * item.price).toLocaleString()}
                  </div>
                  
                  <div className="w-8 flex justify-end">
                    <button className="text-slate-300 hover:text-red-500 transition-colors p-1.5 rounded hover:bg-red-50">
                      <Trash2 size={16} />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
          
          {/* Ledger Footer decoration */}
          <div className="h-6 w-full shrink-0 relative z-10 overflow-hidden opacity-50 flex items-end">
            <div className="w-full h-2 flex justify-around">
               {/* Zig zag teeth pattern mimicking thermal paper tear */}
               <div className="w-full h-full bg-[radial-gradient(circle_at_50%_0,transparent_3px,#fff_4px)] bg-[length:10px_10px]"></div>
            </div>
          </div>
        </div>

        {/* RIGHT COLUMN - PAYMENT / ACTIONS */}
        <div className="w-[420px] flex flex-col gap-4 shrink-0">
          
          {/* HUGE TOTAL BAND */}
          <div className="bg-slate-900 rounded-2xl p-6 text-white shadow-xl shadow-slate-900/10 flex flex-col relative overflow-hidden">
            {/* Subtle glow / highlight */}
            <div className="absolute top-0 right-0 w-64 h-64 bg-purple-500/20 rounded-full blur-3xl -translate-y-1/2 translate-x-1/3"></div>
            
            <div className="relative z-10">
              <div className="flex justify-between items-start mb-6">
                <span className="text-slate-400 font-mono text-sm tracking-wider uppercase">Grand Total</span>
                <span className="bg-white/10 text-white/90 text-xs px-2 py-1 rounded font-mono">7 Items</span>
              </div>
              
              <div className="flex items-baseline gap-2">
                <span className="text-3xl font-medium text-slate-400">Rs.</span>
                <span className="text-6xl font-bold tracking-tight">5,150</span>
              </div>
            </div>

            {/* Breakdown within the dark card */}
            <div className="relative z-10 mt-8 pt-5 border-t border-white/10 flex flex-col gap-2.5 font-mono text-sm">
              <div className="flex justify-between text-slate-400">
                <span>Subtotal</span>
                <span>Rs. 4,440</span>
              </div>
              <div className="flex justify-between text-emerald-400">
                <span>Discount</span>
                <span>Rs. 0</span>
              </div>
              <div className="flex justify-between text-slate-400">
                <span>Tax (PRA 16%)</span>
                <span>Rs. 710</span>
              </div>
            </div>
          </div>

          {/* QUICK TOGGLES */}
          <div className="flex gap-3">
            <button className="flex-1 flex items-center justify-center gap-2 py-3 bg-white rounded-xl border border-slate-200 text-slate-600 hover:text-purple-600 hover:border-purple-200 hover:bg-purple-50 transition-all font-medium text-sm shadow-sm">
              <Tag size={16} />
              % Discount
            </button>
            <button className="flex-1 flex items-center justify-center gap-2 py-3 bg-white rounded-xl border border-slate-200 text-slate-600 hover:text-purple-600 hover:border-purple-200 hover:bg-purple-50 transition-all font-medium text-sm shadow-sm">
              <StickyNote size={16} />
              Add Note
            </button>
          </div>

          {/* MAIN PAYMENT BUTTONS */}
          <div className="flex-1 flex flex-col gap-3">
            <button className="flex items-center justify-between bg-[#7c3aed] text-white p-5 rounded-2xl hover:bg-purple-600 transition-colors shadow-sm shadow-purple-500/20 active:scale-[0.98]">
              <div className="flex items-center gap-4">
                <div className="bg-white/20 p-2.5 rounded-xl">
                  <Banknote size={24} className="text-white" />
                </div>
                <span className="text-xl font-bold tracking-wide">CASH</span>
              </div>
              <div className="flex items-center gap-3 text-purple-200">
                <span className="font-mono text-sm">Alt+1</span>
              </div>
            </button>

            <button className="flex items-center justify-between bg-slate-800 text-white p-5 rounded-2xl hover:bg-slate-700 transition-colors shadow-sm shadow-slate-800/10 active:scale-[0.98]">
              <div className="flex items-center gap-4">
                <div className="bg-white/10 p-2.5 rounded-xl">
                  <CreditCard size={24} className="text-white" />
                </div>
                <span className="text-xl font-bold tracking-wide">CARD</span>
              </div>
              <div className="flex items-center gap-3 text-slate-400">
                <span className="font-mono text-sm">Alt+2</span>
              </div>
            </button>

            <button className="flex-1 flex items-center justify-center gap-3 bg-emerald-500 text-white p-5 rounded-2xl hover:bg-emerald-600 transition-colors shadow-md shadow-emerald-500/20 mt-2 active:scale-[0.98]">
              <span className="text-2xl font-bold tracking-wide">PAY</span>
              <kbd className="hidden sm:inline-block px-2 py-1 bg-white/20 rounded text-xs font-mono font-medium">F8</kbd>
            </button>
          </div>

          {/* SECONDARY ACTIONS */}
          <div className="grid grid-cols-3 gap-2 mt-2">
            <button className="flex flex-col items-center justify-center gap-1.5 py-3 bg-slate-200/50 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-200 transition-all font-medium text-xs">
              <XCircle size={18} />
              <span>Clear</span>
              <span className="text-[10px] font-mono opacity-60">F4</span>
            </button>
            <button className="flex flex-col items-center justify-center gap-1.5 py-3 bg-slate-200/50 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-200 transition-all font-medium text-xs">
              <Pause size={18} />
              <span>Hold</span>
              <span className="text-[10px] font-mono opacity-60">F5</span>
            </button>
            <button className="flex flex-col items-center justify-center gap-1.5 py-3 bg-slate-200/50 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-200 transition-all font-medium text-xs">
              <RotateCcw size={18} />
              <span>Recall</span>
            </button>
          </div>

        </div>

      </main>
    </div>
  );
}
