import type * as React from "react";

const paper: React.CSSProperties = {
  width: 302,
  background: "#fff",
  color: "#000",
  fontFamily: "Arial, Helvetica, sans-serif",
  fontWeight: 700,
  fontSize: 12.5,
  lineHeight: 1.35,
  padding: "10px 12px 14px",
  boxShadow: "0 2px 10px rgba(0,0,0,0.25)",
};

const dashed: React.CSSProperties = { borderTop: "1px dashed #000", margin: "6px 0" };

function Qr() {
  const cells: React.ReactNode[] = [];
  const on = [0,1,2,4,6,7,8,9,14,16,18,21,23,25,27,28,31,33,36,38,40,42,45,47,49,54,56,57,58,60,62,63];
  for (let i = 0; i < 64; i++) {
    cells.push(<div key={i} style={{ background: on.includes(i) ? "#000" : "#fff" }} />);
  }
  return (
    <div style={{ width: 72, height: 72, margin: "0 auto", border: "2px solid #000", padding: 3, display: "grid", gridTemplateColumns: "repeat(8,1fr)", gridTemplateRows: "repeat(8,1fr)", gap: 1 }}>
      {cells}
    </div>
  );
}

export function Before() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-start gap-3 py-8" style={{ background: "#3f4750" }}>
      <div style={{ color: "#fff", fontFamily: "Arial", fontWeight: 700, fontSize: 15, letterSpacing: 1 }}>
        PEHLE — lambi receipt, faaltu boxes
      </div>
      <div style={paper}>
        {/* Logo with gap below (old bug) */}
        <div style={{ textAlign: "center" }}>
          <div style={{ width: 62, height: 62, margin: "0 auto", borderRadius: "50%", border: "2px solid #000", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 30 }}>🍕</div>
        </div>
        <div style={{ height: 14 }} />{/* <-- khali gap logo ke neeche */}
        <div style={{ textAlign: "center", fontSize: 17, letterSpacing: 2.5 }}>PIZZA MASTER</div>
        <div style={{ textAlign: "center", fontSize: 11, marginTop: 4, fontWeight: 700 }}>
          Main Boulevard, Gulberg III, Lahore<br />Tel: 042-35771234
        </div>

        <div style={{ ...dashed, margin: "9px 0" }} />
        <table style={{ width: "100%", fontSize: 11.5 }}>
          <tbody>
            <tr><td>Bill #</td><td style={{ textAlign: "right" }}>L-014</td></tr>
            <tr><td>Date</td><td style={{ textAlign: "right" }}>21-Jul-2026 11:28 PM</td></tr>
            <tr><td>Cashier</td><td style={{ textAlign: "right" }}>Ahmed</td></tr>
          </tbody>
        </table>
        <div style={{ ...dashed, margin: "9px 0" }} />

        <table style={{ width: "100%", fontSize: 11.5 }}>
          <thead>
            <tr style={{ borderBottom: "1px solid #000" }}>
              <th style={{ textAlign: "left" }}>Item</th>
              <th style={{ textAlign: "center" }}>Qty</th>
              <th style={{ textAlign: "right" }}>Price</th>
              <th style={{ textAlign: "right" }}>Total</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Deal No. 1</td><td style={{ textAlign: "center" }}>1</td><td style={{ textAlign: "right" }}>1,150</td><td style={{ textAlign: "right" }}>1,150</td></tr>
            <tr><td>Zinger Burger</td><td style={{ textAlign: "center" }}>2</td><td style={{ textAlign: "right" }}>590</td><td style={{ textAlign: "right" }}>1,180</td></tr>
            <tr><td>Fries Large</td><td style={{ textAlign: "center" }}>1</td><td style={{ textAlign: "right" }}>350</td><td style={{ textAlign: "right" }}>350</td></tr>
          </tbody>
        </table>

        <div style={{ ...dashed, margin: "9px 0" }} />
        <table style={{ width: "100%", fontSize: 13 }}>
          <tbody>
            <tr><td style={{ fontSize: 15 }}>TOTAL</td><td style={{ textAlign: "right", fontSize: 15 }}>Rs 2,680</td></tr>
          </tbody>
        </table>

        {/* Old boxed PAYMENT (removed in new design) */}
        <div style={{ border: "2px solid #000", textAlign: "center", padding: "6px 4px", margin: "10px 0", letterSpacing: 2.5, fontSize: 12.5 }}>
          PAYMENT: CASH
        </div>

        <div style={{ ...dashed, margin: "9px 0" }} />

        {/* Old bottom SALE RECEIPT box */}
        <div style={{ border: "2px solid #000", textAlign: "center", padding: "7px 4px", margin: "10px 0" }}>
          <div style={{ letterSpacing: 2.5, fontSize: 13 }}>SALE RECEIPT</div>
          <div style={{ fontSize: 12, marginTop: 3 }}>L-014</div>
        </div>

        <div style={{ height: 8 }} />
        <Qr />
        <div style={{ textAlign: "center", fontSize: 11, marginTop: 10 }}>
          Shukriya! Phir Tashreef Layen
        </div>
      </div>
      <div style={{ color: "#cdd5dd", fontFamily: "Arial", fontWeight: 700, fontSize: 12.5, textAlign: "center", maxWidth: 330 }}>
        Logo ke neeche khali gap • Serial sab se neeche • “PAYMENT: CASH” ka faaltu box • zyada paper kharch
      </div>
    </div>
  );
}
