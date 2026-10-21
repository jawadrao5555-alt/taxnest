# Menu: group exempt products

Owner source 4b889aaf-bab5-435f-913a-49947cf422b2.mov. The catalogue has Active/Inactive/Low/Third Schedule filters but no filter for all tax-exempt products. Third Schedule alone omits ordinary exempt products.

Add Exempt Items Only using the existing translated label and baked is_tax_exempt boolean. No product tax setting, price, save endpoint, dataset tenancy or bulk mutation changes. Search/category still compose; All/Active/Third Schedule retain their existing meanings, and inactive exempt products remain available under the explicit Exempt filter.

Before patch: production getter regression failed by including a taxable product; other two compatibility cases passed. After patch: 3/3 pass. Syntax/whitespace pass. A dedicated fictional owner Chrome journey exercises the actual products select/getter/table, checks both exempt types and exclusion of taxable, resets All, and captures desktop/390px screenshots. CI pending; supported local PHP 8.4/app/browser unavailable, no independent production acceptance claimed.
