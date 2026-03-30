-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2026-03-30 18:00:57
-- 服务器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `apartment`
--

-- --------------------------------------------------------

--
-- 表的结构 `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `ip_addr` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `detail`, `ip_addr`, `created_at`) VALUES
(1, NULL, 'LOGIN_FAILED', 'Invalid credentials for email: guard@apt.com | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:28:54'),
(2, NULL, 'LOGIN_FAILED', 'Invalid credentials for email: guard@apt.com | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:29:11'),
(3, NULL, 'LOGIN_FAILED', 'Invalid credentials for email: guard@apt.com | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:29:40'),
(4, NULL, 'LOGIN_FAILED', 'Invalid credentials for email: guard@apt.com | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:29:48'),
(5, NULL, 'LOGIN_FAILED', 'Invalid credentials for email: guard@apt.com | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:30:15'),
(6, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:31:26'),
(7, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:32:24'),
(8, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:34:40'),
(9, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:43:19'),
(10, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:48:38'),
(11, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:49:09'),
(12, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:49:20'),
(13, 4, 'BOOKING_CREATED', 'Plate: QQQ0101 applied to visit Resident ID: 3 | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:50:07'),
(14, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:50:10'),
(15, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:50:12'),
(16, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:50:25'),
(17, 3, 'BOOKING_APPROVED', 'Booking 2 approved. Slot: VA-01 | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:50:37'),
(18, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 07:51:04'),
(19, 1, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:00:19'),
(20, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:09:48'),
(21, 5, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:16:29'),
(22, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:19:56'),
(23, 1, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:32:41'),
(24, 5, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-27 20:33:11'),
(25, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 16:57:57'),
(26, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 16:59:07'),
(27, 4, 'BOOKING_CREATED', 'Plate: QQQ0101 applied to visit Resident ID: 3 | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:00:03'),
(28, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:00:22'),
(29, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:00:38'),
(30, 1, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:01:26'),
(31, 5, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:03:06'),
(32, 5, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:04:49'),
(33, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:09:18'),
(34, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:07'),
(35, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:14'),
(36, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:16'),
(37, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:17'),
(38, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:17'),
(39, 2, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:26'),
(40, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:10:39'),
(41, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:11:10'),
(42, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:11:26'),
(43, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:11:28'),
(44, 4, 'UNAUTHORIZED_ACCESS', 'Attempted to access restricted page | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:11:42'),
(45, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:12:26'),
(46, 4, 'BOOKING_CREATED', 'Plate: BBB1111 applied to visit Resident ID: 3 | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:13:00'),
(47, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:13:17'),
(48, 3, 'BOOKING_APPROVED', 'Booking 4 approved. Slot: VA-01 | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:13:31'),
(49, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:13:56'),
(50, 1, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:15:02'),
(51, 5, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:16:05'),
(52, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:19:40'),
(53, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:19:48'),
(54, 4, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:24:05'),
(55, 3, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:28:58'),
(56, 2, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:29:58'),
(57, 1, 'LOGIN_SUCCESS', 'User logged in successfully | Device: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '::1', '2026-03-29 17:32:21');

-- --------------------------------------------------------

--
-- 表的结构 `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `plate_no` varchar(20) NOT NULL,
  `visitor_name` varchar(120) DEFAULT NULL,
  `resident_id` int(11) DEFAULT NULL,
  `slot_id` int(11) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('pending','approved','allocated','checked_in','checked_out','closed','expired') DEFAULT 'pending',
  `qr_token` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `bookings`
--

INSERT INTO `bookings` (`id`, `plate_no`, `visitor_name`, `resident_id`, `slot_id`, `start_time`, `end_time`, `status`, `qr_token`, `created_at`) VALUES
(1, 'WXX1234', 'Ali Bin Abu', 3, 1, '2026-03-27 06:39:34', '2026-03-27 09:39:34', 'checked_in', NULL, '2026-03-27 07:39:34'),
(2, 'QQQ0101', 'ALIBABA', 3, 2, '2026-03-27 07:49:00', '2026-03-27 09:49:00', '', 'd94a2db48aa47df8fd66388987daf8e8', '2026-03-27 07:50:07'),
(3, 'QQQ0101', 'ALIBABA', 3, NULL, '2026-03-01 16:59:00', '2026-03-29 18:59:00', 'pending', '4e466eb679306fd3d49eb00e35ea8623', '2026-03-29 17:00:03'),
(4, 'BBB1111', 'ALIBABA', 3, 2, '2026-03-01 17:12:00', '2026-03-29 19:12:00', 'checked_in', '24cf894ca5f4726401105e3879af6393', '2026-03-29 17:13:00');

-- --------------------------------------------------------

--
-- 表的结构 `gate_logs`
--

CREATE TABLE `gate_logs` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `plate_no` varchar(20) NOT NULL,
  `gate_action` enum('ENTRY','EXIT') NOT NULL,
  `decision` enum('ALLOW','DENY') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `ocr_confidence` decimal(4,2) DEFAULT NULL,
  `guard_id` int(11) DEFAULT NULL,
  `action_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `gate_logs`
--

INSERT INTO `gate_logs` (`id`, `booking_id`, `plate_no`, `gate_action`, `decision`, `reason`, `ocr_confidence`, `guard_id`, `action_time`) VALUES
(1, NULL, 'VAA8899', 'ENTRY', 'DENY', 'No valid booking or expired', NULL, 2, '2026-03-27 07:39:50'),
(2, NULL, 'CZZ7764', 'ENTRY', 'DENY', 'No valid booking or expired', NULL, 2, '2026-03-27 07:40:01'),
(3, 1, 'WXX1234', 'ENTRY', 'ALLOW', 'Valid Booking', NULL, 2, '2026-03-27 07:40:14'),
(4, 2, 'QQQ0101', 'ENTRY', 'ALLOW', 'Valid Booking', NULL, 2, '2026-03-27 07:51:12'),
(5, NULL, 'QQQ0101', 'ENTRY', 'DENY', 'No valid booking or expired', NULL, 2, '2026-03-27 07:51:31'),
(6, NULL, 'QQQ0101', 'ENTRY', 'DENY', 'No valid booking or expired', NULL, 2, '2026-03-27 07:51:37'),
(7, 2, 'QQQ0101', 'EXIT', 'ALLOW', 'Checked out', NULL, 2, '2026-03-27 20:10:02'),
(8, 4, 'BBB1111', 'ENTRY', 'ALLOW', 'Valid Visitor Booking', NULL, 2, '2026-03-29 17:14:38');

-- --------------------------------------------------------

--
-- 表的结构 `parking_slots`
--

CREATE TABLE `parking_slots` (
  `id` int(11) NOT NULL,
  `block_name` enum('Block A','Block B','Block C') NOT NULL,
  `slot_no` varchar(20) NOT NULL,
  `slot_type` enum('Resident','Visitor','Disabled','Loading') DEFAULT 'Visitor',
  `status` enum('available','reserved','maintenance') DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `parking_slots`
--

INSERT INTO `parking_slots` (`id`, `block_name`, `slot_no`, `slot_type`, `status`) VALUES
(1, 'Block B', 'VB-88', 'Visitor', 'reserved'),
(2, 'Block A', 'VA-01', 'Visitor', 'reserved');

-- --------------------------------------------------------

--
-- 表的结构 `resident_vehicles`
--

CREATE TABLE `resident_vehicles` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `plate_no` varchar(20) NOT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `system_config`
--

CREATE TABLE `system_config` (
  `id` int(11) NOT NULL,
  `grace_minutes` int(11) DEFAULT 15,
  `ocr_threshold` decimal(4,2) DEFAULT 0.75,
  `log_retention_days` int(11) DEFAULT 90
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `system_config`
--

INSERT INTO `system_config` (`id`, `grace_minutes`, `ocr_threshold`, `log_retention_days`) VALUES
(1, 15, 0.75, 90);

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('visitor','resident','guard','admin','superadmin') NOT NULL,
  `status` enum('active','inactive','watchlist','blacklisted') DEFAULT 'active',
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `role`, `status`, `phone`, `created_at`) VALUES
(1, 'admin@apt.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active', NULL, '2026-03-27 07:21:48'),
(2, 'guard@apt.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guard', 'active', NULL, '2026-03-27 07:21:48'),
(3, 'resident@apt.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'resident', 'active', NULL, '2026-03-27 07:21:48'),
(4, 'visitor@apt.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'visitor', 'active', NULL, '2026-03-27 07:41:57'),
(5, 'super@apt.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin', 'active', NULL, '2026-03-27 20:15:37');

--
-- 转储表的索引
--

--
-- 表的索引 `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `slot_id` (`slot_id`);

--
-- 表的索引 `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `parking_slots`
--
ALTER TABLE `parking_slots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slot_no` (`slot_no`);

--
-- 表的索引 `resident_vehicles`
--
ALTER TABLE `resident_vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_no` (`plate_no`),
  ADD KEY `resident_id` (`resident_id`);

--
-- 表的索引 `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- 使用表AUTO_INCREMENT `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `gate_logs`
--
ALTER TABLE `gate_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- 使用表AUTO_INCREMENT `parking_slots`
--
ALTER TABLE `parking_slots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `resident_vehicles`
--
ALTER TABLE `resident_vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- 限制导出的表
--

--
-- 限制表 `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`);

--
-- 限制表 `resident_vehicles`
--
ALTER TABLE `resident_vehicles`
  ADD CONSTRAINT `resident_vehicles_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
