#!/usr/bin/env python3
"""Build a self-contained, offline visual review from synthetic PNG evidence."""

import base64
import html
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EVIDENCE = ROOT / "docs/ui-premium/evidence"
OUTPUT = ROOT / ".local/premium-ui-review.html"
SURFACES = [
    ("admin-dashboard", "Admin dashboard"),
    ("pra-invoice-create", "PRA restaurant billing"),
    ("fbr-create", "FBR retail billing"),
    ("hotel-front-desk", "Hotel front desk"),
    ("service-work-orders", "Service work orders"),
    ("health-dashboard", "Health dashboard"),
]


def image(filename, label):
    path = EVIDENCE / filename
    if not path.is_file():
        raise SystemExit(f"Required evidence missing: {filename}")
    data = base64.b64encode(path.read_bytes()).decode("ascii")
    return (
        f'<figure><figcaption>{html.escape(label)}</figcaption>'
        f'<img loading="lazy" alt="{html.escape(label)}" '
        f'src="data:image/png;base64,{data}"></figure>'
    )


sections = []
for stem, title in SURFACES:
    pairs = []
    for size in ("desktop", "mobile"):
        pairs.append(
            f'<details{" open" if size == "desktop" else ""}>'
            f'<summary>{size.title()} comparison</summary><div class="pair">'
            + image(f"before-{stem}-{size}.png", f"Before · {title} · {size}")
            + image(f"after-{stem}-{size}.png", f"After · {title} · {size}")
            + "</div></details>"
        )
    pairs.append(
        '<details><summary>Tablet after · 834px</summary><div class="tablet">'
        + image(f"after-{stem}-tablet.png", f"After · {title} · tablet")
        + "</div></details>"
    )
    sections.append(
        f'<section id="{stem}"><h2>{html.escape(title)}</h2>'
        + "".join(pairs)
        + "</section>"
    )

navigation = "".join(
    f'<a href="#{stem}">{html.escape(title)}</a>' for stem, title in SURFACES
)
document = """<!doctype html><html lang="en"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TaxNest · Premium UI review</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f3;color:#17323a;
font:15px/1.55 system-ui,sans-serif}header,main{max-width:1500px;margin:auto;
padding:28px}header{border-bottom:1px solid #d8e1df}h1{font-size:clamp(28px,4vw,46px);
letter-spacing:-.04em;margin:8px 0}h2{font-size:23px;margin:0 0 15px}
.eyebrow{color:#0a4d5c;font-weight:750;text-transform:uppercase;letter-spacing:.12em;
font-size:12px}.note{max-width:1000px;color:#536c72}nav{display:flex;gap:8px;flex-wrap:wrap}
nav a{padding:8px 12px;border:1px solid #d8e1df;border-radius:9px;text-decoration:none;
color:#0a4d5c;background:white}section{padding:24px 0;border-bottom:1px solid #d8e1df;
scroll-margin-top:20px}.pair{display:grid;grid-template-columns:1fr 1fr;gap:16px}
figure{margin:0;min-width:0;background:white;border:1px solid #d8e1df;border-radius:12px;
overflow:hidden}figcaption{padding:12px 16px;font-weight:700;border-bottom:1px solid #d8e1df}
img{display:block;width:100%;height:auto}details{margin:12px 0}summary{cursor:pointer;
font-weight:700;padding:10px 0}.tablet{max-width:834px}footer{padding:28px;color:#536c72}
@media(max-width:700px){header,main{padding:18px}.pair{grid-template-columns:1fr}}
</style><header><div class="eyebrow">TaxNest · Separate review branch</div>
<h1>Premium interface, existing business rules.</h1>
<p class="note">Real browser captures from an isolated synthetic test application.
Desktop: 1440px; tablet: 834px; mobile: 390px. This is an offline review, not the
production application. No merge or deployment has been performed.</p>
<p class="note"><strong>Comparison notes:</strong> PRA before uses grid-arrangement
mode; after uses normal billing mode. FBR after includes a repaired synthetic
catalog fixture. Its existing data source supplies no product image, so it uses
honest monogram cards rather than fabricated product photos. Native empty states
and zero-valued metrics are real synthetic data.</p><nav>"""
document += navigation + "</nav></header><main>" + "".join(sections)
document += """</main><footer>Review alongside docs/ui-premium/ACCEPTANCE.md.
This file makes no network requests.</footer></html>"""
OUTPUT.parent.mkdir(parents=True, exist_ok=True)
OUTPUT.write_text(document, encoding="utf-8")
print(OUTPUT.relative_to(ROOT))