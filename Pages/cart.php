<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

$cart = $_SESSION['booking_cart'] ?? [];
if (!is_array($cart)) {
    $cart = [];
    $_SESSION['booking_cart'] = [];
}
$errors = [];
$module = null;
$providerId = null;
if ($cart) {
    try {
        $categoryContext = db()->prepare('SELECT module, slug FROM service_categories WHERE id = ?');
        foreach ($cart as $serviceId => &$cartItem) {
            if (!isset($cartItem['module'], $cartItem['slug'])) {
                $categoryContext->execute([(int) $serviceId]);
                $context = $categoryContext->fetch();
                if ($context) {
                    $cartItem['module'] = $context['module'];
                    $cartItem['slug'] = $context['slug'];
                }
            }
            $cartItem['baskets'] = (string) ($cartItem['baskets'] ?? '1');
        }
        unset($cartItem);
        $_SESSION['booking_cart'] = $cart;
    } catch (RuntimeException $exception) {
        page_error($exception->getMessage(), 503);
    } catch (PDOException $exception) {
        page_error('We could not load your saved cart. Please try again.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = isset($_POST['remove_service']) ? 'remove' : (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $serviceId = filter_var($_POST['service'] ?? null, FILTER_VALIDATE_INT);
        $selectedProviderId = filter_var($_POST['provider'] ?? null, FILTER_VALIDATE_INT);
        $selectedLevelId = filter_var($_POST['level'] ?? null, FILTER_VALIDATE_INT);
        if (!$serviceId || !$selectedProviderId) {
            $errors[] = 'Choose a service and provider before adding them to your cart.';
        } else {
            try {
                $selection = db()->prepare(
                    "SELECT c.id, c.module, c.hourly_rate, c.service_price, c.slug, u.id AS provider_id, p.location
                     FROM service_categories c
                     JOIN provider_services ps ON ps.category_id = c.id
                     JOIN provider_profiles p ON p.user_id = ps.provider_id
                     JOIN users u ON u.id = p.user_id
                     WHERE c.id = ? AND u.id = ? AND c.is_active = 1 AND c.is_coming_soon = 0
                       AND ((c.module = 'tutoring' AND c.hourly_rate > 0)
                            OR (c.module = 'home_services' AND c.service_price > 0))
                       AND p.is_available = 1 AND p.approval_status = 'approved'
                       AND u.is_active = 1 AND u.email_verified_at IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
                           AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))
                       AND (c.module <> 'tutoring' OR EXISTS (
                           SELECT 1 FROM provider_teaching_levels ptl WHERE ptl.provider_id = u.id AND ptl.level_id = ?
                       ))"
                );
                $selection->execute([$serviceId, $selectedProviderId, $selectedLevelId ?: 0]);
                $item = $selection->fetch();
                if (!$item) {
                    $errors[] = 'That service is not currently bookable with this provider.';
                } else {
                    foreach ($cart as $existing) {
                        if ((int) $existing['provider_id'] !== (int) $item['provider_id']
                            || $existing['module'] !== $item['module']) {
                            $errors[] = 'A checkout can include multiple services from one provider in one service area. Remove your current items before choosing another provider.';
                            break;
                        }
                    }
                    if (!$errors) {
                        $cart[$serviceId] = $cart[$serviceId] ?? [
                            'service_id' => (int) $serviceId,
                            'provider_id' => (int) $selectedProviderId,
                            'module' => $item['module'],
                            'slug' => $item['slug'],
                            'hours' => '1',
                            'student_count' => '1',
                            'baskets' => '1',
                            'details' => '',
                            'location' => (string) $item['location'],
                            'location_type' => 'other',
                            'level_id' => $item['module'] === 'tutoring' ? (int) $selectedLevelId : null,
                            'scheduled_for' => '',
                        ];
                        $_SESSION['booking_cart'] = $cart;
                        flash('success', 'Service added to your cart. Review your details before checkout.');
                        header('Location: ' . url('Pages/cart.php'));
                        exit;
                    }
                }
            } catch (RuntimeException $exception) {
                page_error($exception->getMessage(), 503);
            } catch (PDOException $exception) {
                page_error('We could not add this service to your cart. Please try again.');
            }
        }
    } elseif ($action === 'remove') {
        $serviceId = filter_var($_POST['remove_service'] ?? $_POST['service'] ?? null, FILTER_VALIDATE_INT);
        if ($serviceId && isset($cart[$serviceId])) {
            unset($cart[$serviceId]);
            $_SESSION['booking_cart'] = $cart;
            flash('info', 'Service removed from your cart.');
            header('Location: ' . url('Pages/cart.php'));
            exit;
        }
        $errors[] = 'That cart item could not be found.';
    } elseif (in_array($action, ['update', 'checkout'], true)) {
        $postedItems = $_POST['items'] ?? [];
        if (!is_array($postedItems)) $postedItems = [];
        foreach ($cart as $serviceId => &$item) {
            $values = $postedItems[$serviceId] ?? [];
            if (!is_array($values)) $values = [];
            if ($item['module'] === 'tutoring') {
                $item['hours'] = trim((string) ($values['hours'] ?? $item['hours']));
                $item['student_count'] = trim((string) ($values['student_count'] ?? $item['student_count']));
            } elseif (($item['slug'] ?? '') === 'laundry') {
                $item['baskets'] = trim((string) ($values['baskets'] ?? $item['baskets'] ?? '1'));
            }
            $item['details'] = trim((string) ($values['details'] ?? $item['details']));
            $item['location'] = trim((string) ($values['location'] ?? $item['location']));
            $item['location_type'] = trim((string) ($values['location_type'] ?? $item['location_type'] ?? 'other'));
            $item['scheduled_for'] = trim((string) ($values['scheduled_for'] ?? $item['scheduled_for']));
        }
        unset($item);
        $_SESSION['booking_cart'] = $cart;

        if (!$cart) {
            $errors[] = 'Add at least one service before checkout.';
        }

        foreach ($cart as $serviceId => $item) {
            if ($item['module'] === 'tutoring') {
                $hours = filter_var($item['hours'], FILTER_VALIDATE_FLOAT);
                if ($hours === false || $hours < 0.5 || $hours > 12 || fmod((float) $hours * 2, 1.0) !== 0.0) {
                    $errors[] = 'Choose a tutoring session length from 0.5 to 12 hours in half-hour increments.';
                }
                $students = filter_var($item['student_count'], FILTER_VALIDATE_INT);
                if ($students === false || $students < 1 || $students > 30) {
                    $errors[] = 'Choose between 1 and 30 students for each tutoring service.';
                }
            } elseif (($item['slug'] ?? '') === 'laundry') {
                $baskets = filter_var($item['baskets'] ?? null, FILTER_VALIDATE_INT);
                if ($baskets === false || $baskets < 1 || $baskets > 10) {
                    $errors[] = 'Choose between 1 and 10 laundry baskets.';
                }
            }
            if ($item['location'] === '' || mb_strlen($item['location']) > 180) {
                $errors[] = 'Enter a service location up to 180 characters for every cart item.';
            }
            if ($item['module'] === 'tutoring' && !in_array($item['location_type'], ['home', 'hall', 'other'], true)) {
                $errors[] = 'Choose where the tutoring session will take place.';
            }
            if (mb_strlen($item['details']) > 5000) {
                $errors[] = 'Keep each booking note under 5,000 characters.';
            }
            if ($item['module'] === 'home_services' && $item['details'] === '') {
                $errors[] = 'Describe the work required for each home service.';
            }
            if ($item['scheduled_for'] !== '') {
                $date = DateTime::createFromFormat('Y-m-d\TH:i', $item['scheduled_for']);
                if (!$date || $date < new DateTime()) {
                    $errors[] = 'Choose a future date and time for each scheduled service.';
                }
            }
        }

        if ($action === 'update' && !$errors) {
            flash('success', 'Your cart details were updated.');
            header('Location: ' . url('Pages/cart.php'));
            exit;
        }

        if ($action === 'checkout' && !$errors) {
            $firstItem = reset($cart);
            $module = $firstItem['module'];
            $providerId = (int) $firstItem['provider_id'];
            $requiredRole = $module === 'tutoring' ? 'student_parent' : 'customer';
            if (!current_user()) {
                unset($_SESSION['pending_service_id'], $_SESSION['pending_provider_id']);
                $_SESSION['return_to'] = 'Pages/cart.php';
                flash('info', 'Your cart is saved. Sign in or create an account to finish checkout.');
                header('Location: ' . url('Pages/login.php'));
                exit;
            }
            if (!is_role($requiredRole)) {
                $errors[] = $module === 'tutoring'
                    ? 'A Student / Parent account is required to book tutoring.'
                    : 'A Home services customer account is required to book home services.';
            } else {
                try {
                    $pdo = db();
                    $pdo->beginTransaction();
                    $providerLock = $pdo->prepare('SELECT user_id FROM provider_profiles WHERE user_id = ? FOR UPDATE');
                    $providerLock->execute([$providerId]);
                    if (!$providerLock->fetchColumn()) {
                        throw new RuntimeException('The selected provider is no longer available.');
                    }
                    $categoryIds = array_map('intval', array_keys($cart));
                    $marks = implode(',', array_fill(0, count($categoryIds), '?'));
                    $providerCheck = $pdo->prepare(
                        "SELECT COUNT(DISTINCT c.id)
                         FROM service_categories c
                         JOIN provider_services ps ON ps.category_id = c.id
                         JOIN provider_profiles p ON p.user_id = ps.provider_id
                         JOIN users u ON u.id = p.user_id
                         WHERE c.id IN ({$marks}) AND c.module = ? AND u.id = ?
                           AND c.is_active = 1 AND c.is_coming_soon = 0
                           AND ((c.module = 'tutoring' AND c.hourly_rate > 0)
                                OR (c.module = 'home_services' AND c.service_price > 0))
                           AND p.is_available = 1 AND p.approval_status = 'approved'
                           AND u.is_active = 1 AND u.email_verified_at IS NOT NULL
                           AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
                               AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))"
                    );
                    $providerCheck->execute([...$categoryIds, $module, $providerId]);
                    if ((int) $providerCheck->fetchColumn() !== count($cart)) {
                        throw new RuntimeException('One or more services or the selected provider are no longer available. Review your cart and choose again.');
                    }

                    $commissionValue = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
                    if ($commissionValue === false || !is_numeric($commissionValue) || (float) $commissionValue < 0 || (float) $commissionValue > 50) {
                        throw new RuntimeException('The platform commission has not been configured correctly.');
                    }
                    $commissionRate = (float) $commissionValue;
                    $categoryQuery = $pdo->prepare('SELECT id, name, module, slug, hourly_rate, service_price FROM service_categories WHERE id = ? AND module = ?');
                    $bookingRows = [];
                    $totalCents = 0;
                    $commissionCentsTotal = 0;
                    $providerCentsTotal = 0;
                    foreach ($cart as $serviceId => $item) {
                        $categoryQuery->execute([(int) $serviceId, $module]);
                        $category = $categoryQuery->fetch();
                        $isTutoring = $module === 'tutoring';
                        if ($isTutoring) {
                            $itemLevelId = filter_var($item['level_id'] ?? null, FILTER_VALIDATE_INT);
                            $levelCheck = $pdo->prepare('SELECT COUNT(*) FROM provider_teaching_levels WHERE provider_id = ? AND level_id = ?');
                            $levelCheck->execute([$providerId, $itemLevelId ?: 0]);
                            if (!$itemLevelId || (int) $levelCheck->fetchColumn() !== 1) {
                                throw new RuntimeException('Choose a learner level this tutor offers. Remove this older cart item and select the level before choosing a subject.');
                            }
                        }
                        $isLaundry = !$isTutoring && $category && $category['slug'] === 'laundry';
                        $hours = $isTutoring ? (float) $item['hours'] : null;
                        $students = $isTutoring ? (int) $item['student_count'] : 1;
                        $baskets = $isLaundry ? (int) ($item['baskets'] ?? 1) : 1;
                        $rateCents = $category
                            ? (int) round((float) ($isTutoring ? $category['hourly_rate'] : $category['service_price']) * 100)
                            : 0;
                        $quantity = $isTutoring ? $hours * $students : $baskets;
                        $amountCents = (int) round($rateCents * $quantity);
                        if (!$category || $rateCents <= 0 || $amountCents <= 0 || $amountCents > 9999999999) {
                            throw new RuntimeException('A service price is unavailable or outside the supported checkout range.');
                        }
                        $commissionCents = (int) round($amountCents * $commissionRate / 100);
                        $providerCents = $amountCents - $commissionCents;
                        $totalCents += $amountCents;
                        $commissionCentsTotal += $commissionCents;
                        $providerCentsTotal += $providerCents;
                        $scheduledAt = null;
                        if ($item['scheduled_for'] !== '') {
                            $date = DateTime::createFromFormat('Y-m-d\TH:i', $item['scheduled_for']);
                            $scheduledAt = $date ? $date->format('Y-m-d H:i:s') : null;
                        }
                        $bookingRows[] = [
                            'category_id' => (int) $category['id'],
                            'teaching_level_id' => $isTutoring ? (int) $itemLevelId : null,
                            'location_type' => $isTutoring ? $item['location_type'] : 'other',
                            'details' => $item['details'] ?: null,
                            'location' => $item['location'],
                            'scheduled_for' => $scheduledAt,
                            'hours' => $hours,
                            'students' => $students,
                            'rate' => $isTutoring ? $rateCents / 100 : null,
                            'service_price' => $isTutoring ? null : $rateCents / 100,
                            'baskets' => $baskets,
                            'amount' => $amountCents / 100,
                            'commission' => $commissionCents / 100,
                            'provider_amount' => $providerCents / 100,
                            'name' => $category['name'],
                        ];
                    }
                    if ($totalCents > 9999999999) {
                        throw new RuntimeException('This cart total is above the supported checkout limit.');
                    }

                    $orderId = null;
                    $bookingStatus = 'requested';
                    $insertBooking = $pdo->prepare(
                        "INSERT INTO bookings
                         (customer_id, provider_id, category_id, teaching_level_id, checkout_order_id, status, details, service_location, session_location_type,
                          scheduled_for, session_hours, booked_hourly_rate, booked_service_price, laundry_baskets,
                          quoted_amount, commission_amount, provider_amount, student_count)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $history = $pdo->prepare(
                        'INSERT INTO booking_status_history (booking_id, changed_by, status, note) VALUES (?, ?, ?, ?)'
                    );
                    foreach ($bookingRows as $row) {
                        $insertBooking->execute([
                            current_user()['id'], $providerId, $row['category_id'], $row['teaching_level_id'] ?: null,
                            $orderId, $bookingStatus, $row['details'], $row['location'], $row['location_type'],
                            $row['scheduled_for'], $row['hours'], $row['rate'],
                            $row['service_price'], $row['baskets'],
                            null,
                            null,
                            null,
                            $row['students'],
                        ]);
                        $bookingId = (int) $pdo->lastInsertId();
                        $history->execute([
                            $bookingId, current_user()['id'], $bookingStatus,
                            $module === 'tutoring'
                                ? 'Student requested a tutor. The student can pay after the tutor accepts.'
                                : 'Customer sent a home-service request. Payment is available after provider acceptance.',
                        ]);
                        $message = 'A new ' . $row['name'] . ' request is waiting for your response. The customer can pay after you accept.';
                        $pdo->prepare("INSERT INTO notifications (user_id, booking_id, channel, subject, body) VALUES (?, ?, 'in_app', 'New booking request', ?)")
                            ->execute([$providerId, $bookingId, $message]);
                    }
                    $pdo->commit();
                    $_SESSION['booking_cart'] = [];
                    flash('success', 'Your request was sent. You can pay after the provider accepts it.');
                    header('Location: ' . url('Pages/dashboard.php'));
                    exit;
                } catch (RuntimeException $exception) {
                    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = $exception->getMessage();
                } catch (PDOException $exception) {
                    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                    page_error('We could not create your checkout. Please try again.');
                }
            }
        }
    } else {
        $errors[] = 'That cart action is not available.';
    }
}

$cart = $_SESSION['booking_cart'] ?? [];
if (!is_array($cart)) $cart = [];
$cartItems = [];
try {
    if ($cart) {
        $categoryQuery = db()->prepare(
            "SELECT c.id, c.name, c.module, c.slug, c.hourly_rate, c.service_price,
                    u.full_name AS provider_name, p.location AS provider_location
             FROM service_categories c
             JOIN provider_services ps ON ps.category_id = c.id
             JOIN provider_profiles p ON p.user_id = ps.provider_id
             JOIN users u ON u.id = p.user_id
             WHERE c.id = ? AND u.id = ?"
        );
        foreach ($cart as $serviceId => $item) {
            $categoryQuery->execute([(int) $serviceId, (int) $item['provider_id']]);
            $category = $categoryQuery->fetch();
            if ($category) {
                $item['category'] = $category;
                $cartItems[] = $item;
                $module = $category['module'];
                $providerId = (int) $item['provider_id'];
            }
        }
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Your cart';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap narrow-wrap">
    <div class="page-heading"><div><div class="eyebrow">BOOKING REQUEST</div><h1>Your service cart.</h1><p>Confirm the services, selected provider, timing and location. The provider must accept before payment is available.</p></div></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach (array_unique($errors) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$cartItems): ?>
        <section class="empty-state"><h2>Your cart is ready when you are.</h2><p>Browse services, compare verified providers and add services from one provider to check out together.</p><a class="button button-primary" href="<?= e(url('Pages/services.php?module=tutoring')) ?>">Explore services</a></section>
    <?php else: ?>
        <?php $firstCartItem = $cartItems[0]; ?>
        <form method="post" class="cart-checkout-form">
            <?= csrf_field() ?>
            <?php foreach ($cartItems as $item): $serviceId = (int) $item['service_id']; $isTutoring = $item['category']['module'] === 'tutoring'; $isLaundry = !$isTutoring && $item['category']['slug'] === 'laundry'; $rate = (float) ($isTutoring ? $item['category']['hourly_rate'] : $item['category']['service_price']); $quantity = $isTutoring ? (float) $item['hours'] * (int) $item['student_count'] : ($isLaundry ? (int) ($item['baskets'] ?? 1) : 1); ?>
                <article class="cart-item" data-cart-rate="<?= e((string) $rate) ?>">
                    <div class="cart-item-heading"><div><span class="eyebrow"><?= $item['category']['module'] === 'tutoring' ? 'TUTORING' : 'HOME SERVICE' ?></span><h2><?= e($item['category']['name']) ?></h2></div>
                        <button class="text-danger" type="submit" name="remove_service" value="<?= $serviceId ?>">Remove</button>
                    </div>
                    <p class="cart-provider">Selected provider: <strong><?= e($item['category']['provider_name']) ?></strong> · <?= e($item['category']['provider_location']) ?></p>
                    <p class="provider-rate">KES <?= number_format($rate, 2) ?> <?= $isTutoring ? 'per student per hour' : ($isLaundry ? 'per laundry basket' : 'per service') ?></p>
                    <?php if ($isTutoring): ?><div class="form-row">
                        <label>Hours<input data-cart-hours type="number" name="items[<?= $serviceId ?>][hours]" min="0.5" max="12" step="0.5" value="<?= e($item['hours']) ?>" required></label>
                        <label>Number of students<input data-cart-students type="number" name="items[<?= $serviceId ?>][student_count]" min="1" max="30" step="1" value="<?= e($item['student_count']) ?>" required></label>
                    </div>
                    <?php elseif ($isLaundry): ?><div class="form-row">
                        <label>Number of laundry baskets<input data-cart-baskets type="number" name="items[<?= $serviceId ?>][baskets]" min="1" max="10" step="1" value="<?= e((string) ($item['baskets'] ?? '1')) ?>" required><span class="form-footnote">Maximum 10 baskets per booking.</span></label>
                    </div><?php endif; ?>
                    <?php if ($isTutoring): ?><label>Where will the session happen?
                        <select name="items[<?= $serviceId ?>][location_type]" required>
                            <option value="home" <?= ($item['location_type'] ?? 'other') === 'home' ? 'selected' : '' ?>>Student’s home</option>
                            <option value="hall" <?= ($item['location_type'] ?? 'other') === 'hall' ? 'selected' : '' ?>>A specific hall</option>
                            <option value="other" <?= ($item['location_type'] ?? 'other') === 'other' ? 'selected' : '' ?>>Other place</option>
                        </select>
                    </label><?php endif; ?>
                    <div class="form-row">
                        <label><?= $isTutoring ? 'Home address / hall or other place' : 'Service location' ?><input name="items[<?= $serviceId ?>][location]" maxlength="180" value="<?= e($item['location']) ?>" required placeholder="<?= $isTutoring ? 'Enter the address, hall name, or other place' : 'Town, neighbourhood, or address' ?>"></label>
                        <label>Preferred date and time <span class="optional">(optional)</span><input type="datetime-local" name="items[<?= $serviceId ?>][scheduled_for]" value="<?= e($item['scheduled_for']) ?>"></label>
                    </div>
                    <label>Booking notes <?= $item['category']['module'] === 'home_services' ? '<span class="optional">(required)</span>' : '<span class="optional">(optional)</span>' ?><textarea name="items[<?= $serviceId ?>][details]" rows="3" maxlength="5000" <?= $item['category']['module'] === 'home_services' ? 'required' : '' ?>><?= e($item['details']) ?></textarea></label>
                    <p class="cart-line-total">Total cost: <strong data-cart-line-total>KES <?= number_format($rate * $quantity, 2) ?></strong></p>
                </article>
            <?php endforeach; ?>
            <section class="cart-summary"><div class="eyebrow"><?= $module === 'home_services' ? 'REQUEST SUMMARY' : 'REQUEST SUMMARY' ?></div><p>Provider: <strong><?= e($firstCartItem['category']['provider_name']) ?></strong></p><p>Services in cart: <strong><?= count($cartItems) ?></strong></p><p class="cart-total">Total cost: <strong data-cart-total>KES <?= number_format(array_sum(array_map(static function (array $item): float { $isTutoring = $item['module'] === 'tutoring'; $quantity = $isTutoring ? (float) $item['hours'] * (int) $item['student_count'] : (($item['category']['slug'] ?? '') === 'laundry' ? (int) ($item['baskets'] ?? 1) : 1); $price = (float) ($isTutoring ? $item['category']['hourly_rate'] : $item['category']['service_price']); return $price * $quantity; }, $cartItems)), 2) ?></strong></p><p class="form-footnote"><?= $module === 'home_services' ? 'The price uses the fixed admin-set service price. The provider must accept your request before you can pay.' : 'Tutoring totals use the current hourly price. The tutor must accept your request before payment is available.' ?><?= $module === 'home_services' ? ' Laundry is priced per basket.' : '' ?></p></section>
            <div class="inline-actions">
                <button class="button button-outline" type="submit" name="action" value="update">Update cart</button>
                <button class="button button-primary" type="submit" name="action" value="checkout">Send booking request</button>
            </div>
        </form>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
