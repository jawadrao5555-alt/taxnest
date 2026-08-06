import { PURPLE, Header, Footer, CartRow } from "./_shared/CartParts";

// FBR POS cart — PRA POS design (purple), with items in the cart
export function PraStyleFilled() {
  return (
    <div className="h-screen w-full bg-white flex flex-col font-sans">
      <Header a={PURPLE} withEdit />
      <div className="flex-1 min-h-0 overflow-y-auto">
        <CartRow name="Chicken Burger" price="450" qty="2" lineTotal="900" active />
        <CartRow name="Fries (Large)" price="250" qty="1" lineTotal="250" />
        <CartRow name="Cold Drink 500ml" price="120" qty="3" lineTotal="360" />
      </div>
      <Footer a={PURPLE} empty={false} total="1,510" subtotal="1,510" />
    </div>
  );
}
