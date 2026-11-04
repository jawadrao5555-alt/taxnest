# Hospital invoice integration decision

Reviewed on 5 November 2026 (Pakistan). Commercial release gate **open**.

| System | Applicable question | Evidence and release decision |
|---|---|---|
| Federal income-tax electronic record | Is this establishment an integrated enterprise under section 237A and Chapter VIIA? | Section 237A(3) requires the prescribed fiscal invoice when an integrated enterprise makes a sale or renders a service. The Rule 33A schedule separately identifies medical practitioners, pathological and diagnostic laboratories, and private hospitals or medical centres. Determine the exact entity, schedule entry, exclusion, establishments and registrations. |
| Hospital fiscal device | Does this installation implement the Board-approved EFD and SDC requirements? | Rule 33B covers licensed integration, establishment and point registration, recording, secure transmission, digital signature, fiscal identifier and QR printing. The application’s retail FBR POS mirror filters lines by FBR sales-tax treatment. That choice does not establish that all services required by the income-tax regime were recorded. |
| Existing FBR POS adapter | Can the hospital reliably reconcile a separately applicable POS submission? | The automated transport replaces the network method. A connection refusal followed by a synthetic Code 100 acknowledgement produces one fiscal number. The retry keeps the original transaction, the submission history and frozen line totals. The actual receipt QR decodes to the acknowledged value. This validates local POS behavior. |
| FBR Digital Invoice | Does the entity or a separate dispensary have federal sales-tax invoice obligations? | Evaluate registration and taxable supplies under the Sales Tax Act and the DI rules. Do not use DI success as evidence of hospital EFD acceptance. |
| Punjab sales tax | Which service is supplied, by whom, where, for which purpose, and under which schedule? | In the 2026 consolidated text, First Schedule entry 22 covers pathological or diagnostic tests exclusively for medical treatment. Second Schedule Part II entries 24 and 26 distinguish certain cosmetic services from specified consultation and hospital room charges. Thresholds, rate, input-credit treatment and the entity supplying the service matter. Determine actual applicability and provincial onboarding before enabling reporting. A third-party supplier must account for its own service. |
| Punjab hospital reporting | Has a healthcare-specific PRA contract been exercised by the ERP? | No healthcare-specific PRA submission or official acknowledgement was verified. The existing restaurant/POS test suite cannot close this gate. |
| Certification and production | What evidence is required before release? | Obtain the licensed integrator’s written confirmation of the applicable channel and device, approved payload and signature specification, sandbox onboarding/test vectors, approved correction and outage process, and regulator acceptance where required. Verify the applicable branch/device identifiers, QR payload, signed response, duplicates, offline recovery and closing against those artifacts. Use a secure secret store for credential material. |

The provincial review covers Punjab. Service taxation and integration in another province need the applicable authority’s own rules and onboarding.

The consolidated Income Tax Rules used here are the FBR edition amended through 15 September 2026. SRO 428(I)/2024 is a final notification. SRO 288(I)/2026 describes its amendments as a draft and was not used as an operative substitute for Chapter VIIA. The income-tax rules use the SDC fiscal number for the QR. FBR POS success and provincial tax treatment require separate determinations.

**Source documents**

- [FBR Income Tax Ordinance, amended through 30 June 2026, section 237A](https://download1.fbr.gov.pk/Docs/2026724177725705IncomeTaxOrdinanace2001.pdf)
- [FBR Income Tax Rules, amended through 15 September 2026, Chapter VIIA](https://download1.fbr.gov.pk/Docs/20269211394336470IncomeTaxRules2002.pdf)
- [FBR SRO 428(I)/2024](https://download1.fbr.gov.pk/SROs/2024322153582988SRO-428.pdf)
- [FBR draft SRO 288(I)/2026](https://download1.fbr.gov.pk/SROs/2026218112270512SRO288dated18.02.2026.pdf)
- [FBR Digital Invoicing legal provisions](https://www.fbr.gov.pk/di-legal-provisions/173967/173968)
- [Punjab Sales Tax on Services Act, consolidation with 2026 amendment references](https://law.legumlawfirm.pk/Punjab-Sales-Tax-on-Services-Act/)
