-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 06, 2026 at 08:03 AM
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
-- Table structure for table `customer_orders`
--

CREATE TABLE `customer_orders` (
  `order_id` int(11) NOT NULL,
  `order_number` varchar(24) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `fulfillment` enum('delivery','pickup') NOT NULL,
  `customer_name` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `delivery_address` varchar(750) DEFAULT NULL,
  `delivery_instructions` varchar(500) DEFAULT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `scheduled_for` datetime DEFAULT NULL,
  `payment_method` enum('cash','gcash','maya','card_pickup') NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `delivery_fee` decimal(8,2) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `payment_status` varchar(32) NOT NULL DEFAULT 'awaiting',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_orders`
--

INSERT INTO `customer_orders` (`order_id`, `order_number`, `user_id`, `fulfillment`, `customer_name`, `phone`, `delivery_address`, `delivery_instructions`, `pickup_location`, `scheduled_for`, `payment_method`, `subtotal`, `delivery_fee`, `total`, `status`, `payment_status`, `created_at`) VALUES
(9, 'NC2611045DB070', 1, 'pickup', 'admin', '09564206180', NULL, NULL, '289 Jose Abad Santos Ave, Mexico', '2026-11-05 10:00:00', 'cash', 702.00, 0.00, 702.00, 'pending', 'awaiting', '2026-11-04 17:15:58'),
(10, 'NC2611056CBBA5', 9, 'delivery', 'Clarise suba', '09543799314', 'Purok Centro Santa Lucia Matua', NULL, NULL, NULL, 'cash', 271.00, NULL, NULL, 'pending', 'awaiting', '2026-11-05 07:01:38'),
(11, 'NC261105D0F100', 1, 'delivery', 'admin', '09564206180', 'Purok Centro Santa Lucia Matua', NULL, NULL, NULL, 'gcash', 4953.00, NULL, NULL, 'pending', 'awaiting', '2026-11-05 07:15:00'),
(12, 'NC2611059CBBF0', 1, 'pickup', 'admin', '09564206180', NULL, NULL, '289 Jose Abad Santos Ave, Mexico', '2026-11-05 15:30:00', 'cash', 1404.00, 0.00, 1404.00, 'pending', 'awaiting', '2026-11-05 07:18:06');

-- --------------------------------------------------------

--
-- Table structure for table `customer_order_items`
--

CREATE TABLE `customer_order_items` (
  `order_item_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_name` varchar(160) NOT NULL,
  `unit_price` decimal(8,2) NOT NULL,
  `quantity` int(11) NOT NULL,
  `options_json` text DEFAULT NULL,
  `line_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_order_items`
--

INSERT INTO `customer_order_items` (`order_item_id`, `order_id`, `item_id`, `item_name`, `unit_price`, `quantity`, `options_json`, `line_total`) VALUES
(13, 9, 29, 'Fish N\' Chix Good for 3', 702.00, 1, NULL, 702.00),
(14, 10, 39, 'Fish & Fries', 271.00, 1, '{\"specialRequest\":\"allergies\"}', 271.00),
(15, 11, 76, '6pc Chikin Rack Fanverse Meal', 4953.00, 1, '{\"flavors\":{\"Soy Garlic\":2,\"Spicy\":1,\"Honey Citrus\":1,\"Crunchy Garlic\":1,\"Bonchon YangNyeom\":1},\"sides\":{\"Fried Tteokbokki - BYN (8 sticks)\":16},\"drink\":\"Regular Pepsi (6pcs)\",\"specialRequest\":\"atin ku pu sakit\"}', 4953.00),
(16, 12, 29, 'Fish N\' Chix Good for 3', 702.00, 2, NULL, 1404.00);

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `email_addr` varchar(255) NOT NULL,
  `attempt_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`email_addr`, `attempt_count`, `locked_until`, `updated_at`) VALUES
('marclapid2006@gmail.com', 6, '2026-11-05 00:11:58', '2026-11-04 16:06:58');

-- --------------------------------------------------------

--
-- Table structure for table `menu_categories`
--

CREATE TABLE `menu_categories` (
  `category_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_categories`
--

INSERT INTO `menu_categories` (`category_id`, `parent_id`, `name`, `description`, `sort_order`) VALUES
(1, NULL, 'Korean Fried Chicken Racks', 'Our signature double-fried Korean Fried Chicken, perfectly glazed in your favorite flavor and served on a rack. Choose from 3, 6, or 8 pieces.', 1),
(2, NULL, 'Group Meals', NULL, 2),
(3, 2, 'Korean Feast', 'Choose the Korean Feast that fits your cravings. Feasts are available for different group sizes.', 1),
(4, 2, 'Nomcha-To-Share', 'A convenient box with noodles and your choice of viand or sides.', 2),
(5, NULL, 'Korean Fried Chicken', NULL, 3),
(6, NULL, 'Beef', 'Nomcha beef dishes prepared with Korean spices and seasonings.', 4),
(7, NULL, 'Seafood', 'Crispy fish favorites and satisfying seafood combinations.', 5),
(8, NULL, 'Korean Rice Bowls', 'A selection of flavorful Korean rice bowls.', 6),
(9, NULL, 'Sandwiches', 'Crispy, juicy, flavorful chicken sandwiches.', 7),
(10, NULL, 'Noodle & Soup', 'Comforting Korean noodle and soup dishes.', 8),
(11, NULL, 'Sides', NULL, 9),
(12, 11, 'Fried Tteokbokki', NULL, 1),
(13, 11, 'Cheeseballs', NULL, 2),
(14, 11, 'Fries', NULL, 3),
(15, 11, 'Rice', NULL, 4),
(16, 11, 'Other', NULL, 5),
(17, NULL, 'Desserts', NULL, 10),
(18, 17, 'Bingsu', NULL, 1),
(19, NULL, 'Beverages', NULL, 11),
(20, 19, 'Cafe Koreano', NULL, 1),
(21, 19, 'Refreshing Drinks', NULL, 2),
(22, NULL, 'BBQ Sizzlers', 'Korean-style BBQ dishes served with rice.', 12);

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `item_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`item_id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_available`, `sort_order`) VALUES
(19, 1, 'Soy Garlic', 'A rich, savory blend of soy sauce with a slightly salty kick, balanced by aromatic garlic. The iconic flavor that first introduced Nomcha to those craving bold Korean flavors.', NULL, 'https://bonchon.com.ph/storage/menu/nq3psktTAiY70CzEHpgount4JDLJ2g2uwEaIU5Xj.webp', 1, 1),
(20, 1, 'Spicy', 'A punchy red pepper-based glaze with just the right amount of saltiness. Spicy, savory, and unapologetically bold.', NULL, 'https://bonchon.com.ph/storage/menu/ZjiHu3efI9FeW1tvt9VPTsB4cC6biWEKUuP4to7l.webp', 1, 2),
(21, 1, 'BYN', 'A creamy mayo-based sauce with spicy and sweet flavors, red chili flakes, and sesame seeds.', NULL, 'https://bonchon.com.ph/storage/menu/ycld776UxU5VsJUzclAiutNuHujhs1ukc0FgViCn.webp', 1, 3),
(22, 1, 'Honey Citrus', 'A golden glaze of sweet honey balanced with refreshing citrus tang.', NULL, 'https://bonchon.com.ph/storage/menu/3siVy9dlXhvigQfz8qXpkmXjTYidJL2kN7aJG6Iz.webp', 1, 4),
(23, 1, 'Crunchy Garlic', 'A honey-kissed glaze with salty undertones, finished with crunchy fried garlic.', NULL, 'https://bonchon.com.ph/storage/menu/t6hvlzCKpH4lRxfFmAoLjZp7I5CdcMH9ujZkHO3b.webp', 1, 5),
(24, 1, 'Golden Crunch', 'Crispy, flavorful chicken with a satisfying crunch in every bite.', NULL, 'https://bonchon.com.ph/storage/menu/0FJIsACdyipkh9JuOrKdHI7WSQkFjAcrXH0IVXVi.png', 1, 6),
(25, 1, 'Snow Cheese', 'A sweet, creamy, salty, and onion-flavored coating with parsley.', NULL, 'https://bonchon.com.ph/storage/menu/CWmrkdbVFqi2gVa7yadKxLYNYgK9F5tXmNY1x9mW.webp', 1, 7),
(26, 3, 'Feast Good for 4-6', '8 pieces Nomcha Korean Fried Chicken, 2 regular Chapchae, 2 Kimchi Coleslaw, 4 cups rice, 6 fried tteokbokki sticks, and 1L iced tea.', NULL, 'https://bonchon.com.ph/storage/menu/vdcKFqxJI1CZ9ACb0UMtkRp6UAoGKallB4jn9geE.png', 1, 1),
(27, 3, 'Chicken Good for 4', '8 pieces Nomcha Korean Fried Chicken, 1 regular Chapchae, 2 regular K-Fries, 4 cups white rice, and 4 cups of your drink of choice.', NULL, 'https://bonchon.com.ph/storage/menu/2qqloMkJWp9Ul3BSzw5pa6ES75rgWmbe7Vo2buSj.png', 1, 2),
(28, 3, 'Chicken Good for 3', '6 pieces Nomcha Korean Fried Chicken, 1 regular Chapchae, 3 cups white rice, and 3 cups of your drink of choice.', NULL, 'https://bonchon.com.ph/storage/menu/49YxynJGgT4yHZXbIKvhqOR1r5S9FJYF8BHWQ4AL.png', 1, 3),
(29, 3, 'Fish N\' Chix Good for 3', '3 pieces crispy glazed fish, 2 pieces Nomcha Korean Fried Chicken, 6 Korean Mandu, 3 cups white rice, and 3 drinks.', 702.00, 'https://bonchon.com.ph/storage/menu/z7tAO6hyVmoE475mhbc5bGufEXzpUIQjNymbtogA.png', 1, 4),
(30, 4, 'NTS Korean Fried Chicken', '6 pieces Nomcha Korean Fried Chicken and 3 servings of Chapchae in one box.', 1043.00, 'https://bonchon.com.ph/storage/menu/AaHq4eMfEgNiSPrltNbyUBwpcxmWgrlSL9Dfnl4m.webp', 1, 1),
(31, 4, 'NTS Bonless Chikin', 'Large Nomcha Bonless Chikin complemented by 3 servings of Chapchae.', 649.00, 'https://bonchon.com.ph/storage/menu/Qkhxn8KnBhAV5ovkV50RlZDhVySIZzcLOX0WO0Zz.webp', 1, 2),
(32, 4, 'NTS Bonless Chikin & Rosébokki', 'Rosébokki paired with a generous serving of Nomcha Bonless Chicken in your favorite glaze.', 1149.00, 'https://bonchon.com.ph/storage/menu/z9dV5W53AT07bIPC9Qwg1M1h5pUTBkA0iTwkOml0.webp', 1, 3),
(33, 5, '2-pc Korean Fried Chicken', 'Two pieces of Nomcha Korean Fried Chicken with rice. Also available with a drink.', 256.00, 'https://bonchon.com.ph/storage/menu/SIVvmNnKdOCuqD7F49sxcTlB3k1cwhYpuwlDaH82.png', 1, 1),
(34, 5, '1-pc Korean Fried Chicken', 'A 1-pc Nomcha Korean Fried Chicken with rice, a budget-friendly meal.', 157.00, 'https://bonchon.com.ph/storage/menu/ZnlSLSPZTsNS2Snp5X3jUIglGBMaAmeEylaKNQ70.png', 1, 2),
(35, 5, '3-pc Bonless Chikin', 'A rice meal with 3 pieces of Bonless Chikin, glazed to your preference.', 261.00, 'https://bonchon.com.ph/storage/menu/0FJzr8t6nm94ddzxUIl9MQghDKt19AoOat9yrnKs.png', 1, 3),
(36, 5, 'Chicken Poppers', 'A bite-sized snack made with 100% chicken coated in crispy batter and brushed with Nomcha\'s signature glazes.', 145.00, 'https://bonchon.com.ph/storage/menu/AMiTA2km2DHrSBMCY9ePsnMaPfQaZ8F1Pks22QdW.webp', 1, 4),
(37, 6, 'Sizzling Beef Stew', 'A hearty Korean beef stew in sweet and savory broth served with rice, vegetables, and green chili.', 323.00, 'https://bonchon.com.ph/storage/menu/hFraOiYUr8dIeIujx8nhY1dAngBd7XLXLNvdX6rI.webp', 1, 1),
(38, 6, 'Beef Bulgogi', 'A tender serving of sweet and savory marinated beef strips served on top of rice.', 291.00, 'https://bonchon.com.ph/storage/menu/O8VJ02PHsBKijh8cbxLjNxZ07MnsCA8bsz2VaeOe.webp', 1, 2),
(39, 7, 'Fish & Fries', 'An irresistible Korean meal combo of Crispy Fish and Krispy Fries.', 271.00, 'https://bonchon.com.ph/storage/menu/TQmKqBpfe7KmwXBFy6fNOuryxXIg9DPTs2K7WnHv.webp', 1, 1),
(40, 7, 'Fish & Chapchae', '2 pieces crispy glazed fish served with rice, half a portion of Chapchae, and your choice of drink.', NULL, 'https://bonchon.com.ph/storage/menu/MvZmKBuRivJLSiBG9d8pl6f38jZzXLvPS16fstnC.webp', 1, 2),
(41, 8, 'Ultimate Sizzling Bibimbowl - Beef', 'Marinated beef topped with fresh vegetables, mushrooms, glass noodles, sweet corn, crisp nori, and a fried egg.', 281.00, 'https://bonchon.com.ph/storage/menu/zJqwt9SEfEH4u113UMlyDM2h108RfftFg1W9N5tD.webp', 1, 1),
(42, 8, 'Bap Bowl', 'Crispy Chicken Poppers coated in Nomcha\'s signature glaze. Available in Soy Garlic, Crunchy Garlic, Honey Citrus, YangNyeom, and Spicy.', 198.00, 'https://bonchon.com.ph/storage/menu/Kj99WWv0I3v9GLjnLL5QN2FpFEOMamlhLMl9hAeh.webp', 1, 2),
(43, 8, 'Ultimate Sizzling Bibimbowl - Chicken', 'Crispy chicken poppers with your preferred glaze, vegetables, mushrooms, glass noodles, sweet corn, nori flakes, and a fried egg.', 281.00, 'https://bonchon.com.ph/storage/menu/JTxyutWJbXExiv0tYqYyPqantuZFLENEt9geTJiO.webp', 1, 3),
(44, 9, 'Chikin Chops Sandwich', 'Crispy Chikin Chops fillet between soft brioche buns with lettuce, tomatoes, and spicy mayo. Available in Soy Garlic, Crunchy Garlic, Honey Citrus, YangNyeom, and Spicy.', 235.00, 'https://bonchon.com.ph/storage/menu/HVRtG3ec5gLcJ1MawtCZEYLv8i8NbqE5ZO8NykGu.webp', 1, 1),
(45, 9, 'Spicy Deluxe Sandwich', 'Crispy marinated boneless Chikin Chops fillet in buttery brioche buns with spicy glaze and Korean-style Kimchi Coleslaw.', 245.00, 'https://bonchon.com.ph/storage/menu/NKSOA9wF6Ll2TPxcYp2CrgjJYgtmyCVscL5xXenh.webp', 1, 2),
(46, 9, 'Snackwich', 'A chicken sandwich with soft buns, a crispy chicken patty, green lettuce, and spicy mayo.', 104.00, 'https://bonchon.com.ph/storage/menu/DhBf5KDXNpiM8D5Hfu84ERlUSv4xBuMLKn7UgjWn.webp', 1, 3),
(47, 10, 'Rosébokki', 'Chewy rice cakes and pasta noodles tossed in creamy Rosé sauce, finished with fish cake, nori, and sesame seeds.', 178.00, 'https://bonchon.com.ph/storage/menu/bUakxX6uPTeZa8ssJPUqBKnXUKE3wAkqofuarFkU.webp', 1, 1),
(48, 10, 'Cheesybokki', 'Chewy rice cakes and pasta in creamy cheese sauce, finished with crispy chicken poppers and parsley.', 178.00, 'https://bonchon.com.ph/storage/menu/vThaDbvz9Uc2j700d3QOQH9e27dTsUirj4enT6ft.webp', 1, 2),
(49, 10, 'Chapchae', 'Stir-fried glass noodles with onions, carrots, spinach, eggs, and sesame seeds. Also available in Mild Spicy and Spicy.', 178.00, 'https://bonchon.com.ph/storage/menu/2ukY62ZS2yN4ofZPw6132nz4zDbDWnjkBaaPFZO6.webp', 1, 3),
(50, 10, 'Bulgogi Noodle Soup', 'A traditional Korean hot soup with grilled and marinated bulgogi, savory-sweet tofu, and glass noodles.', 89.00, 'https://bonchon.com.ph/storage/menu/jcKYK7KzP73jU5vHiOZIkGwnmgRVMu6h0jd963dl.webp', 1, 4),
(51, 10, 'Korean Miso Soup', 'A Korean take on classic miso soup, made with white onion and tofu.', 47.00, 'https://bonchon.com.ph/storage/menu/yaDVpiPPYXHEcpipK8uBXHLoTqK10lUsTORXpL5G.webp', 1, 5),
(52, 12, 'BYN Fried Tteokbokki', 'Crispy fried tteokbokki tossed in creamy mayo-based sauce with a spicy-sweet kick, chili flakes, and sesame seeds. Available in 1-piece and 3-piece servings.', 51.00, 'https://bonchon.com.ph/storage/menu/e8CfwHk4NLrlN147ihCpjqWsvrOUpwfzhxIzsprz.png', 1, 1),
(53, 12, 'Snow Cheese Fried Tteokbokki', 'Crunchy fried tteokbokki in a sweet and savory cheese blend with hints of onion and parsley. Available in 1-piece and 3-piece servings.', 51.00, 'https://bonchon.com.ph/storage/menu/PS5iKxBAN2tqZXAs5mUu16X2gtLAATtGduaRdSEJ.png', 1, 2),
(54, 12, 'Honey Fried Tteokbokki', 'Crispy fried tteokbokki glazed in rich honey for a balance of sweet and crunchy. Available in 1-piece and 3-piece servings.', 51.00, 'https://bonchon.com.ph/storage/menu/3dFX77S7tNnK78w7OhOIZ7a86BXClviQxNiFSbjQ.png', 1, 3),
(55, 13, 'Plain Cheeseballs', 'Golden, bite-sized snacks with a soft mozzarella cheese center, paired with pizza sauce. Available in 1-piece and 3-piece servings.', 125.00, 'https://bonchon.com.ph/storage/menu/YoxdlxmGi1bbQCcFpC1t6aOmfmltDicS8Uuaan0u.png', 1, 1),
(56, 13, 'Snow Cheese Cheeseballs', 'Bite-sized cheeseballs filled with mozzarella and coated in sweet and savory cheese seasoning. Available in 1-piece and 3-piece servings.', 125.00, 'https://bonchon.com.ph/storage/menu/RCkXlSiBA7eQjThKxdGFeaDajraxSTPOY3btuyZl.png', 1, 2),
(57, 14, 'Krispy Fries', 'Thinly cut deep-fried potatoes coated in classic crispy batter. Available in Regular and Large.', 79.00, 'https://bonchon.com.ph/storage/menu/CEwyiUZoI9rMVZbfE5OVhwJBS7P0fQTatqT3PU1P.webp', 1, 1),
(58, 14, 'Plain Fish Cake Fries', 'Korean fish cakes cut into fry-shaped strips and fried until crispy and golden. Available in Regular and Large.', NULL, 'https://bonchon.com.ph/storage/menu/A7MCEN1WS3M9oSilAuJyiisjOd3p4k1now16PtDn.png', 1, 2),
(59, 14, 'Snow Cheese Fish Cake Fries', 'Crispy, fry-cut Korean fish cakes dusted in sweet and savory snow cheese seasoning. Available in Regular and Large.', NULL, 'https://bonchon.com.ph/storage/menu/c5vDYAHcDRybJ3m37tt7D8LfIcJv1QwK4JlJCKIY.png', 1, 3),
(60, 15, 'White Rice', 'A comforting and filling cup of white rice.', 42.00, 'https://bonchon.com.ph/storage/menu/53ABfgmpuW64iv2nZ31VIFaPAGiT85HlXtYQQtUL.webp', 1, 1),
(61, 15, 'Kimchi Rice', 'Rice tossed with fermented cabbage for a boldly spicy, tangy, vibrant flavor.', 93.00, 'https://bonchon.com.ph/storage/menu/mnLee3Q6kHqHfeum8k4Sxwa1waaN0Rznvm7Je6BK.png', 1, 2),
(62, 16, '4-pcs Mandu', 'An a la carte serving of 4 pieces of steamed or fried Nomcha chicken mandu.', 104.00, 'https://bonchon.com.ph/storage/menu/uXwMby1LdGdfxx4WAp55nMikI7q1xrzbWKjheK9X.webp', 1, 1),
(63, 16, 'Kimchi Coleslaw', 'Fresh, thinly sliced cabbage and carrots tossed with kimchi flavor and sweet-and-sour dressing.', NULL, 'https://bonchon.com.ph/storage/menu/aBqkDcSZPFvEzqd6XJ2QVWw88L3qOQSBmueA3x6m.webp', 1, 2),
(64, 16, 'Fresh Kimchi', 'A classic Korean side dish made with fermented cabbage, boldly spicy and tangy.', 51.00, 'https://bonchon.com.ph/storage/menu/nD5VidtyxQgc2nUq22RYnQEyPuPqTpycOsVrUVb6.png', 1, 3),
(65, 18, 'Mango Cheesecake', 'Milky snow ice layered with crushed graham crackers, cheesecake cubes, glazed peanuts, and mango syrup.', 145.00, 'https://bonchon.com.ph/storage/menu/R8fFfmnEhDaWdirs8t0gTWGYAQTdduOTZGTCeh9J.webp', 1, 1),
(66, 18, 'Chocolate Brownie', 'Korean bingsu with milky snow ice topped with brownie cubes and chocolate-coated peanuts, drizzled with chocolate syrup.', 145.00, 'https://bonchon.com.ph/storage/menu/PGr4PdoL8w4kAJlpNYGuYTeSy4cO6mhyTb2oyF5h.webp', 1, 2),
(67, 18, 'Strawberry Oreo', 'Milky snow ice drizzled with strawberry compote and topped with crushed Oreo cookies and cheesecake cubes.', 145.00, 'https://bonchon.com.ph/storage/menu/5uRMWf2BI3A6KJBCGLHbVADUJNT1QliDKmvqJRol.webp', 1, 3),
(68, 20, 'Classic Caramel', 'Creamy snow ice and rich coffee layered with chewy coffee jelly and finished with caramel syrup.', 126.00, 'https://bonchon.com.ph/storage/menu/dsuzrD0xRMY6iDfM6gtuVc68CYnf8nayi3NpwPst.webp', 1, 1),
(69, 20, 'Chocolate', 'A Korean coffee blend of milky snow ice and rich coffee, drizzled with cocoa powder and served with chewy coffee jelly.', 126.00, 'https://bonchon.com.ph/storage/menu/RaycTzRu8bLGLmzqrzQbJz6y1jRbFGQEdpP9rRAO.webp', 1, 2),
(70, 20, 'Koko Mango', 'A refreshing blend of coconut milk, mango syrup, mini tapioca pearls, and mango compote.', 146.00, 'https://bonchon.com.ph/storage/menu/lP1YFhpV7QkO90Jok9wVkSpqZ1OTWVTsbpfSD5rk.webp', 1, 3),
(71, 20, 'Koko Latte', 'A creamy snow ice coffee drink made with coconut milk, condensed milk, and coffee jelly.', 146.00, 'https://bonchon.com.ph/storage/menu/6vTHry6FOfa00GGF9OsNf1oEX4Rvz6qUpjo3sg3c.webp', 1, 4),
(72, 21, '1L Signature Iced Tea', 'Nomcha iced tea. Available in 1L, Regular, and Large cups.', 146.00, 'https://bonchon.com.ph/storage/menu/ZFMt4XcXzZQblERwUFIACOSxQrLKvTi1aadrcsiF.webp', 1, 1),
(73, 21, 'Pineapple Juice', 'A sweet, tropical pineapple juice drink. Available in Regular and Large.', 93.00, 'https://bonchon.com.ph/storage/menu/kEeRCKrpqmMMQIxtGIjP0QglCfPtw63zqqJmHL0i.webp', 1, 2),
(74, 21, 'Soda', 'A classic fizzy drink available in a variety of flavors. Also available in Regular and Large.', 72.00, 'https://bonchon.com.ph/storage/menu/aOvZomiF7jegPwvcuYPu74SkYxPMrTc6V5TtLjda.webp', 1, 3),
(75, 1, '8pc Chikin Rack Fanverse Meal', 'Build your Fanverse Meal with crispy Chikin Rack, your choice of chicken flavors, sides, and a Pepsi.', 828.00, 'https://images.tablevibestaticassets.co/usdp8458h8l9mvqk3h7159g6zcw2', 1, 8),
(76, 1, '6pc Chikin Rack Fanverse Meal', 'Build your Fanverse Meal with crispy Chikin Rack, your choice of chicken flavors, sides, and a Pepsi.', 649.00, 'https://images.tablevibestaticassets.co/8xotaifmlsk8jo5xh8ptf5x54qmj', 1, 9),
(77, 1, '3pc Chikin Rack Fanverse Meal', 'Build your Fanverse Meal with crispy Chikin Rack, your choice of chicken flavors, sides, and a Pepsi.', 334.00, 'https://images.tablevibestaticassets.co/tbedk2l1mx33an049vqed7y7kil6', 1, 10),
(78, 5, '6-pc Chikin Ala Carte', 'Six pieces of Korean fried chikin, served a la carte.', 235.00, 'https://images.tablevibestaticassets.co/8xotaifmlsk8jo5xh8ptf5x54qmj', 1, 5),
(79, 22, 'Pork BBQ Meal', 'Thinly sliced pork belly marinated in soy sauce, Korean chili, and spices, served with rice.', 291.00, 'https://www.bonchon.com.ph/storage/cms_images/lj8oc25rqsJim7X4z8o1WH5ZEJpumFQTQ625BsRa.jpg', 1, 1),
(80, 16, 'Pizza Sauce', 'A serving of pizza sauce.', 31.00, 'https://bonchon.com.ph/storage/menu/YoxdlxmGi1bbQCcFpC1t6aOmfmltDicS8Uuaan0u.png', 1, 4);

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
(1, 1, 'admin@admin', '$2y$12$NCxOs.Kxc.lFIynkQye.XuikjluWMddb.F9WobMX2QwkCuqUcHYem'),
(6, 6, 'marclapid2006@gmail.com', '$2y$12$V8nDY9R5qdy95LrceKK1V.1s1XBPNA5C0nLNtSv78xwdd3LMUQGZO'),
(7, 7, 'tintinsuba@gmail.com', '$2y$12$DVs2jpSuO1zKtyTSRFDOC.16fuBcXvq9bdgIDsTT8uepS89yYjvjO'),
(8, 8, 'cristinajoy@gmail.com', '$2y$12$ogytTjOWR51bYxwHo.Kw/uNDOXUMjG2lFm.tR0LtPVzdgXHDTtU3u'),
(9, 9, 'clacla@gmail.com', '$2y$12$E/8qBUrKKUBuVlhkJFQnxujgOMua9aQFuSTSOKimocMagpCty4YAy');

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
(1, 'admin', NULL, NULL, NULL, NULL),
(6, 'Marc Daniel', 'M.', 'Lapid', 'Santa Lucia Matua, Masantol, Pampanga', '09564206180'),
(7, 'cristine', 'jimenez', 'suba', 'sanjuan apalit', '09518975445'),
(8, 'tintin', 'jimenez', 'suba', 'sanjuan apalit', '09500672246'),
(9, 'Clarise', 'Manalang', 'suba', 'Purok Centro Santa Lucia Matua', '09543799314');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD PRIMARY KEY (`order_id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`email_addr`);

--
-- Indexes for table `menu_categories`
--
ALTER TABLE `menu_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `category_id` (`category_id`);

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
-- AUTO_INCREMENT for table `customer_orders`
--
ALTER TABLE `customer_orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  MODIFY `order_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `menu_categories`
--
ALTER TABLE `menu_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `user_credentials`
--
ALTER TABLE `user_credentials`
  MODIFY `user_cred_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `user_info`
--
ALTER TABLE `user_info`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD CONSTRAINT `customer_orders_user_fk` FOREIGN KEY (`user_id`) REFERENCES `user_info` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD CONSTRAINT `customer_order_items_item_fk` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `customer_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_categories`
--
ALTER TABLE `menu_categories`
  ADD CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `menu_categories` (`category_id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `fk_item_cat` FOREIGN KEY (`category_id`) REFERENCES `menu_categories` (`category_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_credentials`
--
ALTER TABLE `user_credentials`
  ADD CONSTRAINT `user_credentials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user_info` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
