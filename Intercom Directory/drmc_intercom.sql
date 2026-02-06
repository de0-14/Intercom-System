-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 04, 2026 at 07:28 AM
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

-- --------------------------------------------------------

--
-- Table structure for table `admin_chats`
--

CREATE TABLE `admin_chats` (
  `chat_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_chats_archive`
--

CREATE TABLE `admin_chats_archive` (
  `chat_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `is_read` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_messages_archive`
--

CREATE TABLE `admin_messages_archive` (
  `message_id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(3, 'Nursing', 'active'),
(4, 'test', 'active'),
(5, 'Medical', 'active');

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

-- --------------------------------------------------------

--
-- Table structure for table `numbers`
--

CREATE TABLE `numbers` (
  `numbers` varchar(20) NOT NULL,
  `email` text NOT NULL,
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

INSERT INTO `numbers` (`numbers`, `email`, `description`, `head_user_id`, `head`, `division_id`, `department_id`, `unit_id`, `office_id`, `number_id`, `status`) VALUES
('N/A', 'nursing@gmail.com', 'Division contact (Phone: Not Applicable) - Email available', 24, 'Nursing Head', 3, NULL, NULL, NULL, 3, 'active'),
('132, 321', '', 'Division contact (basta, lagi)', 27, 'test', 4, NULL, NULL, NULL, 4, 'active'),
('1234567890', 'dalvyx123@gmail.com', 'Division contact - Email available', 26, 'Ancillary Head', 5, NULL, NULL, NULL, 5, 'active');

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
  `status` enum('active','inactive') DEFAULT 'active',
  `last_activity` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `email`, `full_name`, `role_id`, `status`, `last_activity`) VALUES
(2, 'Operator', '$2y$10$1cl.dBem3urTDopqun8h5eGJkcdXYYyjG/onkmQLn5E.s7YwqohC.', 'dalvyx123@gmail.com', 'Operator', 1, 'active', 1770185702),
(24, 'Nursing Head', '$2y$10$dBLoYhXkyT2A4rGzARZwbOqMX2qKT.tlgDb55Sny/BnkOYqzAHUgK', 'medHead@gmail.com', 'Nursing Head', 3, 'active', 1770184735),
(25, 'Medical Head', '$2y$10$KenX3Uw6JSV0D0c8tnfRaO/AF2S/o0RSPNC4hyT7kK5NhOipAny4u', 'nurHead@gmail.com', 'Medical Head', 3, 'active', 1770094985),
(26, 'Ancillary Head', '$2y$10$TBaO9mEC3uRuOf3GMb2DiecWeAF4wHTEnwBsBw7XzKuEipLF9Sxcm', 'ancHead@gmail.com', 'Ancillary Head', 3, 'active', 0),
(27, 'test', '$2y$10$fHyf92.9//tze8h3g1m9YuschdVwVE2xevg7pWWusu7BbnNtAaXku', 'test@gmail.com', 'test', 4, 'active', 0);

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
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_is_archived` (`is_archived`),
  ADD KEY `idx_last_activity` (`last_activity`);

--
-- Indexes for table `admin_chats_archive`
--
ALTER TABLE `admin_chats_archive`
  ADD PRIMARY KEY (`chat_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_is_archived` (`is_archived`),
  ADD KEY `idx_last_activity` (`last_activity`);

--
-- Indexes for table `admin_messages`
--
ALTER TABLE `admin_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `chat_id` (`chat_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `admin_messages_archive`
--
ALTER TABLE `admin_messages_archive`
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
  ADD KEY `role_id` (`role_id`);

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
  MODIFY `chat_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_messages`
--
ALTER TABLE `admin_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `divisions`
--
ALTER TABLE `divisions`
  MODIFY `division_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `numbers`
--
ALTER TABLE `numbers`
  MODIFY `number_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `office_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

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
-- Constraints for table `admin_chats_archive`
--
ALTER TABLE `admin_chats_archive`
  ADD CONSTRAINT `admin_chats_archive_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_chats_archive_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_messages`
--
ALTER TABLE `admin_messages`
  ADD CONSTRAINT `admin_messages_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `admin_chats` (`chat_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_messages_archive`
--
ALTER TABLE `admin_messages_archive`
  ADD CONSTRAINT `admin_messages_archive_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `admin_chats_archive` (`chat_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admin_messages_archive_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD CONSTRAINT `status_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
