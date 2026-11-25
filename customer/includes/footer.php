<div class="modal-overlay" id="contactModal">
    <div class="modal-card" style="background: rgba(20, 83, 45, 0.95); backdrop-filter: blur(12px); border: 1px solid rgba(187, 247, 208, 0.3); border-radius: var(--radius); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); color: #ffffff;">
        <button type="button" class="modal-close" onclick="closeContactPane()" style="color: #dcfce7; background: transparent; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        <h3 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 0.5rem; color: #ffffff;">
            <i class="fa-solid fa-paper-plane" style="color: #86efac;"></i> Contact Us
        </h3>
        <p style="font-size: 0.9rem; color: #dcfce7; margin-bottom: 1.5rem;">
            Send us a message and our support team will respond shortly.
        </p>

        <form action="index.php" method="POST" onsubmit="const b = this.querySelector('button[type=submit]'); if (b) { b.disabled = true; b.innerHTML = 'Sending...'; }">
            <input type="hidden" name="action" value="submit_contact">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars(generateFormToken('contact_form')) ?>">
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: #dcfce7;">Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>"
                       style="width:100%; padding: 0.75rem 1rem; border: 1px solid rgba(187, 247, 208, 0.3); border-radius: 10px; font-size: 0.95rem; outline: none; background: rgba(255, 255, 255, 0.05); color: #ffffff; transition: border-color 0.2s;" placeholder="Your Name" onfocus="this.style.borderColor='#22c55e'; this.style.background='rgba(255, 255, 255, 0.1)'" onblur="this.style.borderColor='rgba(187, 247, 208, 0.3)'; this.style.background='rgba(255, 255, 255, 0.05)'">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: #dcfce7;">Email</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>"
                       style="width:100%; padding: 0.75rem 1rem; border: 1px solid rgba(187, 247, 208, 0.3); border-radius: 10px; font-size: 0.95rem; outline: none; background: rgba(255, 255, 255, 0.05); color: #ffffff; transition: border-color 0.2s;" placeholder="name@example.com" onfocus="this.style.borderColor='#22c55e'; this.style.background='rgba(255, 255, 255, 0.1)'" onblur="this.style.borderColor='rgba(187, 247, 208, 0.3)'; this.style.background='rgba(255, 255, 255, 0.05)'">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: #dcfce7;">Subject</label>
                <input type="text" name="subject" required placeholder="Subject of your inquiry"
                       style="width:100%; padding: 0.75rem 1rem; border: 1px solid rgba(187, 247, 208, 0.3); border-radius: 10px; font-size: 0.95rem; outline: none; background: rgba(255, 255, 255, 0.05); color: #ffffff; transition: border-color 0.2s;" onfocus="this.style.borderColor='#22c55e'; this.style.background='rgba(255, 255, 255, 0.1)'" onblur="this.style.borderColor='rgba(187, 247, 208, 0.3)'; this.style.background='rgba(255, 255, 255, 0.05)'">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; color: #dcfce7;">Message</label>
                <textarea name="message" rows="4" required placeholder="Type your message here..."
                          style="width:100%; padding: 0.75rem 1rem; border: 1px solid rgba(187, 247, 208, 0.3); border-radius: 10px; font-size: 0.95rem; font-family: inherit; outline: none; background: rgba(255, 255, 255, 0.05); color: #ffffff; transition: border-color 0.2s; resize: vertical;" onfocus="this.style.borderColor='#22c55e'; this.style.background='rgba(255, 255, 255, 0.1)'" onblur="this.style.borderColor='rgba(187, 247, 208, 0.3)'; this.style.background='rgba(255, 255, 255, 0.05)'"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="background: #22c55e; color: white; padding: 0.85rem; border-radius: var(--radius); border: 1px solid #16a34a; font-weight: 600; font-size: 1rem; width: 100%; cursor: pointer; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); transition: all 0.2s;" onmouseenter="this.style.background='#16a34a'; this.style.transform='translateY(-1px)'" onmouseleave="this.style.background='#22c55e'; this.style.transform='none'">
                <i class="fa-solid fa-paper-plane"></i> Send / Submit
            </button>
        </form>
    </div>
</div>

<footer style="background: rgba(20, 83, 45, 0.95); backdrop-filter: blur(12px); border-top: 1px solid rgba(187, 247, 208, 0.3); padding: 1.5rem 2rem; text-align: center; color: #dcfce7; font-size: 0.9rem; margin-top: auto;">
    <p>&copy; <?= date('Y') ?> Project Smiski. All rights reserved.</p>
</footer>

<script>
function openContactPane() {
    document.getElementById('contactModal').classList.add('active');
}
function closeContactPane() {
    document.getElementById('contactModal').classList.remove('active');
}
</script>
</body>
</html>