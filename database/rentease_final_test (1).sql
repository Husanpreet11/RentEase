-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 11, 2026 at 11:49 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rentease_final_test`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `activity_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`activity_id`, `user_id`, `action_type`, `entity_type`, `entity_id`, `description`, `created_at`) VALUES
(1, 1, 'CREATE_PROPERTY', 'property', 1, 'Arman added property RE-P101.', '2026-07-16 09:00:00'),
(2, 1, 'CREATE_PROPERTY', 'property', 2, 'Arman added property RE-P102.', '2026-07-16 09:10:00'),
(3, 1, 'CREATE_PROPERTY', 'property', 3, 'Arman added property RE-P103.', '2026-07-16 09:20:00'),
(4, 1, 'CREATE_LEASE', 'lease', 1, 'Arman created current lease for Husan Kaur.', '2026-07-24 10:00:00'),
(5, 1, 'CREATE_LEASE', 'lease', 2, 'Arman created current lease for Qurat Khan.', '2026-07-25 11:00:00'),
(6, 1, 'CREATE_LEASE', 'lease', 3, 'Arman created current lease for Vijay Kumar.', '2026-07-26 09:30:00'),
(7, 2, 'SUBMIT_MAINTENANCE', 'maintenance', 1, 'Husan submitted a bathroom tap request.', '2026-09-27 18:20:00'),
(8, 3, 'SUBMIT_MAINTENANCE', 'maintenance', 2, 'Qurat submitted a kitchen outlet request.', '2026-10-01 09:10:00'),
(9, 4, 'SUBMIT_MAINTENANCE', 'maintenance', 3, 'Vijay submitted a wardrobe door request.', '2026-10-04 19:30:00'),
(10, 1, 'SCHEDULE_INSPECTION', 'inspection', 2, 'Arman scheduled routine inspection for RE-P102.', '2026-10-04 09:00:00'),
(11, 1, 'SCHEDULE_INSPECTION', 'inspection', 3, 'Arman scheduled routine inspection for RE-P103.', '2026-10-04 09:10:00'),
(12, 1, 'UPDATE_PROFILE', 'user', 1, 'Manager updated their profile information.', '2026-10-06 17:59:35'),
(13, 1, 'UPDATE_PROPERTY', 'property', 5, 'Updated property information', '2026-10-06 18:04:21'),
(14, 1, 'UPDATE_TENANT', 'tenant', 2, 'Updated tenant account for Husan Chahal.', '2026-10-06 18:07:45'),
(15, 1, 'UPDATE_TENANT', 'tenant', 2, 'Updated tenant account for Husan Chahal.', '2026-10-06 18:08:06'),
(16, 1, 'ADD_UTILITY_BILL', 'utility_bill', 7, 'Added Internet utility bill of $300.00 for Husan Chahal.', '2026-10-06 18:12:27'),
(17, 1, 'CHANGE_PASSWORD', 'user', 1, 'Manager changed their account password.', '2026-10-06 23:31:44'),
(18, 2, 'SUBMIT_MAINTENANCE', 'maintenance', 5, 'Husan Kaur submitted maintenance request: Thermostat not working.', '2026-10-06 23:55:47'),
(19, 2, 'PAY_RENT', 'rent_payment', 8, 'Tenant completed rent payment RENT-20261006150624-2.', '2026-10-07 00:06:24'),
(20, 4, 'PAY_UTILITY', 'utility_payment', 4, 'Tenant completed utility payment UTIL-20261007004737-4.', '2026-10-07 09:47:37'),
(21, 1, 'UPDATE_PROFILE', 'user', 1, 'Manager updated their profile information.', '2026-10-07 10:00:41'),
(22, 1, 'UPDATE_PROFILE', 'user', 1, 'Manager updated their profile information.', '2026-10-07 10:30:49'),
(23, 1, 'SEND_MESSAGE', 'message', 7, 'Manager sent a message to Vijay Sidhu.', '2026-10-07 10:39:14'),
(24, 1, 'UPDATE_PROFILE', 'user', 1, 'Manager updated their profile information.', '2026-10-07 22:05:46');

-- --------------------------------------------------------

--
-- Table structure for table `conversations`
--

CREATE TABLE `conversations` (
  `conversation_id` int(11) NOT NULL,
  `manager_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `subject` varchar(180) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `conversations`
--

INSERT INTO `conversations` (`conversation_id`, `manager_id`, `tenant_id`, `subject`, `created_at`, `last_message_at`) VALUES
(1, 1, 2, 'General tenancy communication', '2026-08-01 09:00:00', '2026-10-05 12:15:00'),
(2, 1, 3, 'General tenancy communication', '2026-08-08 09:00:00', '2026-10-04 15:30:00'),
(3, 1, 4, 'General tenancy communication', '2026-08-15 09:00:00', '2026-10-07 10:39:14');

-- --------------------------------------------------------

--
-- Table structure for table `inspections`
--

CREATE TABLE `inspections` (
  `inspection_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `scheduled_by` int(11) NOT NULL,
  `inspection_type` enum('routine','entry','exit','follow_up','other') NOT NULL DEFAULT 'routine',
  `scheduled_at` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `result_summary` text DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inspections`
--

INSERT INTO `inspections` (`inspection_id`, `property_id`, `tenant_id`, `scheduled_by`, `inspection_type`, `scheduled_at`, `notes`, `result_summary`, `status`, `completed_at`, `created_at`) VALUES
(1, 1, 2, 1, 'entry', '2026-08-03 10:00:00', 'Entry condition inspection after lease commencement.', 'Property condition recorded. No major issues identified.', 'completed', '2026-08-03 10:40:00', '2026-07-29 09:00:00'),
(2, 2, 3, 1, 'routine', '2026-11-12 13:30:00', 'Upcoming routine inspection.', NULL, 'scheduled', NULL, '2026-10-04 09:00:00'),
(3, 3, 4, 1, 'routine', '2026-11-20 11:00:00', 'Upcoming routine inspection.', NULL, 'scheduled', NULL, '2026-10-04 09:10:00');

-- --------------------------------------------------------

--
-- Table structure for table `leases`
--

CREATE TABLE `leases` (
  `lease_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `monthly_rent` decimal(10,2) NOT NULL,
  `bond_amount` decimal(10,2) DEFAULT 0.00,
  `lease_status` enum('draft','active','expired','terminated','renewal_due') NOT NULL DEFAULT 'draft',
  `lease_document` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leases`
--

INSERT INTO `leases` (`lease_id`, `property_id`, `tenant_id`, `start_date`, `end_date`, `monthly_rent`, `bond_amount`, `lease_status`, `lease_document`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 2, '2026-08-01', '2027-07-31', 2470.00, 2470.00, 'active', 'uploads/leases/lease_husan.pdf', 'Current twelve-month lease for Husan Kaur.', 1, '2026-07-24 10:00:00', '2026-10-06 16:51:57'),
(2, 2, 3, '2026-08-08', '2027-08-07', 2990.00, 2990.00, 'active', 'uploads/leases/lease_qurat.pdf', 'Current twelve-month lease for Qurat Khan.', 1, '2026-07-25 11:00:00', '2026-10-06 16:51:57'),
(3, 3, 4, '2026-08-15', '2027-08-14', 2578.33, 2578.33, 'active', 'uploads/leases/lease_vijay.pdf', 'Current twelve-month lease for Vijay Kumar.', 1, '2026-07-26 09:30:00', '2026-10-06 16:51:57');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_comments`
--

CREATE TABLE `maintenance_comments` (
  `maintenance_comment_id` int(11) NOT NULL,
  `maintenance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_comments`
--

INSERT INTO `maintenance_comments` (`maintenance_comment_id`, `maintenance_id`, `user_id`, `comment_text`, `created_at`) VALUES
(1, 1, 1, 'A plumber has been booked for the maintenance request.', '2026-09-28 09:05:00'),
(2, 1, 2, 'Thank you, that appointment time works for me.', '2026-09-28 09:20:00'),
(3, 2, 1, 'An electrician has been assigned to inspect the outlet.', '2026-10-02 09:35:00');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_photos`
--

CREATE TABLE `maintenance_photos` (
  `maintenance_photo_id` int(11) NOT NULL,
  `maintenance_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_photos`
--

INSERT INTO `maintenance_photos` (`maintenance_photo_id`, `maintenance_id`, `uploaded_by`, `file_path`, `caption`, `uploaded_at`) VALUES
(7, 1, 2, 'uploads/maintenance/bathroom_tap_leaking.png', 'Bathroom tap leaking', '2026-10-06 23:27:26'),
(8, 2, 3, 'uploads/maintenance/kitchen_power_outlet.jpg', 'Kitchen power outlet issue', '2026-10-06 23:27:26'),
(9, 3, 4, 'uploads/maintenance/wardrobe_door_loose.jpg', 'Wardrobe door loose', '2026-10-06 23:27:26'),
(10, 4, 4, 'uploads/maintenance/smoke_alarm.jpg', 'Smoke alarm check', '2026-10-06 23:27:26'),
(11, 5, 2, 'uploads/maintenance/maintenance_5_1791291347_6ab3ec6f.jpg', 'Photo submitted with maintenance request', '2026-10-06 23:55:47');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_requests`
--

CREATE TABLE `maintenance_requests` (
  `maintenance_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `category` enum('plumbing','electrical','appliance','heating_cooling','structural','security','other') NOT NULL DEFAULT 'other',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('submitted','in_review','scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'submitted',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `scheduled_date` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `assigned_to` varchar(120) DEFAULT NULL,
  `manager_summary` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_requests`
--

INSERT INTO `maintenance_requests` (`maintenance_id`, `property_id`, `tenant_id`, `title`, `description`, `category`, `priority`, `status`, `submitted_at`, `scheduled_date`, `completed_at`, `assigned_to`, `manager_summary`) VALUES
(1, 1, 2, 'Bathroom tap leaking', 'The bathroom basin tap keeps dripping after it is turned off.', 'plumbing', 'medium', 'scheduled', '2026-09-27 18:20:00', '2026-10-08 10:00:00', NULL, 'Capital Plumbing Services', 'Plumber scheduled to inspect the tap.'),
(2, 2, 3, 'Kitchen power outlet issue', 'One kitchen power outlet is not working.', 'electrical', 'high', 'in_progress', '2026-10-01 09:10:00', '2026-10-06 14:00:00', NULL, 'Canberra Electrical Care', 'Electrician assigned.'),
(3, 3, 4, 'Wardrobe door loose', 'Bedroom wardrobe sliding door has become loose.', 'other', 'low', 'submitted', '2026-10-04 19:30:00', NULL, NULL, NULL, NULL),
(4, 3, 4, 'Smoke alarm check', 'Smoke alarm made an intermittent warning sound.', 'security', 'high', 'completed', '2026-08-12 08:15:00', '2026-08-12 15:00:00', '2026-08-12 15:35:00', 'SafeHome Services', 'Battery replaced and alarm tested.'),
(5, 1, 2, 'Thermostat not working', 'The thermostat is not working properly. It does not make the house warm.', 'appliance', 'high', 'submitted', '2026-10-06 23:55:47', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_status_history`
--

CREATE TABLE `maintenance_status_history` (
  `maintenance_history_id` int(11) NOT NULL,
  `maintenance_id` int(11) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(11) NOT NULL,
  `change_note` varchar(500) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_status_history`
--

INSERT INTO `maintenance_status_history` (`maintenance_history_id`, `maintenance_id`, `old_status`, `new_status`, `changed_by`, `change_note`, `changed_at`) VALUES
(1, 1, NULL, 'submitted', 2, 'Tenant created maintenance request.', '2026-09-27 18:20:00'),
(2, 1, 'submitted', 'scheduled', 1, 'Plumber booked for inspection.', '2026-09-28 09:00:00'),
(3, 2, NULL, 'submitted', 3, 'Tenant created maintenance request.', '2026-10-01 09:10:00'),
(4, 2, 'submitted', 'in_progress', 1, 'Electrician assigned.', '2026-10-02 09:30:00'),
(5, 3, NULL, 'submitted', 4, 'Tenant created maintenance request.', '2026-10-04 19:30:00'),
(6, 4, NULL, 'submitted', 4, 'Tenant created maintenance request.', '2026-08-12 08:15:00'),
(7, 4, 'submitted', 'completed', 1, 'Alarm checked and battery replaced.', '2026-08-12 15:35:00'),
(8, 5, NULL, 'submitted', 2, 'Maintenance request submitted by tenant.', '2026-10-06 23:55:47');

-- --------------------------------------------------------

--
-- Table structure for table `manager_profiles`
--

CREATE TABLE `manager_profiles` (
  `manager_id` int(11) NOT NULL,
  `job_title` varchar(100) DEFAULT 'Property Manager',
  `office_phone` varchar(30) DEFAULT NULL,
  `office_email` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `manager_profiles`
--

INSERT INTO `manager_profiles` (`manager_id`, `job_title`, `office_phone`, `office_email`) VALUES
(1, 'Property Manager', '02 6123 4511', 'manager@rentease.com');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `conversation_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message_text` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `conversation_id`, `sender_id`, `message_text`, `sent_at`, `is_read`, `read_at`) VALUES
(1, 1, 2, 'Hi Arman, is the plumber still coming on Thursday?', '2026-10-05 12:00:00', 1, '2026-10-05 12:10:00'),
(2, 1, 1, 'Yes, the appointment is confirmed for 10:00 AM.', '2026-10-05 12:15:00', 1, '2026-10-06 23:56:08'),
(3, 2, 3, 'Thank you for arranging the electrician.', '2026-10-04 15:20:00', 1, '2026-10-04 15:25:00'),
(4, 2, 1, 'You are welcome. Please message me if the issue continues.', '2026-10-04 15:30:00', 1, '2026-10-04 16:00:00'),
(5, 3, 4, 'I submitted the wardrobe door request yesterday.', '2026-10-05 17:00:00', 1, '2026-10-05 17:05:00'),
(6, 3, 1, 'Thanks Vijay. I can see it and will arrange a repair.', '2026-10-05 17:10:00', 1, '2026-10-07 09:48:44'),
(7, 3, 1, 'hlo', '2026-10-07 10:39:14', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `notification_type` enum('rent_reminder','inspection_reminder','maintenance_update','lease_renewal','general_announcement','payment_update') NOT NULL,
  `title` varchar(160) NOT NULL,
  `message` text NOT NULL,
  `related_entity_type` enum('rent','utility','maintenance','inspection','lease','general') NOT NULL DEFAULT 'general',
  `related_entity_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `recipient_id`, `sender_id`, `notification_type`, `title`, `message`, `related_entity_type`, `related_entity_id`, `is_read`, `sent_at`, `read_at`) VALUES
(1, 2, 1, 'rent_reminder', 'October rent outstanding', 'Your October rent balance is still outstanding.', 'rent', 3, 1, '2026-10-05 09:00:00', '2026-10-06 23:56:14'),
(2, 2, 1, 'maintenance_update', 'Plumber scheduled', 'A plumber is scheduled for 8 October at 10:00 AM.', 'maintenance', 1, 1, '2026-09-28 09:05:00', '2026-10-06 23:56:14'),
(3, 3, 1, 'payment_update', 'Rent payment received', 'Your recent rent payment has been recorded.', 'rent', 6, 1, '2026-10-02 13:35:00', '2026-10-02 14:00:00'),
(4, 3, 1, 'inspection_reminder', 'Upcoming inspection', 'A routine inspection is scheduled for 12 November at 1:30 PM.', 'inspection', 2, 0, '2026-10-04 09:05:00', NULL),
(5, 4, 1, 'inspection_reminder', 'Upcoming inspection', 'A routine inspection is scheduled for 20 November at 11:00 AM.', 'inspection', 3, 1, '2026-10-04 09:15:00', '2026-10-07 09:49:20'),
(6, 4, 1, 'maintenance_update', 'Maintenance request received', 'Your wardrobe door request has been received.', 'maintenance', 3, 1, '2026-10-04 19:35:00', '2026-10-04 20:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `properties`
--

CREATE TABLE `properties` (
  `property_id` int(11) NOT NULL,
  `property_code` varchar(20) NOT NULL,
  `address_line1` varchar(180) NOT NULL,
  `suburb` varchar(100) NOT NULL,
  `state` varchar(50) NOT NULL,
  `postcode` varchar(10) NOT NULL,
  `bedrooms` int(11) NOT NULL DEFAULT 1,
  `bathrooms` decimal(3,1) NOT NULL DEFAULT 1.0,
  `parking_spaces` int(11) NOT NULL DEFAULT 0,
  `weekly_rent` decimal(10,2) NOT NULL,
  `property_status` enum('vacant','occupied','maintenance','inactive') NOT NULL DEFAULT 'vacant',
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `properties`
--

INSERT INTO `properties` (`property_id`, `property_code`, `address_line1`, `suburb`, `state`, `postcode`, `bedrooms`, `bathrooms`, `parking_spaces`, `weekly_rent`, `property_status`, `description`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'RE-P101', '24 Chandler Street', 'Belconnen', 'ACT', '2617', 2, 1.0, 1, 570.00, 'occupied', 'Two-bedroom apartment near local shops and transport.', 1, '2026-07-16 09:00:00', '2026-10-06 16:51:57'),
(2, 'RE-P102', '18 Manning Clark Crescent', 'Franklin', 'ACT', '2913', 3, 2.0, 2, 690.00, 'occupied', 'Three-bedroom townhouse with secure garage.', 1, '2026-07-16 09:10:00', '2026-10-06 16:51:57'),
(3, 'RE-P103', '7 Athllon Drive', 'Greenway', 'ACT', '2900', 2, 2.0, 1, 595.00, 'occupied', 'Modern two-bedroom unit with balcony.', 1, '2026-07-16 09:20:00', '2026-10-06 16:51:57'),
(4, 'RE-P104', '15 Acacia Lane', 'Kingston', 'ACT', '2604', 1, 1.0, 1, 535.00, 'vacant', 'One-bedroom apartment close to the town centre.', 1, '2026-07-16 09:30:00', '2026-10-06 16:51:57'),
(5, 'RE-P105', '72 Mountain View Road', 'Harrison', 'ACT', '2911', 4, 2.0, 2, 825.00, 'vacant', 'Four-bedroom family home with outdoor area.', 1, '2026-07-16 09:40:00', '2026-10-06 18:04:21');

-- --------------------------------------------------------

--
-- Table structure for table `property_photos`
--

CREATE TABLE `property_photos` (
  `photo_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `property_photos`
--

INSERT INTO `property_photos` (`photo_id`, `property_id`, `file_path`, `caption`, `is_primary`, `uploaded_at`) VALUES
(1, 1, 'uploads/properties/tenant1.jpg', 'Primary property photo', 1, '2026-07-16 10:00:00'),
(2, 2, 'uploads/properties/tenant2.png', 'Primary property photo', 1, '2026-07-16 10:05:00'),
(3, 3, 'uploads/properties/tenant3.jpeg', 'Primary property photo', 1, '2026-07-16 10:10:00'),
(4, 4, 'uploads/properties/tenant4.jpeg', 'Primary property photo', 1, '2026-07-16 10:15:00'),
(5, 4, 'uploads/properties/property_4_6ac49d251e20b.jpg', '', 0, '2026-10-06 18:03:01'),
(6, 5, 'uploads/properties/tenant5.jpg', NULL, 1, '2026-10-06 23:03:59');

-- --------------------------------------------------------

--
-- Table structure for table `rent_charges`
--

CREATE TABLE `rent_charges` (
  `rent_charge_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `rent_period` varchar(7) NOT NULL COMMENT 'YYYY-MM',
  `due_date` date NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `status` enum('upcoming','due','paid','overdue','part_paid') NOT NULL DEFAULT 'upcoming',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rent_charges`
--

INSERT INTO `rent_charges` (`rent_charge_id`, `lease_id`, `rent_period`, `due_date`, `amount_due`, `status`, `created_at`) VALUES
(1, 1, '2026-08', '2026-08-01', 2470.00, 'paid', '2026-07-25 08:00:00'),
(2, 1, '2026-09', '2026-09-01', 2470.00, 'paid', '2026-08-25 08:00:00'),
(3, 1, '2026-10', '2026-10-01', 2470.00, 'paid', '2026-09-25 08:00:00'),
(4, 2, '2026-08', '2026-08-08', 2990.00, 'paid', '2026-08-01 08:00:00'),
(5, 2, '2026-09', '2026-09-08', 2990.00, 'paid', '2026-09-01 08:00:00'),
(6, 2, '2026-10', '2026-10-08', 2990.00, 'due', '2026-10-01 08:00:00'),
(7, 3, '2026-08', '2026-08-15', 2578.33, 'paid', '2026-08-08 08:00:00'),
(8, 3, '2026-09', '2026-09-15', 2578.33, 'paid', '2026-09-08 08:00:00'),
(9, 3, '2026-10', '2026-10-15', 2578.33, 'part_paid', '2026-10-01 08:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `rent_payments`
--

CREATE TABLE `rent_payments` (
  `payment_id` int(11) NOT NULL,
  `rent_charge_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('bank_transfer','card','cash','other') NOT NULL DEFAULT 'card',
  `reference_number` varchar(100) DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `payment_status` enum('paid','rejected') NOT NULL DEFAULT 'paid',
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `manager_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rent_payments`
--

INSERT INTO `rent_payments` (`payment_id`, `rent_charge_id`, `tenant_id`, `amount_paid`, `submitted_at`, `payment_method`, `reference_number`, `receipt_file`, `payment_status`, `confirmed_by`, `confirmed_at`, `manager_note`) VALUES
(1, 1, 2, 2470.00, '2026-07-31 18:12:00', 'card', 'RENT-HK-AUG-001', NULL, 'paid', NULL, NULL, NULL),
(2, 2, 2, 2470.00, '2026-08-31 17:40:00', 'card', 'RENT-HK-SEP-002', NULL, 'paid', NULL, NULL, NULL),
(3, 4, 3, 2990.00, '2026-08-07 16:20:00', 'bank_transfer', 'RENT-QK-AUG-001', NULL, 'paid', NULL, NULL, NULL),
(4, 5, 3, 2990.00, '2026-09-07 18:05:00', 'card', 'RENT-QK-SEP-002', NULL, 'paid', NULL, NULL, NULL),
(5, 7, 4, 2578.33, '2026-08-14 17:35:00', 'card', 'RENT-VK-AUG-001', NULL, 'paid', NULL, NULL, NULL),
(6, 8, 4, 2578.33, '2026-09-14 19:10:00', 'bank_transfer', 'RENT-VK-SEP-002', NULL, 'paid', NULL, NULL, NULL),
(7, 9, 4, 1000.00, '2026-10-05 20:15:00', 'card', 'RENT-VK-OCT-003', NULL, 'paid', NULL, NULL, NULL),
(8, 3, 2, 2470.00, '2026-10-07 00:06:24', 'card', 'RENT-20261006150624-2', NULL, 'paid', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tenant_profiles`
--

CREATE TABLE `tenant_profiles` (
  `tenant_id` int(11) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `emergency_contact_name` varchar(120) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenant_profiles`
--

INSERT INTO `tenant_profiles` (`tenant_id`, `date_of_birth`, `emergency_contact_name`, `emergency_contact_phone`, `notes`) VALUES
(2, '2001-04-18', 'Arshdeep Singh', '0411 220 310', 'Prefers email communication.'),
(3, '2000-09-12', 'Ayesha Khan', '0420 330 421', 'Prefers afternoon inspection appointments.'),
(4, '1999-12-05', 'Raj Kumar', '0430 440 532', 'Prefers SMS for urgent property updates.');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `role` enum('manager','tenant') NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `account_status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `phone`, `address`, `password_hash`, `account_status`, `created_at`, `updated_at`) VALUES
(1, 'manager', 'Armaan', 'Sharififar', 'manager@rentease.com', '0402 410 511', '15 London Circuit, Canberra ACT 2611', '$2y$10$42sAlan2jgFGne91mtKCWOkwEJ80w4p7MFTo/kAyAa4kiHjMOMwva', 'active', '2026-07-15 09:00:00', '2026-10-07 10:30:49'),
(2, 'tenant', 'Husan', 'Kaur', 'tenant1@rentease.com', '0412 530 201', '24 Chandler Street, Belconnen ACT 2617', '$2y$10$9pBaXFrDa1xGPtulydAcc.DxzrpPbrbR2i0hTtz6XVO8K5n8Uk456', 'active', '2026-07-20 10:15:00', '2026-10-07 09:35:04'),
(3, 'tenant', 'Qurat', 'Khan', 'tenant2@rentease.com', '0423 640 312', '18 Manning Clark Crescent, Franklin ACT 2913', '$2y$10$p3GoRp88r9QumS5e3x6IYuRPJgetA0fmX9xa5EalVOhJzucNfqYDO', 'active', '2026-07-21 11:20:00', '2026-10-06 23:47:10'),
(4, 'tenant', 'Vijay', 'Sidhu', 'tenant3@rentease.com', '0434 750 423', '7 Athllon Drive, Greenway ACT 2900', '$2y$10$USanLMpmPFRIxV/2mP1SUegYMdsv9/P90UsTJwg4BNOGE/oNkc/CC', 'active', '2026-07-22 14:10:00', '2026-10-06 23:47:10');

-- --------------------------------------------------------

--
-- Table structure for table `utility_bills`
--

CREATE TABLE `utility_bills` (
  `utility_bill_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `utility_type` enum('electricity','gas','water','internet','other') NOT NULL,
  `provider_name` varchar(120) DEFAULT NULL,
  `billing_period_start` date NOT NULL,
  `billing_period_end` date NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(10,2) NOT NULL,
  `bill_file` varchar(255) DEFAULT NULL,
  `status` enum('upcoming','unpaid','part_paid','paid','overdue') NOT NULL DEFAULT 'unpaid',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `utility_bills`
--

INSERT INTO `utility_bills` (`utility_bill_id`, `lease_id`, `utility_type`, `provider_name`, `billing_period_start`, `billing_period_end`, `due_date`, `amount_due`, `bill_file`, `status`, `created_by`, `created_at`) VALUES
(1, 1, 'electricity', 'ActewAGL', '2026-08-01', '2026-08-31', '2026-09-18', 118.45, NULL, 'paid', 1, '2026-09-04 10:00:00'),
(2, 1, 'water', 'Icon Water', '2026-08-01', '2026-09-30', '2026-10-22', 74.60, NULL, 'unpaid', 1, '2026-10-03 10:00:00'),
(3, 2, 'electricity', 'ActewAGL', '2026-08-08', '2026-09-07', '2026-09-25', 142.80, NULL, 'paid', 1, '2026-09-10 10:10:00'),
(4, 2, 'internet', 'Aussie Broadband', '2026-09-08', '2026-10-07', '2026-10-18', 89.00, NULL, 'unpaid', 1, '2026-10-05 10:10:00'),
(5, 3, 'electricity', 'ActewAGL', '2026-08-15', '2026-09-14', '2026-10-02', 131.25, NULL, 'paid', 1, '2026-09-17 10:20:00'),
(6, 3, 'water', 'Icon Water', '2026-08-15', '2026-09-30', '2026-10-25', 68.90, NULL, 'paid', 1, '2026-10-04 10:20:00'),
(7, 1, 'internet', 'Telstra', '2026-08-01', '2026-09-01', '2026-09-15', 300.00, NULL, 'unpaid', 1, '2026-10-06 18:12:27');

-- --------------------------------------------------------

--
-- Table structure for table `utility_payments`
--

CREATE TABLE `utility_payments` (
  `utility_payment_id` int(11) NOT NULL,
  `utility_bill_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('bank_transfer','card','cash','other') NOT NULL DEFAULT 'card',
  `reference_number` varchar(100) DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `payment_status` enum('paid','rejected') NOT NULL DEFAULT 'paid',
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `manager_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `utility_payments`
--

INSERT INTO `utility_payments` (`utility_payment_id`, `utility_bill_id`, `tenant_id`, `amount_paid`, `submitted_at`, `payment_method`, `reference_number`, `receipt_file`, `payment_status`, `confirmed_by`, `confirmed_at`, `manager_note`) VALUES
(1, 1, 2, 118.45, '2026-09-16 18:10:00', 'card', 'UTIL-HK-001', NULL, 'paid', NULL, NULL, NULL),
(2, 3, 3, 142.80, '2026-09-23 17:25:00', 'bank_transfer', 'UTIL-QK-001', NULL, 'paid', NULL, NULL, NULL),
(3, 5, 4, 131.25, '2026-10-01 19:05:00', 'card', 'UTIL-VK-001', NULL, 'paid', NULL, NULL, NULL),
(4, 6, 4, 68.90, '2026-10-07 09:47:37', 'card', 'UTIL-20261007004737-4', NULL, 'paid', NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_activity_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_activity_date` (`created_at`);

--
-- Indexes for table `conversations`
--
ALTER TABLE `conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD UNIQUE KEY `uq_manager_tenant` (`manager_id`,`tenant_id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `inspections`
--
ALTER TABLE `inspections`
  ADD PRIMARY KEY (`inspection_id`),
  ADD KEY `property_id` (`property_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `scheduled_by` (`scheduled_by`);

--
-- Indexes for table `leases`
--
ALTER TABLE `leases`
  ADD PRIMARY KEY (`lease_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_lease_tenant` (`tenant_id`),
  ADD KEY `idx_lease_property` (`property_id`);

--
-- Indexes for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  ADD PRIMARY KEY (`maintenance_comment_id`),
  ADD KEY `maintenance_id` (`maintenance_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `maintenance_photos`
--
ALTER TABLE `maintenance_photos`
  ADD PRIMARY KEY (`maintenance_photo_id`),
  ADD KEY `maintenance_id` (`maintenance_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  ADD PRIMARY KEY (`maintenance_id`),
  ADD KEY `property_id` (`property_id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  ADD PRIMARY KEY (`maintenance_history_id`),
  ADD KEY `maintenance_id` (`maintenance_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `manager_profiles`
--
ALTER TABLE `manager_profiles`
  ADD PRIMARY KEY (`manager_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `conversation_id` (`conversation_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `recipient_id` (`recipient_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `properties`
--
ALTER TABLE `properties`
  ADD PRIMARY KEY (`property_id`),
  ADD UNIQUE KEY `property_code` (`property_code`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `property_photos`
--
ALTER TABLE `property_photos`
  ADD PRIMARY KEY (`photo_id`),
  ADD KEY `property_id` (`property_id`);

--
-- Indexes for table `rent_charges`
--
ALTER TABLE `rent_charges`
  ADD PRIMARY KEY (`rent_charge_id`),
  ADD UNIQUE KEY `uq_lease_period` (`lease_id`,`rent_period`);

--
-- Indexes for table `rent_payments`
--
ALTER TABLE `rent_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `rent_charge_id` (`rent_charge_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- Indexes for table `tenant_profiles`
--
ALTER TABLE `tenant_profiles`
  ADD PRIMARY KEY (`tenant_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `utility_bills`
--
ALTER TABLE `utility_bills`
  ADD PRIMARY KEY (`utility_bill_id`),
  ADD KEY `lease_id` (`lease_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `utility_payments`
--
ALTER TABLE `utility_payments`
  ADD PRIMARY KEY (`utility_payment_id`),
  ADD KEY `utility_bill_id` (`utility_bill_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `activity_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `conversations`
--
ALTER TABLE `conversations`
  MODIFY `conversation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inspections`
--
ALTER TABLE `inspections`
  MODIFY `inspection_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `leases`
--
ALTER TABLE `leases`
  MODIFY `lease_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  MODIFY `maintenance_comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maintenance_photos`
--
ALTER TABLE `maintenance_photos`
  MODIFY `maintenance_photo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  MODIFY `maintenance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  MODIFY `maintenance_history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `properties`
--
ALTER TABLE `properties`
  MODIFY `property_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `property_photos`
--
ALTER TABLE `property_photos`
  MODIFY `photo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `rent_charges`
--
ALTER TABLE `rent_charges`
  MODIFY `rent_charge_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `rent_payments`
--
ALTER TABLE `rent_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `utility_bills`
--
ALTER TABLE `utility_bills`
  MODIFY `utility_bill_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `utility_payments`
--
ALTER TABLE `utility_payments`
  MODIFY `utility_payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `conversations`
--
ALTER TABLE `conversations`
  ADD CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inspections`
--
ALTER TABLE `inspections`
  ADD CONSTRAINT `inspections_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`property_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `inspections_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `inspections_ibfk_3` FOREIGN KEY (`scheduled_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `leases`
--
ALTER TABLE `leases`
  ADD CONSTRAINT `leases_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`property_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `leases_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `leases_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_comments`
--
ALTER TABLE `maintenance_comments`
  ADD CONSTRAINT `maintenance_comments_ibfk_1` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_requests` (`maintenance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `maintenance_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_photos`
--
ALTER TABLE `maintenance_photos`
  ADD CONSTRAINT `maintenance_photos_ibfk_1` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_requests` (`maintenance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `maintenance_photos_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  ADD CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`property_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  ADD CONSTRAINT `maintenance_status_history_ibfk_1` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance_requests` (`maintenance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `maintenance_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `manager_profiles`
--
ALTER TABLE `manager_profiles`
  ADD CONSTRAINT `manager_profiles_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `properties`
--
ALTER TABLE `properties`
  ADD CONSTRAINT `properties_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `property_photos`
--
ALTER TABLE `property_photos`
  ADD CONSTRAINT `property_photos_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`property_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `rent_charges`
--
ALTER TABLE `rent_charges`
  ADD CONSTRAINT `rent_charges_ibfk_1` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`lease_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `rent_payments`
--
ALTER TABLE `rent_payments`
  ADD CONSTRAINT `rent_payments_ibfk_1` FOREIGN KEY (`rent_charge_id`) REFERENCES `rent_charges` (`rent_charge_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `rent_payments_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `rent_payments_ibfk_3` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tenant_profiles`
--
ALTER TABLE `tenant_profiles`
  ADD CONSTRAINT `tenant_profiles_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `utility_bills`
--
ALTER TABLE `utility_bills`
  ADD CONSTRAINT `utility_bills_ibfk_1` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`lease_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `utility_bills_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `utility_payments`
--
ALTER TABLE `utility_payments`
  ADD CONSTRAINT `utility_payments_ibfk_1` FOREIGN KEY (`utility_bill_id`) REFERENCES `utility_bills` (`utility_bill_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `utility_payments_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `utility_payments_ibfk_3` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
