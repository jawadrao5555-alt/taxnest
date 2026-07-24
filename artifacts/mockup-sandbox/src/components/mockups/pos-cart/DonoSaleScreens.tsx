import { PooriSaleScreen } from "./PooriSaleScreen";
import { SaafSaleScreen } from "./SaafSaleScreen";

export function DonoSaleScreens() {
  return (
    <div className="flex w-[2740px]">
      <div className="w-[1366px] shrink-0 relative">
        <PooriSaleScreen />
        <div className="absolute top-14 left-1/2 -translate-x-1/2 z-50 rounded-full bg-black text-white text-[12px] font-extrabold px-4 py-1 shadow-md tracking-wide">
          FULL STYLE — poora wala
        </div>
      </div>
      <div className="w-2 shrink-0 bg-gray-400" />
      <div className="w-[1366px] shrink-0 relative">
        <SaafSaleScreen />
        <div
          className="absolute top-14 left-1/2 -translate-x-1/2 z-50 rounded-full text-white text-[12px] font-extrabold px-4 py-1 shadow-md tracking-wide"
          style={{ background: "#0A4D5C" }}
        >
          SAAF STYLE — saada ki jagah
        </div>
      </div>
    </div>
  );
}
