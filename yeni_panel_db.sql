-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1
-- Üretim Zamanı: 16 Oca 2026, 14:10:30
-- Sunucu sürümü: 10.4.32-MariaDB
-- PHP Sürümü: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `yeni_panel_db`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `collection_channels`
--

CREATE TABLE `collection_channels` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `currencies`
--

CREATE TABLE `currencies` (
  `code` varchar(3) NOT NULL,
  `name` varchar(50) NOT NULL,
  `rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `customer_type` enum('real','legal') DEFAULT 'legal',
  `customer_code` varchar(50) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tc_number` varchar(11) DEFAULT NULL,
  `passport_number` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `country` varchar(50) DEFAULT 'Türkiye',
  `city` varchar(50) DEFAULT 'İstanbul',
  `opening_balance` decimal(15,2) DEFAULT 0.00,
  `opening_balance_currency` varchar(3) DEFAULT 'TRY',
  `opening_balance_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_banks`
--

CREATE TABLE `customer_banks` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `iban` varchar(50) NOT NULL,
  `currency` varchar(3) DEFAULT 'TRY',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `kısa-kod` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `payment_channels`
--

CREATE TABLE `payment_channels` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `currency` varchar(3) DEFAULT 'TRY',
  `type` enum('card','bank') DEFAULT 'bank',
  `account_number` varchar(50) DEFAULT NULL,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `statement_debt` decimal(15,2) DEFAULT 0.00,
  `available_balance` decimal(15,2) DEFAULT 0.00,
  `is_blocked` tinyint(1) DEFAULT 0,
  `block_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `tour_codes`
--

CREATE TABLE `tour_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `employer` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `payment_channel_id` int(11) DEFAULT NULL,
  `tour_code_id` int(11) DEFAULT NULL,
  `type` enum('debt','credit') NOT NULL,
  `doc_type` enum('invoice_order','payment_order') DEFAULT 'invoice_order',
  `payment_status` enum('paid','unpaid') DEFAULT 'paid',
  `amount` decimal(15,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'TRY',
  `original_amount` decimal(15,2) DEFAULT 0.00,
  `exchange_rate` decimal(10,4) DEFAULT 1.0000,
  `invoice_no` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `actual_payment_date` date DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_approved` tinyint(1) DEFAULT 0,
  `is_priority` tinyint(1) DEFAULT 0,
  `needs_control` tinyint(1) DEFAULT 0,
  `recipient_bank_id` int(11) DEFAULT NULL,
  `invoice_status` varchar(20) DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `permissions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `collection_channels`
--
ALTER TABLE `collection_channels`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`code`);

--
-- Tablo için indeksler `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `customer_banks`
--
ALTER TABLE `customer_banks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Tablo için indeksler `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `payment_channels`
--
ALTER TABLE `payment_channels`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `tour_codes`
--
ALTER TABLE `tour_codes`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `fk_transaction_channel` (`payment_channel_id`),
  ADD KEY `fk_trans_tour` (`tour_code_id`),
  ADD KEY `fk_trans_dept` (`department_id`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `collection_channels`
--
ALTER TABLE `collection_channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `customer_banks`
--
ALTER TABLE `customer_banks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `payment_channels`
--
ALTER TABLE `payment_channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `tour_codes`
--
ALTER TABLE `tour_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `customer_banks`
--
ALTER TABLE `customer_banks`
  ADD CONSTRAINT `customer_banks_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_trans_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_trans_tour` FOREIGN KEY (`tour_code_id`) REFERENCES `tour_codes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transaction_channel` FOREIGN KEY (`payment_channel_id`) REFERENCES `payment_channels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
