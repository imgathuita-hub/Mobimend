<div id="bookModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <span id="closeModal" class="close-btn">&times;</span>

        <h2>Book a Repair</h2>
        <form id="bookForm" method="POST" action="/backend/book_request.php">

            <label>Your Name</label>
            <input type="text" name="name" required>

            <label>Your Phone</label>
            <input type="text" name="phone" required>

            <label>Device Type</label>
            <input type="text" name="device" required>

            <label>Problem Description</label>
            <textarea name="problem" required></textarea>

            <button type="submit">Submit Booking</button>
        </form>
    </div>
</div>
