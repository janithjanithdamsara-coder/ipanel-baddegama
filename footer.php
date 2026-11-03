<?php
// footer.php
// Global layout footer and Javascript helpers with Sidebar controls
?>
    <footer class="no-print" style="margin-top: 60px; padding: 30px 16px; border-top: 1px solid var(--border-color); text-align: center; font-size: 12px; color: var(--text-muted);">
        <p>&copy; <?= date('Y') ?> i-Panel Baddegama Portal. All rights reserved.</p>
    </footer>

    </main> <!-- close .main-content -->

    </div> <!-- close .app-container -->

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div class="sidebar-overlay no-print" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Global Client Scripts -->
    <script>
        // Toggle mobile sidebar drawer
        function toggleSidebar() {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                const isActive = sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                document.body.style.overflow = isActive ? 'hidden' : '';
            }
        }

        // Close sidebar if window resized above mobile threshold
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                const sidebar = document.getElementById('appSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Close sidebar with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('appSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        // Toast notifications
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const msgEl = document.getElementById('toast-message');
            
            if (!toast || !msgEl) return;
            
            msgEl.textContent = message;
            
            // Set class
            toast.className = 'toast show';
            if (type === 'success') {
                toast.classList.add('toast-success');
            } else if (type === 'danger') {
                toast.classList.add('toast-danger');
            }
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Modal triggers
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
            }
        }

        // Check for session flash messages
        <?php if (isset($_SESSION['flash_msg']) && isset($_SESSION['flash_type'])): ?>
            window.addEventListener('DOMContentLoaded', () => {
                showToast(
                    <?= json_encode($_SESSION['flash_msg']) ?>, 
                    <?= json_encode($_SESSION['flash_type']) ?>
                );
            });
            <?php 
                unset($_SESSION['flash_msg']);
                unset($_SESSION['flash_type']);
            ?>
        <?php endif; ?>
    </script>
</body>
</html>
