# 23 — Cross-Module Dependencies

## High-coupling hubs

| Hub | Dependents |
|-----|------------|
| `Company` model | Nearly everything; printer JSON; agent columns; feature flags |
| `ProductCatalog` / `NestErps` | Admin UI, login routing, impersonation guard mapping, billing |
| `SubscriptionAccessService` + `PlanLimitService` | All product panels’ entitlement UX |
| `currentCompanyId` binding | Controllers, scopes, jobs that assume middleware ran |
| `BranchContextService` | Dashboards, reports, archive portals |
| `PraIntegrationService` | Sale, sync job, agent, Live Ops retry |
| `PosController` | Enormous NestPOS surface — change carefully |
| Print jobs + AgentController | Restaurant KOT, bills, caller, Live Ops test print |
| Impersonation middleware pair | All panel writes while admin present |

## Dangerous edit patterns

1. Changing `product_type` semantics without ProductCatalog + NestErps + admin create + impersonation match arms  
2. Touching receipt tax display without provisional/reporting-OFF invariants  
3. Altering `users.role` / `pos_role` meaning without PosAuth confinement + plan seat counting  
4. Adding a queued DI FBR job while sync path remains — dual writers  
5. Gateway wake without understanding print job SoT  
6. Company settings migrations that overwrite JSON prefs

## Safe extension patterns (existing)

- NestErps vertical registry entry  
- PosAccessService custom grants  
- Live Ops allow-list config (not freeform)  
- Feature flags in `config/features.php` / SystemSetting
