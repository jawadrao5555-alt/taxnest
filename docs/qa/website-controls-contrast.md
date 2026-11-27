# Website and billing control contrast

Local Chromium reproduced these rendered failures before the correction.

| Control | Contrast |
| --- | ---: |
| Admin Plans Edit | 1.31:1 |
| Admin Settings save actions | 3.77:1 |
| Admin Settings Send Test Email | 3.19:1 |
| Digital Invoice and FBR POS FAQ indicators | 2.21:1 |
| PRA POS FAQ indicators | 2.54:1 |

The branch pairs text and surface colours on the affected Admin actions.
Admin tabs preserve the selected palette and identify the selected product
with an underline. Plan editing and proof review use the existing routes and
actions. Public product FAQ disclosures have visible indicators and keyboard
focus and expose their open state. The DI and FBR POS login dialogs use clear
close controls and readable placeholder text.

The existing public browser suite checks all 14 FAQ indicators, keyboard focus,
disclosure toggles and the DI and FBR POS login modals at 390px and 1366px.
The existing Admin directory contrast journey also checks Plan tabs and Edit,
Settings save and test buttons, and pending proof review actions in six saved
palettes, light and dark. It opens the fictional proof panels without submitting
the approval or rejection. The thresholds are 4.5:1 for normal text and 3:1 for
the checked icons and focus rings.

Follow-up audit surfaces include logged-in DI, PRA POS, FBR POS and ERP
workspaces, checkout and report tables. Check the product-specific palette and
mode combinations, including POS Midnight, and exercise populated, empty,
validation and disabled states on desktop and mobile. The homepage and
comparison focus fix belongs to #470. The Website Clients management form
belongs to #468.
