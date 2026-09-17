#!/usr/bin/env python3
"""Build the final, self-contained visual-correction gallery.

The generator deliberately refuses to build a partial review.  The 30 named
visual-correction captures are the review contract; older before/after images
are only an optional, collapsed historical archive.
"""

import base64
import html
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EVIDENCE = ROOT / "docs/ui-premium/evidence"
CORRECTION = EVIDENCE / "visual-correction"
OUTPUT = ROOT / ".local/premium-ui-final-review.html"
SIZES = ("desktop", "tablet", "mobile")
MODES = ("light", "dark")


def required_filenames():
    names = [f"admin-{mode}-{size}.png" for mode in MODES for size in SIZES]
    for product in ("pra", "fbr"):
        names.extend(
            f"{product}-{mode}-{size}-{state}.png"
            for mode in MODES
            for size in SIZES
            for state in ("filled", "payment")
        )
    return names


REQUIRED = required_filenames()
missing = [name for name in REQUIRED if not (CORRECTION / name).is_file()]
if missing:
    joined = "\n".join(f"  - {name}" for name in missing)
    raise SystemExit(
        "Missing required visual-correction screenshot(s); "
        f"{len(missing)} of {len(REQUIRED)} are absent:\n{joined}"
    )


def image(path, label, *, lazy=True):
    """Return an inline figure; path is already known to exist."""
    data = base64.b64encode(path.read_bytes()).decode("ascii")
    loading = ' loading="lazy"' if lazy else ""
    return (
        f'<figure><figcaption>{html.escape(label)}</figcaption>'
        f'<img{loading} alt="{html.escape(label)}" '
        f'src="data:image/png;base64,{data}"></figure>'
    )


def correction_image(filename, label, *, lazy=True):
    return image(CORRECTION / filename, label, lazy=lazy)


def size_label(size):
    return {"desktop": "Desktop · 1440px", "tablet": "Tablet · 834px",
            "mobile": "Mobile · 390px"}[size]


def mode_label(mode):
    return f"{mode.title()} mode"


lead = correction_image(
    "admin-light-desktop.png",
    "Fixture annotation · Admin · light mode · desktop · above fold",
    lazy=False,
)

admin_cards = []
for mode in MODES:
    cards = "".join(
        correction_image(
            f"admin-{mode}-{size}.png",
            f"Admin · {mode_label(mode)} · {size_label(size)}",
        )
        for size in SIZES
    )
    admin_cards.append(
        f'<div class="mode-group"><h3>{mode_label(mode)}</h3>'
        f'<div class="triptych">{cards}</div></div>'
    )


def billing_section(product, title):
    state_cards = []
    for state in ("filled", "payment"):
        mode_groups = []
        for mode in MODES:
            cards = "".join(
                correction_image(
                    f"{product}-{mode}-{size}-{state}.png",
                    f"{title} · {mode_label(mode)} · {size_label(size)} · "
                    f"{'Filled cart' if state == 'filled' else 'Payment review'}",
                )
                for size in SIZES
            )
            mode_groups.append(
                f'<div class="mode-group"><h4>{mode_label(mode)}</h4>'
                f'<div class="triptych">{cards}</div></div>'
            )
        state_title = "Filled cart" if state == "filled" else "Payment review"
        state_cards.append(
            f'<details class="state" open><summary>{state_title}</summary>'
            + "".join(mode_groups)
            + "</details>"
        )
    return (
        f'<section id="{product}"><h2>{html.escape(title)}</h2>'
        '<p class="section-note">Both display modes × all three viewport sizes. '
        "The state is a visual fixture; no payment is submitted.</p>"
        + "".join(state_cards)
        + "</section>"
    )


def historical_archive():
    """Build an optional, closed archive without making it a required fixture."""
    surfaces = (
        ("admin-dashboard", "Admin dashboard"),
        ("pra-invoice-create", "PRA restaurant billing"),
        ("fbr-create", "FBR retail billing"),
        ("hotel-front-desk", "Hotel front desk"),
        ("service-work-orders", "Service work orders"),
        ("health-dashboard", "Health dashboard"),
    )
    blocks = []
    for stem, title in surfaces:
        figures = []
        for size in ("desktop", "mobile"):
            for stage in ("before", "after"):
                path = EVIDENCE / f"{stage}-{stem}-{size}.png"
                if path.is_file():
                    figures.append(
                        image(path, f"Historical {stage} · {title} · {size}")
                    )
        tablet = EVIDENCE / f"after-{stem}-tablet.png"
        if tablet.is_file():
            figures.append(image(tablet, f"Historical after · {title} · tablet"))
        if figures:
            blocks.append(
                f'<details><summary>{html.escape(title)} · archived '
                'before/after captures</summary><div class="archive-grid">'
                + "".join(figures)
                + "</div></details>"
            )
    if not blocks:
        return (
            '<p class="section-note">No historical captures are present in this '
            "checkout; the final visual-correction evidence above is the source "
            "of truth.</p>"
        )
    return "".join(blocks)


def optional_native():
    """Include newer native surface captures only when they are available."""
    prefixes = (("hotel", "Hotel"), ("services", "Services"), ("health", "Health"))
    blocks = []
    for prefix, title in prefixes:
        figures = []
        for mode in MODES:
            for size in SIZES:
                path = CORRECTION / f"{prefix}-{mode}-{size}.png"
                if path.is_file():
                    figures.append(
                        image(path, f"Native {title} · {mode_label(mode)} · "
                        f"{size_label(size)}")
                    )
        if figures:
            blocks.append(
                f'<details><summary>{html.escape(title)} · optional native '
                'surface</summary><div class="triptych">'
                + "".join(figures)
                + "</div></details>"
            )
    return "".join(blocks)


navigation = "".join(
    f'<a href="#{anchor}">{html.escape(label)}</a>'
    for anchor, label in (
        ("admin", "Admin"),
        ("pra", "PRA billing"),
        ("fbr", "FBR billing"),
        ("archive", "Historical archive"),
    )
)

document = """<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TaxNest · Final premium UI visual correction</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f3;color:#17323a;
font:15px/1.55 system-ui,sans-serif}header,main,footer{max-width:1500px;
margin:auto;padding:28px}header{border-bottom:1px solid #d8e1df}h1{
font-size:clamp(28px,4vw,48px);letter-spacing:-.04em;line-height:1.08;
margin:8px 0 12px}h2{font-size:25px;letter-spacing:-.02em;margin:0 0 8px}
h3,h4{margin:0 0 10px}.eyebrow{color:#0a4d5c;font-weight:750;
text-transform:uppercase;letter-spacing:.12em;font-size:12px}.note,.section-note{
max-width:1050px;color:#536c72}.lead{max-width:1440px;margin:22px 0 8px}
.lead figure{max-width:1440px}.annotation{display:grid;grid-template-columns:
repeat(3,1fr);gap:10px;margin:18px 0 22px}.annotation div{background:#e7f0ee;
border:1px solid #cbded9;border-radius:10px;padding:12px 14px}.annotation strong{
display:block;color:#0a4d5c;font-size:12px;text-transform:uppercase;
letter-spacing:.08em}nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:22px}
nav a{padding:8px 12px;border:1px solid #d8e1df;border-radius:9px;
text-decoration:none;color:#0a4d5c;background:white}section{padding:28px 0;
border-bottom:1px solid #d8e1df;scroll-margin-top:20px}.mode-group{margin:18px 0}
.triptych{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.state{margin:16px 0}.state>summary{font-size:18px}.archive-grid{display:grid;
grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding-top:10px}
figure{margin:0;min-width:0;background:white;border:1px solid #d8e1df;
border-radius:12px;overflow:hidden;box-shadow:0 2px 8px #17323a0d}
figcaption{padding:11px 14px;font-weight:700;border-bottom:1px solid #d8e1df;
font-size:13px}img{display:block;width:100%;height:auto}details{margin:10px 0}
summary{cursor:pointer;font-weight:700;padding:10px 0}.lead figcaption{
background:#0a4d5c;color:white;border-bottom:0}.lead img{max-height:none}
footer{color:#536c72}.warning{border-left:4px solid #c47c2c;padding:10px 14px;
background:#fff8ed;max-width:1050px}@media(max-width:900px){
.triptych,.archive-grid{grid-template-columns:1fr 1fr}.annotation{grid-template-columns:1fr}}
@media(max-width:600px){header,main,footer{padding:18px}.triptych,.archive-grid{
grid-template-columns:1fr}}
</style></head><body><header><div class="eyebrow">TaxNest · Final visual correction</div>
<h1>Premium interface, corrected evidence first.</h1>
<p class="note">Offline review generated from the required 30 synthetic browser
captures. Desktop: 1440px; tablet: 834px; mobile: 390px. This gallery is
self-contained and makes no external network requests.</p>
<div class="annotation"><div><strong>Fixture</strong>Admin light desktop
above-fold capture leads this review; it is not an old scrolled admin view.</div>
<div><strong>Safety</strong>All screenshots are synthetic and nonproduction.
No payments were submitted.</div><div><strong>Verification</strong>FBR mobile
checks saved mode across reload; PRA mobile uses its actual command-palette
dark toggle. See the acceptance record for the older visual-only checks.</div></div>
<div class="lead">""" + lead + """</div>
<p class="note"><strong>Catalog annotation:</strong> PRA uses locally attributed
stock food images in its synthetic catalog. FBR's backend has no image field,
so its catalog uses honest monograms rather than fabricated product photos.</p>
<nav>""" + navigation + """</nav></header><main>
<section id="admin"><h2>Admin dashboard · mode and viewport review</h2>
<p class="section-note">The required admin captures cover both modes × all three
viewport sizes. The light desktop above-fold fixture is repeated here for
complete mode/size coverage.</p>""" + "".join(admin_cards) + """</section>
""" + billing_section("pra", "PRA restaurant billing") + billing_section(
    "fbr", "FBR retail billing"
) + """<section id="native"><h2>Optional native surfaces</h2>
<p class="section-note">Included only when matching visual-correction captures
are present; they are not part of the required 30.</p>""" + optional_native() + """
</section><section id="archive"><h2>Historical before/after archive</h2>
<p class="warning"><strong>Historical and collapsed by design.</strong> These
older captures are retained for context only and do not determine this review's
landing view. The final evidence above is the source of truth.</p>""" + historical_archive() + """
</section></main><footer>Review alongside docs/ui-premium/ACCEPTANCE.md.
Evidence is synthetic/nonproduction, no payment was submitted, and this file
makes no network requests.</footer></body></html>"""

OUTPUT.parent.mkdir(parents=True, exist_ok=True)
OUTPUT.write_text(document, encoding="utf-8")
print(OUTPUT.relative_to(ROOT))