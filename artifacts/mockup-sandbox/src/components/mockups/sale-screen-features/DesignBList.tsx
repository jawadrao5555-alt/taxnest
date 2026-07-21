import React, { useState } from "react";
import {
  Store,
  Coffee,
  UtensilsCrossed,
  ShoppingBag,
  ChefHat,
  ReceiptText,
  Keyboard,
  MonitorPlay,
  Lock,
  Search,
  Bell,
  Settings,
  ChevronDown,
} from "lucide-react";

export function DesignBList() {
  const [activePreset, setActivePreset] = useState("Saada Dukaan");

  return (
    <div className="min-h-screen bg-gray-50 font-sans text-gray-900 pb-24">
      {/* Top Navigation */}
      <nav className="bg-indigo-950 text-white px-4 h-14 flex items-center justify-between sticky top-0 z-50 shadow-sm">
        <div className="flex items-center gap-6">
          <div className="flex items-center gap-2 font-bold text-xl tracking-tight">
            <div className="w-8 h-8 bg-purple-600 rounded flex items-center justify-center">
              <Store className="w-5 h-5 text-white" />
            </div>
            NestPOS
          </div>
          <div className="hidden md:flex items-center gap-1">
            {["Dashboard", "Sale", "Bills", "Reports", "Settings"].map((item) => (
              <button
                key={item}
                className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  item === "Settings"
                    ? "bg-indigo-900 text-white"
                    : "text-indigo-200 hover:text-white hover:bg-indigo-900"
                }`}
              >
                {item}
              </button>
            ))}
          </div>
        </div>
        <div className="flex items-center gap-4">
          <button className="text-indigo-200 hover:text-white">
            <Search className="w-5 h-5" />
          </button>
          <button className="text-indigo-200 hover:text-white">
            <Bell className="w-5 h-5" />
          </button>
          <div className="flex items-center gap-2 pl-4 border-l border-indigo-800 cursor-pointer">
            <div className="w-8 h-8 bg-purple-700 rounded-full flex items-center justify-center text-sm font-bold">
              AT
            </div>
            <div className="hidden sm:block text-sm">
              <div className="font-semibold leading-none">Ali Traders</div>
              <div className="text-indigo-300 text-xs">Admin</div>
            </div>
            <ChevronDown className="w-4 h-4 text-indigo-300" />
          </div>
        </div>
      </nav>

      <main className="max-w-3xl mx-auto px-4 sm:px-6 mt-8">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 tracking-tight">Sale Screen Features</h1>
            <p className="text-gray-500 mt-1">Sale screen par kya nazar aaye — yahan se control karein</p>
          </div>
          <button className="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2.5 rounded-md font-medium shadow-sm transition-colors whitespace-nowrap">
            Save Changes
          </button>
        </div>

        {/* Presets Row */}
        <div className="mb-8">
          <h2 className="text-sm font-bold text-gray-900 uppercase tracking-wider mb-1">Presets</h2>
          <p className="text-sm text-gray-500 mb-3">Ek click mein poora set lagayen</p>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {[
              { id: "Saada Dukaan", icon: Store },
              { id: "Cafe", icon: Coffee },
              { id: "Restaurant", icon: UtensilsCrossed },
            ].map((preset) => (
              <button
                key={preset.id}
                onClick={() => setActivePreset(preset.id)}
                className={`flex items-center justify-center gap-2 p-3 rounded-lg border text-sm font-medium transition-all ${
                  activePreset === preset.id
                    ? "border-purple-600 bg-purple-50 text-purple-700 shadow-sm"
                    : "border-gray-200 bg-white text-gray-600 hover:border-purple-300 hover:bg-purple-50"
                }`}
              >
                <preset.icon className={`w-4 h-4 ${activePreset === preset.id ? "text-purple-600" : "text-gray-400"}`} />
                {preset.id}
              </button>
            ))}
          </div>
        </div>

        {/* Jump Links */}
        <div className="flex overflow-x-auto pb-2 mb-6 -mx-4 px-4 sm:mx-0 sm:px-0 scrollbar-hide gap-2">
          {["Order Types", "Kitchen", "Bill Parts", "Typing Speed", "Nazara (Display)"].map((link) => (
            <a
              key={link}
              href={`#${link.replace(/\s+/g, "-").toLowerCase()}`}
              className="whitespace-nowrap px-4 py-2 rounded-full bg-white border border-gray-200 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900 shadow-sm"
            >
              {link}
            </a>
          ))}
        </div>

        {/* Settings List */}
        <div className="space-y-8">
          <SettingsGroup
            id="order-types"
            title="Order Types"
            icon={ShoppingBag}
            items={[
              { label: "Takeaway", hint: "Customer khud pick kare", defaultOn: true },
              { label: "Dine-In", hint: "Table par baith kar khayein", defaultOn: false, locked: true },
              { label: "Delivery", hint: "Ghar par bhejein", defaultOn: true },
            ]}
          />

          <SettingsGroup
            id="kitchen"
            title="Kitchen"
            icon={ChefHat}
            items={[
              { label: "KOT Printing", hint: "Kitchen mein order parchi", defaultOn: false, locked: true },
              { label: "Kitchen Display / KDS", hint: "Kitchen screen par orders", defaultOn: false, locked: true },
              { label: "Waiter Tablets", hint: "Waiter table se order lein", defaultOn: false, locked: true },
            ]}
          />

          <SettingsGroup
            id="bill-parts"
            title="Bill Parts"
            icon={ReceiptText}
            items={[
              { label: "Customer Name Field", hint: "Bill par customer ka naam", defaultOn: true },
              { label: "Bill Notes", hint: "Extra instructions likhein", defaultOn: true },
              { label: "Discount Button", hint: "Bill par discount lagayen", defaultOn: true },
              { label: "Hold Bill", hint: "Kacha bill park karein", defaultOn: false },
              { label: "Provisional / Local Billing", hint: "Kacha bill nikalen", defaultOn: true },
            ]}
          />

          <SettingsGroup
            id="typing-speed"
            title="Typing Speed"
            icon={Keyboard}
            items={[
              { label: "Guided Keyboard Flow", hint: "Next field auto focus", defaultOn: true },
              { label: "Plain Letter Shortcuts T/D/N", hint: "Type karke select karein", defaultOn: true },
              { label: "F-Key Shortcuts", hint: "F1, F2 button use karein", defaultOn: true },
            ]}
          />

          <SettingsGroup
            id="nazara-(display)"
            title="Nazara (Display)"
            icon={MonitorPlay}
            items={[
              { label: "Product Grid", hint: "Item boxes grid mein", defaultOn: true },
              { label: "Category Tabs", hint: "Categories ki alag tabs", defaultOn: true },
              { label: "Product Images", hint: "Items ki tasweer dikhayen", defaultOn: false },
              { label: "Screen Fit Zoom", hint: "Screen ke hisab se bara karein", defaultOn: true },
              { label: "Receipt Popup", hint: "Bill banne ke baad popup", defaultOn: true },
            ]}
          />
        </div>
      </main>
    </div>
  );
}

function SettingsGroup({ id, title, icon: Icon, items }: { id: string; title: string; icon: any; items: any[] }) {
  return (
    <section id={id} className="scroll-mt-24">
      <div className="flex items-center gap-2 mb-4 sticky top-14 bg-gray-50 py-3 z-40 border-b border-gray-200">
        <Icon className="w-5 h-5 text-purple-600" />
        <h2 className="text-lg font-bold text-gray-900">{title}</h2>
      </div>
      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        {items.map((item, index) => (
          <div
            key={item.label}
            className={`p-4 sm:p-5 flex items-center justify-between gap-4 ${
              index !== items.length - 1 ? "border-b border-gray-100" : ""
            } ${item.locked ? "bg-gray-50/50" : ""}`}
          >
            <div className="flex-1 pr-4">
              <div className="flex items-center gap-2">
                <span className={`font-semibold ${item.locked ? "text-gray-500" : "text-gray-900"}`}>
                  {item.label}
                </span>
                {item.locked && (
                  <span className="inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 border border-gray-200">
                    <Lock className="w-3 h-3" />
                    Pro Plan
                  </span>
                )}
              </div>
              <p className="text-sm text-gray-500 mt-1">{item.hint}</p>
            </div>
            <div>
              <Toggle defaultOn={item.defaultOn} locked={item.locked} />
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

function Toggle({ defaultOn, locked }: { defaultOn: boolean; locked?: boolean }) {
  const [isOn, setIsOn] = useState(defaultOn);

  if (locked) {
    return (
      <div className="relative inline-flex h-6 w-11 items-center rounded-full bg-gray-200 cursor-not-allowed opacity-60">
        <span className="inline-block h-5 w-5 transform rounded-full bg-white shadow translate-x-1" />
      </div>
    );
  }

  return (
    <button
      type="button"
      className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-600 focus:ring-offset-2 ${
        isOn ? "bg-purple-600" : "bg-gray-200"
      }`}
      role="switch"
      aria-checked={isOn}
      onClick={() => setIsOn(!isOn)}
    >
      <span
        aria-hidden="true"
        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
          isOn ? "translate-x-5" : "translate-x-0"
        }`}
      />
    </button>
  );
}
