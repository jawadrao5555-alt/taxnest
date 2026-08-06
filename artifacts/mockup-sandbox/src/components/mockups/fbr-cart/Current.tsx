import { BLUE, Header, EmptyState, Footer } from "./_shared/CartParts";

// FBR POS cart — CURRENT look (blue accents), classes 1:1 from fbr-pos/universal.blade.php
export function Current() {
  return (
    <div className="h-screen w-full bg-white flex flex-col font-sans">
      <Header a={BLUE} />
      <EmptyState a={BLUE} />
      <Footer a={BLUE} empty total="0" subtotal="0" />
    </div>
  );
}
