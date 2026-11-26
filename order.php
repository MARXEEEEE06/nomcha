<?php
require 'includes/auth_helpers.php';
require 'includes/db.php';
require_once 'includes/branch_helpers.php';
require_customer($pdo);
const ORDER_MAX_DISTANCE_KM = 25.0;

$pageTitle = 'Order Online';
$pageStyles = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
$branches = get_public_branches($pdo);
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
		'scheduled_for' => '',
		'payment_method' => 'cash',
		'address_id' => '',
		'branch_id' => '',
];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && in_array(($_GET['fulfillment']??''),['delivery','pickup'],true)) {
		$form['fulfillment'] = (string)$_GET['fulfillment'];
		$requestedBranch = filter_var($_GET['branch_id']??null,FILTER_VALIDATE_INT);
		foreach ($branches as $branch) if ($requestedBranch && (int)$requestedBranch === (int)$branch['id']) { $form['branch_id'] = (string)$branch['id']; $selectedBranch = $branch; break; }
}
$orderConfirmation = $_SESSION['order_confirmation'] ?? null;
unset($_SESSION['order_confirmation']);
$claimedVouchersQuery = $pdo->prepare("SELECT c.claim_id,p.name,p.discount_type,p.discount_value FROM promotion_claims c JOIN promotions p ON p.promo_id=c.promo_id WHERE c.user_id=? AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.claim_id=c.claim_id) AND p.is_platform_voucher=1 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at ORDER BY c.claimed_at DESC");
$claimedVouchersQuery->execute([(int)$_SESSION['user_id']]);
$claimedVouchers = $claimedVouchersQuery->fetchAll();

function orderItemOptionGroups(PDO $pdo, int $itemId): array {
	$query = $pdo->prepare("SELECT ig.item_option_group_id,ig.label,ig.min_selections,ig.max_selections,c.option_id,COALESCE(o.name,source_item.name) AS option_name,c.price_adjustment
		FROM menu_item_option_groups ig
		LEFT JOIN menu_item_option_choices c ON c.item_option_group_id=ig.item_option_group_id
		LEFT JOIN menu_options o ON o.option_id=c.option_id AND o.option_group_id=ig.option_group_id AND o.is_available=1
		LEFT JOIN menu_items source_item ON source_item.item_id=o.source_item_id AND source_item.is_available=1 AND (source_item.publish_at IS NULL OR source_item.publish_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))
		WHERE ig.item_id=?
		ORDER BY ig.sort_order,ig.item_option_group_id,c.sort_order,c.option_id");
	$query->execute([$itemId]);
	$groups = [];
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$key = (string)$row['item_option_group_id'];
		if (!isset($groups[$key])) $groups[$key] = [
			'key' => $key,
			'label' => $row['label'],
			'required' => (int)$row['min_selections'] > 0,
			'minCount' => (int)$row['min_selections'],
			'multiple' => $row['max_selections'] === null || (int)$row['max_selections'] > 1 || (int)$row['min_selections'] > 1,
			'maxCount' => $row['max_selections'] === null ? 99 : (int)$row['max_selections'],
			'choices' => [],
		];
		if ($row['option_id'] !== null && $row['option_name'] !== null) $groups[$key]['choices'][] = [
			'id' => (int)$row['option_id'],
			'name' => $row['option_name'],
			'priceCents' => (int)round((float)$row['price_adjustment'] * 100),
		];
	}
	return array_values($groups);
}

function orderAddressLabel(array $address): string {
	return implode(', ', array_filter([
		$address['street_address'] ?? '', $address['barangay'] ?? '', $address['municipality'] ?? '',
		$address['region'] ?? '', $address['zip_code'] ?? '',
	], static fn($part): bool => trim((string)$part) !== ''));
}

if (!empty($_SESSION['user_id'])) {
		try {
				$profileQuery = $pdo->prepare('SELECT first_name, last_name, phone FROM user_info WHERE user_id = ?');
				$profileQuery->execute([(int)$_SESSION['user_id']]);
				if ($profile = $profileQuery->fetch()) {
						$form['customer_name'] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
						$form['phone'] = $profile['phone'] ?? '';
				}
				$addressQuery = $pdo->prepare("SELECT a.address_id,a.recipient_name,a.recipient_phone,a.street_address,b.name AS barangay,l.name AS municipality,r.name AS region,a.zip_code,a.latitude,a.longitude,a.is_default
					FROM user_addresses a JOIN psgc_barangays b ON b.psgc_code=a.barangay_code JOIN psgc_localities l ON l.psgc_code=b.locality_code LEFT JOIN psgc_provinces p ON p.psgc_code=l.province_code JOIN psgc_regions r ON r.psgc_code=COALESCE(l.region_code,p.region_code)
					WHERE a.user_id=? ORDER BY a.is_default DESC,a.address_id DESC");
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
						$form['phone'] = $selectedAddress['recipient_phone'] ?? $form['phone'];
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
			$addressLookup = $pdo->prepare("SELECT a.address_id,a.recipient_name,a.recipient_phone,a.street_address,b.name AS barangay,l.name AS municipality,r.name AS region,a.zip_code,a.latitude,a.longitude
				FROM user_addresses a JOIN psgc_barangays b ON b.psgc_code=a.barangay_code JOIN psgc_localities l ON l.psgc_code=b.locality_code LEFT JOIN psgc_provinces p ON p.psgc_code=l.province_code JOIN psgc_regions r ON r.psgc_code=COALESCE(l.region_code,p.region_code)
				WHERE a.user_id=? AND a.address_id=?");
			$addressLookup->execute([(int)$_SESSION['user_id'], (int)$form['address_id']]);
			$selectedAddress = $addressLookup->fetch() ?: null;
		}
		if (!$selectedAddress) $errors[] = 'Choose a saved billing address.';
		else {
			$form['customer_name'] = $selectedAddress['recipient_name'];
			$form['phone'] = $selectedAddress['recipient_phone'] ?? '';
			$form['delivery_address'] = orderAddressLabel($selectedAddress);
			if ($form['fulfillment'] === 'delivery' && (trim((string)$selectedAddress['street_address']) === '' || !preg_match('/^\d{4}$/', (string)$selectedAddress['zip_code']))) $errors[] = 'Please complete the Address and ZIP code fields for this billing address before delivery.';
			if ($selectedAddress['latitude'] === null || $selectedAddress['longitude'] === null) $errors[] = 'Add a map pin to your saved billing address to check the 25 km ordering range.';
		}
		foreach ($branches as $branch) if ((string)$branch['id'] === (string)$form['branch_id']) { $selectedBranch = $branch; break; }
		if (!$selectedBranch || ($form['fulfillment'] === 'pickup' && empty($selectedBranch['supports_pickup'])) || ($form['fulfillment'] === 'delivery' && empty($selectedBranch['supports_delivery']))) $errors[] = 'Choose a branch that offers the selected fulfillment option.';

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
		if ($form['fulfillment'] === 'delivery' && $scheduledFor === null && $scheduleNow->format('H:i') > '23:30') {
			$errors[] = 'Online delivery is unavailable for today. Please choose pickup or try again tomorrow.';
		}

		$cart = json_decode($_POST['cart_json'] ?? '[]', true);
		if (!is_array($cart) || !$cart || count($cart) > 40) {
				$errors[] = 'Add at least one priced menu item to your basket.';
				$cart = [];
		}

		$orderLines = [];
		$subtotal = 0.0;
		if (!$errors) {
			$itemQuery = $pdo->prepare("SELECT item_id, name, price FROM menu_items WHERE item_id=? AND is_available=1 AND (publish_at IS NULL OR publish_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))");
				foreach ($cart as $entry) {
						if (!is_array($entry)) { $errors[] = 'One basket item is invalid. Review your basket and try again.'; break; }
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

						$selectedOptions = [];
						$optionUnitTotal = 0.0;
						$optionConfig = orderItemOptionGroups($pdo,(int)$item['item_id']);
						$submittedOptions = is_array($entry['options'] ?? null) ? $entry['options'] : [];
						$specialRequestValue = $submittedOptions['specialRequest'] ?? '';
						if (!is_string($specialRequestValue) || mb_strlen($specialRequestValue) > 500) {
							$errors[] = 'Special requests must be 500 characters or fewer.';
							break;
						}
						$specialRequest = trim($specialRequestValue);
						$selection = is_array($submittedOptions['choiceSelections'] ?? null) ? $submittedOptions['choiceSelections'] : [];
						foreach ($optionConfig as $group) {
							$choicesById = [];
							foreach ($group['choices'] as $choice) $choicesById[(string)$choice['id']] = $choice;
							$value = $selection[$group['key']] ?? ($group['multiple'] ? [] : '');
							$countTotal = 0;
							$chosen = [];
							if ($group['multiple']) {
								if (!is_array($value)) { $errors[] = 'One item option selection is invalid.'; break; }
								foreach ($value as $optionId => $count) {
									$optionId = filter_var($optionId,FILTER_VALIDATE_INT);
									$count = filter_var($count,FILTER_VALIDATE_INT);
									$choice = $optionId ? ($choicesById[(string)$optionId] ?? null) : null;
									if (!$choice || $count === false || $count < 1 || $count > 99) { $errors[] = 'One item option selection is invalid.'; break; }
									$countTotal += $count;
									$chosen[] = [$choice,$count];
								}
							} elseif ($value !== '' && $value !== null) {
								$optionId = filter_var($value,FILTER_VALIDATE_INT);
								$choice = $optionId ? ($choicesById[(string)$optionId] ?? null) : null;
								if (!$choice) $errors[] = 'One item option selection is invalid.';
								else { $countTotal = 1; $chosen[] = [$choice,1]; }
							}
							if ($errors) break;
							if ($countTotal < $group['minCount'] || $countTotal > $group['maxCount']) {
								$errors[] = 'Choose ' . ($group['minCount'] > 0 ? 'at least ' . $group['minCount'] : 'up to ' . $group['maxCount']) . ' option(s) for ' . $group['label'] . '.';
								break;
							}
							foreach ($chosen as [$choice,$choiceCount]) {
								$optionUnitTotal += ((float)$choice['priceCents'] / 100) * $choiceCount;
								$selectedOptions[] = [
									'option_id' => (int)$choice['id'],
									'group_name' => $group['label'],
									'option_name' => $choice['name'],
									'unit_price_adjustment' => (float)$choice['priceCents'] / 100,
									'quantity' => $choiceCount * $quantity,
								];
							}
						}
						if ($errors) break;
						$unitPrice = (float)$item['price'];
						$lineTotal = round(($unitPrice + $optionUnitTotal) * $quantity, 2);
						$subtotal += $lineTotal;
						$orderLines[] = [
								'item_id' => (int)$item['item_id'],
								'name' => $item['name'],
								'unit_price' => $unitPrice,
								'promotional_unit_price' => $unitPrice + $optionUnitTotal,
								'quantity' => $quantity,
								'special_request' => $specialRequest ?: null,
								'options' => $selectedOptions,
								'base_line_total' => round($unitPrice * $quantity,2),
								'line_total' => $lineTotal,
						];
				}
		}

		$deliveryDistanceKm = 0.0;
		if (!$errors) {
			if (!$selectedAddress || $selectedAddress['latitude'] === null || $selectedAddress['longitude'] === null || !$selectedBranch) {
				$errors[] = 'Choose a saved billing address with a map pin and a Nomcha branch.';
			} else {
				$rad = static fn(float $value): float => $value * M_PI / 180;
				$lat1 = (float)$selectedAddress['latitude']; $lat2 = (float)$selectedBranch['lat'];
				$dLat = $rad($lat2 - $lat1); $dLng = $rad((float)$selectedBranch['lng'] - (float)$selectedAddress['longitude']);
				$a = sin($dLat / 2) ** 2 + cos($rad($lat1)) * cos($rad($lat2)) * sin($dLng / 2) ** 2;
				$branchDistanceKm = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
				if ($branchDistanceKm > ORDER_MAX_DISTANCE_KM) $errors[] = 'Choose a Nomcha branch within 25 km of your saved billing address for delivery or pickup.';
				elseif ($form['fulfillment'] === 'delivery') $deliveryDistanceKm = $branchDistanceKm;
			}
		}
		$deliveryFee = $form['fulfillment'] === 'pickup' ? 0.00 : round(30 + ceil($deliveryDistanceKm) * 10, 2);
		$deliveryFreeForNewAccount = false;
		if (!$errors && $form['fulfillment'] === 'delivery' && $subtotal >= 400) {
			$accountAge = $pdo->prepare('SELECT created_at, (SELECT COUNT(*) FROM customer_orders WHERE user_id = user_credentials.user_id) AS order_count FROM user_credentials WHERE user_id = ?');
			$accountAge->execute([(int)$_SESSION['user_id']]);
			$accountEligibility = $accountAge->fetch();
			if ($accountEligibility && (int)$accountEligibility['order_count'] < 3 && strtotime((string)$accountEligibility['created_at']) > strtotime('-1 month')) {
				$deliveryFee = 0.00;
				$deliveryFreeForNewAccount = true;
			}
		}
		if (!$errors && $subtotal > 4999) $errors[] = 'Online orders are limited to ₱4,999. Orders of ₱5,000 or more must be placed through your nearest Nomcha branch.';
		$promo = null; $claimId = (int)($_POST['voucher_claim_id'] ?? 0); $promoCode = strtoupper(trim((string)($_POST['promo_code'] ?? ''))); $discountAmount = 0.00;
		if (!$errors && ($claimId > 0 || $promoCode !== '')) {
			if ($claimId > 0 && $promoCode !== '') $errors[] = 'Choose either a voucher or a promo code.';
			else {
				$promoQuery = $claimId > 0
					? $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM promotion_redemptions r WHERE r.promo_id=p.promo_id) AS redemption_count FROM promotion_claims c JOIN promotions p ON p.promo_id=c.promo_id WHERE c.claim_id=? AND c.user_id=? AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.claim_id=c.claim_id) AND p.is_platform_voucher=1 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at")
					: $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM promotion_redemptions r WHERE r.promo_id=p.promo_id) AS redemption_count FROM promotions p WHERE p.promo_code=? AND p.is_platform_voucher=0 AND p.status='active' AND NOW() BETWEEN p.starts_at AND p.ends_at");
				$claimId > 0 ? $promoQuery->execute([$claimId,(int)$_SESSION['user_id']]) : $promoQuery->execute([$promoCode]);
				$promo = $promoQuery->fetch() ?: null;
				if (!$promo) $errors[] = 'That voucher or promo code is invalid, expired, or already used.';
				elseif ($promo['usage_limit'] !== null && (int)$promo['redemption_count'] >= (int)$promo['usage_limit']) $errors[] = 'This offer has reached its redemption limit.';
				elseif ((float)$subtotal < (float)$promo['minimum_spend']) $errors[] = 'This offer requires a minimum spend of ₱' . number_format((float)$promo['minimum_spend'],2) . '.';
				elseif (!in_array((string)$promo['channel'],['','all',$form['payment_method']],true)) $errors[] = 'This offer is not available with the selected payment method.';
				else {
					$eligibleQuery=$pdo->prepare('SELECT item_id FROM promotion_eligible_items WHERE promo_id=?');$eligibleQuery->execute([(int)$promo['promo_id']]);$eligibleIds=array_map('intval',$eligibleQuery->fetchAll(PDO::FETCH_COLUMN));
					$eligibleSubtotal=0.0;
					foreach($orderLines as $line) if($promo['eligibility_scope']==='all'||in_array((int)$line['item_id'],$eligibleIds,true)) $eligibleSubtotal+=$line['line_total'];
					if($eligibleSubtotal<=0) $errors[]='Your basket does not contain an eligible product for this offer.';
					else if($promo['discount_type']==='percent') $discountAmount=min($eligibleSubtotal,$eligibleSubtotal*(float)$promo['discount_value']/100);
					else if($promo['discount_type']==='fixed') $discountAmount=min($eligibleSubtotal,(float)$promo['discount_value']);
					else {
						$freeUnit=0.0; foreach($orderLines as $line) if(($promo['eligibility_scope']==='all'||in_array((int)$line['item_id'],$eligibleIds,true))&&$line['promotional_unit_price']>$freeUnit)$freeUnit=(float)$line['promotional_unit_price'];
						$discountAmount=min($eligibleSubtotal,$freeUnit,(float)$promo['discount_value']>0?(float)$promo['discount_value']:$freeUnit);
					}
					if($promo['discount_type']==='percent'&&$promo['max_discount']!==null) $discountAmount=min($discountAmount,(float)$promo['max_discount']);
					$discountAmount=round($discountAmount,2);
				}
			}
		}
		if (!$errors && $orderLines) {
				try {
						$pdo->beginTransaction();
						if($promo){
							$locked=$pdo->prepare("SELECT promo_id,usage_limit FROM promotions WHERE promo_id=? AND status='active' AND NOW() BETWEEN starts_at AND ends_at FOR UPDATE");$locked->execute([(int)$promo['promo_id']]);$lockedPromo=$locked->fetch();
							$usageQuery=$pdo->prepare('SELECT COUNT(*) FROM promotion_redemptions WHERE promo_id=?');$usageQuery->execute([(int)$promo['promo_id']]);$usageCount=(int)$usageQuery->fetchColumn();
							if(!$lockedPromo||($lockedPromo['usage_limit']!==null&&$usageCount>=(int)$lockedPromo['usage_limit']))throw new RuntimeException('Offer is no longer available.');
							if($claimId){$claimLock=$pdo->prepare('SELECT c.claim_id FROM promotion_claims c WHERE c.claim_id=? AND c.user_id=? AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.claim_id=c.claim_id) FOR UPDATE');$claimLock->execute([$claimId,(int)$_SESSION['user_id']]);if(!$claimLock->fetchColumn())throw new RuntimeException('Voucher has already been used.');}
						}
						$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
						$orderNumber = 'NC' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
						$total = round(max(0,$subtotal + $deliveryFee - $discountAmount), 2);
						$pdo->prepare(
								'INSERT INTO customer_orders
								 (order_number, user_id, fulfillment, branch_id, customer_name, phone, delivery_address,
									delivery_instructions, scheduled_for, payment_method, subtotal,
									delivery_fee, delivery_distance_km, total)
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
								$scheduledFor,
								$form['payment_method'],
								$subtotal,
								$deliveryFee,
								$form['fulfillment'] === 'delivery' ? round($deliveryDistanceKm, 2) : null,
								$total,
						]);
						$orderId = (int)$pdo->lastInsertId();
						$lineInsert = $pdo->prepare(
				'INSERT INTO customer_order_items
					 (order_id, item_id, item_name, unit_price, quantity, special_request)
					 VALUES (?, ?, ?, ?, ?, ?)'
						);
				$optionInsert = $pdo->prepare('INSERT INTO customer_order_item_options(order_item_id,option_id,group_name_snapshot,option_name_snapshot,unit_price_adjustment,quantity) VALUES(?,?,?,?,?,?)');
						foreach ($orderLines as $line) {
								$lineInsert->execute([
										$orderId,
										$line['item_id'],
										$line['name'],
										$line['unit_price'],
										$line['quantity'],
								$line['special_request'],
								]);
								$orderItemId=(int)$pdo->lastInsertId();
								foreach ($line['options'] as $option) {
								$optionInsert->execute([$orderItemId,$option['option_id'],$option['group_name'],$option['option_name'],$option['unit_price_adjustment'],$option['quantity']]);
								}
						}
						if($promo){
							$termsSnapshot = json_encode(['discount_type'=>$promo['discount_type'],'discount_value'=>(float)$promo['discount_value'],'max_discount'=>$promo['max_discount']===null?null:(float)$promo['max_discount'],'minimum_spend'=>(float)$promo['minimum_spend'],'eligibility_scope'=>$promo['eligibility_scope'],'channel'=>$promo['channel'],'starts_at'=>$promo['starts_at'],'ends_at'=>$promo['ends_at']], JSON_UNESCAPED_UNICODE);
							$pdo->prepare('INSERT INTO promotion_redemptions(promo_id,order_id,user_id,claim_id,code_used,promo_name_snapshot,promo_terms_snapshot,discount_amount) VALUES(?,?,?,?,?,?,?,?)')->execute([(int)$promo['promo_id'],$orderId,(int)$_SESSION['user_id'],$claimId?:null,$promoCode?:null,(string)$promo['name'],$termsSnapshot,$discountAmount]);
						}
						$pdo->commit();
						$_SESSION['order_confirmation'] = [
								'number' => $orderNumber,
								'subtotal' => $subtotal,
								'delivery_fee' => $deliveryFee,
								'delivery_free_new_account' => $deliveryFreeForNewAccount,
								'promo_name' => $promo['name'] ?? null,
								'total' => $total,
							'discount' => $discountAmount,
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
				 "SELECT item_id, category_id, name, description, price, IF(image_data IS NOT NULL, CONCAT('menu-image.php?id=', item_id), image_url) AS image_url
					 FROM menu_items WHERE is_available=1 AND (publish_at IS NULL OR publish_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)) ORDER BY sort_order,item_id"
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
				$itemsByCategory[$item['category_id']][] = $item;
				$customOptions = orderItemOptionGroups($pdo,(int)$item['item_id']);
				$menuData[] = [
						'id' => (int)$item['item_id'],
						'name' => $item['name'],
						'priceCents' => $item['price'] !== null && (float)$item['price'] > 0 ? (int)round((float)$item['price'] * 100) : null,
						'imageUrl' => $item['image_url'],
						'description' => $item['description'],
						'options' => [],
						'customOptions' => $customOptions,
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
						 "SELECT o.order_id,o.order_number,o.fulfillment,o.subtotal,o.delivery_fee,o.delivery_distance_km,
						 r.discount_amount,o.total,o.status,o.payment_status,o.created_at,o.scheduled_for,COALESCE(r.promo_name_snapshot,p.name) AS promo_name,
						 TIMESTAMPDIFF(SECOND, COALESCE((SELECT MAX(a.created_at) FROM staff_order_actions a WHERE a.order_id=o.order_id AND a.to_status='out_for_delivery'), o.created_at), NOW()) AS delivery_elapsed_seconds,
						 (SELECT GROUP_CONCAT(CONCAT(i.quantity,' x ',i.item_name,COALESCE((SELECT CONCAT(' (',GROUP_CONCAT(CONCAT(q.quantity,' × ',q.option_name_snapshot) ORDER BY q.order_item_option_id SEPARATOR ', '),')') FROM customer_order_item_options q WHERE q.order_item_id=i.order_item_id),'')) ORDER BY i.order_item_id SEPARATOR ' · ')
						  FROM customer_order_items i WHERE i.order_id = o.order_id) AS item_summary
						 FROM customer_orders o
						 LEFT JOIN promotion_redemptions r ON r.order_id=o.order_id
						 LEFT JOIN promotions p ON p.promo_id=r.promo_id
						 WHERE o.user_id = ?
						 ORDER BY o.created_at DESC LIMIT 50"
				);
				$historyQuery->execute([(int)$_SESSION['user_id']]);
				$myOrders = $historyQuery->fetchAll();
		} catch (PDOException $ex) {
				$orderHistoryError = 'Your order history is temporarily unavailable.';
		}
}
$orderGroupCounts=['confirmation'=>0,'processing'=>0,'delivery'=>0,'history'=>0];
foreach($myOrders as $orderRow){$group=match(strtolower((string)$orderRow['status'])){'pending'=>'confirmation','confirmed','preparing','ready'=>'processing','out_for_delivery'=>'delivery',default=>'history'};$orderGroupCounts[$group]++;}
$activeOrderGroup='confirmation';foreach(array_keys($orderGroupCounts) as $groupKey){if($orderGroupCounts[$groupKey]>0){$activeOrderGroup=$groupKey;break;}}

require 'includes/header.php';
?>

<main class="order-page" data-order-confirmed="<?= $orderConfirmation ? 'yes' : 'no' ?>" data-ph-now="<?= e($scheduleNow->format('Y-m-d\TH:i')) ?>">
	<?php render_toasts($errors ? [['kind' => 'danger', 'title' => 'Please review your order', 'items' => $errors]] : []); ?>
	<section class="order-heading-band" aria-hidden="true"></section>
	<?php if ($viewOrders): ?><section class="order-tracking-shell" aria-live="polite"><div class="container"><div id="delivery-tracking-banner" class="delivery-tracking-banner" hidden></div></div></section><?php endif; ?>
	<?php if ($orderConfirmation): ?>
		<section class="container pt-4" aria-live="polite">
			<div class="order-success">
				<i class="bi bi-check-circle-fill" aria-hidden="true"></i>
				<div>
					<h2 class="h4 mb-1">Order request received</h2>
					<p class="mb-1">Reference <strong><?= e($orderConfirmation['number']) ?></strong>. Nomcha will contact you to confirm availability<?= $orderConfirmation['fulfillment'] === 'delivery' ? ' and delivery coverage' : ' and pickup timing' ?>.</p>
					<p class="mb-1">Items: <strong>₱<?= number_format((float)$orderConfirmation['subtotal'], 2) ?></strong><?php if(($orderConfirmation['discount']??0)>0):?> · <?=e((string)($orderConfirmation['promo_name']??'Promo / voucher'))?> discount: <strong>−₱<?=number_format((float)$orderConfirmation['discount'],2)?></strong><?php endif;?><?php if($orderConfirmation['fulfillment']==='delivery'): ?> · Delivery fee: <strong>₱<?=number_format((float)$orderConfirmation['delivery_fee'],2)?></strong><?php endif; ?> · Estimated total: <strong>₱<?=number_format((float)$orderConfirmation['total'],2)?></strong>. No online payment was taken.</p>
					<?php if (!empty($orderConfirmation['delivery_free_new_account'])): ?><p class="small mb-0">Free delivery applied: accounts under one month old receive this offer on their first three orders with at least ₱400 in items for delivery.</p><?php endif; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php if ($viewOrders): ?>
		<section class="container py-4 py-lg-5" id="my-orders">
			<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
				<div><p class="order-eyebrow mb-2">Your account</p><h2 class="h3 mb-0">My orders</h2></div>
				<a class="btn btn-order" href="order.php">Start an order</a>
			</div>
			<?php if ($orderHistoryError): ?>
				<div class="alert alert-warning"><?= e($orderHistoryError) ?></div>
			<?php elseif (!$myOrders): ?>
				<div class="order-history-empty"><i class="bi bi-receipt" aria-hidden="true"></i><h3>No orders yet</h3><p>Your submitted orders will appear here.</p><a class="btn btn-order" href="order.php">Browse the menu</a></div>
			<?php else: ?>
				<ul class="nav nav-pills order-status-tabs mb-3" role="tablist" aria-label="Filter orders by status">
					<li class="nav-item"><button class="nav-link <?=$activeOrderGroup==='confirmation'?'active':''?>" type="button" role="tab" aria-selected="<?=$activeOrderGroup==='confirmation'?'true':'false'?>" data-order-tab="confirmation">Confirmation <span class="badge text-bg-light"><?=$orderGroupCounts['confirmation']?></span></button></li>
					<li class="nav-item"><button class="nav-link <?=$activeOrderGroup==='processing'?'active':''?>" type="button" role="tab" aria-selected="<?=$activeOrderGroup==='processing'?'true':'false'?>" data-order-tab="processing">Processing <span class="badge text-bg-light"><?=$orderGroupCounts['processing']?></span></button></li>
					<li class="nav-item"><button class="nav-link <?=$activeOrderGroup==='delivery'?'active':''?>" type="button" role="tab" aria-selected="<?=$activeOrderGroup==='delivery'?'true':'false'?>" data-order-tab="delivery">Out for Delivery <span class="badge text-bg-light"><?=$orderGroupCounts['delivery']?></span></button></li>
					<li class="nav-item"><button class="nav-link <?=$activeOrderGroup==='history'?'active':''?>" type="button" role="tab" aria-selected="<?=$activeOrderGroup==='history'?'true':'false'?>" data-order-tab="history">Past Orders <span class="badge text-bg-light"><?=$orderGroupCounts['history']?></span></button></li>
				</ul>
				<div class="order-history-list">
					<?php foreach ($myOrders as $myOrder): ?>
						<?php $itemSummary = str_replace(['BTS Bonless Chikin & Rosébokki', 'BTS Bonless Chikin', 'BTS Korean Fried Chicken'], ['NTS Bonless Chikin & Rosébokki', 'NTS Bonless Chikin', 'NTS Korean Fried Chicken'], (string)($myOrder['item_summary'] ?? '')); $rowGroup=match(strtolower((string)$myOrder['status'])){'pending'=>'confirmation','confirmed','preparing','ready'=>'processing','out_for_delivery'=>'delivery',default=>'history'}; ?>
						<article class="order-history-row" data-order-group="<?=$rowGroup?>" <?=$rowGroup!==$activeOrderGroup?'hidden':''?>>
							<div class="order-history-heading">
								<div><strong><?= e($myOrder['order_number']) ?></strong><time datetime="<?= e(date(DATE_ATOM, strtotime($myOrder['created_at']))) ?>"><?= e(date('M j, Y · g:i A', strtotime($myOrder['created_at']))) ?></time></div>
								<span class="order-history-status"><?= e(ucwords(str_replace('_', ' ', (string)$myOrder['status']))) ?></span>
								<div class="order-history-actions"><?php if ($myOrder['fulfillment'] === 'delivery' && strtolower((string)$myOrder['status']) === 'out_for_delivery'): ?><?php $distance=(float)($myOrder['delivery_distance_km']??5); $etaSeconds=max(60,(int)ceil(($distance>0?$distance:5)/25*3600/60)*60); $canConfirm=(int)$myOrder['delivery_elapsed_seconds'] >= $etaSeconds; ?><button type="button" class="order-track-button" data-track-delivery data-order-number="<?=e($myOrder['order_number'])?>" data-distance-km="<?=e($myOrder['delivery_distance_km'] === null ? '' : (string)$myOrder['delivery_distance_km'])?>" data-distance-known="<?=$myOrder['delivery_distance_km'] === null ? 'no' : 'yes'?>" data-delivery-elapsed="<?=(int)$myOrder['delivery_elapsed_seconds']?>" aria-controls="delivery-tracking-banner">Track delivery</button><?php endif; ?>
								<?php if ($myOrder['fulfillment'] === 'delivery' && strtolower((string)$myOrder['status']) === 'out_for_delivery'): ?><form method="post" action="order-received.php" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$myOrder['order_id']?>"><button class="btn btn-sm btn-success" data-received-button data-remaining-seconds="<?=max(0,$etaSeconds-(int)$myOrder['delivery_elapsed_seconds'])?>" <?=$canConfirm?'':'disabled title="Confirm receipt when the delivery progress is complete."'?>>Received</button></form><?php endif; ?>
							<?php if (strtolower((string)$myOrder['status']) === 'pending'): ?><button type="button" class="btn btn-sm btn-outline-danger" data-cancel-order="<?= (int)$myOrder['order_id'] ?>" data-bs-toggle="modal" data-bs-target="#cancelOrderModal">Cancel order</button><?php endif; ?></div>
							</div>
							<p class="order-history-items mb-2"><?= e($itemSummary ?: 'No item details') ?></p>
							<div class="order-history-meta">
								<span><?= e(ucfirst($myOrder['fulfillment'])) ?><?= $myOrder['scheduled_for'] ? ' · ' . e(date('M j, g:i A', strtotime($myOrder['scheduled_for']))) : ' · ASAP' ?></span>
								<strong>₱<?= number_format((float)($myOrder['total'] ?? $myOrder['subtotal']), 2) ?><?= $myOrder['total'] === null ? ' + delivery fee' : '' ?></strong>
							</div>
							<div class="order-history-breakdown"><span>Items ₱<?=number_format((float)$myOrder['subtotal'],2)?></span><?php if($myOrder['fulfillment']==='delivery'):?><span>Delivery ₱<?=number_format((float)($myOrder['delivery_fee']??0),2)?></span><?php endif;?><?php if((float)($myOrder['discount_amount']??0)>0):?><span><?=e((string)($myOrder['promo_name']??'Promo / voucher'))?> discount −₱<?=number_format((float)$myOrder['discount_amount'],2)?></span><?php endif;?></div>
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
					<div class="container py-4 py-lg-5">
						<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= e((string)$_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
						<div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
							<div>
								<p class="order-eyebrow mb-2">Nomcha, made to order</p>
								<h1 class="mb-1">Order online</h1>
								<p class="mb-0">Choose your favorites, then select delivery or pickup.</p>
							</div>
						</div>
					</div>
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
						<a class="order-back-link" href="order.php?view=orders"><i class="bi bi-receipt me-2" aria-hidden="true"></i>My orders</a>
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
						<p class="order-helper">Free delivery applies automatically to an account’s first three delivery orders with at least ₱400 in items, when placed within one month of signup.</p>

						<fieldset class="order-form-section">
							<legend>Voucher or promo code</legend>
							<?php if($claimedVouchers):?><label class="form-label" for="voucher-claim-id">My claimed vouchers</label><select class="form-select mb-2" id="voucher-claim-id" name="voucher_claim_id"><option value="">No voucher</option><?php foreach($claimedVouchers as $voucher):?><option value="<?=(int)$voucher['claim_id']?>"><?=e($voucher['name'])?> · <?=e($voucher['discount_type']==='percent'?$voucher['discount_value'].'% off':($voucher['discount_type']==='fixed'?'₱'.number_format((float)$voucher['discount_value'],2).' off':'Free item'))?></option><?php endforeach;?></select><?php endif;?>
							<label class="form-label" for="promo-code">Promo code</label><input class="form-control" id="promo-code" name="promo_code" maxlength="60" placeholder="Enter promo code"><p class="order-helper mb-0">Only one voucher or promo code can be used per order. Any discount will be itemized in your order summary.</p>
						</fieldset>

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
			<div class="order-address-list" role="radiogroup" aria-label="Choose a saved billing address"><?php foreach($savedAddresses as $saved): ?><label class="order-address-option <?= (string)$saved['address_id']===(string)$form['address_id']?'selected':'' ?>"><input type="radio" name="address_id" value="<?=(int)$saved['address_id']?>" data-lat="<?=e((string)$saved['latitude'])?>" data-lng="<?=e((string)$saved['longitude'])?>" data-name="<?=e($saved['recipient_name'])?>" data-mobile="<?=e($saved['recipient_phone'])?>" data-address="<?=e(orderAddressLabel($saved))?>" <?= (string)$saved['address_id']===(string)$form['address_id']?'checked':'' ?> required><span><strong>Name:</strong> <?=e($saved['recipient_name'])?><br><strong>Address:</strong> <?=e(orderAddressLabel($saved))?><br><strong>Mobile number:</strong> <?=e($saved['recipient_phone'])?><?php if(trim((string)$saved['street_address'])==='' || !preg_match('/^\d{4}$/',(string)$saved['zip_code'])): ?><br><small class="text-danger">Complete Address and ZIP code in Billing Address to enable delivery.</small><?php endif; ?></span></label><?php endforeach; ?></div>
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

<div class="modal fade" id="branch-picker-modal" data-max-distance-km="<?=ORDER_MAX_DISTANCE_KM?>" tabindex="-1" aria-labelledby="branch-picker-title" aria-hidden="true">
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
$pageScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/order.js?v=' . filemtime(__DIR__ . '/assets/js/order.js') . '"></script><script src="assets/js/order-branch-picker.js?v=' . filemtime(__DIR__ . '/assets/js/order-branch-picker.js') . '"></script><script src="assets/js/order-tracking.js?v=' . filemtime(__DIR__ . '/assets/js/order-tracking.js') . '"></script>';
require 'includes/footer.php';
?>
