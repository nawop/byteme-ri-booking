# ByteMe — RI Booking System

A lightweight PHP + SQLite app to manage workshop bookings at my school.  
No external dependencies. Responsive UI. Works entirely server-side.

---

## Features

- Browse available activities by cycle
- Choose a date and submit a booking
- Admin panel for confirming/rejecting requests
- Quota-aware tracking by trimester
- Minimal dark UI (no frameworks, no JS build tools)

---

## Setup

```bash
git clone https://github.com/nawop/byteme-ri-booking.git
cd byteme-ri-booking
cp config/config.example.php config/config.php
sqlite3 db.sqlite < init.sql
