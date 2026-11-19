<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$serviceId = filter_var($_GET['service'] ?? null, FILTER_VALIDATE_INT);
$levelId = filter_var($_GET['level'] ?? null, FILTER_VALIDATE_INT);
$browseAllTutors = ($_GET['module'] ?? '') === 'tutoring';
if (isset($_GET['service']) && (string) $_GET['service'] !== '' && (!$serviceId || $serviceId < 1)) {
    page_error('Choose a valid service from the catalogue.', 404);
}
if (!$serviceId && !$browseAllTutors) page_error('Choose a service from the catalogue first.', 404);
if ($browseAllTutors && !$levelId) {
    header('Location: ' . url('Pages/services.php?module=tutoring'));
    exit;
}
try {
    $pdo = db();
    $teachingLevels = $pdo->query('SELECT id, name FROM tutor_teaching_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
    if ($levelId && !in_array((int) $levelId, array_map('intval', array_column($teachingLevels, 'id')), true)) {
        page_error('Choose a valid teaching level.', 404);
    }
    if ($browseAllTutors) {
        $category = null;
        $categoryOptions = $pdo->query(
            "SELECT id, name, module, hourly_rate FROM service_categories
             WHERE module = 'tutoring' AND is_active = 1 AND is_coming_soon = 0
               AND LOWER(name) NOT IN ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school')
             ORDER BY sort_order, name"
        )->fetchAll();
        $categoryById = [];
        foreach ($categoryOptions as $option) $categoryById[(int) $option['id']] = $option;
        if ($serviceId) {
            $category = $categoryById[(int) $serviceId] ?? null;
            if (!$category) page_error('This tutoring subject is not available.', 404);
        }

        $subjectFilter = $category ? ' AND c.id = ?' : '';
        $levelFilter = $levelId ? ' AND EXISTS (SELECT 1 FROM provider_teaching_levels ptl WHERE ptl.provider_id = u.id AND ptl.level_id = ?)' : '';
        $providersQuery = $pdo->prepare(
            "SELECT u.id, u.full_name, p.qualifications, p.location, p.service_mode,
                    COALESCE(AVG(r.rating), 0) AS average_rating, COUNT(DISTINCT r.id) AS review_count,
                    p.bio, GROUP_CONCAT(DISTINCT c.name ORDER BY c.sort_order SEPARATOR ', ') AS categories
             FROM provider_profiles p
             JOIN users u ON u.id = p.user_id
             JOIN roles role ON role.id = u.role_id AND role.slug = 'tutor'
             JOIN provider_services ps ON ps.provider_id = u.id
             JOIN service_categories c ON c.id = ps.category_id AND c.module = 'tutoring'
             LEFT JOIN reviews r ON r.provider_id = u.id
             WHERE p.is_available = 1 AND p.approval_status = 'approved'
               AND u.is_active = 1 AND u.email_verified_at IS NOT NULL{$subjectFilter}{$levelFilter}
               AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
                   AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))
             GROUP BY u.id, u.full_name, p.qualifications, p.location, p.service_mode, p.bio
             ORDER BY average_rating DESC, u.full_name"
        );
        $providersQuery->execute([...($category ? [(int) $category['id']] : []), ...($levelId ? [(int) $levelId] : [])]);
        $providers = $providersQuery->fetchAll();
        $registeredSubjectFilter = $category ? ' AND c.id = ?' : '';
        $registeredLevelFilter = $levelId
            ? ' AND EXISTS (SELECT 1 FROM provider_teaching_levels ptl WHERE ptl.provider_id = u.id AND ptl.level_id = ?)'
            : '';
        $registeredCountQuery = $pdo->prepare(
            "SELECT COUNT(DISTINCT p.user_id)
             FROM provider_profiles p
             JOIN users u ON u.id = p.user_id
             JOIN roles role ON role.id = u.role_id AND role.slug = 'tutor'
             JOIN provider_services ps ON ps.provider_id = u.id
             JOIN service_categories c ON c.id = ps.category_id AND c.module = 'tutoring'
             WHERE u.is_active = 1{$registeredSubjectFilter}{$registeredLevelFilter}"
        );
        $registeredCountQuery->execute([
            ...($category ? [(int) $category['id']] : []),
            ...($levelId ? [(int) $levelId] : []),
        ]);
        $registeredTutorCount = (int) $registeredCountQuery->fetchColumn();
    } else {
        $categoryQuery = $pdo->prepare('SELECT id, name, module, hourly_rate, service_price, slug FROM service_categories WHERE id = ? AND is_active = 1 AND is_coming_soon = 0');
        $categoryQuery->execute([$serviceId]);
        $category = $categoryQuery->fetch();
        if (!$category) page_error('This service is not available yet.', 404);
        if ($category['module'] === 'tutoring' && !$levelId) {
            header('Location: ' . url('Pages/services.php?module=tutoring'));
            exit;
        }
        $levelJoin = $category['module'] === 'tutoring' && $levelId
            ? ' JOIN provider_teaching_levels ptl ON ptl.provider_id = p.user_id AND ptl.level_id = ?'
            : '';
        $providersQuery = $pdo->prepare(
            "SELECT u.id, u.full_name, p.qualifications, p.location, p.service_mode,
                    COALESCE(AVG(r.rating), 0) AS average_rating, COUNT(r.id) AS review_count, p.bio
             FROM provider_services ps
             JOIN provider_profiles p ON p.user_id = ps.provider_id
             {$levelJoin}
             JOIN users u ON u.id = p.user_id
             LEFT JOIN reviews r ON r.provider_id = u.id
             WHERE ps.category_id = ? AND p.is_available = 1 AND p.approval_status = 'approved'
               AND u.is_active = 1 AND u.email_verified_at IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM bookings busy WHERE busy.provider_id = u.id
                   AND busy.status IN ('requested','accepted','linked','awaiting_payment','paid','on_the_way','in_progress'))
             GROUP BY u.id, u.full_name, p.qualifications, p.location, p.service_mode, p.bio
             ORDER BY average_rating DESC, u.full_name"
        );
        $providersQuery->execute([...($category['module'] === 'tutoring' && $levelId ? [(int) $levelId] : []), $serviceId]);
        $providers = $providersQuery->fetchAll();
        $registeredLevelFilter = $category['module'] === 'tutoring' && $levelId
            ? ' AND EXISTS (SELECT 1 FROM provider_teaching_levels ptl WHERE ptl.provider_id = p.user_id AND ptl.level_id = ?)'
            : '';
        $matchedCountQuery = $pdo->prepare(
            "SELECT COUNT(DISTINCT p.user_id)
             FROM provider_services ps
             JOIN provider_profiles p ON p.user_id = ps.provider_id
             JOIN users u ON u.id = p.user_id
             WHERE ps.category_id = ? AND u.is_active = 1{$registeredLevelFilter}"
        );
        $matchedCountQuery->execute([
            $serviceId,
            ...($category['module'] === 'tutoring' && $levelId ? [(int) $levelId] : []),
        ]);
        $registeredTutorCount = (int) $matchedCountQuery->fetchColumn();
    }
    $canReviewProviders = is_role('super_admin')
        || (is_role('admin1', 'admin2') && admin_has_permission($browseAllTutors ? 'tutoring' : $category['module'], 'manage_providers'));
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
$module = $browseAllTutors ? 'tutoring' : $category['module'];
$pageTitle = $browseAllTutors ? 'Browse tutors' : $category['name'] . ($module === 'tutoring' ? ' tutors' : ' professionals');
$activePage = $module === 'tutoring' ? 'tutors' : 'services';
$providerLabel = $module === 'tutoring' ? 'tutor' : 'provider';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="breadcrumb"><a href="<?= e(url('Pages/services.php?module=' . $module)) ?>">← Back to <?= $module === 'tutoring' ? 'tutoring' : 'home services' ?></a></div>
    <div class="page-heading">
        <div><div class="eyebrow">MEET YOUR <?= $module === 'tutoring' ? 'TUTOR' : 'PROFESSIONAL' ?></div>
            <h1><?= $browseAllTutors ? 'Find a tutor.' : e($category['name']) . ', made personal.' ?></h1>
            <p><?= $browseAllTutors ? 'Browse all available tutors, then choose a subject to see tutors who teach it.' : 'Browse profiles, experience and service mode. Choose who feels right for you.' ?></p>
        </div>
    </div>
    <?php if ($browseAllTutors || $module === 'tutoring'): ?>
        <form class="tutor-subject-filter" method="get">
            <?php if ($browseAllTutors): ?><input type="hidden" name="module" value="tutoring"><?php else: ?><input type="hidden" name="service" value="<?= (int) $serviceId ?>"><?php endif; ?>
            <label for="tutor-level">Learner level
                <select id="tutor-level" name="level" required>
                    <option value="">Choose level</option>
                    <?php foreach ($teachingLevels as $level): ?><option value="<?= (int) $level['id'] ?>" <?= $levelId && (int) $levelId === (int) $level['id'] ? 'selected' : '' ?>><?= e($level['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php if ($browseAllTutors): ?>
            <label for="tutor-subject">Match tutors by subject
                <select id="tutor-subject" name="service">
                    <option value="">All subjects · all tutors</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $category && (int) $category['id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <button class="button button-primary button-small" type="submit">Find tutors</button>
            <?php if ($category): ?><a class="button button-outline button-small" href="<?= e(url('Pages/providers.php?module=tutoring')) ?>">Show all tutors</a><?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if (!$providers): ?>
        <section class="empty-state"><h2><?= $registeredTutorCount ? 'No ' . $providerLabel . 's are bookable yet.' : 'We’re growing our community.' ?></h2><p><?php if ($registeredTutorCount): ?><?= $registeredTutorCount ?> active <?= $providerLabel ?> profile<?= $registeredTutorCount === 1 ? '' : 's' ?> <?php if ($browseAllTutors && !$category): ?>registered with LertFind<?php else: ?>linked to <?= e($category['name']) ?><?php endif; ?>, but <?= $registeredTutorCount === 1 ? 'it is' : 'they are' ?> not bookable yet. Profiles appear here after email verification, admin approval, and availability is enabled.<?php else: ?><?= $browseAllTutors ? ($category ? 'No approved and available tutors teach ' . e($category['name']) . ' just yet.' : 'No approved and available tutors are listed just yet.') : 'No approved and available providers are listed for this service just yet.' ?> Check back soon or explore another category.<?php endif; ?></p>
        <?php if ($canReviewProviders): ?><a class="button button-primary" href="<?= e(url('Admin/providers.php')) ?>">Review provider profiles</a><?php endif; ?>
        <a class="button button-outline" href="<?= e(url('Pages/services.php?module=' . $module)) ?>">Explore other services</a>
        <p class="form-footnote">Are you a qualified professional? <a href="<?= e(url('Pages/register.php')) ?>">Join LertFind</a> and add your services.</p></section>
    <?php else: ?>
        <section class="provider-grid">
            <?php foreach ($providers as $provider): ?>
                <?php $averageRating = min(5.0, max(0.0, (float) $provider['average_rating'])); $filledStars = (int) round($averageRating); ?>
                <article class="provider-card">
                    <div class="provider-avatar"><?= e(mb_strtoupper(mb_substr($provider['full_name'], 0, 1))) ?></div>
                    <div class="provider-main">
                        <div class="provider-title"><h2><?= e($provider['full_name']) ?></h2><span class="rating"><?php if ($provider['review_count']): ?><span class="rating-stars" role="img" aria-label="Rated <?= e(number_format($averageRating, 1)) ?> out of 5 stars"><?php for ($star = 1; $star <= 5; $star++): ?><span class="<?= $star <= $filledStars ? 'filled' : '' ?>">★</span><?php endfor; ?></span><small><?= number_format($averageRating, 1) ?>/5 · <?= (int) $provider['review_count'] ?> reviews</small><?php else: ?>New provider<?php endif; ?></span></div>
                        <p class="provider-location"><?= e($provider['location']) ?> <span>·</span> <?= e(ucfirst($provider['service_mode'])) ?><?= $provider['service_mode'] === 'both' ? ' services' : ' only' ?></p>
                        <?php if ($browseAllTutors): ?><p class="provider-rate">Subjects: <?= e($provider['categories']) ?></p>
                        <?php else: ?><?php $categoryPrice = $module === 'tutoring' ? $category['hourly_rate'] : $category['service_price']; ?><p class="provider-rate"><?= $categoryPrice !== null ? 'KES ' . number_format((float) $categoryPrice, 2) . ($module === 'tutoring' ? ' per hour per student' : ($category['slug'] === 'laundry' ? ' per basket' : ' per service')) . ' · LertFind price' : 'Price is being set by LertFind.' ?></p><?php endif; ?>
                        <p><?= e($provider['qualifications']) ?></p>
                        <?php if ($provider['bio']): ?><p><?= e($provider['bio']) ?></p><?php endif; ?>
                    </div>
                    <?php if ($browseAllTutors && !$category): ?>
                        <a class="button button-outline button-small provider-choose" href="#tutor-subject">Choose a subject</a>
                    <?php elseif (($module === 'tutoring' ? $category['hourly_rate'] : $category['service_price']) === null): ?>
                        <span class="button button-outline button-small provider-choose" aria-disabled="true">Price being set</span>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('Pages/cart.php')) ?>" class="provider-choose-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="service" value="<?= (int) $serviceId ?>">
                            <input type="hidden" name="provider" value="<?= (int) $provider['id'] ?>">
                            <?php if ($module === 'tutoring' && $levelId): ?><input type="hidden" name="level" value="<?= (int) $levelId ?>"><?php endif; ?>
                            <button class="button button-primary provider-choose" type="submit">Add to cart</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
