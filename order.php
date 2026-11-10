<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';

require_customer($pdo);

if (empty($_SESSION['user_id'])) {
	$loginRedirect = 'order.php';
	if (($_GET['fulfillment'] ?? '') === 'pickup') {
		$pickupLocation = trim((string)($_GET['pickup_location'] ?? ''));
		if (mb_strlen($pickupLocation) <= 255 && $pickupLocation !== '') {
			$loginRedirect .= '?fulfillment=pickup&pickup_location=' . rawurlencode($pickupLocation);
		}
	}
	header('Location: login.php?redirect=' . rawurlencode($loginRedirect));
	exit;
}

$pageTitle = 'Order Online';
$pageStyles = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
$branches = json_decode((string)file_get_contents(__DIR__ . '/assets/branches-data.json'), true) ?: [];
$savedAddresses = [];
$selectedAddress = null;
$selectedBranch = null;
$scheduleTimezone = new DateTimeZone('Asia/Manila');
$scheduleNow = new DateTimeImmutable('now', $scheduleTimezone);
$errors = [];
$form = [
		'fulfillment' => 'delivery',
		'customer_name' => '',
		'phone' => '',
		'delivery_address' => '',
		'delivery_instructions' => '',
		'pickup_location' => '',
		'scheduled_for' => '',
		'payment_method' => 'cash',
		'address_id' => '',
		'branch_id' => '',
];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['fulfillment'] ?? '') === 'pickup') {
		$form['fulfillment'] = 'pickup';
		$pickupLocation = trim((string)($_GET['pickup_location'] ?? ''));
		if (mb_strlen($pickupLocation) <= 255) {
			$form['pickup_location'] = $pickupLocation;
			foreach ($branches as $branch) if ($pickupLocation === $branch['address'] || $pickupLocation === $branch['name']) { $form['branch_id'] = (string)$branch['id']; $selectedBranch = $branch; break; }
		}
}
$orderConfirmation = $_SESSION['order_confirmation'] ?? null;
unset($_SESSION['order_confirmation']);

function orderItemOptions(string $name): array {
	if (in_array($name, ['NTS Bonless Chikin', 'NTS Bonless Chikin & Rosébokki'], true)) {
		return [[
			'key' => 'glaze',
			'label' => 'Choose a glaze',
			'required' => true,
			'choices' => array_map(static fn($value) => ['name' => $value, 'priceCents' => 0], ['Soy Garlic', 'Spicy', 'BYN', 'Honey Citrus', 'Crunchy Garlic', 'Golden Crunch', 'Snow Cheese']),
		]];
	}
	if (strcasecmp($name, 'Chapchae') === 0) {
		return [[
			'key' => 'spiceLevel',
			'label' => 'Choose your spice level',
			'required' => true,
			'choices' => array_map(static fn($value) => ['name' => $value, 'priceCents' => 0], ['Regular', 'Mild Spicy', 'Spicy']),
		]];
	}
	$chicken = preg_match('/chicken|chikin|bonless|chix|bap bowl|poppers|sandwich|fish & chapchae|good for/i', $name) === 1;
	$meal = preg_match('/meal|rice|bibimbowl|bap bowl|poppers|sandwich|snackwich|beef|chix|pork bbq|fish & chapchae|good for|fish n\s*[\'’] chix/i', $name) === 1;
	if ((!$chicken && !$meal) || preg_match('/Fanverse Meal$/', $name)) return [];
	$flavors = ['Soy Garlic', 'Spicy', 'BYN', 'Honey Citrus', 'Crunchy Garlic', 'YangNyeom', 'Snow Cheese', 'Golden Crunch'];
	$groups = $chicken ? [[ 'key' => 'flavor', 'label' => 'Choice of Chicken Flavors', 'required' => true,
		'choices' => array_map(static fn($v) => ['name' => $v, 'priceCents' => 0], $flavors) ]] : [];
	if ($meal) {
		$groups[] = ['key' => 'sides', 'label' => 'Choice of Sides', 'required' => false, 'multiple' => true, 'maxCount' => 9, 'choices' => [
			['name' => 'Cheeseballs - Plain w/ Pizza Sauce (1pc)', 'priceCents' => 4700], ['name' => 'Cheeseballs - Snowcheese (1pc)', 'priceCents' => 4700], ['name' => 'Kimchi', 'priceCents' => 4700],
		]];
		$groups[] = ['key' => 'drink', 'label' => 'Choice of Drink', 'required' => true, 'choices' => [
			['name' => 'Regular Iced Tea', 'priceCents' => 0], ['name' => 'Large Iced Tea', 'priceCents' => 4000],
			['name' => 'Caramel Snow Ice Coffee Drink Upgrade', 'priceCents' => 9500], ['name' => 'Chocolate Snow Ice Coffee Drink Upgrade', 'priceCents' => 9500],
		]];
		$groups[] = ['key' => 'riceUpgrade', 'label' => 'Upgrade to Kimchi Rice', 'required' => false, 'choices' => [['name' => 'Kimchi Rice Upgrade', 'priceCents' => 5100]]];
		$groups[] = ['key' => 'soup', 'label' => 'Add-ons', 'required' => false, 'choices' => [['name' => 'Add on Bulgogi Soup', 'priceCents' => 6800]]];
	}
	return $groups;
}

function isOrderCustomizationItem(array $item): bool {
	// These rack entries are flavor choices for the Chikin Rack, not separate products.
	return (int)($item['category_id'] ?? 0) === 1
		&& !preg_match('/^[0-9]+pc Chikin Rack Fanverse Meal$/', (string)($item['name'] ?? ''));
}

function orderAddressLabel(array $address): string {
	return implode(', ', array_filter([
		$address['street_address'] ?? '', $address['barangay'] ?? '', $address['municipality'] ?? '',
		$address['province'] ?? '', $address['zip_code'] ?? '',
	], static fn($part): bool => trim((string)$part) !== ''));
}

if (!empty($_SESSION['user_id'])) {
		try {
				$profileQuery = $pdo->prepare('SELECT first_name, last_name, home_addr, mobileno FROM user_info WHERE user_id = ?');
				$profileQuery->execute([(int)$_SESSION['user_id']]);
				if ($profile = $profileQuery->fetch()) {
						$form['customer_name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
						$form['phone'] = $profile['mobileno'] ?? '';
						$form['delivery_address'] = $profile['home_addr'] ?? '';
				}
				$addressQuery = $pdo->prepare('SELECT address_id, recipient_name, mobile_no, street_address, province, municipality, barangay, zip_code, latitude, longitude, is_default FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC');
				$addressQuery->execute([(int)$_SESSION['user_id']]);
				$savedAddresses = $addressQuery->fetchAll();
				$defaultAddresses = array_values(array_filter($savedAddresses, static fn(array $row): bool => (int)$row['is_default'] === 1));
				if (count($defaultAddresses) > 1) {
					$keepAddressId = (int)$defaultAddresses[0]['address_id'];
					$pdo->prepare('UPDATE user_addresses SET is_default = CASE WHEN address_id = ? THEN 1 ELSE 0 END WHERE user_id = ?')->execute([$keepAddressId, (int)$_SESSION['user_id']]);
					foreach ($savedAddresses as &$savedRow) $savedRow['is_default'] = (int)$savedRow['address_id'] === $keepAddressId ? 1 : 0;
					unset($savedRow);
				}
				if ($savedAddresses) {
						$selectedAddress = $savedAddresses[0];
						$form['address_id'] = (string)$selectedAddress['address_id'];
						$form['customer_name'] = $selectedAddress['recipient_name'];
						$form['phone'] = $selectedAddress['mobile_no'] ?? $form['phone'];
						$form['delivery_address'] = orderAddressLabel($selectedAddress);
				}
		} catch (PDOException $ex) {
		}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		foreach ($form as $key => $default) {
				$form[$key] = trim((string)($_POST[$key] ?? $default));
		}
		$form['fulfillment'] = in_array($form['fulfillment'], ['delivery', 'pickup'], true) ? $form['fulfillment'] : 'delivery';
		if ($form['address_id'] !== '') {
			$addressLookup = $pdo->prepare('SELECT address_id, recipient_name, mobile_no, street_address, province, municipality, barangay, zip_code, latitude, longitude FROM user_addresses WHERE user_id = ? AND address_id = ?');
			$addressLookup->execute([(int)$_SESSION['user_id'], (int)$form['address_id']]);
			$selectedAddress = $addressLookup->fetch() ?: null;
		}
		if (!$selectedAddress) $errors[] = 'Choose a saved billing address.';
		else {
			$form['customer_name'] = $selectedAddress['recipient_name'];
			$form['phone'] = $selectedAddress['mobile_no'] ?? '';
			$form['delivery_address'] = orderAddressLabel($selectedAddress);
			if ($form['fulfillment'] === 'delivery' && (trim((string)$selectedAddress['street_address']) === '' || !preg_match('/^\d{4}$/', (string)$selectedAddress['zip_code']))) $errors[] = 'Please complete the Address and ZIP code fields for this billing address before delivery.';
		}
		foreach ($branches as $branch) if ((string)$branch['id'] === (string)$form['branch_id']) { $selectedBranch = $branch; break; }
		if (!$selectedBranch || ($form['fulfillment'] === 'pickup' && empty($selectedBranch['pickup']))) $errors[] = 'Choose an available Nomcha branch.';
		else $form['pickup_location'] = $selectedBranch['name'];

		if (!csrf_check($_POST['csrf'] ?? null)) {
				$errors[] = 'Your session expired. Refresh the page and try again.';
		}
		if (mb_strlen($form['customer_name']) < 2 || mb_strlen($form['customer_name']) > 120) {
				$errors[] = 'Enter your name (2 to 120 characters).';
		}
		if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $form['phone'])) {
				$errors[] = 'Enter a Philippine mobile number, such as 09XXXXXXXXX.';
		}
		if ($form['fulfillment'] === 'delivery' && (mb_strlen($form['delivery_address']) < 8 || mb_strlen($form['delivery_address']) > 500)) {
				$errors[] = 'Enter a complete delivery address.';
		}
		if ($form['fulfillment'] === 'pickup' && (mb_strlen($form['pickup_location']) < 3 || mb_strlen($form['pickup_location']) > 255)) {
				$errors[] = 'Enter the Nomcha pickup branch or location.';
		}
		if (mb_strlen($form['delivery_instructions']) > 500) {
			$errors[] = 'Rider instructions must be 500 characters or fewer.';
		}
		if (($_POST['confirm_order'] ?? '') !== '1') {
			$errors[] = 'Confirm your order details before submitting.';
		}
		if (!in_array($form['payment_method'], ['cash', 'gcash', 'maya', 'card_pickup'], true)) {
				$errors[] = 'Choose a payment method.';
		}
		if ($form['payment_method'] === 'card_pickup' && $form['fulfillment'] !== 'pickup') {
				$errors[] = 'Card payment is available for pickup orders only.';
		}

		$scheduledFor = null;
		if ($form['scheduled_for'] !== '') {
				$scheduleNow = new DateTimeImmutable('now', $scheduleTimezone);
				$scheduledDate = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:(00|30)$/', $form['scheduled_for'])
						? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $form['scheduled_for'], $scheduleTimezone)
						: false;
				$slotTime = $scheduledDate ? $scheduledDate->format('H:i') : '';
				if (!$scheduledDate
						|| $scheduledDate->format('Y-m-d') !== $scheduleNow->format('Y-m-d')
						|| $slotTime < '09:30'
						|| $slotTime > '23:00'
						|| $scheduledDate <= $scheduleNow) {
						$errors[] = 'Choose an available same-day slot between 9:30 AM and 11:30 PM, or select ASAP.';
				} else {
						$scheduledFor = $scheduledDate->format('Y-m-d H:i:s');
				}
		}

		$cart = json_decode($_POST['cart_json'] ?? '[]', true);
		if (!is_array($cart) || !$cart || count($cart) > 40) {
				$errors[] = 'Add at least one priced menu item to your basket.';
				$cart = [];
		}

		$orderLines = [];
		$subtotal = 0.0;
		if (!$errors) {
			$itemQuery = $pdo->prepare('SELECT item_id, name, price, customizations_json FROM menu_items WHERE item_id = ? AND is_available = 1 AND (publish_at IS NULL OR publish_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))');
				foreach ($cart as $entry) {
						$itemId = filter_var($entry['id'] ?? null, FILTER_VALIDATE_INT);
						$quantity = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
						if (!$itemId || !$quantity || $quantity < 1 || $quantity > 99) {
								$errors[] = 'One basket item is invalid. Review your basket and try again.';
								break;
						}
						$itemQuery->execute([$itemId]);
						$item = $itemQuery->fetch();
						if (!$item || $item['price'] === null || (float)$item['price'] <= 0) {
								$errors[] = 'A basket item has no configured price and cannot be ordered yet.';
								break;
						}

						$options = [];
						$addOnTotal = 0.0;
						$optionConfig = json_decode((string)($item['customizations_json'] ?? ''), true);
						if (!is_array($optionConfig)) $optionConfig = orderItemOptions($item['name']);
						$submittedOptions = is_array($entry['options'] ?? null) ? $entry['options'] : [];
						$specialRequestValue = $submittedOptions['specialRequest'] ?? '';
						if (!is_string($specialRequestValue) || mb_strlen($specialRequestValue) > 500) {
							$errors[] = 'Special requests must be 500 characters or fewer.';
							break;
						}
						$specialRequest = trim($specialRequestValue);
						$fanverseMatch = preg_match('/^([0-9]+)pc Chikin Rack Fanverse Meal$/', $item['name'], $bundleMatch);
						if ($fanverseMatch) {
							$portionCount = (int)$bundleMatch[1];
							$allowedFlavors = ['Soy Garlic', 'Spicy', 'Honey Citrus', 'Crunchy Garlic', 'Nomcha YangNyeom', 'Snow Cheese', 'Golden Crunch Chikin without Gravy', 'Golden Crunch Chikin with Gravy'];
							$allowedSides = [
								'Fried Tteokbokki - BYN (8 sticks)',
								'Fried Tteokbokki - Snowcheese (8 sticks)',
								'Fried Tteokbokki - Honey (8 sticks)',
								'Cheeseballs Plain with Pizza Sauce (8pcs)',
								'Cheeseballs - Snowcheese (8pcs)',
							];
							$flavors = is_array($submittedOptions['flavors'] ?? null) ? $submittedOptions['flavors'] : [];
							$sides = is_array($submittedOptions['sides'] ?? null) ? $submittedOptions['sides'] : [];
							$flavorCount = 0;
							$sideCount = 0;
							foreach ($flavors as $name => $count) {
								$count = filter_var($count, FILTER_VALIDATE_INT);
								if (!in_array($name, $allowedFlavors, true) || $count === false || $count < 1) {
									$errors[] = 'One chicken flavor selection is invalid.';
									break;
								}
								$flavors[$name] = $count;
								$flavorCount += $count;
							}
							foreach ($sides as $name => $count) {
								$count = filter_var($count, FILTER_VALIDATE_INT);
								if (!in_array($name, $allowedSides, true) || $count === false || $count < 1 || $count > 99) {
									$errors[] = 'One side selection is invalid.';
									break;
								}
								$sides[$name] = $count;
								$sideCount += $count;
							}
							$drink = $submittedOptions['drink'] ?? '';
							if ($flavorCount < 1 || $flavorCount > $portionCount) {
								$errors[] = 'Select at least one chicken flavor and no more than ' . $portionCount . ' pieces.';
							}
							if ($sideCount < 1) {
								$errors[] = 'Select at least one side for your Fanverse Meal.';
							}
							if ($drink !== 'Regular Pepsi (' . $portionCount . 'pcs)') {
								$errors[] = 'Choose the included Pepsi for your Fanverse Meal.';
							}
							if ($errors) break;
							$options = [
								'flavors' => $flavors,
								'sides' => $sides,
								'drink' => $drink,
								'specialRequest' => $specialRequest,
							];
							$addOnTotal = $sideCount * 269.00;
						} elseif ($optionConfig) {
							$selection = is_array($submittedOptions['choiceSelections'] ?? null) ? $submittedOptions['choiceSelections'] : [];
							if (!$optionConfig) { $errors[] = 'Options are not available for this item.'; break; }
							foreach ($optionConfig as $group) {
								$value = $selection[$group['key']] ?? '';
								if (!empty($group['multiple'])) {
									if (!is_array($value)) { $errors[] = 'One side selection is invalid.'; break; }
									$countTotal = 0; $validCounts = [];
									foreach ($value as $choiceName => $count) {
										$choice = null; foreach ($group['choices'] as $candidate) if ($candidate['name'] === $choiceName) $choice = $candidate;
										$count = filter_var($count, FILTER_VALIDATE_INT);
										if (!$choice || $count === false || $count < 1) { $errors[] = 'One side selection is invalid.'; break; }
										$validCounts[$choiceName] = $count; $countTotal += $count; $addOnTotal += ($choice['priceCents'] / 100) * $count;
									}
									if ($errors) break;
									if ($countTotal > (int)$group['maxCount']) { $errors[] = 'Choose up to ' . (int)$group['maxCount'] . ' side options.'; break; }
									if ($validCounts) $options[$group['key']] = $validCounts;
									continue;
								}
								if ($group['required'] && !in_array($value, array_column($group['choices'], 'name'), true)) { $errors[] = 'Choose an option for ' . $group['label'] . '.'; break; }
								if ($value !== '') {
									$choice = null;
									foreach ($group['choices'] as $candidate) if ($candidate['name'] === $value) $choice = $candidate;
									if (!$choice) { $errors[] = 'One item option is invalid.'; break; }
									$options[$group['key']] = $value;
									$addOnTotal += $choice['priceCents'] / 100;
								}
							}
							if ($errors) break;
							if ($specialRequest !== '') $options['specialRequest'] = $specialRequest;
						} elseif (in_array($item['name'], ['NTS Bonless Chikin', 'NTS Bonless Chikin & Rosébokki'], true)) {
								$glaze = $submittedOptions['glaze'] ?? '';
								$validGlazes = ['Soy Garlic', 'Spicy', 'BYN', 'Honey Citrus', 'Crunchy Garlic', 'Golden Crunch', 'Snow Cheese'];
								if (!in_array($glaze, $validGlazes, true)) {
										$errors[] = 'Choose a glaze for ' . $item['name'] . '.';
										break;
								}
								$options['glaze'] = $glaze;
								if ($specialRequest !== '') $options['specialRequest'] = $specialRequest;
						} elseif ($specialRequest !== '') {
								$options['specialRequest'] = $specialRequest;
						}

							$unitPrice = (float)$item['price'] + $addOnTotal;
						$lineTotal = round($unitPrice * $quantity, 2);
						$subtotal += $lineTotal;
						$orderLines[] = [
								'item_id' => (int)$item['item_id'],
								'name' => $item['name'],
								'unit_price' => $unitPrice,
								'quantity' => $quantity,
								'options' => $options,
								'line_total' => $lineTotal,
						];
				}
		}

		$deliveryDistanceKm = 0.0;
		if (!$errors && $form['fulfillment'] === 'delivery') {
			if (!$selectedAddress || $selectedAddress['latitude'] === null || $selectedAddress['longitude'] === null || !$selectedBranch) {
				$errors[] = 'Choose a billing address with a map pin and a Nomcha branch to calculate delivery.';
			} else {
				$rad = static fn(float $value): float => $value * M_PI / 180;
				$lat1 = (float)$selectedAddress['latitude']; $lat2 = (float)$selectedBranch['lat'];
				$dLat = $rad($lat2 - $lat1); $dLng = $rad((float)$selectedBranch['lng'] - (float)$selectedAddress['longitude']);
				$a = sin($dLat / 2) ** 2 + cos($rad($lat1)) * cos($rad($lat2)) * sin($dLng / 2) ** 2;
				$deliveryDistanceKm = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
			}
		}
		$deliveryFee = $form['fulfillment'] === 'pickup' ? 0.00 : round(30 + ceil($deliveryDistanceKm) * 10, 2);
		if (!$errors && $orderLines) {
				try {
						$pdo->beginTransaction();
						$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
						$orderNumber = 'NC' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
						$total = round($subtotal + $deliveryFee, 2);
						$pdo->prepare(
								'INSERT INTO customer_orders
								 (order_number, user_id, fulfillment, branch_id, customer_name, phone, delivery_address,
									delivery_instructions, pickup_location, scheduled_for, payment_method,
									subtotal, delivery_fee, total)
								 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
						)->execute([
								$orderNumber,
								$userId,
								$form['fulfillment'],
								$selectedBranch['id'],
								$form['customer_name'],
								$form['phone'],
								$form['fulfillment'] === 'delivery' ? $form['delivery_address'] : null,
								$form['fulfillment'] === 'delivery' && $form['delivery_instructions'] !== '' ? $form['delivery_instructions'] : null,
								$form['fulfillment'] === 'pickup' ? $form['pickup_location'] : null,
								$scheduledFor,
								$form['payment_method'],
								$subtotal,
								$deliveryFee,
								$total,
						]);
						$orderId = (int)$pdo->lastInsertId();
						$lineInsert = $pdo->prepare(
								'INSERT INTO customer_order_items
								 (order_id, item_id, item_name, unit_price, quantity, options_json, line_total)
								 VALUES (?, ?, ?, ?, ?, ?, ?)'
						);
						foreach ($orderLines as $line) {
								$lineInsert->execute([
										$orderId,
										$line['item_id'],
										$line['name'],
										$line['unit_price'],
										$line['quantity'],
										$line['options'] ? json_encode($line['options'], JSON_UNESCAPED_UNICODE) : null,
										$line['line_total'],
								]);
						}
						$pdo->commit();
						$_SESSION['order_confirmation'] = [
								'number' => $orderNumber,
								'subtotal' => $subtotal,
								'delivery_fee' => $deliveryFee,
								'total' => $total,
								'fulfillment' => $form['fulfillment'],
						];
						header('Location: order.php?placed=1');
						exit;
				} catch (Throwable $ex) {
						if ($pdo->inTransaction()) $pdo->rollBack();
						$errors[] = 'We could not save your order request. Please check your basket and try again.';
				}
		}
}

$categories = [];
$itemsByCategory = [];
$menuData = [];
$dbError = null;
try {
		$allCategories = $pdo->query(
				'SELECT category_id, parent_id, name, description
				 FROM menu_categories ORDER BY sort_order, category_id'
		)->fetchAll();
		$allItems = $pdo->query(
			 'SELECT item_id, category_id, name, description, price, image_url, customizations_json
				 FROM menu_items WHERE is_available = 1 AND (publish_at IS NULL OR publish_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR)) ORDER BY sort_order, item_id'
		)->fetchAll();
		foreach ($allCategories as $category) {
				if ($category['parent_id'] === null) {
						$category['children'] = [];
						$categories[$category['category_id']] = $category;
				}
		}
		foreach ($allCategories as $category) {
				if ($category['parent_id'] !== null && isset($categories[$category['parent_id']])) {
						$categories[$category['parent_id']]['children'][] = $category;
				}
		}
		foreach ($allItems as $item) {
				if (isOrderCustomizationItem($item)) continue;
				$itemsByCategory[$item['category_id']][] = $item;
				$customOptions = json_decode((string)($item['customizations_json'] ?? ''), true);
				if (!is_array($customOptions)) $customOptions = orderItemOptions($item['name']);
				$fanverse = null;
				if (preg_match('/^([0-9]+)pc Chikin Rack Fanverse Meal$/', $item['name'], $bundleMatch)) {
					$fanverse = [
						'portionCount' => (int)$bundleMatch[1],
						'flavors' => ['Soy Garlic', 'Spicy', 'Honey Citrus', 'Crunchy Garlic', 'Nomcha YangNyeom', 'Snow Cheese', 'Golden Crunch Chikin without Gravy', 'Golden Crunch Chikin with Gravy'],
						'sides' => [
							['name' => 'Fried Tteokbokki - BYN (8 sticks)', 'priceCents' => 26900],
							['name' => 'Fried Tteokbokki - Snowcheese (8 sticks)', 'priceCents' => 26900],
							['name' => 'Fried Tteokbokki - Honey (8 sticks)', 'priceCents' => 26900],
							['name' => 'Cheeseballs Plain with Pizza Sauce (8pcs)', 'priceCents' => 26900],
							['name' => 'Cheeseballs - Snowcheese (8pcs)', 'priceCents' => 26900],
						],
						'drink' => 'Regular Pepsi (' . (int)$bundleMatch[1] . 'pcs)',
					];
				}
				$menuData[] = [
						'id' => (int)$item['item_id'],
						'name' => $item['name'],
						'priceCents' => $item['price'] !== null && (float)$item['price'] > 0 ? (int)round((float)$item['price'] * 100) : null,
						'imageUrl' => $item['image_url'],
						'description' => $item['description'],
						'options' => [],
						'customOptions' => $customOptions,
						'fanverse' => $fanverse,
				];
		}
		$hasOrderItems = static fn(int $categoryId): bool => !empty($itemsByCategory[$categoryId]);
		foreach ($categories as $categoryId => $category) {
				$children = array_values(array_filter(
						$category['children'],
						static fn(array $child): bool => $hasOrderItems((int)$child['category_id'])
				));
				if (!$hasOrderItems((int)$categoryId) && !$children) {
						unset($categories[$categoryId]);
				} else {
						$categories[$categoryId]['children'] = $children;
				}
		}
} catch (PDOException $ex) {
		$dbError = 'The menu is temporarily unavailable. Please try again later.';
}

$viewOrders = ($_GET['view'] ?? '') === 'orders';
$myOrders = [];
$orderHistoryError = null;
if ($viewOrders) {
		try {
				$historyQuery = $pdo->prepare(
						"SELECT o.order_id, o.order_number, o.fulfillment, o.subtotal, o.delivery_fee, o.total,
						 o.status, o.payment_status, o.created_at, o.scheduled_for,
						 (SELECT GROUP_CONCAT(CONCAT(i.quantity, ' x ', i.item_name) ORDER BY i.order_item_id SEPARATOR ' · ')
						  FROM customer_order_items i WHERE i.order_id = o.order_id) AS item_summary
						 FROM customer_orders o
						 WHERE o.user_id = ?
						 ORDER BY o.created_at DESC LIMIT 50"
				);
				$historyQuery->execute([(int)$_SESSION['user_id']]);
				$myOrders = $historyQuery->fetchAll();
		} catch (PDOException $ex) {
				$orderHistoryError = 'Your order history is temporarily unavailable.';
		}
}

require 'includes/header.php';
?>

<main class="order-page" data-order-confirmed="<?= $orderConfirmation ? 'yes' : 'no' ?>" data-ph-now="<?= e($scheduleNow->format('Y-m-d\TH:i')) ?>">
	<div class="order-toast-container" id="order-toast-container" aria-live="assertive" aria-atomic="false">
		<?php if ($errors): ?>
			<div class="order-toast order-toast-danger" role="alert">
				<i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
				<div><strong>Please review your order</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
				<button type="button" class="order-toast-close" data-order-toast-close aria-label="Dismiss">&times;</button>
			</div>
		<?php endif; ?>
	</div>
	<section class="order-heading-band">
		<div class="container py-4 py-lg-5">
			<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= e((string)$_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
			<img class="" src="https://www.bonchon.com.ph/storage/cms_images/lj8oc25rqsJim7X4z8o1WH5ZEJpumFQTQ625BsRa" alt="BBQ Sizzlers Meal" >
			<div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
				<div>
					<p class="order-eyebrow mb-2">Nomcha, made to order</p>
					<h1 class="mb-1">Order online</h1>
					<p class="mb-0">Choose your favorites, then select delivery or pickup.</p>
				</div>
				<a class="order-back-link" href="order.php?view=orders"><i class="bi bi-receipt me-2" aria-hidden="true"></i>My orders</a>
			</div>
		</div>
	</section>

	<?php if ($orderConfirmation): ?>
		<section class="container pt-4" aria-live="polite">
			<div class="order-success">
				<i class="bi bi-check-circle-fill" aria-hidden="true"></i>
				<div>
					<h2 class="h4 mb-1">Order request received</h2>
					<p class="mb-1">Reference <strong><?= e($orderConfirmation['number']) ?></strong>. Nomcha will contact you to confirm availability<?= $orderConfirmation['fulfillment'] === 'delivery' ? ' and delivery coverage' : ' and pickup timing' ?>.</p>
					<p class="mb-0">Items: <strong>₱<?= number_format((float)$orderConfirmation['subtotal'], 2) ?></strong><?php if($orderConfirmation['fulfillment']==='delivery'): ?> · Estimated delivery: <strong>₱<?=number_format((float)$orderConfirmation['delivery_fee'],2)?></strong><?php endif; ?> · Estimated total: <strong>₱<?=number_format((float)$orderConfirmation['total'],2)?></strong>. No online payment was taken.</p>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($viewOrders): ?>
		<section class="container py-4 py-lg-5">
			<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
				<div><p class="order-eyebrow mb-2">Your account</p><h2 class="h3 mb-0">My orders</h2></div>
				<a class="btn btn-order" href="order.php">Start an order</a>
			</div>
			<?php if ($orderHistoryError): ?>
				<div class="alert alert-warning"><?= e($orderHistoryError) ?></div>
			<?php elseif (!$myOrders): ?>
				<div class="order-history-empty"><i class="bi bi-receipt" aria-hidden="true"></i><h3>No orders yet</h3><p>Your submitted orders will appear here.</p><a class="btn btn-order" href="order.php">Browse the menu</a></div>
			<?php else: ?>
				<div class="order-history-list">
					<?php foreach ($myOrders as $myOrder): ?>
						<?php $itemSummary = str_replace(['BTS Bonless Chikin & Rosébokki', 'BTS Bonless Chikin', 'BTS Korean Fried Chicken'], ['NTS Bonless Chikin & Rosébokki', 'NTS Bonless Chikin', 'NTS Korean Fried Chicken'], (string)($myOrder['item_summary'] ?? '')); ?>
						<article class="order-history-row">
							<div class="order-history-heading">
								<div><strong><?= e($myOrder['order_number']) ?></strong><time datetime="<?= e(date(DATE_ATOM, strtotime($myOrder['created_at']))) ?>"><?= e(date('M j, Y · g:i A', strtotime($myOrder['created_at']))) ?></time></div>
								<span class="order-history-status"><?= e(ucfirst($myOrder['status'])) ?></span>
							<?php if (strtolower((string)$myOrder['status']) === 'pending'): ?><button type="button" class="btn btn-sm btn-outline-danger" data-cancel-order="<?= (int)$myOrder['order_id'] ?>" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">Cancel order</button><?php endif; ?>
							</div>
							<p class="order-history-items mb-2"><?= e($itemSummary ?: 'No item details') ?></p>
							<div class="order-history-meta">
								<span><?= e(ucfirst($myOrder['fulfillment'])) ?><?= $myOrder['scheduled_for'] ? ' · ' . e(date('M j, g:i A', strtotime($myOrder['scheduled_for']))) : ' · ASAP' ?></span>
								<strong>₱<?= number_format((float)($myOrder['total'] ?? $myOrder['subtotal']), 2) ?><?= $myOrder['total'] === null ? ' + delivery fee' : '' ?></strong>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	<?php elseif ($dbError): ?>
		<div class="container py-4"><div class="alert alert-warning mb-0"><?= e($dbError) ?></div></div>
	<?php else: ?>
		<div class="container-fluid order-layout-container py-4 py-lg-5">
			<div class="row g-4 g-xl-5 align-items-start">
				<aside class="col-lg-3 order-categories-col">
					<nav class="order-categories" aria-label="Menu categories">
						<h2 class="h5 mb-3">Browse menu</h2>
						<label class="visually-hidden" for="order-search">Search menu</label>
						<div class="input-group mb-3">
							<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
							<input id="order-search" class="form-control" type="search" placeholder="Search menu">
						</div>
						<div class="order-category-links">
							<?php foreach ($categories as $category): ?>
								<?php if ($category['children']): ?>
									<div class="order-category-group">
										<div class="order-category-heading">
											<a href="#order-category-<?= (int)$category['category_id'] ?>"><?= e($category['name']) ?></a>
											<button class="btn btn-sm menu-toggle order-category-toggle" type="button"
												data-bs-toggle="collapse" data-bs-target="#order-subcategories-<?= (int)$category['category_id'] ?>"
												aria-expanded="true" aria-controls="order-subcategories-<?= (int)$category['category_id'] ?>"
												aria-label="Toggle <?= e($category['name']) ?> subcategories">
												<i class="bi bi-chevron-up" aria-hidden="true"></i>
											</button>
										</div>
										<div class="collapse show order-category-sub-links" id="order-subcategories-<?= (int)$category['category_id'] ?>">
											<?php foreach ($category['children'] as $child): ?>
												<a class="order-category-child" href="#order-category-<?= (int)$child['category_id'] ?>"><?= e($child['name']) ?></a>
											<?php endforeach; ?>
										</div>
									</div>
								<?php else: ?>
									<a href="#order-category-<?= (int)$category['category_id'] ?>"><?= e($category['name']) ?></a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</nav>
				</aside>

				<section class="col-lg-6 order-catalog" aria-label="Menu items">
					<?php foreach ($categories as $category): ?>
						<?php $rootItems = $itemsByCategory[$category['category_id']] ?? []; ?>
						<section class="order-menu-section" id="order-category-<?= (int)$category['category_id'] ?>">
							<h2><?= e($category['name']) ?></h2>
							<?php if ($category['description']): ?><p class="order-section-desc"><?= e($category['description']) ?></p><?php endif; ?>
							<?php if ($rootItems): ?>
								<div class="order-item-list">
									<?php foreach ($rootItems as $item): ?>
										<?php $priced = $item['price'] !== null && (float)$item['price'] > 0; ?>
										<article class="order-item-card" data-order-card data-item-id="<?= (int)$item['item_id'] ?>" data-search-text="<?= e(mb_strtolower($item['name'] . ' ' . ($item['description'] ?? ''))) ?>">
											<?php if ($item['image_url']): ?>
												<img src="<?= e($item['image_url']) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
											<?php else: ?>
												<div class="order-item-placeholder"><i class="bi bi-egg-fried" aria-hidden="true"></i></div>
											<?php endif; ?>
											<div class="order-item-content">
												<div class="d-flex justify-content-between gap-2 align-items-start">
													<h3><?= e($item['name']) ?></h3>
													<?php if ($priced): ?><strong class="order-item-price">₱<?= number_format((float)$item['price'], 2) ?></strong><?php endif; ?>
												</div>
												<?php if ($item['description']): ?><p><?= e($item['description']) ?></p><?php endif; ?>
												<?php if ($priced): ?>
													<button type="button" class="btn btn-order-item" data-add-item="<?= (int)$item['item_id'] ?>">
														<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Add</span>
													</button>
												<?php else: ?>
													<span class="order-price-pending">Price to be confirmed</span>
												<?php endif; ?>
											</div>
										</article>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
								<?php foreach ($category['children'] as $child): ?>
								<?php $childItems = $itemsByCategory[$child['category_id']] ?? []; ?>
								<section class="order-subsection" id="order-category-<?= (int)$child['category_id'] ?>">
									<h3><?= e($child['name']) ?></h3>
									<?php if ($child['description']): ?><p class="order-section-desc"><?= e($child['description']) ?></p><?php endif; ?>
									<div class="order-item-list">
										<?php foreach ($childItems as $item): ?>
											<?php $priced = $item['price'] !== null && (float)$item['price'] > 0; ?>
											<article class="order-item-card" data-order-card data-item-id="<?= (int)$item['item_id'] ?>" data-search-text="<?= e(mb_strtolower($item['name'] . ' ' . ($item['description'] ?? ''))) ?>">
												<?php if ($item['image_url']): ?>
													<img src="<?= e($item['image_url']) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
												<?php else: ?>
													<div class="order-item-placeholder"><i class="bi bi-egg-fried" aria-hidden="true"></i></div>
												<?php endif; ?>
												<div class="order-item-content">
													<div class="d-flex justify-content-between gap-2 align-items-start">
														<h4><?= e($item['name']) ?></h4>
														<?php if ($priced): ?><strong class="order-item-price">₱<?= number_format((float)$item['price'], 2) ?></strong><?php endif; ?>
													</div>
													<?php if ($item['description']): ?><p><?= e($item['description']) ?></p><?php endif; ?>
													<?php if ($priced): ?>
														<button type="button" class="btn btn-order-item" data-add-item="<?= (int)$item['item_id'] ?>">
															<i class="bi bi-plus-lg" aria-hidden="true"></i><span>Add</span>
														</button>
													<?php else: ?>
														<span class="order-price-pending">Price to be confirmed</span>
													<?php endif; ?>
												</div>
											</article>
										<?php endforeach; ?>
									</div>
								</section>
							<?php endforeach; ?>
						</section>
					<?php endforeach; ?>
					<p id="order-no-results" class="alert alert-light d-none">No menu items match your search.</p>
				</section>

				<aside class="col-lg-3" id="checkout-panel">
					<form id="checkout-form" class="order-checkout" method="post" action="order.php" novalidate>
						<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
						<input type="hidden" name="cart_json" id="cart-json" value="[]">
						<div class="order-checkout-heading">
							<h2>Your basket</h2>
							<span id="cart-count" class="order-count">0</span>
						</div>
						<div id="cart-items" class="order-cart-items" aria-live="polite">
							<div class="order-cart-empty"><i class="bi bi-bag" aria-hidden="true"></i><p>Your basket is empty.</p><span>Add a priced item to get started.</span></div>
						</div>
						<div class="order-totals">
							<div><span>Item subtotal</span><strong id="cart-subtotal">₱0.00</strong></div>
							<div class="order-fee-row"><span>Delivery fee</span><span id="delivery-fee-label">Choose address and branch</span></div>
						</div>

						<fieldset class="order-form-section">
							<legend>Receive your order</legend>
							<div class="order-mode-switch" role="group" aria-label="Fulfillment type">
							<button type="button" class="<?= $form['fulfillment']==='delivery'?'active':'' ?>" data-fulfillment-option="delivery" aria-pressed="<?= $form['fulfillment']==='delivery'?'true':'false' ?>"><i class="bi bi-bicycle" aria-hidden="true"></i>Delivery</button>
							<button type="button" class="<?= $form['fulfillment']==='pickup'?'active':'' ?>" data-fulfillment-option="pickup" aria-pressed="<?= $form['fulfillment']==='pickup'?'true':'false' ?>"><i class="bi bi-shop" aria-hidden="true"></i>Pickup</button>
							</div>
							<input type="hidden" name="fulfillment" id="fulfillment" value="<?= e($form['fulfillment']) ?>">
						<input type="hidden" name="branch_id" id="branch-id" value="<?=e($form['branch_id'])?>">
						<div>
							<label class="form-label">Billing address</label>
			<div class="order-address-list" role="radiogroup" aria-label="Choose a saved billing address"><?php foreach($savedAddresses as $saved): ?><label class="order-address-option <?= (string)$saved['address_id']===(string)$form['address_id']?'selected':'' ?>"><input type="radio" name="address_id" value="<?=(int)$saved['address_id']?>" data-lat="<?=e((string)$saved['latitude'])?>" data-lng="<?=e((string)$saved['longitude'])?>" data-name="<?=e($saved['recipient_name'])?>" data-mobile="<?=e($saved['mobile_no'])?>" data-address="<?=e(orderAddressLabel($saved))?>" <?= (string)$saved['address_id']===(string)$form['address_id']?'checked':'' ?> required><span><strong>Name:</strong> <?=e($saved['recipient_name'])?><br><strong>Address:</strong> <?=e(orderAddressLabel($saved))?><br><strong>Mobile number:</strong> <?=e($saved['mobile_no'])?><?php if(trim((string)$saved['street_address'])==='' || !preg_match('/^\d{4}$/',(string)$saved['zip_code'])): ?><br><small class="text-danger">Complete Address and ZIP code in Billing Address to enable delivery.</small><?php endif; ?></span></label><?php endforeach; ?></div>
							<?php if(!$savedAddresses): ?><p class="order-helper">Add a saved billing address before placing an order. <a href="billing-address.php">Manage addresses</a></p><?php endif; ?>
							<div id="selected-address-summary" class="order-selected-address"></div>
						</div>
						<div data-delivery-fields>
								<label for="delivery-instructions" class="form-label">Rider instructions <span class="text-secondary">(optional)</span></label>
								<textarea id="delivery-instructions" class="form-control" name="delivery_instructions" rows="2" maxlength="500" placeholder="Gate, landmark, or delivery notes"><?= e($form['delivery_instructions']) ?></textarea>
							</div>
							<div data-pickup-fields hidden>
							<p class="order-helper">Choose a branch for pickup below.</p>
							</div>
						<div class="mt-2">
							<label class="form-label" for="branch-choice-button">Nomcha branch</label>
							<button type="button" class="order-branch-choice" id="branch-choice-button" data-bs-toggle="modal" data-bs-target="#branch-picker-modal"><span id="selected-branch-label"><?= $selectedBranch ? e($selectedBranch['name'].' · '.$selectedBranch['address']) : 'Choose a branch' ?></span><i class="bi bi-chevron-right float-end" aria-hidden="true"></i></button>
							<p class="order-helper" id="delivery-distance-label" aria-live="polite"></p>
						</fieldset>

						<fieldset class="order-form-section">
							<legend>Choose time</legend>
							<button type="button" class="order-schedule-trigger" id="schedule-picker-open">
								<span><i class="bi bi-clock me-2" aria-hidden="true"></i><strong id="order-schedule-label"><?= e($form['scheduled_for'] !== '' ? ucfirst($form['fulfillment']) . ' · ' . date('g:i A', strtotime($form['scheduled_for'])) : ucfirst($form['fulfillment']) . ' ASAP') ?></strong></span>
								<i class="bi bi-chevron-right" aria-hidden="true"></i>
							</button>
							<input id="scheduled-for" type="hidden" name="scheduled_for" value="<?= e($form['scheduled_for']) ?>">
							<p class="order-helper">Same-day times available from 9:30 AM to 11:30 PM.</p>
						</fieldset>

						<fieldset class="order-form-section">
							<legend>Payment method</legend>
							<label class="order-payment-option"><input type="radio" name="payment_method" value="cash" <?= $form['payment_method'] === 'cash' ? 'checked' : '' ?>><span><strong>Cash</strong><small>Pay when your order arrives or at pickup</small></span><i class="bi bi-cash-coin" aria-hidden="true"></i></label>
							<label class="order-payment-option"><input type="radio" name="payment_method" value="gcash" <?= $form['payment_method'] === 'gcash' ? 'checked' : '' ?>><span><strong>GCash</strong><small>Payment instructions confirmed by Nomcha</small></span><i class="bi bi-phone" aria-hidden="true"></i></label>
							<label class="order-payment-option"><input type="radio" name="payment_method" value="maya" <?= $form['payment_method'] === 'maya' ? 'checked' : '' ?>><span><strong>Maya</strong><small>Payment instructions confirmed by Nomcha</small></span><i class="bi bi-phone" aria-hidden="true"></i></label>
							<label class="order-payment-option" data-card-payment><input type="radio" name="payment_method" value="card_pickup" <?= $form['payment_method'] === 'card_pickup' ? 'checked' : '' ?>><span><strong>Card at pickup</strong><small>Pay at the counter</small></span><i class="bi bi-credit-card" aria-hidden="true"></i></label>
							<p class="order-helper mb-0">This site does not collect card details or process online payments.</p>
						</fieldset>

						<div class="form-check order-confirm-check">
							  <input class="form-check-input" type="checkbox" id="order-confirm" name="confirm_order" value="1" required>
							<label class="form-check-label" for="order-confirm">I confirm my order and contact details are correct.</label>
						</div>
						<button type="submit" id="place-order" class="btn btn-order w-100 py-3" disabled>Place order request</button>
						<p class="order-submit-note">Your request is pending confirmation. Delivery fee and availability will be confirmed by Nomcha.</p>
					</form>
				</aside>
			</div>
		</div>
	<?php endif; ?>
</main>

<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="cancel-order.php"><div class="modal-header"><h2 class="modal-title fs-5">Cancel this order?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">This pending order will be cancelled. You cannot undo this action.<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="order_id" id="cancel-order-id"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep order</button><button class="btn btn-danger">Cancel order</button></div></form></div></div></div>
<script>document.querySelectorAll('[data-cancel-order]').forEach(button=>button.addEventListener('click',()=>{document.getElementById('cancel-order-id').value=button.dataset.cancelOrder;}));</script>

<button type="button" class="order-mobile-cart d-lg-none" id="mobile-cart-button" aria-label="View basket and checkout">
	<span><i class="bi bi-bag me-2" aria-hidden="true"></i><span id="mobile-cart-count">0</span> items</span>
	<strong id="mobile-cart-total">₱0.00</strong>
</button>

<div class="modal fade" id="schedule-time-modal" tabindex="-1" aria-labelledby="schedule-time-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content order-schedule-modal">
			<div class="modal-header">
				<h2 class="modal-title h4" id="schedule-time-title">Choose time</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="order-mode-switch" role="group" aria-label="Fulfillment type">
					<button type="button" data-schedule-fulfillment="delivery"><i class="bi bi-bicycle" aria-hidden="true"></i>Delivery</button>
					<button type="button" data-schedule-fulfillment="pickup"><i class="bi bi-shop" aria-hidden="true"></i>Pickup</button>
				</div>
				<div class="order-schedule-day"><strong>Today</strong><span id="schedule-day-label"></span></div>
				<div id="schedule-time-options" class="order-schedule-options" role="radiogroup" aria-label="Available same-day times"></div>
			</div>
			<div class="modal-footer order-schedule-footer">
				<button type="button" id="confirm-schedule-time" class="btn btn-order w-100 py-3">Confirm Delivery Time</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="branch-picker-modal" tabindex="-1" aria-labelledby="branch-picker-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
		<div class="modal-header"><h2 class="modal-title h4" id="branch-picker-title">Choose a Nomcha branch</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
		<div class="modal-body"><label for="branch-picker-search" class="form-label">Search branches</label><input id="branch-picker-search" class="form-control mb-2" type="search" placeholder="Type a branch, city, or address" autocomplete="off"><div id="branch-picker-results" class="order-branch-results mb-3" role="listbox" aria-label="Branch search results"></div><div id="order-branch-map" class="order-branch-map" aria-label="Map of Nomcha branches"></div><p class="branch-map-credit">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a></p></div>
	</div></div>
</div>

<div class="modal fade" id="item-options-modal" tabindex="-1" aria-labelledby="item-options-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content order-options-modal">
			<form id="item-options-form">
				<div class="modal-header">
					<div><p class="order-eyebrow mb-1">Customize your order</p><h2 class="modal-title h4" id="item-options-title"></h2></div>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body order-options-body">
					<div class="order-option-product">
						<img id="item-options-image" src="" alt="">
						<div><p id="item-options-description" class="mb-2"></p><strong id="item-options-base-price"></strong><span class="order-option-base-label">Base price</span></div>
					</div>
					<div id="item-options-fields"></div>
					<p id="item-options-error" class="order-option-error" role="alert"></p>
					<div class="order-special-request"><label for="option-special-request" class="form-label">Any special request?</label><textarea id="option-special-request" class="form-control" rows="3" maxlength="500" placeholder="Add a note to your order"></textarea></div>
				</div>
				<div class="modal-footer order-options-footer">
					<div class="order-option-quantity">
						<button type="button" data-order-quantity="decrease" aria-label="Decrease order quantity">−</button>
						<input id="option-quantity" type="number" min="1" max="99" value="1" required aria-label="Order quantity">
						<button type="button" data-order-quantity="increase" aria-label="Increase order quantity">+</button>
					</div>
					<button type="submit" class="btn btn-order order-options-submit">Add to basket <strong id="item-options-total"></strong></button>
				</div>
			</form>
		</div>
	</div>
</div>

<div class="modal fade" id="remove-item-modal" tabindex="-1" aria-labelledby="remove-item-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="remove-item-title">Remove from basket?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">Remove <strong id="remove-item-name"></strong> from your basket?</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep item</button><button type="button" class="btn btn-danger" id="confirm-remove-item">Remove item</button></div></div></div>
</div>

<script id="order-menu-data" type="application/json"><?= json_encode($menuData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script id="order-branch-data" type="application/json"><?= json_encode($branches, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script id="order-address-data" type="application/json"><?= json_encode($savedAddresses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php
$pageScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/order.js?v=' . filemtime(__DIR__ . '/assets/js/order.js') . '"></script><script src="assets/js/order-branch-picker.js?v=' . filemtime(__DIR__ . '/assets/js/order-branch-picker.js') . '"></script>';
require 'includes/footer.php';
?>
