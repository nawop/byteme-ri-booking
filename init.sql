-- init.sql
PRAGMA foreign_keys=ON;

CREATE TABLE ri (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  quota_hours_trimester INTEGER NOT NULL DEFAULT 0,
  quota_hours_year INTEGER NOT NULL DEFAULT 0,
  consumed_hours_trimester INTEGER NOT NULL DEFAULT 0,
  consumed_hours_year INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE activity (
  id INTEGER PRIMARY KEY,
  ri_id INTEGER NOT NULL REFERENCES ri(id) ON DELETE CASCADE,
  cycle TEXT NOT NULL CHECK (cycle IN ('C1','C2','C3','C4')),
  name TEXT NOT NULL,
  duration_hours REAL NOT NULL,
  group_size INTEGER NOT NULL,
  summary TEXT NOT NULL,
  is_published INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE slot (
  id INTEGER PRIMARY KEY,
  activity_id INTEGER NOT NULL REFERENCES activity(id) ON DELETE CASCADE,
  ri_id INTEGER NOT NULL REFERENCES ri(id) ON DELETE CASCADE,
  starts_at TEXT NOT NULL,              -- ISO8601 UTC (YYYY-MM-DD HH:MM:SS)
  ends_at TEXT NOT NULL,
  capacity INTEGER NOT NULL DEFAULT 1,  -- if you ever want >1
  status TEXT NOT NULL DEFAULT 'OPEN'   -- OPEN | PENDING | BOOKED | HIDDEN
);

CREATE TABLE booking (
  id INTEGER PRIMARY KEY,
  slot_id INTEGER NOT NULL REFERENCES slot(id) ON DELETE CASCADE,
  teacher_name TEXT NOT NULL,
  teacher_email TEXT NOT NULL,
  teacher_cycle TEXT NOT NULL,
  created_at TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'PENDING' -- PENDING | CONFIRMED | REJECTED
);

-- optional immutable ledger (audit quotas)
CREATE TABLE quota_ledger (
  id INTEGER PRIMARY KEY,
  ri_id INTEGER NOT NULL REFERENCES ri(id) ON DELETE CASCADE,
  booking_id INTEGER REFERENCES booking(id) ON DELETE SET NULL,
  hours REAL NOT NULL,
  period_key TEXT NOT NULL, -- e.g. "2526T1" or "2526YEAR"
  direction TEXT NOT NULL CHECK(direction IN ('RESERVE','RELEASE','CONSUME')),
  created_at TEXT NOT NULL
);

-- example seed
INSERT INTO ri (name, quota_hours_trimester, quota_hours_year) VALUES
('Alice RI', 20, 60),
('Bob RI', 20, 60);

INSERT INTO activity (ri_id, cycle, name, duration_hours, group_size, summary) VALUES
(1,'C4','Intro to Python','2',20,'First steps with variables, loops and tiny challenges'),
(2,'C3','Robotics Basics','1.5',16,'Move a bot, avoid obstacles, basic sensors');

-- example slots (adjust dates)
INSERT INTO slot (activity_id, ri_id, starts_at, ends_at) VALUES
(1,1,'2025-11-12 13:30:00','2025-11-12 15:30:00'),
(1,1,'2025-11-19 13:30:00','2025-11-19 15:30:00'),
(2,2,'2025-11-14 10:00:00','2025-11-14 11:30:00');
