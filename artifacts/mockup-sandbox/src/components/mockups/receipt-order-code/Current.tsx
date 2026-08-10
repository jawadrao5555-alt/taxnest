import {
  Paper, Header, Sep, DoubleSep, TakeAwayBadge, InfoRows, ItemsTable, TotalRow, QrBlock, Footer,
} from "./_shared/ReceiptParts";

/** ABHI wali receipt — jaise photo mein hai (fiscal bill, code style). */
export function Current() {
  return (
    <Paper>
      <Header />
      <Sep />

      {/* (1) Top POS/PRA numbers box — owner: EXTRA, remove */}
      <table style={{ width: "100%", fontSize: 10.5, fontWeight: 700, border: "1px solid #000", borderCollapse: "collapse" }}>
        <tbody>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 4px", borderBottom: "1px dashed #000" }}>POS Invoice #:</td>
            <td style={{ textAlign: "right", padding: "2px 4px", borderBottom: "1px dashed #000" }}>POS-2026-00025</td>
          </tr>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 4px" }}>PRA Fiscal #:</td>
            <td style={{ textAlign: "right", padding: "2px 4px" }}>195994FHD020182466</td>
          </tr>
        </tbody>
      </table>

      <TakeAwayBadge />

      {/* (3) chhota boxed code — oopar */}
      <div style={{ textAlign: "center", padding: "2px 0 3px" }}>
        <span style={{ display: "inline-block", border: "2px solid #000", padding: "2px 14px", fontSize: 14, fontWeight: 900, letterSpacing: 2 }}>
          928E2
        </span>
      </div>

      <InfoRows />
      <ItemsTable />
      <TotalRow />

      {/* extra dotted line — owner: EXTRA */}
      <Sep />

      {/* (2) PRA fiscal box — title ke saath */}
      <div style={{ border: "2px solid #000", textAlign: "center", padding: "4px 2px", margin: "3px 0" }}>
        <div style={{ fontSize: 12, fontWeight: 900 }}>PRA FISCAL INVOICE</div>
        <div style={{ fontSize: 10.5, fontWeight: 700 }}>POS: POS-2026-00025</div>
        <div style={{ fontSize: 11, fontWeight: 900 }}>PRA: 195994FHD020182466</div>
      </div>

      <QrBlock />
      <Footer />
    </Paper>
  );
}
