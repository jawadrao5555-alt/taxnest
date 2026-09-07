# 14 — External Integrations

## FBR — Digital Invoicing

| Item | FACT |
|------|------|
| Why | Pakistan DI compliance |
| Service | `FbrService` |
| Endpoints | `gw.fbr.gov.pk/di_data/...` sandbox/prod |
| Entry | `InvoiceController::submitToFbrSync`, bulk jobs, DI API |
| Persist | invoice fields + `fbr_logs` |
| Queue | Bulk path queued; primary submit **sync** |
| Sandbox | Per-company environment fields |

## FBR — POS IMS

| Item | FACT |
|------|------|
| Why | FBR POS fiscalization (SRO path) |
| Service | same `FbrService` IMS URLs (`esp.fbr.gov.pk` / imsp) |
| Entry | `FbrPosController`, retry job, agent fiscal device |
| Persist | `fbr_pos_*` + `fbr_pos_logs` |

## PRA / PRAL

| Item | FACT |
|------|------|
| Why | NestPOS Punjab fiscal |
| Service | `PraIntegrationService` → `ims.pral.com.pk` |
| Modes | cloud vs `fiscal_device` (agent localhost IMS) |
| Relays | `pra-relay`, company `pra_proxy_url` |
| Persist | `pra_*` on txn + `pra_logs` |

## WhatsApp

1. Per-company Meta Cloud API (`WhatsAppBusinessApi`) + webhook  
2. Central TaxNest number for owner alerts (`SystemSetting`)  
3. Many POS “send bill” flows = **wa.me deep links** (`PkPhone`) — not Cloud API

## Email

Domain SMTP `noreply@taxnest.pk` / `mail.taxnest.pk`; admin override via settings; MailHealth banner. Several mailables intentionally **sync** (cPanel worker constraints noted in code comments).

## OpenAI

AI Invoice Reader (DI Premium) — gpt extraction; drafts only.

## Cloudflare

Rocket Loader / zone settings commands — keep CF from breaking POS JS.

## DRAP

Medicine catalogue crawl for FBR pharmacy.

## SMS

**FACT:** No SMS provider integration found. OTP is email-based.

## Payment rails

Payment proofs (bank transfer evidence) — not a card gateway. Method labels may mention JazzCash/EasyPaisa as proof types.
