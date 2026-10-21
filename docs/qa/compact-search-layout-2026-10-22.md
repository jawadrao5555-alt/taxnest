# Product search: keep name and price together

Owner source 7a866fb1-4ffe-4af7-ac6c-74ce3de40796.mov. The full-width input/dropdown and flex-1 name block place prices far away. Cap the search control at 640px while allowing it to shrink/wrap with the existing toolbar on mobile. Size each name to content rather than filling spare width; keep its price adjacent. Long names wrap instead of hiding behind an ellipsis. Preserve stock dots, images, result ranking, unlimited matches/load-more, scanner priority, keyboard navigation, categories, quick-create and saved settings.

Existing actual catalog search regressions pass locally; JS syntax and whitespace pass. The real Chrome catalog journey now measures short-name/price spacing and input width, renders a long name without clipping, captures desktop/390px evidence and continues all-match/keyboard/load-more regressions. Supported local PHP/app/browser unavailable; actual visual CI pending, not independently live verified.
