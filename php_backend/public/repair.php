  <?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = '';
$tone = 'info';
$selectedRepairType = trim((string) ($_GET['repair_type'] ?? ''));

$form = [
    'customer_name' => '',
    'phone_number' => '',
    'email' => '',
    'device_model' => '',
    'repair_type' => $selectedRepairType,
    'issue_description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
        'phone_number' => trim((string) ($_POST['phone_number'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'device_model' => trim((string) ($_POST['device_model'] ?? '')),
        'repair_type' => trim((string) ($_POST['repair_type'] ?? '')),
        'issue_description' => trim((string) ($_POST['issue_description'] ?? '')),
    ];

    if ($form['customer_name'] === '' || $form['phone_number'] === '' || $form['device_model'] === '' || $form['repair_type'] === '') {
        $message = 'Customer name, phone number, device model, and repair type are required.';
        $tone = 'error';
    } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $message = 'Email format is invalid.';
        $tone = 'error';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO repair_bookings
             (customer_name, phone_number, email, device_model, repair_type, issue_description, status, booking_date, created_at, updated_at)
             VALUES
             (:customer_name, :phone_number, :email, :device_model, :repair_type, :issue_description, :status, :booking_date, :created_at, :updated_at)'
        );

        $timestamp = now();
        $stmt->execute([
            'customer_name' => $form['customer_name'],
            'phone_number' => $form['phone_number'],
            'email' => $form['email'],
            'device_model' => $form['device_model'],
            'repair_type' => $form['repair_type'],
            'issue_description' => $form['issue_description'],
            'status' => 'Pending',
            'booking_date' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $bookingId = (int) $pdo->lastInsertId();
        $message = 'Booking submitted successfully. Your booking ID is #' . $bookingId . '.';
        $tone = 'success';
        $form = [
            'customer_name' => '',
            'phone_number' => '',
            'email' => '',
            'device_model' => '',
            'repair_type' => '',
            'issue_description' => '',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Repair Booking | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Repair booking</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a class="active" href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main>
    <section class="section alt">
      <div class="section-inner diagnosis-layout">
        <div class="repair-phone">
          <div class="repair-device issue-screen" id="repairDevice" aria-label="Interactive phone fault diagram">
            <div class="repair-zone zone-screen"></div>
            <div class="repair-zone zone-battery"></div>
            <div class="repair-zone zone-port"></div>
          </div>
        </div>

        <div class="booking-panel" id="repair-booking">
          <p class="section-kicker"><i class="fa-solid fa-stethoscope"></i> Guided repair booking</p>
          <h1 class="section-title">Select device, issue, date, and slot.</h1>
          <p class="section-copy">The visual phone highlights the likely fault area while the PHP form saves bookings directly into MySQL.</p>

          <?php if ($message !== ''): ?>
            <div class="php-banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
          <?php endif; ?>

          <div class="wizard-progress" aria-hidden="true">
            <div class="wizard-step active"></div>
            <div class="wizard-step active"></div>
            <div class="wizard-step active"></div>
            <div class="wizard-step"></div>
          </div>

          <form method="post" action="#repair-booking" class="php-form-grid">
        <?= csrf_field() ?>
        <?= csrf_field() ?>
            <div>
              <label for="customer_name">Full name</label>
              <input id="customer_name" name="customer_name" value="<?= htmlspecialchars($form['customer_name']) ?>" required>
            </div>
            <div>
              <label for="phone_number">Phone number</label>
              <input id="phone_number" name="phone_number" value="<?= htmlspecialchars($form['phone_number']) ?>" required>
            </div>
            <div>
              <label for="email">Email</label>
              <input id="email" type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>">
            </div>
            <div>
              <label for="device_model">Device model</label>
              <input id="device_model" name="device_model" placeholder="iPhone 13, Samsung A32..." value="<?= htmlspecialchars($form['device_model']) ?>" required>
            </div>

            <div class="full">
              <label>Issue type</label>
              <div class="choice-grid">
                <?php
                $issues = [
                    ['Screen Replacement', 'screen', 'fa-mobile-screen'],
                    ['Battery Replacement', 'battery', 'fa-battery-quarter'],
                    ['Charging Port', 'charging', 'fa-plug-circle-bolt'],
                    ['Water Damage', 'screen', 'fa-droplet'],
                    ['Speaker/Mic', 'screen', 'fa-volume-high'],
                    ['Software Issue', 'screen', 'fa-microchip'],
                ];
                foreach ($issues as [$label, $zone, $icon]):
                ?>
                  <label class="choice-card">
                    <input type="radio" name="repair_type" value="<?= htmlspecialchars($label) ?>" data-issue-choice="<?= htmlspecialchars($zone) ?>" <?= $form['repair_type'] === $label ? 'checked' : '' ?> required>
                    <i class="fa-solid <?= htmlspecialchars($icon) ?>"></i>
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <span>Estimate after diagnosis</span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div>
              <label>Preferred date</label>
              <input type="date">
            </div>
            <div>
              <label>Time slot</label>
              <input id="preferred_time_slot" placeholder="Select a slot below">
            </div>
            <div class="full">
              <div class="slot-grid">
                <button class="slot" type="button" data-slot="09:00">09:00</button>
                <button class="slot" type="button" data-slot="11:00">11:00</button>
                <button class="slot" type="button" data-slot="14:00">14:00</button>
                <button class="slot" type="button" data-slot="16:00">16:00</button>
              </div>
            </div>
            <div class="full">
              <label for="issue_description">Issue description</label>
              <textarea id="issue_description" name="issue_description" rows="4" placeholder="Tell us what happened and when it started."><?= htmlspecialchars($form['issue_description']) ?></textarea>
            </div>
            <div class="full">
              <button type="submit" class="btn-primary"><i class="fa-solid fa-calendar-check"></i> Confirm repair booking</button>
            </div>
          </form>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-clock-rotate-left"></i> Tracking preview</p>
        <h2 class="section-title">Customers know what is happening after booking.</h2>
        <div class="timeline">
          <div class="timeline-step done"><div class="timeline-dot"><i class="fa-solid fa-check"></i></div><div><strong>Booking received</strong><p>Repair request saved in MySQL with Pending status.</p></div></div>
          <div class="timeline-step live"><div class="timeline-dot"><i class="fa-solid fa-magnifying-glass"></i></div><div><strong>Diagnosis</strong><p>Technician checks issue and confirms parts.</p></div></div>
          <div class="timeline-step"><div class="timeline-dot"><i class="fa-solid fa-screwdriver-wrench"></i></div><div><strong>Repair</strong><p>Device is repaired and quality checked.</p></div></div>
          <div class="timeline-step"><div class="timeline-dot"><i class="fa-solid fa-box"></i></div><div><strong>Ready</strong><p>Customer receives pickup or delivery update.</p></div></div>
        </div>
      </div>
    </section>
  </main>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
