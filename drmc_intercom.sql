-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 22, 2026 at 12:53 AM
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
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` int(11) NOT NULL,
  `number_id` int(11) NOT NULL,
  `initiated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`conversation_id`, `number_id`, `initiated_by`, `created_at`) VALUES
(1, 13, 4, '2026-01-21 07:22:30'),
(2, 7, 4, '2026-01-21 08:46:30'),
(3, 6, 4, '2026-01-21 23:50:14');

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
(1, 'department 1', 1, 'active'),
(2, 'department 2', 2, 'active'),
(3, 'department 3', 1, 'active'),
(5, 'Nursing', 6, 'active'),
(6, 'OPS', 7, 'active');

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
(1, 'division 1', 'decommissioned'),
(2, 'division 2', 'active'),
(3, 'division 3', 'active'),
(6, 'Medical', 'active'),
(7, 'Hospital Operations', 'active');

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
(2, 6, 1, 4, 'meh', '2026-01-21 10:50:21', '2026-01-21 11:25:01'),
(3, 3, 1, 1, 'wowers', '2026-01-21 10:50:30', '2026-01-21 10:50:30'),
(4, 6, 4, 3, 'you mid', '2026-01-21 13:02:21', '2026-01-21 13:02:21');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) DEFAULT NULL,
  `receiver_head` varchar(255) DEFAULT NULL,
  `number_id` int(11) NOT NULL,
  `conversation_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `parent_message_id` int(11) DEFAULT NULL,
  `chat_thread` varchar(50) DEFAULT NULL,
  `is_head_reply` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `sender_id`, `receiver_id`, `receiver_head`, `number_id`, `conversation_id`, `message`, `created_at`, `updated_at`, `is_read`, `parent_message_id`, `chat_thread`, `is_head_reply`) VALUES
(1, 1, NULL, 'John Cena', 13, NULL, 'sup g', '2026-01-21 09:00:58', '2026-01-21 14:49:58', 0, NULL, 'chat_1_e35dedd267261eebb310b95e8e73c19b_1768978198', 0),
(2, 1, NULL, 'Roy Nino Salas', 6, NULL, 'sup g', '2026-01-21 11:32:56', '2026-01-21 14:49:58', 0, NULL, 'chat_1_57d9c5f08e36d017745d11ec7be7cb53_1768978198', 0),
(3, 4, NULL, NULL, 13, 1, 'yo sup', '2026-01-21 15:22:30', '2026-01-21 15:22:30', 0, NULL, NULL, 0),
(4, 4, NULL, NULL, 7, 2, 'sup', '2026-01-21 16:46:30', '2026-01-21 16:46:30', 0, NULL, NULL, 0),
(5, 4, NULL, NULL, 6, 3, 'sup g', '2026-01-22 07:50:14', '2026-01-22 07:50:14', 0, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `numbers`
--

CREATE TABLE `numbers` (
  `numbers` int(11) NOT NULL,
  `description` text NOT NULL,
  `head` text NOT NULL,
  `head_user_id` int(11) DEFAULT NULL,
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

INSERT INTO `numbers` (`numbers`, `description`, `head`, `head_user_id`, `division_id`, `department_id`, `unit_id`, `office_id`, `number_id`, `status`) VALUES
(1234, 'Landline', 'Deover Pasco Jr.', NULL, 6, NULL, NULL, NULL, 3, 'active'),
(1234, 'Intercom', 'Deover Pasco Jr.', NULL, 6, NULL, NULL, NULL, 4, 'active'),
(4321, 'SMS', 'Rain Winslet Reyes', NULL, NULL, 5, NULL, NULL, 5, 'active'),
(9999, 'SMS', 'Roy Nino Salas', NULL, 7, NULL, NULL, NULL, 6, 'active'),
(9999, 'Intercom', 'Roy Nino Salas', NULL, 7, NULL, NULL, NULL, 7, 'active'),
(5643, 'Intercom', 'Ealdrick James  Raganas', NULL, NULL, 6, NULL, NULL, 8, 'active'),
(42335, 'Landline', 'Ealdrick James  Raganas', NULL, NULL, 6, NULL, NULL, 9, 'active'),
(67, 'Landline', 'Godwin Solis', NULL, NULL, NULL, 3, NULL, 10, 'active'),
(68, 'Landline', 'Hubert', NULL, NULL, NULL, 1, NULL, 11, 'active'),
(2147483647, 'Landline', 'Cris Jhan', NULL, NULL, NULL, NULL, 2, 12, 'active'),
(69420, 'Landline', 'John Cena', NULL, 1, NULL, NULL, NULL, 13, 'decommissioned'),
(2147483647, 'Landline', 'Lukas blalba', NULL, NULL, NULL, NULL, 3, 14, 'active');

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
(1, 'HR', 3, 'active'),
(2, 'office 67', 3, 'active'),
(3, 'office 420', 3, 'active');

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
(1, 'unit 1', 1, 'active'),
(2, 'unit 2', 2, 'active'),
(3, 'unitundernursing', 5, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
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
(1, 'admintest', '$2y$10$JdoWmxeMwRwNXSgid775a.f.EzmhiFEbCzkz6Mz8ujcu.BkQpS28O', NULL, NULL, 1, NULL, NULL, NULL, NULL, 'active', 1769038541),
(2, 'jdADMIN', '$2y$10$kXa/V.J0goFobRXrWKe3Bu3LjRB56g0QwIiA/mny9MiImTXL4aIX.', 'dalvyx123@gmail.com', 'JDLT', 4, 6, 5, NULL, NULL, 'active', 0),
(3, 'reign', '$2y$10$36eZEKyskm2w73VvSerYmua1rve/oJiZqb.DFtHEkfyQ8XgIdRLG.', 'raignewinsletreyes@gmail.com', 'raigne winslet a. reyes', 1, NULL, NULL, NULL, NULL, 'active', 0),
(4, 'ricky', '$2y$10$A6Iq5VpqFY7l1heRWLYcJ.2jsfar8mRl.6Rh2xX7dypgi/nXA.qfy', 'junjun@gmail.com', 'Ricardo Diaz', 4, 7, 6, NULL, NULL, 'active', 1769039403);

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
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `idx_number_id` (`number_id`),
  ADD KEY `idx_initiated_by` (`initiated_by`);

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
  ADD KEY `idx_receiver_head` (`receiver_head`),
  ADD KEY `idx_chat_thread` (`chat_thread`),
  ADD KEY `idx_parent_message` (`parent_message_id`),
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
  ADD KEY `fk_numbers_head_user_id` (`head_user_id`);

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
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `divisions`
--
ALTER TABLE `divisions`
  MODIFY `division_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `numbers`
--
ALTER TABLE `numbers`
  MODIFY `number_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `office_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

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
  ADD CONSTRAINT `fk_messages_conversation_id` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`number_id`) REFERENCES `numbers` (`number_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `messages_ibfk_4` FOREIGN KEY (`parent_message_id`) REFERENCES `messages` (`message_id`) ON DELETE SET NULL;

--
-- Constraints for table `numbers`
--
ALTER TABLE `numbers`
  ADD CONSTRAINT `department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `divsion` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`division_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_numbers_head_user_id` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `offices`
--
ALTER TABLE `offices`
  ADD CONSTRAINT `offices_ibfk_1` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`) ON DELETE CASCADE;

--
-- Constraints for table `units`
--
ALTER TABLE `units`
  ADD CONSTRAINT `units_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`division_id`),
  ADD CONSTRAINT `users_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `users_ibfk_4` FOREIGN KEY (`unit_id`) REFERENCES `units` (`unit_id`),
  ADD CONSTRAINT `users_ibfk_5` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`);

--
-- Constraints for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD CONSTRAINT `user_online_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
