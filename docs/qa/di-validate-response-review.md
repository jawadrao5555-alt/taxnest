# DI validation response review

The shared parser requires an invoice number even for a validate-only request. With a successful header and one successful item, removing the number reproduces the contradictory failure message. The original item response from the observed sandbox session was not captured.

PRAL DI API specification sections 4.1.3, 4.2.3 and 4.2.4 show separate posting and validation responses. The valid validation example has no issued number. The invalid example also carries header code 00, with an invalid status and an item error.

Source: https://download1.fbr.gov.pk/Docs/20256201364855300TechnicalDocumentationforDIAPI.pdf

Before finalizing, compare redacted sandbox examples against the current specification. Cover valid unnumbered validation, mixed item outcomes, missing or duplicate item results, error codes, malformed or unreachable responses and numbered submission acknowledgements. Validation must leave the draft editable. Only an authoritative submission acknowledgement can lock an invoice. Check the current transport fallback that reports local validation as valid when FBR is unreachable.
