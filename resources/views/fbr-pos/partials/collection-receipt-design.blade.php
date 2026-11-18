<style>
.collection-print-receipt .receipt-brand { padding-bottom: 10px; border-bottom: 1px solid #aaa; margin-bottom: 12px; }
.collection-print-receipt .receipt-brand h1 { font-size: 16px; line-height: 1.3; }
.collection-print-receipt .receipt-brand h2 { font-weight: 700; margin-top: 12px; }
.collection-print-receipt .receipt-payment { font-weight: 700; }
.collection-print-receipt .receipt-fiscal { margin-top: 12px; padding-top: 10px; border-top: 1px solid #aaa; text-align: center; }
.collection-print-receipt .receipt-fiscal img { display: block; margin: 0 auto 6px; width: 28mm; height: 28mm; }
.collection-print-receipt[data-paper="a4"] .receipt-brand h1 { font-size: 23px; }
.collection-print-receipt[data-paper="a4"] .receipt-fiscal { display: flex; align-items: center; gap: 16px; text-align: start; }
.collection-print-receipt[data-paper="a4"] .receipt-fiscal img { width: 34mm; height: 34mm; margin: 0; }
@media print {
    .collection-print-receipt { color: #111; background: white; }
    .collection-print-receipt .receipt-payment, .collection-print-receipt .receipt-fiscal { break-inside: avoid; }
}
</style>
