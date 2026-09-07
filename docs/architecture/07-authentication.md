# 07 — Authentication

## Guards (FACT — `config/auth.php`)

| Guard | Provider | Model | Intended users |
|-------|----------|-------|----------------|
| `web` | `users` | `User` | Digital Invoice |
| `pos` | `users` | `User` | NestPOS |
| `fbrpos` | `users` | `User` | FBR POS |
| `health` | `users` | `User` | Nest ERPS Healthcare |
| `admin` | `admin_users` | `AdminUser` | SaaS admins |
| `franchise` | `franchises` | `Franchise` | Franchise portal |
| `agent` | `agents` | `Agent` | Distributor portal (currently 404) |

**FACT:** Company panels share one `users` table; uniqueness is composite with `product_type` (`IdentityScope`).

**FACT:** Password reset broker exists for `users` only — not admin/franchise/agent.

## Login surfaces

| Product | Login path | Middleware after login |
|---------|------------|------------------------|
| DI | `/login` | `auth` + `company` (+ approval/rate limits) |
| NestPOS | `/pos/login` | `pos.auth` |
| FBR POS | `/fbr-pos/login` | `fbrpos.auth` |
| Health | `/health/login` | `health.auth` |
| SaaS | `/admin/login` | `admin.auth` |
| Franchise | `/franchise/login` | `franchise.auth` |

**FACT (`replit.md`):** Identifiers = Email / Phone / Username / CNIC / NTN; admin creds may auto-detect across forms; pending companies view-only.

## Desktop Agent authentication (distinct)

**FACT:** Middleware `agent.auth` (`AgentAuth`) — Bearer / `X-Agent-Key` matches `companies.agent_api_key` where `agent_enabled`. Binds company context for `/api/agent/*`. Stateless; session middleware stripped on those routes.

## Consultant authentication pattern

**FACT:** Not a guard. `ConsultantProfile` on a DI `User`. Session `consultant_console`. Switch logs in as client `company_admin` on `web`. Exit `/consultant/exit`. Guarded continuously by `ConsultantSwitchGuard`.

## Session coexistence

| Scenario | Guards alive |
|----------|--------------|
| Normal company user | One panel guard |
| SaaS admin only | `admin` |
| Impersonation | `admin` + panel guard |
| Consultant switch | `web` as client (+ consultant session meta) |

## Logout rules under impersonation

**FACT:** Panel logout is blocked; must use Exit (`ReadOnlyImpersonation`). Admin logout via `/admin/logout` invalidates whole session (**INFERENCE:** would also clear impersonation as side effect of invalidate).

## Diagram

```mermaid
flowchart LR
  subgraph SharedUsers[users table]
    U[User + product_type + company_id]
  end
  U --> web & pos & fbrpos & health
  AU[admin_users] --> admin
  F[franchises] --> franchise
  AG[agents] --> agent
  Key[companies.agent_api_key] --> agentAuth[agent.auth API]
```
