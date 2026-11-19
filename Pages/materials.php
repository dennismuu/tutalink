<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!current_user()) {
    $_SESSION['return_to'] = 'Pages/materials.php';
    header('Location: ' . url('Pages/login.php'));
    exit;
}
require_roles('student_parent');

try {
    $query = db()->prepare(
        "SELECT m.id, m.title, m.description, m.price,
                (SELECT o.status FROM revision_material_orders o
                 WHERE o.material_id = m.id AND o.customer_id = ? AND o.status = 'paid'
                 ORDER BY o.id DESC LIMIT 1) AS purchase_status
         FROM revision_materials m
         WHERE m.is_active = 1 OR EXISTS (
             SELECT 1 FROM revision_material_orders paid
             WHERE paid.material_id = m.id AND paid.customer_id = ? AND paid.status = 'paid'
         )
         ORDER BY m.created_at DESC"
    );
    $query->execute([current_user()['id'], current_user()['id']]);
    $materials = $query->fetchAll();
} catch (RuntimeException $exception) {
    page_error($exception->getMessage(), 503);
}

$pageTitle = 'Revision materials';
$activePage = 'materials';
require dirname(__DIR__) . '/includes/header.php';
?>
<main class="container page-wrap">
    <div class="page-heading"><div><div class="eyebrow">STUDY RESOURCES</div><h1>Revision materials.</h1><p>Browse resources uploaded by LertFind administrators. Payment unlocks a secure download.</p></div></div>
    <?php if (!$materials): ?><section class="empty-state"><h2>No materials are available yet.</h2><p>Check back when the study resources are published.</p></section>
    <?php else: ?><section class="material-grid"><?php foreach ($materials as $material): ?>
        <article class="material-card"><span class="eyebrow">REVISION RESOURCE</span><h2><?= e($material['title']) ?></h2><?php if ($material['description']): ?><p><?= e($material['description']) ?></p><?php endif; ?><strong>KES <?= number_format((float) $material['price'], 2) ?></strong>
            <?php if ($material['purchase_status'] === 'paid'): ?><a class="button button-primary button-small" href="<?= e(url('Pages/material_download.php?id=' . (int) $material['id'])) ?>">Download material</a>
            <?php else: ?><form method="post" action="<?= e(url('Pages/material_checkout.php')) ?>"><?= csrf_field() ?><input type="hidden" name="material_id" value="<?= (int) $material['id'] ?>"><?php if (APP_ENV === 'local' && !paystack_checkout_ready(null, false)): ?><label>Simulated payment<select name="demo_method" required><option value="">Choose method</option><option value="mpesa">M-Pesa (simulated)</option><option value="card">Card (simulated)</option><option value="bank_transfer">Bank transfer (simulated)</option></select></label><small class="demo-payment-note">Local demo only — no money is transferred.</small><?php elseif (APP_ENV === 'local'): ?><small>Paystack test checkout</small><?php endif; ?><button class="button button-primary button-small" type="submit">Buy and download</button></form><?php endif; ?>
        </article>
    <?php endforeach; ?></section><?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
