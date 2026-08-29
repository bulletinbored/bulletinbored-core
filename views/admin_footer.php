<?php
// Admin footer - completely separate from frontend theme
?>
                </div>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            <!-- Footer -->
            <footer class="sticky-footer bg-white">
                <div class="copyright text-center my-auto">
                    <span>Copyright &copy; bulletinbored <?= date('Y') ?></span>
                </div>
            </footer>
            <!-- End of Footer -->

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    <!-- Scroll to Top Button-->
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/sidebar.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(base_url() . '/assets/js/admin-helpers.js', ENT_QUOTES, 'UTF-8') ?>" nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
