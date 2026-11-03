{{-- Same width on screen and paper so content-height measurement stays exact.
     Browser CSS cannot override a printer driver's hardware cut policy. --}}
<style>
    html, body { color: #000; background: #fff; }
    .receipt { width: 72mm; max-width: 72mm; padding: 2mm; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 600; line-height: 1.4; color: #000; background: #fff; }
    html[lang="ur"] .receipt { font-family: inherit; line-height: 1.9; }
    .receipt td { font-size: 13px; padding: 2px 1px; overflow-wrap: anywhere; }
    .receipt td.r { font-variant-numeric: tabular-nums; }
    .receipt .sm { font-size: 11px; color: #000; }
    .receipt .sec { font-size: 13px; font-weight: 800; letter-spacing: 0; margin-top: 7px; color: #000; }
    .receipt .lg { font-size: 15px; }
    .receipt .xl { font-size: 17px; }
    .receipt .b { font-weight: 800; }
    .receipt tr { break-inside: avoid; }
    @media print {
        html, body { margin: 0; padding: 0; }
        .toolbar { display: none; }
        .receipt { width: 72mm; max-width: 72mm; padding: 2mm; margin: 0; }
        .receipt table, .receipt tbody { break-inside: auto; }
    }
</style>
<style id="day-close-roll-size"></style>
<script>
(() => {
    const prepare = () => {
        const receipt = document.querySelector('.receipt');
        if (!receipt) return;
        // CSS px are 1/96 inch; reserve a short end feed once, not per section.
        const height = Math.ceil(receipt.getBoundingClientRect().height * 25.4 / 96) + 4;
        document.getElementById('day-close-roll-size').textContent = '@page { size: 80mm ' + Math.max(30, height) + 'mm; margin: 0; }';
    };
    window.addEventListener('beforeprint', prepare);
    window.addEventListener('load', prepare);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(prepare);
    window.printDayCloseRoll = async () => {
        if (document.fonts && document.fonts.ready) await document.fonts.ready;
        prepare();
        window.print();
    };
})();
</script>
