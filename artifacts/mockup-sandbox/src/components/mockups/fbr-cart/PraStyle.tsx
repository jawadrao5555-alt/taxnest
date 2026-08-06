import { PURPLE, Header, EmptyState, Footer } from "./_shared/CartParts";

// FBR POS cart — PRA POS design (purple accents), matching the PRA screenshot
export function PraStyle() {
  return (
    <div className="h-screen w-full bg-white flex flex-col font-sans">
      <Header a={PURPLE} />
      <EmptyState a={PURPLE} />
      <Footer a={PURPLE} empty total="0" subtotal="0" />
    </div>
  );
}
