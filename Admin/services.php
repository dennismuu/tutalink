<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_roles('super_admin', 'admin1', 'admin2');
$modules = [];
foreach (['tutoring', 'home_services'] as $module) {
    if (admin_has_permission($module, 'manage_services')) $modules[] = $module;
}
if (current_user()['role'] === 'super_admin') $modules = ['tutoring', 'home_services'];
if (!$modules) page_error('Your administrator account is not assigned to catalogue management.', 403);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    reject_invalid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add-category') {
            $module = (string) ($_POST['module'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!in_array($module, $modules, true) || $name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Choose a catalogue you can manage and enter a subject or service name up to 100 characters.';
            } elseif ($price === false || $price <= 0 || $price > 1000000) {
                $errors[] = 'Enter a price between KES 1 and KES 1,000,000.';
            } else {
                $slugPart = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
                if ($slugPart === '') {
                    $errors[] = 'Use a name that contains at least one letter or number.';
                } else {
                    $slug = $module === 'home_services' && $slugPart === 'laundry'
                        ? 'laundry'
                        : $module . '-' . $slugPart;
                    $nextOrder = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM service_categories WHERE module = ?');
                    $nextOrder->execute([$module]);
                    if ($module === 'tutoring') {
                        $create = db()->prepare('INSERT INTO service_categories (module, name, hourly_rate, slug, sort_order) VALUES (?, ?, ?, ?, ?)');
                    } else {
                        $create = db()->prepare('INSERT INTO service_categories (module, name, service_price, slug, sort_order) VALUES (?, ?, ?, ?, ?)');
                    }
                    $create->execute([$module, $name, $price, $slug, (int) $nextOrder->fetchColumn()]);
                    flash('success', 'The subject or service was added to the catalogue.');
                    header('Location: ' . url('Admin/services.php'));
                    exit;
                }
            }
        } elseif ($action === 'delete-category') {
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$categoryId) {
                $errors[] = 'Choose a valid subject or service to delete.';
            } else {
                $marks = implode(',', array_fill(0, count($modules), '?'));
                $categoryQuery = db()->prepare("SELECT id FROM service_categories WHERE id = ? AND module IN ({$marks})");
                $categoryQuery->execute([$categoryId, ...$modules]);
                if (!$categoryQuery->fetchColumn()) {
                    $errors[] = 'This subject or service was not found or is outside your assigned modules.';
                } else {
                    $usage = db()->prepare(
                        'SELECT
                            (SELECT COUNT(*) FROM provider_services WHERE category_id = ?) +
                            (SELECT COUNT(*) FROM bookings WHERE category_id = ?)'
                    );
                    $usage->execute([$categoryId, $categoryId]);
                    if ((int) $usage->fetchColumn() > 0) {
                        $errors[] = 'This subject or service is already in use. Hide it instead so existing provider profiles and bookings are preserved.';
                    } else {
                        $delete = db()->prepare("DELETE FROM service_categories WHERE id = ? AND module IN ({$marks})");
                        $delete->execute([$categoryId, ...$modules]);
                        flash('success', 'The subject or service was deleted.');
                        header('Location: ' . url('Admin/services.php'));
                        exit;
                    }
                }
            }
        } elseif ($action === 'category') {
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
            if (!$categoryId || !in_array($active, [0, 1], true)) {
                $errors[] = 'Choose a valid service and status.';
            } else {
                $marks = implode(',', array_fill(0, count($modules), '?'));
                $update = db()->prepare("UPDATE service_categories SET is_active = ? WHERE id = ? AND module IN ({$marks})");
                $update->execute([$active, $categoryId, ...$modules]);
                if (!$update->rowCount()) $errors[] = 'This service was not found or is outside your assigned modules.';
                else {
                    flash('success', $active ? 'Service is now visible in the catalogue.' : 'Service has been hidden from the catalogue.');
                    header('Location: ' . url('Admin/services.php'));
                    exit;
                }
            }
        } elseif ($action === 'category-price') {
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$categoryId || $price === false || $price <= 0 || $price > 1000000) {
                $errors[] = 'Enter a valid price.';
            } else {
                $exists = db()->prepare('SELECT module FROM service_categories WHERE id = ? AND module IN (' . implode(',', array_fill(0, count($modules), '?')) . ')');
                $exists->execute([$categoryId, ...$modules]);
                $categoryModule = $exists->fetchColumn();
                if (!$categoryModule) {
                    $errors[] = 'This subject or service was not found or is outside your assigned modules.';
                } else {
                    $priceColumn = $categoryModule === 'tutoring' ? 'hourly_rate' : 'service_price';
                    $update = db()->prepare(
                        "UPDATE service_categories SET {$priceColumn} = ?
                         WHERE id = ? AND module IN (" . implode(',', array_fill(0, count($modules), '?')) . ')'
                    );
                    $update->execute([$price, $categoryId, ...$modules]);
                    flash('success', 'Admin-set price updated. New bookings will use this price.');
                    header('Location: ' . url('Admin/services.php'));
                    exit;
                }
            }
        } elseif ($action === 'commission') {
            if (!is_role('super_admin')) page_error('Only the Super Admin can change the platform commission.', 403);
            $commission = filter_var($_POST['commission_percent'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($commission === false || $commission < 0 || $commission > 50) {
                $errors[] = 'Choose a platform commission between 0% and 50%.';
            } else {
                db()->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'commission_percent'")
                    ->execute([number_format($commission, 2, '.', '')]);
                flash('success', 'Platform commission updated. New quotes will use the new rate.');
                header('Location: ' . url('Admin/services.php'));
                exit;
            }
        } else {
            $errors[] = 'That action is not available.';
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $errors[] = 'A subject or service with a similar name already exists, or this entry is still referenced. Hide it if it is already in use.';
        } else {
            $errors[] = 'Catalogue changes could not be saved.';
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

try {
    $marks = implode(',', array_fill(0, count($modules), '?'));
    $query = db()->prepare("SELECT id, module, name, hourly_rate, service_price, slug, is_coming_soon, is_active FROM service_categories WHERE module IN ({$marks}) ORDER BY module, sort_order, name");
    $query->execute($modules);
    $categories = $query->fetchAll();
    $commissionRate = (float) db()->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'commission_percent'")->fetchColumn();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Manage services';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/dashboard.php')) ?>">← Back to dashboard</a></div>
    <div class="page-heading"><div><div class="eyebrow">PLATFORM CONFIGURATION</div><h1>Shape the catalogue.</h1><p>Add or remove subjects and services, or hide categories without disturbing existing provider profiles and bookings.</p></div></div>
    <?php if ($errors): ?><div class="notice notice-error"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (is_role('super_admin')): ?><section class="commission-settings"><div><div class="eyebrow">TRANSPARENT PRICING</div><h2>Platform commission</h2><p>Applied to the lesson total. Tutoring prices are set per subject below; tutor profiles do not set prices.</p></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="commission"><label for="commission-percent">Commission %</label><input id="commission-percent" name="commission_percent" type="number" min="0" max="50" step="0.01" value="<?= e(number_format($commissionRate, 2, '.', '')) ?>" required><button class="button button-primary button-small" type="submit">Update rate</button></form></section><?php endif; ?>
    <section class="catalogue-management">
        <h2>Add a subject or service</h2>
        <form method="post" class="catalogue-add-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="add-category">
            <label>Catalogue<select name="module" data-service-module required>
                <?php foreach ($modules as $module): ?>
                    <option value="<?= e($module) ?>"><?= $module === 'tutoring' ? 'Tutoring subjects' : 'Home services' ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Name<input data-service-name name="name" maxlength="100" required placeholder="e.g. French"></label>
            <label data-service-price-field>Price (KES)<input data-service-price name="price" type="number" min="1" max="1000000" step="0.01" placeholder="e.g. 500" required><span class="form-footnote">Tutoring uses an hourly price; home services use a fixed per-service price. Laundry is priced per basket.</span></label>
            <button class="button button-primary button-small" type="submit">Add to catalogue</button>
        </form>
    </section>
    <label class="service-filter">Find a service or subject<input type="search" data-service-filter placeholder="Start typing a name"></label>
    <section class="service-admin-grid"><?php foreach ($categories as $category): $categoryPrice = $category['module'] === 'tutoring' ? $category['hourly_rate'] : $category['service_price']; ?>
        <article class="service-admin-card" data-service-admin-card data-name="<?= e(mb_strtolower($category['name'])) ?>" data-module="<?= e($category['module']) ?>"><div class="service-card-top"><span class="status <?= $category['is_active'] ? 'status-accepted' : 'status-suspended' ?>"><?= $category['is_active'] ? 'Visible' : 'Hidden' ?></span></div><small><?= $category['module'] === 'tutoring' ? 'TUTORING' : 'HOME SERVICES' ?><?= $category['is_coming_soon'] ? ' · COMING SOON' : '' ?></small><h2><?= e($category['name']) ?></h2><form method="post" class="category-price-form"><?= csrf_field() ?><input type="hidden" name="action" value="category-price"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><label><?= $category['module'] === 'tutoring' ? 'Price per student / hour' : ($category['slug'] === 'laundry' ? 'Price per laundry basket' : 'Price per service') ?> (KES)<input type="number" name="price" min="1" max="1000000" step="0.01" value="<?= e((string) $categoryPrice) ?>" required></label><button class="button button-outline button-small" type="submit">Save price</button></form><div class="catalogue-card-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="is_active" value="<?= $category['is_active'] ? '0' : '1' ?>"><button class="button button-outline button-small" type="submit"><?= $category['is_active'] ? 'Hide' : 'Show' ?></button></form><form method="post" onsubmit="return confirm('Delete this subject or service? Entries in use can only be hidden.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete-category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><button class="button button-outline button-small" type="submit">Delete</button></form></div></article>
    <?php endforeach; ?></section>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
