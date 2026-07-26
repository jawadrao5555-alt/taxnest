export function FinalConfirm() {
  return (
    <div className="min-h-screen bg-gray-900/85 flex items-center justify-center p-8 font-sans">
      <div className="flex items-center gap-10">
        <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
          <div className="p-5 text-center border-b border-gray-100">
            <p className="text-xs font-bold text-gray-400 uppercase tracking-wide">Bill Final hoga</p>
            <p className="text-2xl font-black text-gray-900 mt-1">T-5</p>
            <p className="text-lg font-extrabold text-green-600 mt-0.5">Rs 2,660</p>
            <p className="text-[11px] text-gray-500 mt-1">Pakka final karna hai? Payment ka tareeqa chunein:</p>
          </div>
          <div className="p-4 grid grid-cols-2 gap-3">
            <button className="py-4 rounded-xl text-center border-2 transition bg-green-50 border-green-200 hover:bg-green-100 hover:border-green-400">
              <p className="text-sm font-black text-green-700">CASH</p>
            </button>
            <button className="py-4 rounded-xl text-center border-2 transition bg-blue-50 border-blue-200 hover:bg-blue-100 hover:border-blue-400">
              <p className="text-sm font-black text-blue-700">CARD</p>
            </button>
          </div>
          <div className="px-4 pb-4">
            <button className="w-full py-2 rounded-xl text-xs font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 transition">
              Cancel — wapas jao
            </button>
          </div>
        </div>

        <div className="w-[400px] space-y-3">
          <h2 className="text-xl font-black text-white">FINAL se pehle — Confirm Step</h2>
          <p className="text-sm text-white/80 leading-relaxed">
            Action Menu mein <b>"FINAL karo"</b> dabane se bill foran final <b>nahin</b> hota —
            pehle yeh confirm box khulta hai jisme table number aur poori raqam bari
            bari nazar aati hai.
          </p>
          <div className="bg-white/10 border border-white/20 rounded-xl p-3 space-y-2">
            <p className="text-xs text-white/90 leading-relaxed">
              • <b>CASH ya CARD</b> chunte hi bill final ho kar PRA ko chala jata hai
              aur receipt print hoti hai.
            </p>
            <p className="text-xs text-white/90 leading-relaxed">
              • <b>Cancel</b> dabao to kuch nahin hota — order waisa hi chalta rehta hai.
            </p>
            <p className="text-xs text-white/90 leading-relaxed">
              • Agar do counter <b>ek hi waqt</b> pe same table final karne ki koshish karein,
              to sirf <b>ek</b> bill banta hai — doosre ko paighaam milta hai ke yeh order
              pehle hi final ho chuka hai. Double-bill ka khatra zero.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
