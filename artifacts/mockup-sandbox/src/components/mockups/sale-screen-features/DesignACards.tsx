import React from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import { 
  Lock, 
  Utensils, 
  Receipt, 
  Keyboard, 
  MonitorPlay, 
  Check, 
  ShoppingBag, 
  Settings, 
  LayoutDashboard, 
  FileText, 
  PieChart, 
  Store,
  ChevronDown,
  Bell
} from "lucide-react";

export function DesignACards() {
  return (
    <div className="min-h-screen bg-gray-50 flex flex-col font-sans">
      {/* Navigation Bar */}
      <nav className="bg-indigo-950 text-white px-4 py-3 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div className="flex items-center gap-8">
          <div className="flex items-center gap-2">
            <div className="bg-white text-indigo-950 p-1.5 rounded flex items-center justify-center">
              <Store className="w-5 h-5" />
            </div>
            <span className="font-bold text-xl tracking-tight">NestPOS</span>
          </div>
          
          <div className="hidden md:flex items-center gap-1 text-sm font-medium">
            <a href="#" className="px-3 py-2 text-indigo-100 hover:bg-indigo-900 rounded-md transition-colors flex items-center gap-2">
              <LayoutDashboard className="w-4 h-4" /> Dashboard
            </a>
            <a href="#" className="px-3 py-2 text-indigo-100 hover:bg-indigo-900 rounded-md transition-colors flex items-center gap-2">
              <ShoppingBag className="w-4 h-4" /> Sale
            </a>
            <a href="#" className="px-3 py-2 text-indigo-100 hover:bg-indigo-900 rounded-md transition-colors flex items-center gap-2">
              <Receipt className="w-4 h-4" /> Bills
            </a>
            <a href="#" className="px-3 py-2 text-indigo-100 hover:bg-indigo-900 rounded-md transition-colors flex items-center gap-2">
              <PieChart className="w-4 h-4" /> Reports
            </a>
            <a href="#" className="px-3 py-2 bg-indigo-900 text-white rounded-md transition-colors flex items-center gap-2">
              <Settings className="w-4 h-4" /> Settings
            </a>
          </div>
        </div>
        
        <div className="flex items-center gap-4">
          <button className="text-indigo-100 hover:text-white relative">
            <Bell className="w-5 h-5" />
            <span className="absolute -top-1 -right-1 w-2.5 h-2.5 bg-red-500 rounded-full border border-indigo-950"></span>
          </button>
          <div className="flex items-center gap-2 bg-indigo-900 px-3 py-1.5 rounded-full cursor-pointer hover:bg-indigo-800 transition-colors">
            <div className="w-7 h-7 bg-purple-600 rounded-full flex items-center justify-center text-xs font-bold">
              AT
            </div>
            <span className="text-sm font-medium">Ali Traders</span>
            <ChevronDown className="w-4 h-4 text-indigo-300" />
          </div>
        </div>
      </nav>

      {/* Main Content */}
      <main className="flex-1 max-w-6xl w-full mx-auto p-4 md:p-6 lg:p-8">
        
        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 mb-1 tracking-tight">Sale Screen Features</h1>
            <p className="text-gray-500 font-medium">Sale screen par kya nazar aaye — yahan se control karein.</p>
          </div>
          <Button className="bg-purple-600 hover:bg-purple-700 text-white shadow-sm font-medium px-6 py-2 h-auto text-base">
            <Check className="w-4 h-4 mr-2" /> Save Changes
          </Button>
        </div>

        {/* Presets Row */}
        <div className="mb-8">
          <div className="flex items-center gap-2 mb-3">
            <h2 className="text-sm font-bold text-gray-700 uppercase tracking-wider">Quick Presets</h2>
            <span className="text-xs text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full font-medium">Ek click mein poora set lagayen</span>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="bg-purple-50 border-2 border-purple-600 rounded-xl p-4 cursor-pointer shadow-sm relative overflow-hidden transition-all">
              <div className="absolute top-0 right-0 bg-purple-600 text-white text-[10px] font-bold px-2 py-1 rounded-bl-lg uppercase tracking-wider">Active</div>
              <h3 className="font-bold text-purple-900 text-lg">Saada Dukaan</h3>
              <p className="text-sm text-purple-700 mt-1 font-medium">Basic retail setup. Quick billing, no kitchen.</p>
            </div>
            <div className="bg-white border border-gray-200 hover:border-gray-300 hover:shadow-sm rounded-xl p-4 cursor-pointer transition-all">
              <h3 className="font-bold text-gray-900 text-lg">Cafe</h3>
              <p className="text-sm text-gray-500 mt-1 font-medium">Takeaway & Dine-in. KOT printing enabled.</p>
            </div>
            <div className="bg-white border border-gray-200 hover:border-gray-300 hover:shadow-sm rounded-xl p-4 cursor-pointer transition-all">
              <h3 className="font-bold text-gray-900 text-lg">Restaurant</h3>
              <p className="text-sm text-gray-500 mt-1 font-medium">Full setup. Waiter tablets, KDS, all order types.</p>
            </div>
          </div>
        </div>

        {/* Switchboard Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
          
          {/* Order Types */}
          <Card className="shadow-sm border-gray-200">
            <CardHeader className="pb-3 border-b border-gray-100 bg-gray-50/50">
              <div className="flex items-center gap-3">
                <div className="bg-indigo-100 text-indigo-700 p-2 rounded-lg">
                  <ShoppingBag className="w-5 h-5" />
                </div>
                <div>
                  <CardTitle className="text-lg">Order Types</CardTitle>
                  <CardDescription>Kis kisam ke orders lena chahte hain?</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="divide-y divide-gray-100">
                <ToggleRow 
                  title="Takeaway" 
                  description="Customer khud aakar le jaye" 
                  checked={true} 
                />
                <ToggleRow 
                  title="Dine-In" 
                  description="Tables par khana serve karein" 
                  checked={false} 
                  locked={true} 
                />
                <ToggleRow 
                  title="Delivery" 
                  description="Ghar par deliver karein" 
                  checked={true} 
                />
              </div>
            </CardContent>
          </Card>

          {/* Kitchen */}
          <Card className="shadow-sm border-gray-200">
            <CardHeader className="pb-3 border-b border-gray-100 bg-gray-50/50">
              <div className="flex items-center gap-3">
                <div className="bg-orange-100 text-orange-700 p-2 rounded-lg">
                  <Utensils className="w-5 h-5" />
                </div>
                <div>
                  <CardTitle className="text-lg">Kitchen</CardTitle>
                  <CardDescription>Kitchen operations aur order routing</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="divide-y divide-gray-100">
                <ToggleRow 
                  title="KOT Printing" 
                  description="Kitchen mein order parchi print karein" 
                  checked={false} 
                  locked={true} 
                />
                <ToggleRow 
                  title="Kitchen Display / KDS" 
                  description="Kitchen mein screen par orders dekhein" 
                  checked={false} 
                  locked={true} 
                />
                <ToggleRow 
                  title="Waiter Tablets" 
                  description="Waiters table se order lein" 
                  checked={false} 
                  locked={true} 
                />
              </div>
            </CardContent>
          </Card>

          {/* Bill Parts */}
          <Card className="shadow-sm border-gray-200">
            <CardHeader className="pb-3 border-b border-gray-100 bg-gray-50/50">
              <div className="flex items-center gap-3">
                <div className="bg-emerald-100 text-emerald-700 p-2 rounded-lg">
                  <FileText className="w-5 h-5" />
                </div>
                <div>
                  <CardTitle className="text-lg">Bill Parts</CardTitle>
                  <CardDescription>Bill banate waqt kya options aayein</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="divide-y divide-gray-100">
                <ToggleRow 
                  title="Customer Name Field" 
                  description="Bill par customer ka naam likhein" 
                  checked={true} 
                />
                <ToggleRow 
                  title="Bill Notes" 
                  description="Bill par extra hidayat likhein" 
                  checked={true} 
                />
                <ToggleRow 
                  title="Discount Button" 
                  description="Bill par discount dene ka option" 
                  checked={true} 
                />
                <ToggleRow 
                  title="Hold Bill" 
                  description="Adha bana bill rok kar naya shuru karein" 
                  checked={false} 
                />
                <ToggleRow 
                  title="Provisional / Local Billing" 
                  description="Kacha bill print karne ka option" 
                  checked={true} 
                />
              </div>
            </CardContent>
          </Card>

          <div className="flex flex-col gap-6">
            {/* Typing Speed */}
            <Card className="shadow-sm border-gray-200">
              <CardHeader className="pb-3 border-b border-gray-100 bg-gray-50/50">
                <div className="flex items-center gap-3">
                  <div className="bg-teal-100 text-teal-700 p-2 rounded-lg">
                    <Keyboard className="w-5 h-5" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">Typing Speed</CardTitle>
                    <CardDescription>Keyboard shortcuts aur speed options</CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                <div className="divide-y divide-gray-100">
                  <ToggleRow 
                    title="Guided Keyboard Flow" 
                    description="Enter dabane se agle field mein jaye" 
                    checked={true} 
                  />
                  <ToggleRow 
                    title="Plain Letter Shortcuts T/D/N" 
                    description="Sirf huruf dabane se action ho" 
                    checked={true} 
                  />
                  <ToggleRow 
                    title="F-Key Shortcuts" 
                    description="F1-F12 buttons se functions chalayein" 
                    checked={true} 
                  />
                </div>
              </CardContent>
            </Card>

            {/* Nazara (Display) */}
            <Card className="shadow-sm border-gray-200">
              <CardHeader className="pb-3 border-b border-gray-100 bg-gray-50/50">
                <div className="flex items-center gap-3">
                  <div className="bg-pink-100 text-pink-700 p-2 rounded-lg">
                    <MonitorPlay className="w-5 h-5" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">Nazara (Display)</CardTitle>
                    <CardDescription>Sale screen kaisa dikhai de</CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                <div className="divide-y divide-gray-100">
                  <ToggleRow 
                    title="Product Grid" 
                    description="Items ko dabbon ki shakal mein dekhein" 
                    checked={true} 
                  />
                  <ToggleRow 
                    title="Category Tabs" 
                    description="Categories ke alag tabs banayein" 
                    checked={true} 
                  />
                  <ToggleRow 
                    title="Product Images" 
                    description="Items ki tasweerein show karein" 
                    checked={false} 
                  />
                  <ToggleRow 
                    title="Screen Fit Zoom" 
                    description="Choti screen par hisab se fit karein" 
                    checked={true} 
                  />
                  <ToggleRow 
                    title="Receipt Popup" 
                    description="Save hone par bill screen par aaye" 
                    checked={true} 
                  />
                </div>
              </CardContent>
            </Card>
          </div>

        </div>
      </main>
      
      <style dangerouslySetInnerHTML={{__html: `
        /* Override default switch color to purple */
        [data-state=checked].bg-primary {
          background-color: #9333ea !important; /* purple-600 */
        }
      `}} />
    </div>
  );
}

function ToggleRow({ 
  title, 
  description, 
  checked, 
  locked = false 
}: { 
  title: string, 
  description: string, 
  checked: boolean, 
  locked?: boolean 
}) {
  return (
    <div className={`p-4 flex items-center justify-between gap-4 transition-colors hover:bg-gray-50/50 ${locked ? 'bg-gray-50/30' : ''}`}>
      <div className="flex-1">
        <div className="flex items-center gap-2 mb-1">
          <label className={`font-semibold text-base ${locked ? 'text-gray-400' : 'text-gray-900'}`}>
            {title}
          </label>
          {locked && (
            <Badge variant="outline" className="bg-gray-100 text-gray-500 border-gray-200 font-semibold gap-1 text-[10px] px-1.5 py-0">
              <Lock className="w-3 h-3" /> Pro Plan
            </Badge>
          )}
        </div>
        <p className={`text-sm ${locked ? 'text-gray-400' : 'text-gray-500'}`}>
          {description}
        </p>
      </div>
      <div>
        <Switch 
          checked={checked} 
          disabled={locked}
          className={`${locked ? 'opacity-50' : ''}`}
        />
      </div>
    </div>
  );
}
