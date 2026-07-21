import React from "react";
import { Search, Settings, Receipt, LogOut, CheckCircle, Store, CreditCard, Banknote } from "lucide-react";

export function SaleScreenSaada() {
  return (
    <div className="flex flex-col h-screen bg-neutral-50 font-sans text-neutral-900">
      {/* Top Nav */}
      <header className="flex-none flex items-center justify-between px-4 h-14 bg-purple-900 text-white">
        <div className="flex items-center gap-6">
          <div className="flex items-center gap-2 font-bold text-lg tracking-tight">
            <Store className="w-5 h-5 text-teal-400" />
            <span>NestPOS</span>
          </div>
          <nav className="hidden md:flex items-center gap-1">
            <button className="px-3 py-1.5 rounded-md bg-purple-800 text-white font-medium text-sm">Sale</button>
            <button className="px-3 py-1.5 rounded-md hover:bg-purple-800/50 text-purple-200 transition-colors text-sm font-medium">Bills</button>
            <button className="px-3 py-1.5 rounded-md hover:bg-purple-800/50 text-purple-200 transition-colors text-sm font-medium">Reports</button>
          </nav>
        </div>
        <div className="flex items-center gap-4">
          <button className="p-2 hover:bg-purple-800 rounded-md transition-colors text-purple-200"><Settings className="w-5 h-5" /></button>
          <div className="flex items-center gap-2 pl-4 border-l border-purple-800">
            <div className="w-8 h-8 rounded bg-purple-700 flex items-center justify-center font-bold text-sm">AT</div>
            <div className="text-sm font-medium">Ali Traders</div>
            <button className="p-2 hover:bg-purple-800 rounded-md transition-colors text-purple-200 ml-2"><LogOut className="w-4 h-4" /></button>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 flex overflow-hidden">
        {/* Left: Products */}
        <section className="flex-1 flex flex-col min-w-0 border-r border-neutral-200 bg-white">
          <div className="p-4 border-b border-neutral-100">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400" />
              <input 
                type="text" 
                placeholder="Search products..." 
                className="w-full h-12 pl-10 pr-4 bg-neutral-100 border-none rounded-md text-neutral-900 placeholder-neutral-500 focus:ring-2 focus:ring-purple-600 outline-none text-lg"
              />
            </div>
          </div>
          
          <div className="flex-1 overflow-y-auto p-4">
            <div className="grid grid-cols-3 xl:grid-cols-4 gap-3">
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Chicken Tikka</span>
                <span className="text-purple-700 font-bold mt-2">Rs 450</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Seekh Kabab</span>
                <span className="text-purple-700 font-bold mt-2">Rs 300</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Naan</span>
                <span className="text-purple-700 font-bold mt-2">Rs 40</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Biryani Single</span>
                <span className="text-purple-700 font-bold mt-2">Rs 350</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Pepsi 500ml</span>
                <span className="text-purple-700 font-bold mt-2">Rs 120</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Mineral Water</span>
                <span className="text-purple-700 font-bold mt-2">Rs 60</span>
              </button>
              <button className="p-4 rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 flex flex-col justify-between h-24">
                <span className="font-semibold text-neutral-800 leading-tight">Chai</span>
                <span className="text-purple-700 font-bold mt-2">Rs 80</span>
              </button>
            </div>
          </div>
        </section>

        {/* Right: Cart */}
        <aside className="w-[400px] flex-none bg-neutral-50 flex flex-col">
          {/* Info Strip */}
          <div className="bg-teal-50 px-4 py-2 flex items-center gap-2 border-b border-teal-100 text-teal-800 text-sm font-medium">
            <CheckCircle className="w-4 h-4 text-teal-600" />
            Saada mode ON — sirf zaroori cheezen
          </div>

          {/* Cart Items */}
          <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-3">
            <div className="flex items-center justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900">Chicken Tikka</div>
                <div className="text-sm text-neutral-500">2 x Rs 450</div>
              </div>
              <div className="font-bold text-neutral-900">Rs 900</div>
            </div>
            <div className="flex items-center justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900">Naan</div>
                <div className="text-sm text-neutral-500">4 x Rs 40</div>
              </div>
              <div className="font-bold text-neutral-900">Rs 160</div>
            </div>
            <div className="flex items-center justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900">Pepsi 500ml</div>
                <div className="text-sm text-neutral-500">4 x Rs 120</div>
              </div>
              <div className="font-bold text-neutral-900">Rs 480</div>
            </div>
          </div>

          {/* Cart Footer */}
          <div className="bg-white border-t border-neutral-200 p-4 pb-6 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
            <div className="flex items-center justify-between mb-4 text-neutral-600">
              <span>Items: 10</span>
              <span>Subtotal: Rs 1,540</span>
            </div>
            <div className="flex items-center justify-between mb-6 pb-4 border-b border-neutral-100">
              <span className="text-lg font-semibold text-neutral-900">Total</span>
              <span className="text-3xl font-bold text-purple-700">Rs 1,540</span>
            </div>
            
            <div className="grid grid-cols-2 gap-3 mb-4">
              <button className="flex items-center justify-center gap-2 h-14 rounded-md border-2 border-purple-600 bg-purple-50 text-purple-800 font-bold transition-colors active:bg-purple-100">
                <Banknote className="w-5 h-5" />
                Cash
              </button>
              <button className="flex items-center justify-center gap-2 h-14 rounded-md border-2 border-neutral-200 bg-white text-neutral-600 font-bold hover:border-neutral-300 hover:bg-neutral-50 transition-colors active:bg-neutral-100">
                <CreditCard className="w-5 h-5" />
                Card
              </button>
            </div>

            <button className="w-full h-16 rounded-md bg-purple-700 hover:bg-purple-800 text-white text-xl font-bold transition-all active:scale-[0.98] shadow-sm flex items-center justify-center gap-3">
              Pay — Rs 1,540
            </button>
          </div>
        </aside>
      </main>
    </div>
  );
}
