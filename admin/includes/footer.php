<?php if (isAdminLoggedIn()): ?>
        </div> 
        <footer style="background: rgba(20, 83, 45, 0.95); backdrop-filter: blur(12px); border-top: 1px solid rgba(187, 247, 208, 0.3); padding: 1.25rem 2rem; text-align: center; color: #dcfce7; font-size: 0.85rem; margin-top: auto;">
            <p>&copy; <?= date('Y') ?> Project  Smiski - Admin Management System</p>
        </footer>
    </div> 
</div> 
<?php else: ?>
</div> 
<?php endif; ?>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    if (window.innerWidth <= 992) {
        sidebar.classList.toggle('active');
    } else {
        sidebar.classList.toggle('collapsed');
    }
}
</script>
</body>
</html>