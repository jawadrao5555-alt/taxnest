/**
 * KOT (Kitchen Order Ticket) — Pizza Master layout
 * Compact mode ON, code style (full ORD- number, no box)
 * Mirrors kitchen-ticket.blade.php as of Aug 2026
 */

const Sep = ({ thick }: { thick?: boolean }) => (
  <div style={{
    borderTop: thick ? "2px dashed #000" : "1px dashed #000",
    margin: thick ? "3px 0" : "2px 0",
  }} />
);

const StationSep = () => (
  <div style={{ borderTop: "3px solid #000", margin: "4px 0 2px" }} />
);

export function Current() {
  return (
    <div style={{
      fontFamily: "Arial, 'Helvetica Neue', Helvetica, sans-serif",
      fontSize: 12,
      lineHeight: 1.3,
      width: 280,
      padding: "8px 10px",
      background: "#fff",
      color: "#000",
      border: "1px solid #ddd",
      borderRadius: 4,
    }}>
      {/* Header */}
      <div style={{ textAlign: "center" }}>
        <div style={{ fontSize: 16, fontWeight: 900 }}>*** KITCHEN ***</div>
        {/* CODE style: full ORD number, no box */}
        <div style={{ fontSize: 14, fontWeight: 700, marginTop: 2 }}>
          ORD-260810-928E2
        </div>
      </div>

      <Sep thick />

      {/* Date + Time + Order type — ek hi line mein */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", fontWeight: 700 }}>
        <span style={{
          display: "inline-block", padding: "1px 6px",
          border: "2px solid #000", fontWeight: 700, fontSize: 12,
          textTransform: "uppercase", letterSpacing: 1,
        }}>TAKE AWAY</span>
        <span>Aug 10, 2026</span>
        <span>12:20 AM</span>
      </div>

      <Sep thick />

      {/* Items table */}
      <table style={{ width: "100%", borderCollapse: "collapse", margin: "2px 0" }}>
        <thead>
          <tr style={{ borderTop: "2px solid #000", borderBottom: "2px solid #000" }}>
            <td style={{ fontWeight: 700, fontSize: 11, textTransform: "uppercase", letterSpacing: 1, padding: "3px 2px", width: "85%" }}>ITEM</td>
            <td style={{ fontWeight: 700, fontSize: 11, textTransform: "uppercase", letterSpacing: 1, padding: "3px 2px", textAlign: "right", paddingRight: 4, width: "15%" }}>QTY</td>
          </tr>
        </thead>
        <tbody>
          {/* Item 1 */}
          <tr style={{ borderBottom: "1px dashed #000" }}>
            <td style={{ padding: "3px 2px", fontSize: 13, fontWeight: 600, verticalAlign: "top" }}>
              <span style={{ fontWeight: 700 }}>Zingster</span>
            </td>
            <td style={{ padding: "3px 2px", fontSize: 15, fontWeight: 700, textAlign: "right", paddingRight: 4, verticalAlign: "top" }}>1</td>
          </tr>
          {/* Item 2 with note */}
          <tr style={{ borderBottom: "1px dashed #000" }}>
            <td style={{ padding: "3px 2px", fontSize: 13, fontWeight: 600, verticalAlign: "top" }}>
              <span style={{ fontWeight: 700 }}>1. Ltr Cold Drink</span>
              <br />
              <span style={{ fontSize: 13, fontStyle: "normal", fontWeight: 900 }}>
                <span style={{ fontSize: 11, textTransform: "uppercase", letterSpacing: 1 }}>&raquo; NOTE</span>
                <span style={{ fontSize: 14, fontWeight: 700, marginLeft: 4 }}>Extra chilled</span>
              </span>
            </td>
            <td style={{ padding: "3px 2px", fontSize: 15, fontWeight: 700, textAlign: "right", paddingRight: 4, verticalAlign: "top" }}>1</td>
          </tr>
        </tbody>
      </table>

      {/* Kitchen notes */}
      <Sep thick />
      <div style={{
        border: "3px solid #000", padding: "4px 6px", marginTop: 4,
        fontSize: 14, fontWeight: 900, background: "#fff", color: "#000",
        textTransform: "uppercase", letterSpacing: 0.5,
      }}>
        NOTES Jaldi banana
      </div>

      <Sep thick />

      {/* Order-by footer */}
      <div style={{ textAlign: "center", fontSize: 11 }}>
        <p>Order by AAMIR LATIF &mdash; 2 item(s), Total Qty 2</p>
      </div>

      <Sep thick />

      {/* Barcode placeholder */}
      <div style={{ textAlign: "center", margin: "4px 0 2px" }}>
        <div style={{
          width: "90%", height: 36, margin: "0 auto",
          background: "repeating-linear-gradient(90deg, #000 0px, #000 2px, #fff 2px, #fff 5px)",
          opacity: 0.85,
        }} />
        <div style={{ fontSize: 9, fontWeight: 700, letterSpacing: 1, marginTop: 2 }}>SCAN TO CLEAR</div>
      </div>

      {/* Company footer */}
      <div style={{ textAlign: "center", fontWeight: 700, fontSize: 11, marginTop: 4 }}>
        PIZZA MASTER
      </div>
    </div>
  );
}
