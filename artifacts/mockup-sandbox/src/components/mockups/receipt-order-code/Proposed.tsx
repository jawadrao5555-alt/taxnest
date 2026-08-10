import {
  Paper, Header, TakeAwayBadge, InfoRows, ItemsTable, TotalRow, QrBlock, Footer,
} from "./_shared/ReceiptParts";

/**
 * NAI TAJVEEZ v2 (10 Aug 2026):
 *  - Phone ke neeche wali dotted line HATA di (Sep hata di).
 *  - Oopar ke box mein "POS Invoice #" ki jagah poora Order Number.
 *  - PRA Fiscal # wali line usi box mein rahti hai.
 *  - "PRA FISCAL INVOICE" title neeche wale box se hata di.
 *  - TOTAL ke baad extra dotted Sep nahi.
 */
export function Proposed() {
  return (
    <Paper>
      <Header />
      {/* NO dotted Sep here — phone ke neeche seedha box */}

      {/* Top box: Order Number + PRA Fiscal # */}
      <table style={{
        width: "100%", fontSize: 10.5, fontWeight: 700,
        border: "1px solid #000", borderCollapse: "collapse", margin: "3px 0",
      }}>
        <tbody>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 4px", borderBottom: "1px dashed #000" }}>Order #:</td>
            <td style={{ textAlign: "right", padding: "2px 4px", borderBottom: "1px dashed #000", fontWeight: 900 }}>
              ORD-260810-928E2
            </td>
          </tr>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 4px" }}>PRA Fiscal #:</td>
            <td style={{ textAlign: "right", padding: "2px 4px" }}>195994FHD020182466</td>
          </tr>
        </tbody>
      </table>

      <TakeAwayBadge />
      <InfoRows />
      <ItemsTable />
      <TotalRow />

      {/* PRA box — bina title ke, sirf POS: + PRA: */}
      <div style={{ border: "2px solid #000", textAlign: "center", padding: "4px 2px", margin: "4px 0" }}>
        <div style={{ fontSize: 10.5, fontWeight: 700 }}>POS: POS-2026-00025</div>
        <div style={{ fontSize: 11, fontWeight: 900 }}>PRA: 195994FHD020182466</div>
      </div>

      <QrBlock />
      <Footer />
    </Paper>
  );
}
