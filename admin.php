<?php
declare(strict_types=1);
header_remove("X-Powered-By");

// Serve a tiny HTML app; all actions go through index.php APIs.
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ByteMe — Admin</title>
  <link rel="stylesheet" href="static/admin.css" />
</head>
<body>
  <header class="topbar">
    <div class="brand">ByteMe • Admin</div>
    <div class="right">
      <input id="adminSecret" type="password" placeholder="Admin_Secret" />
      <button id="saveSecret">Save</button>
    </div>
  </header>

  <main class="container">
    <div class="tabs">
      <button class="tab active" data-tab="bookings">Pending bookings</button>
      <button class="tab" data-tab="activities">Activities</button>
    </div>

    <section id="bookings" class="panel active">
      <div id="bookingsList" class="card">Loading…</div>
    </section>

    <section id="activities" class="panel">
      <div id="activitiesList" class="card">Loading…</div>
    </section>
  </main>

  <script src="static/admin.js?v=1"></script>
</body>
</html>
