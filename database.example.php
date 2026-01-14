CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL, -- Şifreleri ASLA düz metin saklamayacağız, hashleyeceğiz.
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,      -- Firma Unvanı veya Ad Soyad
  `contact_name` varchar(100) DEFAULT NULL,  -- Yetkili Kişi
  `tax_office` varchar(100) DEFAULT NULL,    -- Vergi Dairesi
  `tax_number` varchar(50) DEFAULT NULL,     -- Vergi No / TC Kimlik
  `phone` varchar(20) DEFAULT NULL,          -- Telefon
  `email` varchar(100) DEFAULT NULL,         -- E-posta
  `address` text DEFAULT NULL,               -- Adres
  `current_balance` decimal(15,2) DEFAULT 0.00, -- Bakiye (Alacak/Borç durumu)
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,       -- Hangi müşteri?
  `type` enum('debt','credit') NOT NULL, -- debt: Borç (Satış), credit: Alacak (Tahsilat)
  `amount` decimal(15,2) NOT NULL,       -- Tutar
  `description` varchar(255) DEFAULT NULL, -- Açıklama (Örn: Fatura No: 123)
  `date` date NOT NULL,                  -- İşlem Tarihi
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payment_channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,              -- Örn: Merkez Kasa, Garanti Bankası
  `type` enum('card','bank') DEFAULT 'bank', -- Nakit mi Banka mı?
  `account_number` varchar(50) DEFAULT NULL, -- IBAN veya Hesap No
  `current_balance` decimal(15,2) DEFAULT 0.00, -- Kasa Bakiyesi
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transactions tablosuna Kasa ID sütunu ekle
ALTER TABLE `transactions` 
ADD COLUMN `payment_channel_id` int(11) DEFAULT NULL AFTER `customer_id`;

-- Bu sütunu payment_channels tablosuna bağla (İlişkilendir)
ALTER TABLE `transactions`
ADD CONSTRAINT `fk_transaction_channel`
FOREIGN KEY (`payment_channel_id`) REFERENCES `payment_channels`(`id`) ON DELETE SET NULL;

-- 1. TUR KODLARI (PROJELER) TABLOSU
CREATE TABLE `tour_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,        -- Örn: 2026-DELL-01
  `name` varchar(255) NOT NULL,       -- Örn: Dell Tech Forum 2026
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. TRANSACTIONS TABLOSUNU GÜNCELLEME
-- İşlemleri projelere bağlamak ve ödeme durumunu takip etmek için sütunlar ekliyoruz.
ALTER TABLE `transactions`
ADD COLUMN `tour_code_id` int(11) DEFAULT NULL AFTER `payment_channel_id`,
ADD COLUMN `invoice_no` varchar(50) DEFAULT NULL AFTER `amount`, -- Fatura No
ADD COLUMN `payment_status` enum('paid','unpaid') DEFAULT 'paid' AFTER `type`, -- Ödendi mi? Bekliyor mu?
ADD COLUMN `due_date` date DEFAULT NULL AFTER `date`; -- Vade Tarihi (Ne zaman ödenecek?)

-- İlişkiyi kuralım (Tur kodu silinirse işlem silinmesin, bağı kopsun)
ALTER TABLE `transactions`
ADD CONSTRAINT `fk_trans_tour`
FOREIGN KEY (`tour_code_id`) REFERENCES `tour_codes`(`id`) ON DELETE SET NULL;

-- Kullanıcılara yetkiler sütunu ekle
ALTER TABLE `users` ADD COLUMN `permissions` TEXT DEFAULT NULL AFTER `role`;

-- Mevcut Admin'e "süper yetki" verelim (all = her şey)
UPDATE `users` SET `permissions` = '["all"]' WHERE `role` = 'admin';

-- 1. BÖLÜMLER TABLOSU
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Örnek Bölümler
INSERT INTO `departments` (`name`) VALUES ('Yönetim'), ('Operasyon'), ('Pazarlama'), ('Bilgi İşlem'), ('Lojistik');

-- 2. DÖVİZ KURLARI TABLOSU
CREATE TABLE `currencies` (
  `code` varchar(3) NOT NULL, -- USD, EUR, GBP
  `name` varchar(50) NOT NULL,
  `rate` decimal(10,4) NOT NULL DEFAULT 1.0000, -- Kur Değeri
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `currencies` (`code`, `name`, `rate`) VALUES 
('TRY', 'Türk Lirası', 1.0000),
('USD', 'Amerikan Doları', 0.0000),
('EUR', 'Euro', 0.0000),
('GBP', 'İngiliz Sterlini', 0.0000);

-- 3. TRANSACTIONS GÜNCELLEME
ALTER TABLE `transactions`
ADD COLUMN `department_id` int(11) DEFAULT NULL AFTER `customer_id`,
ADD COLUMN `currency` varchar(3) DEFAULT 'TRY' AFTER `amount`,
ADD COLUMN `exchange_rate` decimal(10,4) DEFAULT 1.0000 AFTER `currency`,
ADD COLUMN `original_amount` decimal(15,2) DEFAULT 0.00 AFTER `currency`, -- Dövizli Tutar
ADD COLUMN `doc_type` enum('invoice_order','payment_order') DEFAULT 'invoice_order' AFTER `type`, -- Fatura Emri / Ödeme Emri
ADD COLUMN `is_approved` tinyint(1) DEFAULT 0, -- 0: Onaysız, 1: Onaylı
ADD COLUMN `is_priority` tinyint(1) DEFAULT 0, -- 0: Normal, 1: Öncelikli
ADD COLUMN `needs_control` tinyint(1) DEFAULT 0; -- 0: Tamam, 1: Kontrol Lazım

-- İlişki
ALTER TABLE `transactions`
ADD CONSTRAINT `fk_trans_dept`
FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL;

-- Döviz cinsi, Limit ve Ekstre Borcu sütunlarını ekleyelim
ALTER TABLE `payment_channels`
ADD COLUMN `currency` varchar(3) DEFAULT 'TRY' AFTER `name`,
ADD COLUMN `credit_limit` decimal(15,2) DEFAULT 0.00, -- Kart Limiti
ADD COLUMN `statement_debt` decimal(15,2) DEFAULT 0.00, -- Ekstre Borcu
ADD COLUMN `available_balance` decimal(15,2) DEFAULT 0.00; -- Kullanılabilir Bakiye

ALTER TABLE `payment_channels`
ADD COLUMN `is_blocked` TINYINT(1) DEFAULT 0,
ADD COLUMN `block_reason` VARCHAR(255) NULL;

ALTER TABLE `customers`
ADD COLUMN `customer_type` ENUM('real', 'legal') DEFAULT 'legal' AFTER `id`,
ADD COLUMN `customer_code` VARCHAR(50) NULL AFTER `customer_type`,
ADD COLUMN `tc_number` VARCHAR(11) NULL,
ADD COLUMN `passport_number` VARCHAR(20) NULL,
ADD COLUMN `fax` VARCHAR(20) NULL,
ADD COLUMN `country` VARCHAR(50) DEFAULT 'Türkiye',
ADD COLUMN `city` VARCHAR(50) DEFAULT 'İstanbul',
ADD COLUMN `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
ADD COLUMN `opening_balance_currency` VARCHAR(3) DEFAULT 'TRY',
ADD COLUMN `opening_balance_date` DATE NULL;

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Örnek veriler ekleyelim
INSERT INTO `payment_methods` (`title`) VALUES 
('Nakit'),
('Kredi Kartı'),
('Havale / EFT'),
('Mail Order'),
('Çek / Senet');