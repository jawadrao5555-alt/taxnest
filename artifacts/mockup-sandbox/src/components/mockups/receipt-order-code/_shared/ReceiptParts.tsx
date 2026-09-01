// Shared building blocks for the Pizza Master fiscal receipt mockups
// (order-code placement feedback, 10 Aug 2026).

export function Sep() {
  return <div style={{ borderTop: "1px dashed #000", margin: "4px 0" }} />;
}

export function DoubleSep() {
  return <div style={{ borderTop: "2px solid #000", margin: "4px 0" }} />;
}

export function Header() {
  return (
    <div style={{ textAlign: "center" }}>
      {/* Logo approximation */}
      <div style={{ lineHeight: 1, margin: "2px 0 4px" }}>
        <div style={{ fontSize: 30, color: "#d81f26", fontWeight: 900, fontFamily: "'Brush Script MT', cursive" }}>
          Pizza
        </div>
        <div style={{ fontSize: 9, color: "#d81f26", letterSpacing: 4, fontWeight: 700 }}>— M A S T E R —</div>
      </div>
      <div style={{ fontWeight: 900, fontSize: 15 }}>PIZZA MASTER</div>
      <div style={{ fontWeight: 700, fontSize: 10.5 }}>OPPOSITE FAMILY HOSPITAL MAIN MULTAN ROAD</div>
      <div style={{ fontWeight: 700, fontSize: 10.5 }}>LODHRAN, LODHRAN</div>
      <div style={{ fontWeight: 700, fontSize: 10.5 }}>Phone: 0300-1438383 / 0315-6838383</div>
    </div>
  );
}

export function TakeAwayBadge() {
  return (
    <div style={{ textAlign: "center", padding: "3px 0" }}>
      <span style={{ display: "inline-block", border: "1.5px solid #000", padding: "1px 12px", fontSize: 11, fontWeight: 700, letterSpacing: 1 }}>
        TAKE AWAY
      </span>
    </div>
  );
}

export function InfoRows() {
  const rows: Array<[string, string]> = [
    ["Tareekh:", "10/08/2026 12:20 AM"],
    ["Payment:", "Cash"],
    ["Cashier:", "AAMIR LATIF"],
  ];
  return (
    <table style={{ width: "100%", fontSize: 11, fontWeight: 700 }}>
      <tbody>
        {rows.map(([l, v]) => (
          <tr key={l}>
            <td style={{ textAlign: "left", padding: "1px 0" }}>{l}</td>
            <td style={{ textAlign: "right", padding: "1px 0" }}>{v}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

export function ItemsTable() {
  return (
    <>
      <Sep />
      <table style={{ width: "100%", fontSize: 11, fontWeight: 700 }}>
        <thead>
          <tr style={{ borderBottom: "1px solid #000" }}>
            <td style={{ textAlign: "left", paddingBottom: 2 }}>ITEM</td>
            <td style={{ textAlign: "center" }}>QTY</td>
            <td style={{ textAlign: "right" }}>RATE</td>
            <td style={{ textAlign: "right" }}>RAQAM</td>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 0" }}>1. Ltr Cold Drink</td>
            <td style={{ textAlign: "center" }}>1</td>
            <td style={{ textAlign: "right" }}>170</td>
            <td style={{ textAlign: "right" }}>170</td>
          </tr>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 0" }}>Zingster</td>
            <td style={{ textAlign: "center" }}>1</td>
            <td style={{ textAlign: "right" }}>450</td>
            <td style={{ textAlign: "right" }}>450</td>
          </tr>
        </tbody>
      </table>
      <DoubleSep />
    </>
  );
}

export function TotalRow() {
  return (
    <table style={{ width: "100%" }}>
      <tbody>
        <tr>
          <td style={{ textAlign: "left", fontSize: 15, fontWeight: 900 }}>TOTAL:</td>
          <td style={{ textAlign: "right", fontSize: 15, fontWeight: 900 }}>PKR 620.00</td>
        </tr>
      </tbody>
    </table>
  );
}

/** Fake QR placeholder (deterministic pattern). */
export function QrBlock() {
  const N = 17;
  const cells: boolean[] = [];
  let seed = 7;
  for (let i = 0; i < N * N; i++) {
    seed = (seed * 31 + 17) % 97;
    cells.push(seed % 2 === 0);
  }
  // corner finder squares
  const isFinder = (r: number, c: number) =>
    (r < 5 && c < 5) || (r < 5 && c >= N - 5) || (r >= N - 5 && c < 5);
  return (
    <div style={{ textAlign: "center", margin: "6px 0 2px" }}>
      <svg width="92" height="92" viewBox={`0 0 ${N} ${N}`} style={{ display: "inline-block" }}>
        {Array.from({ length: N * N }, (_, i) => {
          const r = Math.floor(i / N), c = i % N;
          let on = cells[i];
          if (isFinder(r, c)) {
            const rr = r < 5 ? r : r - (N - 5), cc = c < 5 ? c : c - (N - 5);
            on = rr === 0 || rr === 4 || cc === 0 || cc === 4 || (rr >= 1 && rr <= 3 && cc >= 1 && cc <= 3 && rr !== 1 && rr !== 3 && cc !== 1 && cc !== 3) || (rr === 2 && cc === 2);
          }
          return on ? <rect key={i} x={c} y={r} width="1" height="1" fill="#000" /> : null;
        })}
      </svg>
    </div>
  );
}

export function Footer() {
  return (
    <div style={{ textAlign: "center", fontSize: 10.5, fontWeight: 700 }}>
      <div>PRA Sahulat App se scan kar ke verify karein</div>
      <div style={{ marginTop: 3 }}>Khareedari ka shukriya!</div>
      <div style={{ marginTop: 3 }}>Developed by: taxnest.pk</div>
      <div style={{ marginTop: 3 }}>10/08/2026 09:39:08 AM</div>
    </div>
  );
}

export function Paper({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ minHeight: "100vh", background: "#e8e8ec", display: "flex", justifyContent: "center", padding: "16px 0" }}>
      <div
        style={{
          width: 320,
          background: "#fff",
          boxShadow: "0 2px 10px rgba(0,0,0,.25)",
          padding: "10px 12px 16px",
          fontFamily: "Arial, Helvetica, sans-serif",
          color: "#000",
        }}
      >
        {children}
      </div>
    </div>
  );
}
