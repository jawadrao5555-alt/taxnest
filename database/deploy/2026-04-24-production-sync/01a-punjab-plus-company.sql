-- MySQL dump 10.13  Distrib 8.0.42, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: taxnest_staging
-- ------------------------------------------------------
-- Server version	8.0.42

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `companies`
--
-- WHERE:  id=17

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT  IGNORE INTO `companies` (`id`, `name`, `owner_name`, `registration_no`, `ntn`, `cnic`, `email`, `website`, `logo_path`, `print_paper_size`, `receipt_footer_note`, `phone`, `mobile`, `address`, `city`, `business_activity`, `business_category`, `feature_flags`, `use_universal_pos`, `pos_ui_density`, `fbr_token`, `token_expires_at`, `compliance_score`, `created_at`, `updated_at`, `deleted_at`, `deleted_reason`, `is_internal_account`, `onboarding_completed`, `fbr_sandbox_url`, `fbr_production_url`, `fbr_environment`, `fbr_sandbox_token`, `fbr_production_token`, `fbr_registration_no`, `fbr_business_name`, `suspended_at`, `company_status`, `force_watermark`, `status`, `product_type`, `pos_type`, `pos_theme`, `pos_dashboard_style`, `franchise_id`, `token_expiry_date`, `last_successful_submission`, `fbr_connection_status`, `standard_tax_rate`, `sector_type`, `province`, `invoice_number_prefix`, `next_invoice_number`, `invoice_limit_override`, `user_limit_override`, `branch_limit_override`, `inventory_enabled`, `pra_reporting_enabled`, `kds_enabled`, `restaurant_mode`, `kitchen_printer_enabled`, `print_on_hold`, `print_on_pay`, `auto_print_kot`, `pra_environment`, `pra_pos_id`, `pra_production_token`, `pra_proxy_url`, `agent_api_key`, `agent_last_seen`, `agent_version`, `agent_enabled`, `fbr_pos_enabled`, `fbr_reporting_enabled`, `fbr_pos_id`, `fbr_pos_token`, `fbr_pos_environment`, `receipt_printer_size`, `confidential_pin`, `next_local_invoice_number`, `pra_access_code`, `manager_override_pin`, `cashier_discount_limit`, `manager_discount_limit`) VALUES (17,'PUNJAB PLUS RESTAURANT AND B.B.Q','KHALID MEHMMOD','P1687011-S','1687011-5','3620227946687','hassankhan21500@gmail.com',NULL,NULL,'thermal',NULL,'03007805465','03007805465','NEAR MAILSI CHOWK, DUNYAPUR ROAD NEAR RAILWAY PHATAK','Kahror Pakka','9801.6000 - Hotels, Restaurants, Marriage Halls, Caterers','restaurant',NULL,0,'standard',NULL,NULL,100,'2026-04-24 13:54:32','2026-04-24 08:56:55',NULL,NULL,0,1,NULL,NULL,'sandbox',NULL,NULL,NULL,NULL,NULL,'active',0,'approved','pos','restaurant','purple','default',NULL,NULL,NULL,'unknown',16.00,'Restaurant','Punjab','PPR',1,NULL,NULL,NULL,1,1,1,1,1,0,1,1,'production','192944','6c037120-aa1f-3b47-8eed-b06776858bdf',NULL,'ppr_kM3hQawTmq6VPEIEeHB0lcLbrk2l0P3LytVUEpAQyzS4PdoawTYm0qSDYyD2',NULL,NULL,1,0,0,NULL,NULL,'sandbox','80mm',NULL,1,'3DE27E2F',NULL,10.00,50.00);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-24  9:14:28
