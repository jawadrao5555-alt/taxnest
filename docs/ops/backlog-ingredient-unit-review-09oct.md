# Ingredient unit backlog review — 9 October 2026

## Finding
Issue #45's display-case validation and silent error problems have already been addressed on main. PR #56 also protects stocked/recipe-linked ingredients from direct relabeling. This focused PR retests the real HTTP and Chrome edit flow; it does not remove the stock guard or invent a conversion ratio.

The original 50 pcs example has a different valid outcome from an unused ingredient: changing its meaning to 50 kg without a documented conversion would corrupt stock and recipe accounting. It must receive a visible refusal with unchanged stock. Empty unused ingredients may change pcs to kg normally.

## Compatibility matrix
| Case | Check |
|---|---|
| Empty unused ingredient, display-cased Kg | Actual authenticated PUT normalizes and saves kg; redirected card shows kg |
| Empty unused ingredient, normal browser select | Chrome saves pcs to kg and verifies the reloaded card at desktop/mobile |
| 50 pcs stock | Actual HTTP/Chrome refusal is visible; unit, stock, cost and base unit stay unchanged |
| Zero stock with recipe dependency | Unit change refused; recipe quantity preserved |
| Invalid client unit | Server validation is visible and no success flash/DB change occurs |
| Existing stocked ingredient, same units | Ordinary name/cost metadata edits still work without changing stock |
| Other tenant | Foreign ingredient PUT returns 404 and preserves that row |
| Branch-stock-only ingredient | Existing IngredientUnitEditTest retains branch-stock guard coverage |

## Evidence and limits
- PHP/Composer are unavailable locally; actual HTTP and Chrome execution is delegated to isolated PR CI, never production.
- Browser runner syntax: `node --check` PASS.
- Chrome uses two separate unused fixtures so both 1366 and 390 width runs start from pcs. Stocked and invalid submissions are intentional negative cases through the real form and server.
- No application code or saved inventory configuration changes; existing stock/recipe semantics stay intact.
- A stocked-unit conversion is a separate business operation requiring its actual conversion ratio and audited stock/recipe treatment. This verification PR does not claim to implement it.
- Ready only after full CI passes. No merge or deployment.
