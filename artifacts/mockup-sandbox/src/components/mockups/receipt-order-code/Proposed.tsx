import {
  Paper, Header, Sep, TakeAwayBadge, InfoRows, ItemsTable, TotalRow, QrBlock, Footer,
} from "./_shared/ReceiptParts";

/**
 * NAI TAJVEEZ (10 Aug 2026):
 *  1. Oopar wala POS/PRA numbers box HATA diya (neeche wale box mein wohi numbers hain).
 *  2. TOTAL ke neeche wali extra dotted line HATA di.
 *  3. "PRA FISCAL INVOICE" title line HATA di — box mein sirf POS: + PRA: numbers.
 *  4. Chhota boxed code (928E2) oopar se hata kar TOTAL ke neeche — POORA order
 *     number (ORD-260810-928E2) bold, bina box ke (KOT jaisa format).
 */
export function Proposed() {
  return (
    <Paper>
      <Header />
      <Sep />

      <TakeAwayBadge />

      <InfoRows />
      <ItemsTable />
      <TotalRow />

      {/* (4) POORA order number — TOTAL ke neeche, bold, no box (KOT jaisa) */}
      <div style={{ textAlign: "center", padding: "4px 0 2px" }}>
        <span style={{ fontSize: 15, fontWeight: 900 }}>ORD-260810-928E2</span>
      </div>

      {/* PRA box — bina title ke, sirf numbers */}
      <div style={{ border: "2px solid #000", textAlign: "center", padding: "4px 2px", margin: "3px 0" }}>
        <div style={{ fontSize: 10.5, fontWeight: 700 }}>POS: POS-2026-00025</div>
        <div style={{ fontSize: 11, fontWeight: 900 }}>PRA: 195994FHD020182466</div>
      </div>

      <QrBlock />
      <Footer />
    </Paper>
  );
}
