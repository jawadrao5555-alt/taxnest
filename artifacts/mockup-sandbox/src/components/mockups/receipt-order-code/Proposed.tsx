import {
  Paper, Header, TakeAwayBadge, InfoRows, ItemsTable, TotalRow, QrBlock, Footer,
} from "./_shared/ReceiptParts";

/**
 * NAI TAJVEEZ v3 (10 Aug 2026):
 *  - Phone ke neeche wali dotted Sep hata di.
 *  - Oopar ke box mein TEEN rows: Order # + POS Invoice # + PRA Fiscal #.
 *  - Neeche wala bordered POS/PRA box BILKUL HATA DIYA.
 *  - TOTAL ke baad seedha QR.
 */
export function Proposed() {
  return (
    <Paper>
      <Header />
      {/* NO dotted Sep — phone ke seedha neeche box */}

      {/* Top box: Order # + POS Invoice # + PRA Fiscal # */}
      <table style={{
        width: "100%", fontSize: 10.5, fontWeight: 700,
        border: "1px solid #000", borderCollapse: "collapse", margin: "3px 0",
      }}>
        <tbody>
          <tr>
            <td style={{ textAlign: "left", padding: "2px 4px", borderBottom: "1px dashed #000" }}>Order #:</td>
            <td style={{ textAlign: "right", padding: "2px 4px", borderBottom: "1px dashed #000", color: "#c00", fontWeight: 900 }}>
              ORD-260810-928E2
            </td>
          </tr>
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
      <InfoRows />
      <ItemsTable />
      <TotalRow />

      {/* Neeche wala box HATA DIYA — seedha QR */}
      <QrBlock />
      <Footer />
    </Paper>
  );
}
