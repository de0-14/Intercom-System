-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 28, 2026 at 01:46 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `drmc_intercom`
--

DELIMITER $$
--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `generate_chat_thread` (`sender_id` INT, `receiver_head` VARCHAR(255), `number_id` INT) RETURNS VARCHAR(50) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC BEGIN
    RETURN CONCAT('chat_', sender_id, '_', MD5(CONCAT(receiver_head, number_id)), '_', UNIX_TIMESTAMP());
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admin_chats`
--

CREATE TABLE `admin_chats` (
  `chat_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_chats`
--

INSERT INTO `admin_chats` (`chat_id`, `admin_id`, `user_id`, `created_at`) VALUES
(1, 17, 3, '2026-01-28 08:24:58'),
(2, 2, 3, '2026-01-28 08:25:22'),
(3, 17, 14, '2026-01-28 08:28:24'),
(4, 2, 14, '2026-01-28 08:46:11'),
(5, 14, 2, '2026-01-28 08:46:18');

-- --------------------------------------------------------

--
-- Table structure for table `admin_messages`
--

CREATE TABLE `admin_messages` (
  `message_id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_messages`
--

INSERT INTO `admin_messages` (`message_id`, `chat_id`, `sender_id`, `message`, `created_at`, `is_read`) VALUES
(1, 1, 3, 'eyo cuh', '2026-01-28 08:25:07', 0),
(2, 2, 2, 'fuck of', '2026-01-28 08:25:42', 1),
(3, 2, 3, 'saman', '2026-01-28 08:26:50', 1),
(4, 4, 14, 'oi', '2026-01-28 08:46:15', 1),
(5, 4, 2, 'sup', '2026-01-28 08:46:24', 1);

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `initiated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(4) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `last_message_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`conversation_id`, `number_id`, `initiated_by`, `created_at`, `last_activity`, `is_archived`, `archived_at`, `last_message_time`) VALUES
(1, 78, 2, '2026-01-27 03:13:00', '2026-01-27 06:37:20', 1, '2026-01-27 06:37:20', '2026-01-27 06:37:20');

-- --------------------------------------------------------

--
-- Table structure for table `conversations_archive`
--

CREATE TABLE `conversations_archive` (
  `conversation_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `initiated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversations_archive`
--

INSERT INTO `conversations_archive` (`conversation_id`, `number_id`, `initiated_by`, `created_at`, `last_activity`, `is_archived`, `archived_at`) VALUES
(1, 78, 2, '2026-01-27 03:13:00', '2026-01-27 03:13:42', 1, '2026-01-27 06:37:20'),
(11, 1, 1, '2026-01-26 06:06:53', '2026-01-26 07:11:07', 1, '2026-01-27 00:55:11'),
(12, 1, 1, '2026-01-27 00:56:24', '2026-01-27 01:43:48', 1, '2026-01-27 02:16:16'),
(13, 1, 1, '2026-01-27 02:17:53', '2026-01-27 02:17:53', 1, '2026-01-27 02:47:58');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `division_id` int(11) NOT NULL,
  `status` enum('active','decommissioned') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `division_id`, `status`) VALUES
(1, 'Emergency Medicine', 1, 'active'),
(2, 'Internal Medicine', 1, 'active'),
(3, 'Pediatrics', 1, 'active'),
(4, 'Obstetrics & Gynecology', 2, 'active'),
(5, 'Surgery', 2, 'active'),
(6, 'Pharmacy', 3, 'active'),
(7, 'Laboratory', 3, 'active'),
(8, 'Radiology', 3, 'active'),
(9, 'Finance & Accounting', 3, 'active'),
(10, 'Human Resources', 3, 'active'),
(11, 'Medical Records', 3, 'active'),
(12, 'General Services', 4, 'active'),
(13, 'Information Technology', 4, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `divisions`
--

CREATE TABLE `divisions` (
  `division_id` int(11) NOT NULL,
  `division_name` text NOT NULL,
  `status` enum('active','decommissioned') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `divisions`
--

INSERT INTO `divisions` (`division_id`, `division_name`, `status`) VALUES
(1, 'Medical Services', 'active'),
(2, 'Surgical Services', 'active'),
(3, 'Administrative Services', 'active'),
(4, 'Support Services', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `comment` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`feedback_id`, `number_id`, `user_id`, `rating`, `comment`, `created_at`, `updated_at`) VALUES
(1, 78, 2, 4, 'wow', '2026-01-27 11:02:36', '2026-01-27 11:02:36');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `is_head_reply` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(4) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_archive`
--

CREATE TABLE `messages_archive` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `is_head_reply` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages_archive`
--

INSERT INTO `messages_archive` (`message_id`, `sender_id`, `receiver_id`, `number_id`, `conversation_id`, `message`, `created_at`, `updated_at`, `is_read`, `is_head_reply`, `is_archived`, `archived_at`) VALUES
(41, 1, 2, 1, 11, 'YEYHEY NING GANA', '2026-01-26 14:06:53', '2026-01-26 14:07:06', 1, 0, 0, '2026-01-27 00:55:11'),
(42, 2, 1, 1, 11, 'niceeeee', '2026-01-26 14:07:09', '2026-01-26 14:10:47', 1, 0, 0, '2026-01-27 00:55:11'),
(43, 1, 2, 1, 11, 'please', '2026-01-26 15:10:31', '2026-01-26 15:11:02', 1, 0, 0, '2026-01-27 00:55:11'),
(44, 2, 1, 1, 11, 'please', '2026-01-26 15:11:07', '2026-01-26 15:11:07', 0, 0, 0, '2026-01-27 00:55:11'),
(45, 1, 2, 1, 12, 'please ma archive', '2026-01-27 08:56:24', '2026-01-27 08:56:37', 1, 0, 0, '2026-01-27 02:16:16'),
(46, 2, 1, 1, 12, 'ganigani', '2026-01-27 08:56:40', '2026-01-27 08:57:03', 1, 0, 0, '2026-01-27 02:16:16'),
(47, 1, 2, 1, 13, 'oi', '2026-01-27 10:17:53', '2026-01-27 10:17:53', 0, 0, 0, '2026-01-27 02:47:58'),
(48, 2, 11, 78, 1, 'sup nigga', '2026-01-27 11:13:00', '2026-01-27 11:13:00', 0, 0, 0, '2026-01-27 06:37:20'),
(49, 2, 11, 78, 1, 'sup nigga', '2026-01-27 11:13:42', '2026-01-27 11:13:42', 0, 0, 0, '2026-01-27 06:37:20');

-- --------------------------------------------------------

--
-- Table structure for table `numbers`
--

CREATE TABLE `numbers` (
  `numbers` varchar(20) NOT NULL,
  `description` text NOT NULL,
  `head_user_id` int(11) NOT NULL,
  `head` varchar(255) DEFAULT NULL,
  `division_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `office_id` int(11) DEFAULT NULL,
  `number_id` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `numbers`
--

INSERT INTO `numbers` (`numbers`, `description`, `head_user_id`, `head`, `division_id`, `department_id`, `unit_id`, `office_id`, `number_id`, `status`) VALUES
('118', 'ER Triage', 3, 'ER Head', 1, 1, 1, 1, 1, 'active'),
('150', 'ER Triage', 3, 'ER Head', 1, 1, 1, 1, 2, 'active'),
('135', 'ER CIU', 3, 'ER Head', 1, 1, 2, 2, 3, 'active'),
('134', 'ER Pedia', 3, 'ER Head', 1, 1, 2, 2, 4, 'active'),
('615', 'ER Medicine/EMED', 3, 'ER Head', 1, 1, 2, 2, 5, 'active'),
('131', 'ER Medicine', 3, 'ER Head', 1, 1, 2, 2, 6, 'active'),
('136', 'ER FAMED', 3, 'ER Head', 1, 1, 2, 2, 7, 'active'),
('139', 'ER Surgery', 3, 'ER Head', 1, 1, 2, 2, 8, 'active'),
('116', 'MSS ER', 3, 'ER Head', 1, 1, 2, 2, 9, 'active'),
('222', 'ER TINT', 3, 'ER Head', 1, 1, 2, 2, 10, 'active'),
('509', 'Medicine Ward 1', 4, 'Medicine Head', 1, 2, 3, 2, 11, 'active'),
('408', 'Medicine Ward 2', 4, 'Medicine Head', 1, 2, 3, 2, 12, 'active'),
('507', 'MICU Nurse Station', 4, 'Medicine Head', 1, 2, 4, 2, 13, 'active'),
('514', 'CCU', 4, 'Medicine Head', 1, 2, 4, 2, 14, 'active'),
('300', 'CENSICU', 4, 'Medicine Head', 1, 2, 4, 2, 15, 'active'),
('511', 'Medicine Conference Room', 4, 'Medicine Head', 1, 2, NULL, 3, 16, 'active'),
('506', 'Misc Ward', 4, 'Medicine Head', 1, 2, 3, 2, 17, 'active'),
('863', 'Medicine Ward 1 Extension', 4, 'Medicine Head', 1, 2, 3, 2, 18, 'active'),
('248', 'Private ICU', 4, 'Medicine Head', 1, 2, 4, 2, 19, 'active'),
('621', 'AY/Adolescent', 5, 'Pedia Head', 1, 3, 5, 2, 20, 'active'),
('403', 'Pedia Gastro', 5, 'Pedia Head', 1, 3, 5, 2, 21, 'active'),
('500', 'Pedia Nurse Station/MR', 5, 'Pedia Head', 1, 3, 5, 2, 22, 'active'),
('400', 'Pedia Neo/NEO Ward', 5, 'Pedia Head', 1, 3, 6, 2, 23, 'active'),
('519', 'Pedia PUM', 5, 'Pedia Head', 1, 3, 5, 2, 24, 'active'),
('404', 'PICU', 5, 'Pedia Head', 1, 3, 5, 2, 25, 'active'),
('401', 'Pedia Pulmo', 5, 'Pedia Head', 1, 3, 5, 2, 26, 'active'),
('510', 'Pedia Onco', 5, 'Pedia Head', 1, 3, 5, 2, 27, 'active'),
('314', 'NICU/Outborn', 5, 'Pedia Head', 1, 3, 6, 2, 28, 'active'),
('517', 'NICU Complex/Inborn', 5, 'Pedia Head', 1, 3, 6, 2, 29, 'active'),
('402', 'Pedia Conference Room', 5, 'Pedia Head', 1, 3, NULL, 3, 30, 'active'),
('313', 'OB Gyne Ward', 6, 'OB-GYN Head', 2, 4, 7, 2, 31, 'active'),
('312', 'OB Nurse Station', 6, 'OB-GYN Head', 2, 4, 7, 2, 32, 'active'),
('516', 'OB ER Complex', 6, 'OB-GYN Head', 2, 4, 7, 2, 33, 'active'),
('315', 'Delivery Room', 6, 'OB-GYN Head', 2, 4, 8, 2, 34, 'active'),
('119', 'DR/Labor Room', 6, 'OB-GYN Head', 2, 4, 8, 2, 35, 'active'),
('319', 'OB Conference Room', 6, 'OB-GYN Head', 2, 4, NULL, 3, 36, 'active'),
('324', 'OB IMCU/Gyne Ward', 6, 'OB-GYN Head', 2, 4, 7, 2, 37, 'active'),
('831', 'ACC O.R.', 7, 'Surgery Head', 2, 5, 9, 2, 38, 'active'),
('303', 'Operating Room', 7, 'Surgery Head', 2, 5, 9, 2, 39, 'active'),
('309', 'PACU', 7, 'Surgery Head', 2, 5, 9, 2, 40, 'active'),
('310', 'Anesthesia Conference Room', 7, 'Surgery Head', 2, 5, NULL, 3, 41, 'active'),
('318', 'Surgery Conference Room', 7, 'Surgery Head', 2, 5, NULL, 3, 42, 'active'),
('316', 'Surgery Ward', 7, 'Surgery Head', 2, 5, NULL, 2, 43, 'active'),
('317', 'Neuro Ortho Ward', 7, 'Surgery Head', 2, 5, NULL, 2, 44, 'active'),
('861', 'Neuro Ortho Conference Room', 7, 'Surgery Head', 2, 5, NULL, 3, 45, 'active'),
('237', 'Surgery IMCU', 7, 'Surgery Head', 2, 5, NULL, 2, 46, 'active'),
('826', 'ACC Pharmacy', 8, 'Pharmacy Head', 3, 6, 10, 3, 47, 'active'),
('602', 'Pharmacy Main', 8, 'Pharmacy Head', 3, 6, 10, 3, 48, 'active'),
('305', 'Pharmacy O.R.', 8, 'Pharmacy Head', 3, 6, 10, 3, 49, 'active'),
('634', 'Pharmacy Warehouse', 8, 'Pharmacy Head', 3, 6, 10, 3, 50, 'active'),
('110', 'Pharmacy Bus. Center', 8, 'Pharmacy Head', 3, 6, 10, 3, 51, 'active'),
('609', 'Cashier Pharmacy', 8, 'Pharmacy Head', 3, 6, 10, 3, 52, 'active'),
('123', 'Laboratory Histopath', 9, 'Lab Head', 3, 7, 11, 3, 53, 'active'),
('122', 'Laboratory/Genexpert', 9, 'Lab Head', 3, 7, 11, 3, 54, 'active'),
('126', 'Laboratory OPD', 9, 'Lab Head', 3, 7, 11, 3, 55, 'active'),
('127', 'Laboratory Main', 9, 'Lab Head', 3, 7, 11, 3, 56, 'active'),
('130', 'Laboratory Bloodbank', 9, 'Lab Head', 3, 7, 11, 3, 57, 'active'),
('125', 'Laboratory Bacteriology', 9, 'Lab Head', 3, 7, 11, 3, 58, 'active'),
('124', 'Laboratory Histopath Doctor', 9, 'Lab Head', 3, 7, 11, 3, 59, 'active'),
('141', 'Laboratory Transfusion', 9, 'Lab Head', 3, 7, 11, 3, 60, 'active'),
('154', 'Laboratory OPD New', 9, 'Lab Head', 3, 7, 11, 3, 61, 'active'),
('847', 'ACC CT Scan', 10, 'Radiology Head', 3, 8, 12, 3, 62, 'active'),
('106', 'CT Scan Main', 10, 'Radiology Head', 3, 8, 12, 3, 63, 'active'),
('836', 'CT Scan Cancer Center', 10, 'Radiology Head', 3, 8, 12, 3, 64, 'active'),
('105', 'M.R.I. Main', 10, 'Radiology Head', 3, 8, NULL, 3, 65, 'active'),
('843', 'Cancer Center M.R.I.', 10, 'Radiology Head', 3, 8, NULL, 3, 66, 'active'),
('109', 'Ultrasound (Old)', 10, 'Radiology Head', 3, 8, NULL, 3, 67, 'active'),
('132', 'Ultrasound (New)', 10, 'Radiology Head', 3, 8, NULL, 3, 68, 'active'),
('832', 'ACC Ultrasound', 10, 'Radiology Head', 3, 8, NULL, 3, 69, 'active'),
('103', 'Mammography', 10, 'Radiology Head', 3, 8, NULL, 3, 70, 'active'),
('107', 'Radiology Office', 10, 'Radiology Head', 3, 8, NULL, 3, 71, 'active'),
('133', 'Radiology Conference Room', 10, 'Radiology Head', 3, 8, NULL, 3, 72, 'active'),
('120', 'Radiology E.R.', 10, 'Radiology Head', 3, 8, NULL, 3, 73, 'active'),
('104', 'Radiology Reception', 10, 'Radiology Head', 3, 8, NULL, 4, 74, 'active'),
('722', 'NUCMED/Nuclear Medicine', 10, 'Radiology Head', 3, 8, NULL, 3, 75, 'active'),
('111', 'NUCMED 2nd Floor', 10, 'Radiology Head', 3, 8, NULL, 3, 76, 'active'),
('829', 'ACC Cashier', 11, 'Finance Head', 3, 9, 14, 3, 77, 'active'),
('845', 'ACC Billing', 11, 'Finance Head', 3, 9, 13, 3, 78, 'active'),
('844', 'ACC MSS', 11, 'Finance Head', 3, 9, NULL, 3, 79, 'active'),
('830', 'ACC Nurse Station', 11, 'Finance Head', 3, 9, NULL, 2, 80, 'active'),
('828', 'ACC Philhealth', 11, 'Finance Head', 3, 9, NULL, 3, 81, 'active'),
('708', 'Accounting 1', 11, 'Finance Head', 3, 9, 13, 3, 82, 'active'),
('709', 'Accounting 2', 11, 'Finance Head', 3, 9, 13, 3, 83, 'active'),
('710', 'Accounting Head', 11, 'Finance Head', 3, 9, 13, 3, 84, 'active'),
('113', 'Billing & Claims Main', 11, 'Finance Head', 3, 9, 13, 3, 85, 'active'),
('114', 'Billing & Claims Head', 11, 'Finance Head', 3, 9, 13, 3, 86, 'active'),
('111', 'Billing & Claims Staff', 11, 'Finance Head', 3, 9, 13, 3, 87, 'active'),
('814', 'Cashier Main', 11, 'Finance Head', 3, 9, 14, 3, 88, 'active'),
('815', 'Cashier Head', 11, 'Finance Head', 3, 9, 14, 3, 89, 'active'),
('117', 'Cashier Admitting', 11, 'Finance Head', 3, 9, 14, 3, 90, 'active'),
('112', 'Cashier Bus. Center', 11, 'Finance Head', 3, 9, 14, 3, 91, 'active'),
('705', 'Finance & Budget Section', 11, 'Finance Head', 3, 9, 13, 3, 92, 'active'),
('707', 'Finance Head', 11, 'Finance Head', 3, 9, NULL, 3, 93, 'active'),
('706', 'Finance Staff', 11, 'Finance Head', 3, 9, NULL, 3, 94, 'active'),
('612', 'Budget Officer', 11, 'Finance Head', 3, 9, 13, 3, 95, 'active'),
('714', 'HRMIS/Human Resource Head', 12, 'HR Head', 3, 10, 15, 3, 96, 'active'),
('715', 'HRMIS/Human Resource Staff', 12, 'HR Head', 3, 10, 15, 3, 97, 'active'),
('721', 'HRMIS/Human Resource Staff', 12, 'HR Head', 3, 10, 15, 3, 98, 'active'),
('724', 'HRMIS', 12, 'HR Head', 3, 10, 15, 3, 99, 'active'),
('727', 'HR Payroll', 12, 'HR Head', 3, 10, 15, 3, 100, 'active'),
('805', 'Medical Records Head', 13, 'Medical Records Head', 3, 11, 16, 3, 101, 'active'),
('803', 'Medical Records 1st Floor', 13, 'Medical Records Head', 3, 11, 16, 3, 102, 'active'),
('804', 'Medical Records 2nd Floor', 13, 'Medical Records Head', 3, 11, 16, 3, 103, 'active'),
('869', 'OGR/Head', 13, 'Medical Records Head', 3, 11, 16, 3, 104, 'active'),
('818', 'OGR Office/General Records', 13, 'Medical Records Head', 3, 11, 16, 3, 105, 'active'),
('504', 'GSO', 14, 'GSO Head', 4, 12, 17, 3, 106, 'active'),
('505', 'Housekeeping', 14, 'GSO Head', 4, 12, 17, 3, 107, 'active'),
('852', 'Linen/Laundry', 14, 'GSO Head', 4, 12, 17, 3, 108, 'active'),
('802', 'Powerhouse', 14, 'GSO Head', 4, 12, 17, 3, 109, 'active'),
('855', 'Plumbing', 14, 'GSO Head', 4, 12, 17, 3, 110, 'active'),
('857', 'Biomed', 14, 'GSO Head', 4, 12, 17, 3, 111, 'active'),
('853', 'Motorpool', 14, 'GSO Head', 4, 12, 17, 3, 112, 'active'),
('600', 'Guard House', 14, 'GSO Head', 4, 12, 17, 3, 113, 'active'),
('101', 'Exit Area Guard', 14, 'GSO Head', 4, 12, 17, 3, 114, 'active'),
('518', 'Main Entrance Guard', 14, 'GSO Head', 4, 12, 17, 3, 115, 'active'),
('601', 'Watchers Area', 14, 'GSO Head', 4, 12, 17, 4, 116, 'active'),
('823', 'ICT Head', 15, 'ICT Head', 4, 13, 18, 3, 117, 'active'),
('824', 'ICT Technical', 15, 'ICT Head', 4, 13, 18, 3, 118, 'active'),
('0', 'ICT Operator', 15, 'ICT Head', 4, 13, 18, 3, 119, 'active'),
('624', 'PHD/Mental Health', 16, 'Directory Admin', 1, NULL, NULL, 3, 120, 'active'),
('625', 'OPD Amphi Theater', 16, 'Directory Admin', 1, NULL, NULL, 3, 121, 'active'),
('644', 'OPD Animal Bite', 16, 'Directory Admin', 1, NULL, NULL, 3, 122, 'active'),
('642', 'OPD Virtual 1', 16, 'Directory Admin', 1, NULL, NULL, 3, 123, 'active'),
('635', 'OPD Virtual 2', 16, 'Directory Admin', 1, NULL, NULL, 3, 124, 'active'),
('145', 'OPD Triage', 16, 'Directory Admin', 1, NULL, NULL, 1, 125, 'active'),
('618', 'OPD Dental', 16, 'Directory Admin', 2, NULL, NULL, 3, 126, 'active'),
('619', 'OPD Optha', 16, 'Directory Admin', 2, NULL, NULL, 3, 127, 'active'),
('613', 'OPD OB', 16, 'Directory Admin', 2, NULL, NULL, 3, 128, 'active'),
('614', 'OPD Surgery', 16, 'Directory Admin', 2, NULL, NULL, 3, 129, 'active'),
('604', 'OPD Surgery', 16, 'Directory Admin', 2, NULL, NULL, 3, 130, 'active'),
('620', 'OPD Red Star Clinic', 16, 'Directory Admin', 1, NULL, NULL, 3, 131, 'active'),
('607', 'OPD Radiology', 16, 'Directory Admin', 3, NULL, NULL, 3, 132, 'active'),
('808', 'MCC Office', 2, 'Admin', 3, NULL, NULL, 3, 133, 'active'),
('809', 'MCC Lobby', 2, 'Admin', 3, NULL, NULL, 4, 134, 'active'),
('812', 'Chief Nurse', 2, 'Admin', 3, NULL, NULL, 3, 135, 'active'),
('813', 'Chief Nurse Secretariat', 2, 'Admin', 3, NULL, NULL, 3, 136, 'active'),
('102', 'Supervisors Office', 2, 'Admin', 3, NULL, NULL, 3, 137, 'active'),
('311', 'MSS Main', 2, 'Admin', 3, NULL, NULL, 3, 138, 'active'),
('321', 'WCPU', 2, 'Admin', 3, NULL, NULL, 3, 139, 'active'),
('806', 'Chief of Clinics', 2, 'Admin', 3, NULL, NULL, 3, 140, 'active'),
('807', 'Chief of Clinics Secretariat', 2, 'Admin', 3, NULL, NULL, 3, 141, 'active'),
('702', 'CAO', 2, 'Admin', 3, NULL, NULL, 3, 142, 'active'),
('703', 'CAO Head', 2, 'Admin', 3, NULL, NULL, 3, 143, 'active'),
('816', 'COA', 2, 'Admin', 3, NULL, NULL, 3, 144, 'active'),
('810', 'Legal Office', 2, 'Admin', 3, NULL, NULL, 3, 145, 'active'),
('819', 'QMS/PSMO', 2, 'Admin', 3, NULL, NULL, 3, 146, 'active'),
('811', 'Research Unit', 2, 'Admin', 3, NULL, NULL, 3, 147, 'active'),
('817', 'R.D.R. Conference Room', 2, 'Admin', 3, NULL, NULL, 3, 148, 'active'),
('821', 'PET U Head', 2, 'Admin', 3, NULL, NULL, 3, 149, 'active'),
('820', 'PET U Office', 2, 'Admin', 3, NULL, NULL, 3, 150, 'active'),
('822', 'NSO', 2, 'Admin', 3, NULL, NULL, 3, 151, 'active'),
('800', 'EFMS Head', 2, 'Admin', 3, NULL, NULL, 3, 152, 'active'),
('801', 'EFMS Staff', 2, 'Admin', 3, NULL, NULL, 3, 153, 'active'),
('623', 'HEPO', 2, 'Admin', 3, NULL, NULL, 3, 154, 'active'),
('622', 'PHD', 2, 'Admin', 3, NULL, NULL, 3, 155, 'active'),
('629', 'Malasakit Center', 2, 'Admin', 3, NULL, NULL, 3, 156, 'active'),
('870', 'CMPS/DR FLORES', 2, 'Admin', 3, NULL, NULL, 3, 157, 'active'),
('617', 'HSS', 2, 'Admin', 3, NULL, NULL, 3, 158, 'active'),
('840', 'Cancer Center Branch Unit', 2, 'Admin', 3, NULL, NULL, 3, 159, 'active'),
('839', 'Cancer Center Infusion Unit', 2, 'Admin', 3, NULL, NULL, 3, 160, 'active'),
('838', 'Cancer Center Linac Control', 2, 'Admin', 3, NULL, NULL, 3, 161, 'active'),
('837', 'Cancer Center Physics Room', 2, 'Admin', 3, NULL, NULL, 3, 162, 'active'),
('835', 'Cancer Center Triage', 2, 'Admin', 3, NULL, NULL, 3, 163, 'active'),
('833', 'Cancer Center MSS', 2, 'Admin', 3, NULL, NULL, 3, 164, 'active'),
('834', 'Cancer Center Triage', 2, 'Admin', 3, NULL, NULL, 3, 165, 'active'),
('867', 'Cancer Center Linac', 2, 'Admin', 3, NULL, NULL, 3, 166, 'active'),
('872', 'Cancer Center Pharmacy', 2, 'Admin', 3, NULL, NULL, 3, 167, 'active'),
('850', 'Hostel 1', 2, 'Admin', 4, NULL, NULL, 3, 168, 'active'),
('858', 'Hostel 2', 2, 'Admin', 4, NULL, NULL, 3, 169, 'active'),
('856', 'Hostel 3', 2, 'Admin', 4, NULL, NULL, 3, 170, 'active'),
('859', 'Hostel Office', 2, 'Admin', 4, NULL, NULL, 3, 171, 'active'),
('254', 'Private North Wing', 2, 'Admin', 1, NULL, NULL, 2, 172, 'active'),
('202', 'Private Main 1', 2, 'Admin', 1, NULL, NULL, 2, 173, 'active'),
('211', 'Private Main 2', 2, 'Admin', 1, NULL, NULL, 2, 174, 'active'),
('863', 'PUM Ward', 2, 'Admin', 1, NULL, NULL, 2, 175, 'active'),
('237', 'PUM ICU', 2, 'Admin', 1, NULL, NULL, 2, 176, 'active'),
('234', 'Dialysis COVID', 2, 'Admin', 3, NULL, NULL, 3, 177, 'active'),
('253', 'OPCEN 1', 2, 'Admin', 1, NULL, NULL, 3, 178, 'active'),
('210', 'OPCEN 2', 2, 'Admin', 1, NULL, NULL, 3, 179, 'active'),
('137', 'OPCEN 3', 2, 'Admin', 1, NULL, NULL, 3, 180, 'active'),
('200', 'OPCEN Triage', 2, 'Admin', 1, NULL, NULL, 1, 181, 'active'),
('716', 'OPCEN Extension', 2, 'Admin', 1, NULL, NULL, 3, 182, 'active'),
('236', 'OPCEN Extension 2', 2, 'Admin', 1, NULL, NULL, 3, 183, 'active'),
('302', 'Endoscopy', 2, 'Admin', 2, NULL, NULL, 3, 184, 'active'),
('209', 'ERID Complex', 2, 'Admin', 1, NULL, NULL, 3, 185, 'active'),
('322', 'Entrance Triage/Balay sa Lumad', 2, 'Admin', 1, NULL, NULL, 1, 186, 'active'),
('100', 'PAC D 1', 2, 'Admin', 1, NULL, NULL, 3, 187, 'active'),
('144', 'PAC D 2', 2, 'Admin', 1, NULL, NULL, 3, 188, 'active'),
('512', 'IPCU', 2, 'Admin', 1, NULL, NULL, 2, 189, 'active'),
('508', 'ASU', 2, 'Admin', 3, NULL, NULL, 3, 190, 'active'),
('864', 'Wellness', 2, 'Admin', 1, NULL, NULL, 3, 191, 'active'),
('868', 'MMS Bodega', 2, 'Admin', 4, NULL, NULL, 3, 192, 'active'),
('701', 'MMS Office', 2, 'Admin', 4, NULL, NULL, 3, 193, 'active'),
('700', 'MMS Head', 2, 'Admin', 4, NULL, NULL, 3, 194, 'active'),
('115', 'Admitting', 16, 'Directory Admin', 3, NULL, NULL, 3, 195, 'active'),
('151', 'BUCAS 1 Registration', 16, 'Directory Admin', 3, NULL, NULL, 4, 196, 'active'),
('152', 'BUCAS 2', 16, 'Directory Admin', 3, NULL, NULL, 4, 197, 'active'),
('153', 'BUCAS 3', 16, 'Directory Admin', 3, NULL, NULL, 4, 198, 'active'),
('704', 'Planning Office', 16, 'Directory Admin', 3, NULL, NULL, 3, 199, 'active'),
('713', 'Procurement Head', 16, 'Directory Admin', 3, NULL, NULL, 3, 200, 'active'),
('712', 'Procurement Office', 16, 'Directory Admin', 3, NULL, NULL, 3, 201, 'active'),
('711', 'BAC', 16, 'Directory Admin', 3, NULL, NULL, 3, 202, 'active'),
('121', 'Physical Therapy/PMR', 16, 'Directory Admin', 3, NULL, NULL, 3, 203, 'active'),
('717', 'Dietary', 16, 'Directory Admin', 3, NULL, NULL, 3, 204, 'active'),
('719', 'Dietary Clinical Nutrition', 16, 'Directory Admin', 3, NULL, NULL, 3, 205, 'active'),
('630', 'Kidney Center/RDU Down', 2, 'Admin', 3, NULL, NULL, 3, 206, 'active'),
('306', 'Kidney Center/RDU Up', 2, 'Admin', 3, NULL, NULL, 3, 207, 'active'),
('301', 'Respiratory Therapy', 2, 'Admin', 3, NULL, NULL, 3, 208, 'active'),
('842', 'RTH', 2, 'Admin', 3, NULL, NULL, 3, 209, 'active'),
('084-6297120', 'DRMC Telephone Hotline', 2, 'Admin', NULL, NULL, NULL, NULL, 210, 'active'),
('9126417327', 'DRMC SMART Hotline', 2, 'Admin', NULL, NULL, NULL, NULL, 211, 'active'),
('9559801761', 'DRMC Globe Hotline', 2, 'Admin', NULL, NULL, NULL, NULL, 212, 'active'),
('9190967660', 'OPCEN SMART Hotline', 2, 'Admin', NULL, NULL, NULL, NULL, 213, 'active'),
('9171035843', 'OPCEN Globe 1', 2, 'Admin', NULL, NULL, NULL, NULL, 214, 'active'),
('9171044743', 'OPCEN Transit', 2, 'Admin', NULL, NULL, NULL, NULL, 215, 'active'),
('2164731', 'Fire Station', 2, 'Admin', NULL, NULL, NULL, NULL, 216, 'active'),
('2161845', 'PNP Tagum 1', 2, 'Admin', NULL, NULL, NULL, NULL, 217, 'active'),
('6556439', 'PNP Tagum 2', 2, 'Admin', NULL, NULL, NULL, NULL, 218, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `offices`
--

CREATE TABLE `offices` (
  `office_id` int(11) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `status` enum('active','decommissioned') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `offices`
--

INSERT INTO `offices` (`office_id`, `office_name`, `unit_id`, `status`) VALUES
(1, 'Main Desk', 1, 'active'),
(2, 'Nurse Station', 2, 'active'),
(3, 'Office', 3, 'active'),
(4, 'Reception', 4, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'Admin'),
(2, 'MCC'),
(3, 'Division Head'),
(4, 'Department Head'),
(5, 'Unit Head'),
(6, 'Office Head'),
(7, 'Staff');

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(100) NOT NULL,
  `department_id` int(11) NOT NULL,
  `status` enum('active','decommissioned') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`unit_id`, `unit_name`, `department_id`, `status`) VALUES
(1, 'ER Triage', 1, 'active'),
(2, 'ER Treatment', 1, 'active'),
(3, 'Medicine Ward', 2, 'active'),
(4, 'ICU', 2, 'active'),
(5, 'Pedia Ward', 3, 'active'),
(6, 'NICU', 3, 'active'),
(7, 'OB Ward', 4, 'active'),
(8, 'Delivery Room', 4, 'active'),
(9, 'Operating Room', 5, 'active'),
(10, 'Main Pharmacy', 6, 'active'),
(11, 'Main Laboratory', 7, 'active'),
(12, 'CT Scan', 8, 'active'),
(13, 'Accounting', 9, 'active'),
(14, 'Cashier', 9, 'active'),
(15, 'HR Office', 10, 'active'),
(16, 'Records Office', 11, 'active'),
(17, 'Maintenance', 12, 'active'),
(18, 'ICT Office', 13, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` text NOT NULL,
  `role_id` int(11) NOT NULL,
  `division_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `office_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `last_activity` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `full_name`, `role_id`, `division_id`, `department_id`, `unit_id`, `office_id`, `status`, `last_activity`) VALUES
(2, 'Operator', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'dalvyx123@gmail.com', 'Operator', 1, NULL, NULL, NULL, NULL, 'active', 1769561210),
(3, 'er_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'er.head@drmc.gov.ph', 'Emergency Medicine Head', 4, NULL, 1, NULL, NULL, 'active', 1769560010),
(4, 'medicine_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'medicine.head@drmc.gov.ph', 'Internal Medicine Head', 4, NULL, 2, NULL, NULL, 'active', 0),
(5, 'pedia_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'pedia.head@drmc.gov.ph', 'Pediatrics Head', 4, NULL, 3, NULL, NULL, 'active', 0),
(6, 'obgyn_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'obgyn.head@drmc.gov.ph', 'OB-GYN Head', 4, NULL, 4, NULL, NULL, 'active', 0),
(7, 'surgery_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'surgery.head@drmc.gov.ph', 'Surgery Head', 4, NULL, 5, NULL, NULL, 'active', 0),
(8, 'pharmacy_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'pharmacy.head@drmc.gov.ph', 'Pharmacy Head', 4, NULL, 6, NULL, NULL, 'active', 0),
(9, 'lab_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'lab.head@drmc.gov.ph', 'Laboratory Head', 4, NULL, 7, NULL, NULL, 'active', 0),
(10, 'radiology_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'radiology.head@drmc.gov.ph', 'Radiology Head', 4, NULL, 8, NULL, NULL, 'active', 0),
(11, 'finance_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'finance.head@drmc.gov.ph', 'Finance Head', 4, NULL, 9, NULL, NULL, 'active', 0),
(12, 'hr_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'hr.head@drmc.gov.ph', 'HR Head', 4, NULL, 10, NULL, NULL, 'active', 0),
(13, 'medrec_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'medrec.head@drmc.gov.ph', 'Medical Records Head', 4, NULL, 11, NULL, NULL, 'active', 0),
(14, 'gso_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'gso.head@drmc.gov.ph', 'GSO Head', 4, NULL, 12, NULL, NULL, 'active', 1769561210),
(15, 'ict_head', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'ict.head@drmc.gov.ph', 'ICT Head', 4, NULL, 13, NULL, NULL, 'active', 0),
(16, 'directory_admin', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'directory@drmc.gov.ph', 'Directory Administrator', 7, NULL, NULL, NULL, NULL, 'active', 0),
(17, 'deo', '$2y$10$.qdQOKYPKrDcLHGe0mjyjevYJZVMUp3ijc8diIGHUwGqWIIKiINbK', 'deoverpascojr@gmail.com', 'Deooo', 1, NULL, NULL, NULL, NULL, 'active', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_online_status`
--

CREATE TABLE `user_online_status` (
  `user_id` int(11) NOT NULL,
  `last_seen` datetime NOT NULL,
  `is_online` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_chats`
--
ALTER TABLE `admin_chats`
  ADD PRIMARY KEY (`chat_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `admin_messages`
--
ALTER TABLE `admin_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `chat_id` (`chat_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `idx_number_id` (`number_id`),
  ADD KEY `idx_initiated_by` (`initiated_by`),
  ADD KEY `idx_last_activity` (`last_activity`),
  ADD KEY `idx_is_archived` (`is_archived`);

--
-- Indexes for table `conversations_archive`
--
ALTER TABLE `conversations_archive`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `idx_number_id` (`number_id`),
  ADD KEY `idx_initiated_by` (`initiated_by`),
  ADD KEY `idx_last_activity` (`last_activity`),
  ADD KEY `idx_is_archived` (`is_archived`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD KEY `division_id` (`division_id`);

--
-- Indexes for table `divisions`
--
ALTER TABLE `divisions`
  ADD PRIMARY KEY (`division_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD UNIQUE KEY `unique_feedback` (`number_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `number_id` (`number_id`),
  ADD KEY `idx_sender_receiver` (`sender_id`,`receiver_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `fk_messages_conversation_id` (`conversation_id`);

--
-- Indexes for table `messages_archive`
--
ALTER TABLE `messages_archive`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `number_id` (`number_id`),
  ADD KEY `idx_sender_receiver` (`sender_id`,`receiver_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `fk_messages_conversation_id` (`conversation_id`);

--
-- Indexes for table `numbers`
--
ALTER TABLE `numbers`
  ADD PRIMARY KEY (`number_id`),
  ADD KEY `division_id` (`division_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `office_id` (`office_id`),
  ADD KEY `head_user_id` (`head_user_id`);

--
-- Indexes for table `offices`
--
ALTER TABLE `offices`
  ADD PRIMARY KEY (`office_id`),
  ADD KEY `unit_id` (`unit_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`unit_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `division_id` (`division_id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `unit_id` (`unit_id`),
  ADD KEY `office_id` (`office_id`);

--
-- Indexes for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_chats`
--
ALTER TABLE `admin_chats`
  MODIFY `chat_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `admin_messages`
--
ALTER TABLE `admin_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `divisions`
--
ALTER TABLE `divisions`
  MODIFY `division_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `numbers`
--
ALTER TABLE `numbers`
  MODIFY `number_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=219;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `office_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_chats`
--
ALTER TABLE `admin_chats`
  ADD CONSTRAINT `admin_chats_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_chats_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_messages`
--
ALTER TABLE `admin_messages`
  ADD CONSTRAINT `admin_messages_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `admin_chats` (`chat_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`number_id`) REFERENCES `numbers` (`number_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`division_id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`number_id`) REFERENCES `numbers` (`number_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `feedback_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `conid` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `numbb` FOREIGN KEY (`number_id`) REFERENCES `numbers` (`number_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `recieve` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `send` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `numbers`
--
ALTER TABLE `numbers`
  ADD CONSTRAINT `numbers_ibfk_1` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`division_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `numbers_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `numbers_ibfk_3` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `numbers_ibfk_4` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `numbers_ibfk_5` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `userasasd` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `offices`
--
ALTER TABLE `offices`
  ADD CONSTRAINT `unit_ofice` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `units`
--
ALTER TABLE `units`
  ADD CONSTRAINT `department_unit` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `division` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`division_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD CONSTRAINT `status_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
