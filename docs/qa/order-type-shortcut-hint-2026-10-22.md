# Accurate order-type instructions

Owner videos 4ba007ac-34ea-4d27-8e20-6058ba6dcacc.mov and 05b540f4-f9da-4ce6-8279-c379a95c388d.mov show the "F2 to cycle" hint and no order-type change. The live keyboard handler intentionally sends F2 to product search (including exits from guided/table overlays), as documented by earlier owner requirements. The order-type hint/chip is stale.

Remove F2 from that control and give plain English/Urdu click-selection instructions. Do not change F2's existing search shortcut, order selection, guided flow or saved settings. Browser acceptance presses F2 on the real sale screen, checks actual search focus, unchanged order type and no misleading F2/cycle label, then continues catalog/table regressions. JS syntax/whitespace pass locally; supported local PHP/app/browser unavailable, actual browser acceptance pending CI. This corrects misleading instructions; it does not claim a separately observed hardware keyboard failure is diagnosed.

The F1 shortcut panel contained the same stale F2 order-cycle label; it now correctly says Product Search using existing translations.
