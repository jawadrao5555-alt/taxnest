---
name: POS inventory dual-switch trap
description: Inventory module has two independent switches (feature flag + column); page gates read only the column — keep write paths synced
---

- POS "Inventory" is controlled by TWO switches: `companies.feature_flags['inventory']` (Features wizard) and `companies.inventory_enabled` column (Customize master toggle). Inventory page gates + sidebar OFF badge read ONLY the column.
- **Why:** they drifted (flag ON, column OFF) so the wizard toggle looked broken — pages kept redirecting "module OFF". Fixed Jul 2026 by syncing both in every write path (feature save, category reset, master toggle) and running flags through `PosFeatureService::normalize()` so child features (recipes) cascade off in STORED flags, not just at read-time resolve.
- **How to apply:** any new write path that touches either switch must update BOTH and normalize; never gate a new page on the flag alone or the column alone without checking which one the toggles actually write.
