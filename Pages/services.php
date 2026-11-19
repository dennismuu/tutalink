<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$module = (string) ($_GET['module'] ?? 'tutoring');
if (!in_array($module, ['tutoring', 'home_services'], true)) $module = 'tutoring';
$levelId = filter_var($_GET['level'] ?? null, FILTER_VALIDATE_INT);
$catalogueView = $module === 'tutoring' && ($_GET['view'] ?? '') === 'catalogue';
$subjectQuery = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$teachingLevels = [];
$pageTitle = $catalogueView ? 'Tutoring services' : ($module === 'tutoring' ? 'Find a tutor' : 'Home services');
$activePage = $catalogueView ? 'tutor-services' : ($module === 'tutoring' ? 'tutors' : 'services');
try {
    if ($module === 'tutoring') {
        $teachingLevels = db()->query('SELECT id, name FROM tutor_teaching_levels WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
        if ($levelId && !in_array((int) $levelId, array_map('intval', array_column($teachingLevels, 'id')), true)) {
            page_error('Choose a valid teaching level.', 404);
        }
    }
    $query = $module === 'tutoring' && $levelId
        ? db()->prepare(
            "SELECT id, name, slug, hourly_rate, service_price, is_coming_soon
             FROM service_categories
             WHERE module = ? AND is_active = 1 AND is_coming_soon = 0
               AND hourly_rate > 0
               AND LOWER(name) NOT IN ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school')
             ORDER BY sort_order, name"
        )
        : db()->prepare(
            $module === 'tutoring'
                ? "SELECT id, name, slug, hourly_rate, service_price, is_coming_soon
                   FROM service_categories
                   WHERE module = ? AND is_active = 1
                     AND LOWER(name) NOT IN ('primary','junior','secondary','kcse','pp1','pp2','grade 1','grade 2','grade 3','grade 4','grade 5','grade 6','grade 7','grade 8','grade 9','senior school')
                   ORDER BY sort_order, name"
                : 'SELECT id, name, slug, hourly_rate, service_price, is_coming_soon FROM service_categories WHERE module = ? AND is_active = 1 ORDER BY sort_order, name'
        );
    $query->execute([$module]);
    $categories = $query->fetchAll();
    if ($catalogueView && $subjectQuery !== '') {
        $categories = array_values(array_filter(
            $categories,
            static fn (array $category): bool => mb_stripos($category['name'], $subjectQuery) !== false
        ));
    }
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="page-heading">
        <div><div class="eyebrow"><?= $module === 'tutoring' ? 'TUTORING & EDUCATION' : 'HELP AROUND THE HOME' ?></div>
            <h1><?= $catalogueView ? 'Explore tutoring services.' : ($module === 'tutoring' ? 'Find the right tutor.' : 'Make home feel lighter.') ?></h1>
            <p><?= $catalogueView ? 'Browse tutoring subjects, search by name, and filter by the learner’s level to find a good fit.' : ($module === 'tutoring' ? 'Choose the learner’s level first, then browse subjects and tutors who teach that level.' : 'Find trusted local professionals for the things that keep home running.') ?></p>
        </div>
        <div class="catalogue-actions">
            <?php if ($catalogueView): ?><a class="button button-primary" href="<?= e(url($levelId ? 'Pages/providers.php?module=tutoring&level=' . (int) $levelId : 'Pages/services.php?module=tutoring')) ?>">Find a tutor</a>
            <?php elseif ($module === 'tutoring' && $levelId): ?><a class="button button-primary" href="<?= e(url('Pages/providers.php?module=tutoring&level=' . (int) $levelId)) ?>">Browse all tutors</a>
            <?php elseif ($module === 'tutoring'): ?><a class="button button-primary" href="#learner-level-picker">Choose learner level</a><?php endif; ?>
            <?php if (!$catalogueView): ?><a class="button button-outline" href="<?= e(url('Pages/services.php?module=' . ($module === 'tutoring' ? 'home_services' : 'tutoring'))) ?>"><?= $module === 'tutoring' ? 'Explore home services' : 'Explore tutoring' ?></a><?php endif; ?>
        </div>
    </div>
    <?php if ($catalogueView): ?>
        <form class="tutor-catalogue-filter" id="tutor-catalogue-filter" method="get">
            <input type="hidden" name="module" value="tutoring">
            <input type="hidden" name="view" value="catalogue">
            <label for="tutor-subject-search">Search tutoring subjects
                <input id="tutor-subject-search" type="search" name="q" value="<?= e($subjectQuery) ?>" maxlength="80" placeholder="e.g. Mathematics or English">
            </label>
            <label for="catalogue-learner-level">Learner level
                <select id="catalogue-learner-level" name="level">
                    <option value="">All levels</option>
                    <?php foreach ($teachingLevels as $level): ?><option value="<?= (int) $level['id'] ?>" <?= $levelId && (int) $levelId === (int) $level['id'] ? 'selected' : '' ?>><?= e($level['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button class="button button-primary button-small" type="submit">Filter services</button>
            <?php if ($subjectQuery !== '' || $levelId): ?><a class="button button-outline button-small" href="<?= e(url('Pages/services.php?module=tutoring&view=catalogue')) ?>">Clear filters</a><?php endif; ?>
        </form>
    <?php elseif ($module === 'tutoring' && !$levelId): ?>
        <section class="level-picker" id="learner-level-picker"><h2>Which level is the learner studying?</h2><p>Select a level to see subjects with available tutors.</p><div class="level-picker-options"><?php foreach ($teachingLevels as $level): ?><a class="button button-outline" href="<?= e(url('Pages/services.php?module=tutoring&level=' . (int) $level['id'])) ?>"><?= e($level['name']) ?></a><?php endforeach; ?></div></section>
    <?php elseif ($module === 'tutoring'): ?>
        <div class="breadcrumb"><a href="<?= e(url('Pages/services.php?module=tutoring')) ?>">← Change learner level</a><span>Level: <?= e((string) array_column($teachingLevels, 'name', 'id')[$levelId]) ?></span></div>
    <?php endif; ?>
    <?php if ($module !== 'tutoring' || $levelId || $catalogueView): ?><div class="catalogue-meta"><span><?= count($categories) ?> <?= $catalogueView || $module === 'tutoring' ? 'tutoring subjects' : 'ways to get started' ?></span><span class="catalogue-note"><i></i> Browse freely. Sign in when you're ready to book.</span></div>
    <?php if (!$categories): ?><section class="empty-state compact"><h2>No matching tutoring services</h2><p>Try a different subject search or choose another learner level.</p></section><?php else: ?>
    <section class="service-grid" aria-label="Available services">
        <?php foreach ($categories as $index => $category): ?>
            <article class="service-card <?= (int) $category['is_coming_soon'] ? 'service-card-soon' : '' ?>">
                <span class="service-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                <h2><?= e($category['name']) ?></h2>
                <p><?= (int) $category['is_coming_soon'] ? 'We’re preparing this service for you.' : ($module === 'tutoring' ? 'Find a trusted educator for your learning goals.' : 'Connect with a trusted professional near you.') ?></p>
                <?php if (!(int) $category['is_coming_soon'] && $module === 'home_services'): ?><p class="service-price"><?= $category['service_price'] !== null ? 'KES ' . number_format((float) $category['service_price'], 2) . ($category['slug'] === 'laundry' ? ' per laundry basket' : ' per service') : 'Price being set by LertFind' ?></p><?php endif; ?>
                <?php if ((int) $category['is_coming_soon']): ?>
                    <span class="coming-soon">COMING SOON</span>
                <?php elseif ($catalogueView && !$levelId): ?>
                    <a class="button button-outline button-small service-link" href="<?= e(url('Pages/services.php?module=tutoring')) ?>">Choose a level to find tutors</a>
                <?php else: ?>
                    <a class="button button-primary button-small service-link" href="<?= e(url('Pages/providers.php?service=' . (int) $category['id'] . ($module === 'tutoring' ? '&level=' . (int) $levelId : ''))) ?>"><?= $module === 'tutoring' ? 'Find a tutor' : 'Explore providers' ?></a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
