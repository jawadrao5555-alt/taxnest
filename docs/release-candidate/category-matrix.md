# TaxNest marketed category matrix

**Recovery state:** recovered from `PosCategoryProfiles` at code/tooling
checkpoint `75466819e4b5bcefff37b28fbd51d8f8d0025721` after the temporary
worktree loss. The rows below are the exact
advertised profiles in that source: **46 commercial categories plus the
non-commercial `general` fallback**. This table is a source contract; native
category verification is explicitly **BLOCKED** after loss and this is not
current browser/native acceptance.

| # | Category | Family | Landing surface | Workflow classification | Regression fixture |
|---:|---|---|---|---|---|
| 1 | `restaurant` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 2 | `cafe` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 3 | `quick_service` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 4 | `hotel` | `accommodation` | `hotel_front_desk` | `hotel_stay_folio` | `tests/Feature/HotelCategoryNativeUiTest.php` |
| 5 | `marquee` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 6 | `catering` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 7 | `retail` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 8 | `grocery` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 9 | `wholesale` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 10 | `clothing` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 11 | `electronics` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 12 | `hardware` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 13 | `autoparts` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 14 | `bakery` | `goods_retail` | `sale_engine` | `goods_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 15 | `hybrid_cafe_retail` | `food_service` | `sale_engine` | `food_pos` | `tests/Feature/PraServiceCategoriesTest.php` |
| 16 | `pharmacy` | `pharmacy` | `sale_engine` | `pharmacy_pos` | `tests/Feature/CategoryModuleUrlGateTest.php` |
| 17 | `salon` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 18 | `gym` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 19 | `laundry` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 20 | `workshop` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 21 | `courier` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 22 | `photography` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 23 | `event_management` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 24 | `travel_agent` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 25 | `rent_a_car` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 26 | `property_dealer` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 27 | `advertising` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 28 | `it_services` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 29 | `security_services` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 30 | `clinic` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 31 | `education` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 32 | `consultant` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 33 | `architect` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 34 | `construction` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 35 | `manpower` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 36 | `cargo` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 37 | `warehouse` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 38 | `cleaning` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 39 | `repair_service` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 40 | `printing` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 41 | `media_production` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 42 | `entertainment` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 43 | `financial_services` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 44 | `equipment_rental` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 45 | `tailoring` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |
| 46 | `other_service` | `services` | `service_work_orders` | `typed_work_order` | `tests/Feature/PosServiceWorkOrderTest.php` |

## Non-commercial fallback

| Category | Family | Landing surface | Workflow classification | Regression fixture |
|---|---|---|---|---|
| `general` | `general` | `catalogue_billing` | `catalogue_billing_fallback` | `tests/Unit/PosCategoryProfilesTest.php` |

`general` is deliberately not a marketed vertical. It is the unclassified
fallback and resolves to the complete known module set rather than hiding
vertical modules. Existing tenant settings remain authoritative.
