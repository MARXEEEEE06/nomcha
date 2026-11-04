-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 04, 2026 at 05:41 AM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nomcha_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `menu_categories`
--

CREATE TABLE `menu_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `menu_categories_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `menu_categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Seed data for table `menu_categories`
--

INSERT INTO `menu_categories` (`category_id`, `parent_id`, `name`, `description`, `sort_order`) VALUES
(1, NULL, 'Korean Fried Chicken Racks', 'Nomcha chikin favorites, prepared for sharing.', 1),
(2, NULL, 'Group Meals', 'Generous combinations for the table.', 2),
(3, NULL, 'Beef', 'Hearty Korean-inspired beef meals.', 3),
(4, NULL, 'Seafood', 'Crispy fish favorites and satisfying combos.', 4),
(5, NULL, 'Korean Rice Bowls', 'Comforting rice choices and Korean flavors.', 5),
(6, NULL, 'Sandwiches', 'Crispy chikin tucked into a soft bun.', 6),
(7, NULL, 'Noodle & Soup', 'Warm, savory bowls and pasta to share.', 7),
(8, NULL, 'Sides', 'Add a little something extra to your meal.', 8),
(9, NULL, 'Desserts', 'A cool, creamy finish to your meal.', 9),
(10, NULL, 'Beverages', 'Cold drinks to pair with your Nomcha favorites.', 10);

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `price` decimal(8,2) NOT NULL,
  `image_url` varchar(1000) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `menu_items_category_fk` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Seed data for table `menu_items`
--

INSERT INTO `menu_items` (`category_id`, `name`, `description`, `price`, `image_url`, `sort_order`) VALUES
(1, '6-pc chikin Ala Carte', 'Six pieces of crispy Nomcha chikin, served ala carte.', 235.00, 'https://bonchon.com.ph/storage/menu/nq3psktTAiY70CzEHpgount4JDLJ2g2uwEaIU5Xj.webp', 1),
(2, 'Large boneless chikin rack', 'A generous boneless chikin rack made for sharing.', 649.00, 'https://bonchon.com.ph/storage/menu/Qkhxn8KnBhAV5ovkV50RlZDhVySIZzcLOX0WO0Zz.webp', 1),
(2, 'Box to share rosebokki pasta', 'A shareable box pairing boneless chikin with rosebokki pasta.', 1149.00, 'https://bonchon.com.ph/storage/menu/z9dV5W53AT07bIPC9Qwg1M1h5pUTBkA0iTwkOml0.webp', 2),
(3, 'Pork BBQ meal', 'Savory pork BBQ served as a satisfying meal.', 291.00, 'https://bonchon.com.ph/storage/menu/2MIc1I4qVJmFZNxql5fVO752loiGXsX5fLTumEWz.png', 1),
(3, 'Beef snow meal', 'A hearty beef meal with a rich, savory finish.', 323.00, 'https://bonchon.com.ph/storage/menu/O8VJ02PHsBKijh8cbxLjNxZ07MnsCA8bsz2VaeOe.webp', 2),
(4, 'Crispy Fish meal', 'Crispy fish served with a classic side.', 292.00, 'https://bonchon.com.ph/storage/menu/TQmKqBpfe7KmwXBFy6fNOuryxXIg9DPTs2K7WnHv.webp', 1),
(8, 'Cheeseballs Ala cart', 'Golden, bite-sized cheeseballs served ala carte.', 125.00, 'https://bonchon.com.ph/storage/menu/YoxdlxmGi1bbQCcFpC1t6aOmfmltDicS8Uuaan0u.png', 1),
(8, 'Krispy fries regular', 'A regular serving of crisp, golden fries.', 79.00, 'https://bonchon.com.ph/storage/menu/CEwyiUZoI9rMVZbfE5OVhwJBS7P0fQTatqT3PU1P.webp', 2),
(5, 'Kimchi rice Ala carte', 'Korean-style kimchi rice served ala carte.', 93.00, 'https://bonchon.com.ph/storage/menu/mnLee3Q6kHqHfeum8k4Sxwa1waaN0Rznvm7Je6BK.png', 1),
(7, 'Korean miso soup', 'A warm, savory bowl of Korean-style miso soup.', 47.00, 'https://bonchon.com.ph/storage/menu/yaDVpiPPYXHEcpipK8uBXHLoTqK10lUsTORXpL5G.webp', 1),
(8, 'Pizza sauce', 'A side of pizza sauce for dipping.', 31.00, NULL, 3),
(6, 'Spicy reluxe sandwich Ala carte', 'Crispy chikin with a spicy kick in a soft sandwich bun.', 245.00, 'https://bonchon.com.ph/storage/menu/NKSOA9wF6Ll2TPxcYp2CrgjJYgtmyCVscL5xXenh.webp', 1),
(4, 'Fish & fries Ala carte', 'Crispy fish paired with a serving of fries.', 271.00, 'https://bonchon.com.ph/storage/menu/TQmKqBpfe7KmwXBFy6fNOuryxXIg9DPTs2K7WnHv.webp', 2),
(10, 'Koko mango snow drink', 'A cool mango drink blended for a sweet, refreshing sip.', 146.00, 'https://bonchon.com.ph/storage/menu/lP1YFhpV7QkO90Jok9wVkSpqZ1OTWVTsbpfSD5rk.webp', 1),
(9, 'Mango cheese bingsu', 'Shaved ice topped with mango and creamy cheese.', 145.00, NULL, 1),
(10, '1liter iced tea', 'A one-liter serving of refreshing iced tea.', 146.00, 'https://bonchon.com.ph/storage/menu/ZFMt4XcXzZQblERwUFIACOSxQrLKvTi1aadrcsiF.webp', 2),
(10, 'Large pineapple juice', 'A large serving of bright, fruity pineapple juice.', 93.00, 'https://bonchon.com.ph/storage/menu/kEeRCKrpqmMMQIxtGIjP0QglCfPtw63zqqJmHL0i.webp', 3),
(10, 'Regular soda', 'A regular cold soda.', 72.00, 'https://bonchon.com.ph/storage/menu/aOvZomiF7jegPwvcuYPu74SkYxPMrTc6V5TtLjda.webp', 4);

-- --------------------------------------------------------

--
-- Table structure for table `user_credentials`
--

CREATE TABLE `user_credentials` (
  `user_cred_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `email_addr` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_credentials`
--

INSERT INTO `user_credentials` (`user_cred_id`, `user_id`, `email_addr`, `password`) VALUES
(1, 1, 'marclapid2006@gmail.com', '$2y$12$LS3aKfjpH2IV5djXwMmgq.nZVeX8w2IbW83mLF8F5hgFsm/Mnq61O'),
(2, 2, 'tintinsuba@gmail.com', '$2y$12$mAN/Ci7lTeL5IwjF4bxAHu.owPLXu8G6fDpeJWUEuRWcVxUcgJjy2');

-- --------------------------------------------------------

--
-- Table structure for table `user_info`
--

CREATE TABLE `user_info` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `home_addr` varchar(255) DEFAULT NULL,
  `mobileno` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_info`
--

INSERT INTO `user_info` (`user_id`, `first_name`, `middle_name`, `last_name`, `home_addr`, `mobileno`) VALUES
(1, 'Marc Daniel', 'Manalang', 'Lapid', 'Santa Lucia Matua, Masantol, Pampanga', '09564206180'),
(2, 'Marc Daniel', 'Manalang', 'Lapid', 'Purok Centro Santa Lucia Matua', '09518975445');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `user_credentials`
--
ALTER TABLE `user_credentials`
  ADD PRIMARY KEY (`user_cred_id`),
  ADD UNIQUE KEY `email_addr` (`email_addr`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_info`
--
ALTER TABLE `user_info`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `mobileno` (`mobileno`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `user_credentials`
--
ALTER TABLE `user_credentials`
  MODIFY `user_cred_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_info`
--
ALTER TABLE `user_info`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_credentials`
--
ALTER TABLE `user_credentials`
  ADD CONSTRAINT `user_credentials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_info` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
