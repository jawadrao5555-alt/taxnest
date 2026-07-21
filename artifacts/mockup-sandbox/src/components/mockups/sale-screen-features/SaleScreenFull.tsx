import React from "react";
import { Search, Settings, Receipt, LogOut, Store, CreditCard, Banknote, Coffee, Drumstick, Utensils, UtensilsCrossed, Package, Star, HandPlatter, ShoppingBag, Bike, Users, Clock, AlignLeft, Tag, QrCode, MapPin } from "lucide-react";

export function SaleScreenFull() {
  return (
    <div className="flex flex-col h-screen bg-neutral-100 font-sans text-neutral-900">
      {/* Top Nav */}
      <header className="flex-none flex items-center justify-between px-4 h-14 bg-purple-900 text-white shadow-sm z-10">
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
          
          {/* Order Types */}
          <div className="bg-neutral-100 p-2 flex gap-2 border-b border-neutral-200">
            <button className="flex-1 py-2 flex items-center justify-center gap-2 rounded-md font-semibold text-neutral-600 hover:bg-neutral-200 transition-colors">
              <ShoppingBag className="w-4 h-4" />
              Takeaway
            </button>
            <button className="flex-1 py-2 flex items-center justify-center gap-2 rounded-md bg-white border border-purple-600 text-purple-700 font-bold shadow-sm ring-1 ring-purple-600/20">
              <UtensilsCrossed className="w-4 h-4" />
              Dine-In
              <span className="ml-1 px-1.5 py-0.5 rounded text-xs bg-purple-100 text-purple-800">Table 5</span>
            </button>
            <button className="flex-1 py-2 flex items-center justify-center gap-2 rounded-md font-semibold text-neutral-600 hover:bg-neutral-200 transition-colors">
              <Bike className="w-4 h-4" />
              Delivery
            </button>
          </div>

          <div className="p-3 border-b border-neutral-100 flex gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
              <input 
                type="text" 
                placeholder="Search (F1)..." 
                className="w-full h-10 pl-9 pr-4 bg-neutral-100 border-none rounded-md text-neutral-900 placeholder-neutral-500 focus:ring-2 focus:ring-purple-600 outline-none text-sm"
              />
            </div>
          </div>
          
          {/* Category Tabs */}
          <div className="px-3 pt-3 flex gap-2 overflow-x-auto pb-2 hide-scrollbar">
            <button className="px-4 py-1.5 rounded-full bg-purple-700 text-white font-medium text-sm whitespace-nowrap shadow-sm">All</button>
            <button className="px-4 py-1.5 rounded-full bg-neutral-100 text-neutral-700 font-medium text-sm hover:bg-neutral-200 whitespace-nowrap">BBQ</button>
            <button className="px-4 py-1.5 rounded-full bg-neutral-100 text-neutral-700 font-medium text-sm hover:bg-neutral-200 whitespace-nowrap">Rice</button>
            <button className="px-4 py-1.5 rounded-full bg-neutral-100 text-neutral-700 font-medium text-sm hover:bg-neutral-200 whitespace-nowrap">Drinks</button>
            <button className="px-4 py-1.5 rounded-full bg-teal-50 text-teal-700 border border-teal-200 font-medium text-sm hover:bg-teal-100 whitespace-nowrap flex items-center gap-1">
              <Star className="w-3.5 h-3.5" />
              Deals
            </button>
          </div>

          <div className="flex-1 overflow-y-auto p-3">
            <div className="grid grid-cols-3 xl:grid-cols-4 gap-3">
              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-orange-50 flex items-center justify-center text-orange-400 group-hover:bg-orange-100 transition-colors">
                   <Drumstick className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Chicken Tikka</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 450</span>
                </div>
              </button>
              
              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-red-50 flex items-center justify-center text-red-400 group-hover:bg-red-100 transition-colors">
                   <Drumstick className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Seekh Kabab</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 300</span>
                </div>
              </button>

              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-yellow-50 flex items-center justify-center text-yellow-500 group-hover:bg-yellow-100 transition-colors">
                   <Package className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Naan</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 40</span>
                </div>
              </button>

              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-amber-50 flex items-center justify-center text-amber-500 group-hover:bg-amber-100 transition-colors">
                   <Utensils className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Biryani Single</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 350</span>
                </div>
              </button>

              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="absolute top-0 right-0 bg-teal-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-bl z-10">DEAL</div>
                <div className="h-14 bg-teal-50 flex items-center justify-center text-teal-500 group-hover:bg-teal-100 transition-colors">
                   <Star className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Deal 1</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 999</span>
                </div>
              </button>

              <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-neutral-100 flex items-center justify-center text-neutral-400 group-hover:bg-neutral-200 transition-colors">
                   <Coffee className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Pepsi 500ml</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 120</span>
                </div>
              </button>

               <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-teal-50 flex items-center justify-center text-teal-500 group-hover:bg-teal-100 transition-colors">
                   <Coffee className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Mineral Water</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 60</span>
                </div>
              </button>

               <button className="relative rounded-md border border-neutral-200 bg-white hover:border-purple-600 hover:shadow-sm text-left transition-all active:scale-95 overflow-hidden flex flex-col h-32 group">
                <div className="h-14 bg-amber-100 flex items-center justify-center text-amber-600 group-hover:bg-amber-200 transition-colors">
                   <Coffee className="w-6 h-6" />
                </div>
                <div className="p-2 flex flex-col justify-between flex-1">
                  <span className="font-semibold text-sm text-neutral-800 leading-tight">Chai</span>
                  <span className="text-purple-700 font-bold text-sm">Rs 80</span>
                </div>
              </button>
            </div>
          </div>
        </section>

        {/* Right: Cart */}
        <aside className="w-[420px] flex-none bg-neutral-50 flex flex-col border-l border-neutral-200">
          
          {/* Customer Field */}
          <div className="p-3 border-b border-neutral-200 bg-white">
            <div className="flex items-center gap-2 bg-neutral-100 p-2 rounded-md border border-neutral-200">
              <Users className="w-4 h-4 text-neutral-500" />
              <div className="flex-1 text-sm font-semibold text-neutral-700">Bilal — 0300-1234567</div>
              <button className="text-purple-600 text-xs font-bold hover:underline">EDIT</button>
            </div>
            <div className="flex gap-2 mt-2">
               <button className="flex-1 flex items-center justify-center gap-1.5 py-1.5 bg-white border border-neutral-300 rounded text-xs font-bold text-neutral-600 hover:bg-neutral-50">
                  <Clock className="w-3.5 h-3.5" /> Hold
               </button>
               <button className="flex-1 flex items-center justify-center gap-1.5 py-1.5 bg-orange-50 border border-orange-200 rounded text-xs font-bold text-orange-700 hover:bg-orange-100">
                  <HandPlatter className="w-3.5 h-3.5" /> KOT
               </button>
            </div>
          </div>

          {/* Cart Items */}
          <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-4">
            <div className="flex items-start justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900 flex items-center gap-2">
                   Chicken Tikka
                   <span className="px-1 py-0.5 bg-orange-100 text-orange-800 rounded text-[10px] font-bold">KOT</span>
                </div>
                <div className="text-sm text-neutral-500 mt-0.5">2 x Rs 450</div>
                <div className="text-xs text-neutral-400 mt-1 flex items-center gap-1"><AlignLeft className="w-3 h-3"/> Less spicy</div>
              </div>
              <div className="font-bold text-neutral-900 mt-0.5">Rs 900</div>
            </div>
            <div className="flex items-start justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900">Naan</div>
                <div className="text-sm text-neutral-500 mt-0.5">4 x Rs 40</div>
              </div>
              <div className="font-bold text-neutral-900 mt-0.5">Rs 160</div>
            </div>
            <div className="flex items-start justify-between group">
              <div className="flex-1">
                <div className="font-semibold text-neutral-900">Pepsi 500ml</div>
                <div className="text-sm text-neutral-500 mt-0.5">4 x Rs 120</div>
              </div>
              <div className="font-bold text-neutral-900 mt-0.5">Rs 480</div>
            </div>
          </div>

          {/* Cart Footer */}
          <div className="bg-white border-t border-neutral-200 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
            <div className="p-4 pb-2">
               <div className="flex items-center justify-between text-sm text-neutral-600 mb-1">
                 <span>Items: 10</span>
                 <span>Rs 1,540</span>
               </div>
               <div className="flex items-center justify-between text-sm text-teal-600 font-medium mb-2 pb-2 border-b border-neutral-100">
                 <span className="flex items-center gap-1"><Tag className="w-3.5 h-3.5"/> Discount (0%)</span>
                 <span>- Rs 0</span>
               </div>
               <div className="flex items-center justify-between mb-4">
                 <span className="text-lg font-semibold text-neutral-900">Total</span>
                 <span className="text-3xl font-bold text-purple-700">Rs 1,540</span>
               </div>
               
               <div className="grid grid-cols-2 gap-2 mb-3">
                 <button className="flex items-center justify-center gap-2 h-12 rounded-md border-2 border-purple-600 bg-purple-50 text-purple-800 font-bold transition-colors active:bg-purple-100">
                   <Banknote className="w-4 h-4" />
                   Cash
                 </button>
                 <button className="flex items-center justify-center gap-2 h-12 rounded-md border-2 border-neutral-200 bg-white text-neutral-600 font-bold hover:border-neutral-300 hover:bg-neutral-50 transition-colors active:bg-neutral-100">
                   <CreditCard className="w-4 h-4" />
                   Card
                 </button>
               </div>

               <button className="w-full h-14 rounded-md bg-purple-700 hover:bg-purple-800 text-white text-lg font-bold transition-all active:scale-[0.98] shadow-sm flex items-center justify-center gap-2">
                 Pay — Rs 1,540
               </button>
            </div>
            
            {/* F-Key Hint Bar */}
            <div className="bg-neutral-100 px-2 py-1.5 border-t border-neutral-200 flex items-center justify-between overflow-x-auto text-[10px] font-medium text-neutral-500 whitespace-nowrap hide-scrollbar">
               <span className="flex items-center gap-1"><kbd className="bg-neutral-200 px-1 rounded font-mono text-neutral-700">F3</kbd> Table</span>
               <span className="flex items-center gap-1"><kbd className="bg-neutral-200 px-1 rounded font-mono text-neutral-700">F6</kbd> Waiter</span>
               <span className="flex items-center gap-1"><kbd className="bg-neutral-200 px-1 rounded font-mono text-neutral-700">F8</kbd> <QrCode className="w-3 h-3"/> Menu</span>
               <span className="flex items-center gap-1"><kbd className="bg-neutral-200 px-1 rounded font-mono text-neutral-700">F10</kbd> <MapPin className="w-3 h-3"/></span>
               <span className="flex items-center gap-1"><kbd className="bg-neutral-200 px-1 rounded font-mono text-neutral-700">T/D/N</kbd> Types</span>
            </div>
          </div>
        </aside>
      </main>
    </div>
  );
}
