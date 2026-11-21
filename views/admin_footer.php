<?php
// Admin footer - completely separate from frontend theme
?>
                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer">
                <div class="copyright">
                    <span>&copy; <?= date('Y') ?> <?= escape(strip_tags($GLOBALS['config']['site_name'] ?? 'bulletinbored')) ?></span>
                    <span class="footer-meta">
                        <i class="fas fa-cubes"></i> bulletinbored
                    </span>
                </div>
            </footer>
            <!-- End of Footer -->

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top" href="#main-content" aria-label="Back to top">
        <i class="fas fa-angle-up"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/sidebar.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/admin-helpers.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
        (function () {
            var button = document.querySelector('.scroll-to-top');
            if (!button) return;
            var toggle = function () {
                button.classList.toggle('is-visible', window.scrollY > 320);
            };
            window.addEventListener('scroll', toggle, { passive: true });
            toggle();
        })();
    </script>
</body>
</html>
