-- 1. KULLANICILAR VE YETKİLENDİRME
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL, -- Hashlenmiş şifre
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `permissions` longtext DEFAULT NULL, -- JSON formatında yetkiler (örn: ["all"])
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin kullanıcısına varsayılan süper yetki
-- Not: Bu satırı tabloyu oluşturduktan sonra bir kullanıcı ekleyince çalıştırabilirsin.

-- 2. BÖLÜMLER (DEPARTMENTS)
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `departments` (`name`) VALUES ('Yönetim'), ('Operasyon'), ('Pazarlama'), ('Bilgi İşlem'), ('Lojistik');

-- 3. DÖVİZ KURLARI
CREATE TABLE `currencies` (
  `code` varchar(3) NOT NULL, -- USD, EUR, TRY
  `name` varchar(50) NOT NULL,
  `rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `currencies` (`code`, `name`, `rate`) VALUES 
('TRY', 'Türk Lirası', 1.0000),
('USD', 'Amerikan Doları', 0.0000),
('EUR', 'Euro', 0.0000),
('GBP', 'İngiliz Sterlini', 0.0000);

-- 4. ÖDEME KANALLARI (KASA / BANKA)
CREATE TABLE `payment_channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL, -- Örn: Merkez Kasa, Garanti Bankası
  `currency` varchar(3) DEFAULT 'TRY',
  `type` enum('card','bank','cash') DEFAULT 'bank',
  `account_number` varchar(50) DEFAULT NULL, -- IBAN
  `credit_limit` decimal(15,2) DEFAULT 0.00, -- Kredi Kartı Limiti
  `statement_debt` decimal(15,2) DEFAULT 0.00, -- Ekstre Borcu
  `available_balance` decimal(15,2) DEFAULT 0.00, -- Kullanılabilir Limit/Bakiye
  `current_balance` decimal(15,2) DEFAULT 0.00, -- Mevcut Bakiye
  `is_blocked` tinyint(1) DEFAULT 0,
  `block_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. MÜŞTERİLER (CARİ HESAPLAR)
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_type` enum('real','legal') DEFAULT 'legal', -- Tüzel / Gerçek Kişi
  `customer_code` varchar(50) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `tc_number` varchar(11) DEFAULT NULL,
  `passport_number` varchar(20) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `country` varchar(50) DEFAULT 'Türkiye',
  `city` varchar(50) DEFAULT 'İstanbul',
  `address` text DEFAULT NULL,
  `opening_balance` decimal(15,2) DEFAULT 0.00, -- Açılış Bakiyesi
  `opening_balance_currency` varchar(3) DEFAULT 'TRY',
  `opening_balance_date` date DEFAULT NULL,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. PROJELER / TUR KODLARI
CREATE TABLE `tour_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL, -- Örn: 2026-DELL-01
  `name` varchar(255) NOT NULL,
  `employer` varchar(255) DEFAULT NULL, -- İşveren
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. FİNANSAL İŞLEMLER (TRANSACTIONS)
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `payment_channel_id` int(11) DEFAULT NULL,
  `tour_code_id` int(11) DEFAULT NULL,
  
  -- İşlem Tipleri
  `type` enum('debt','credit') NOT NULL, -- debt: Borçlanma (Satış), credit: Alacak (Tahsilat)
  `doc_type` enum('invoice_order','payment_order') DEFAULT 'invoice_order', -- Fatura / Ödeme Emri
  
  -- Tutar Bilgileri
  `amount` decimal(15,2) NOT NULL, -- TL Karşılığı veya Ana Tutar
  `currency` varchar(3) DEFAULT 'TRY',
  `exchange_rate` decimal(10,4) DEFAULT 1.0000,
  `original_amount` decimal(15,2) DEFAULT 0.00, -- Dövizli Tutar
  
  -- Detaylar
  `description` varchar(255) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `payment_status` enum('paid','unpaid') DEFAULT 'paid',
  
  -- Tarihler
  `date` date NOT NULL, -- İşlem Tarihi
  `due_date` date DEFAULT NULL, -- Vade Tarihi
  
  -- Durumlar ve Onaylar
  `priority` tinyint(1) DEFAULT 0, -- 1: Yüksek Öncelik, 0: Normal
  `is_approved` tinyint(1) DEFAULT 0, -- Onay Durumu
  `is_checked` tinyint(1) DEFAULT 0, -- Kontrol Edildi mi?
  `needs_control` tinyint(1) DEFAULT 0, -- Kontrol Gerektiriyor mu?
  
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  
  -- Foreign Keys (İlişkiler)
  CONSTRAINT `fk_trans_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trans_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trans_channel` FOREIGN KEY (`payment_channel_id`) REFERENCES `payment_channels`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trans_tour` FOREIGN KEY (`tour_code_id`) REFERENCES `tour_codes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. ÖDEME YÖNTEMLERİ (Sözlük Tablosu)
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `payment_methods` (`title`) VALUES ('Nakit'), ('Kredi Kartı'), ('Havale / EFT'), ('Mail Order'), ('Çek / Senet');

-- 9. TAHSİLAT KANALLARI (Auxiliary Table)
-- Not: Payment Channels ile benzer işlevi görüyor olabilir, kontrol edilmeli.
CREATE TABLE `collection_channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `collection_channels` (`title`) VALUES ('DENİZBANK.USD'), ('Ziraat.TL'), ('Ziraat.USD'), ('Ziraat.EURO'), ('Ziraat Pos');

-- 10. AKTİVİTE LOGLARI
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;