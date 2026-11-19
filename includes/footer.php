<?php if ($isAppShell): ?>
    </div>
</div>
<?php else: ?>
    <footer class="site-footer">
        <span class="footer-brand"><span class="footer-mark" aria-hidden="true">L</span><span><strong>LertFind Kenya</strong><small>© <?= date('Y') ?> · Learn. Book. Live better.</small></span></span>
        <span class="footer-tagline">Good people. Good work. Right here.</span>
        <a href="<?= e(url('Pages/services.php')) ?>">Explore the platform</a>
    </footer>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
