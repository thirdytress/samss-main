Student API Cheat Sheet

Purpose: quick reference for mobile/web integration and student-facing endpoints.

Authentication
- Method: Bearer token recommended.
- Header: `Authorization: Bearer <token>`
- Alternatively: session cookie + `X-CSRF-Token` for web forms (not recommended for mobile).

Common response envelope
- Successful: `{ "success": true, ... }`
- Error: `{ "success": false, "error_code": "...", "message": "..." }`
- HTTP codes: 200 OK, 201 Created, 400 Bad Request, 401 Unauthorized, 403 Forbidden, 500 Server Error

Endpoints

POST /api/login (map: `login.php`)
- Body: `{ "email": "x", "password": "y" }`
- Response: `{ "success": true, "token": "<token>", "expires_at": "ISO8601" }`
- If OTP required: follow with `verify_otp.php` flow.

POST /api/register (map: `register.php`)
- Body: `{ "email","password","first_name","last_name","student_number","preferred_office" }`
- Response: `{ success, message }
`

GET /api/profile (map: `students/profile.php`)
- Auth required. Returns student profile fields.

PUT /api/profile
- Update allowed profile fields. Auth required.

GET /api/availability (map: `students/availability.php`)
- Returns availability rows for student/application/term.

POST /api/availability (map: `students/save_availability.php`)
- Body: `{ "day_of_week": "Monday", "start_time": "08:00:00", "end_time": "12:00:00" }`
- Response: `{ success: true }
- Note: schedule generator reads `availability` to create duties.

GET /api/schedules (maps: `students/schedule.php` / `admin/scheduling.php` for admin)
- Returns assigned/pending schedules for student. Fields: `duty_id, day_of_week, start_time, end_time, office_name, status`.

POST /api/schedules/respond (map: `students/respond_schedule.php`)
- Body: `{ "duty_id": 123, "status": "accepted" | "declined" }`
- Response: `{ success: true, status: "accepted" }

GET /api/dashboard (map: `api/student_dashboard_snapshot.php`)
- Summary, upcoming duties, counts.

GET /api/attendance (map: `api/attendance_stream.php`)
- Returns normalized logs (uses `config/attendance.php` logic). Derived absences now skip duties assigned after scheduled end.

POST /api/attendance/clock (map: `attendance/clock.php`)
- Body: `{ "schedule_id": 42, "action": "in" | "out", "fingerprint_verified": true|false }
- Response (in): `{ "success": true, "action":"in","attendance_id":321,"status":"present","late_minutes":0 }`
- Response (out): `{ "success": true, "action":"out","attendance_id":321,"status":"completed" }`
- Notes: server computes `late_minutes` from schedule start time; `fingerprint_verified` must be validated by client/device.

POST /api/logout (map: `students/logout.php`)
- Invalidate token or destroy session.

Behavior notes & gotchas
- Schedule generation uses `availability`. If a day is unchecked in `availability`, generator will not create duties for that day.
- Generator will not insert a duty if a non-`declined` `duty_schedules` row for that `application_id/term_id/day_of_week` exists.
- Derived-absent logic: will NOT mark a duty absent if the schedule was assigned/accepted after the scheduled end time (prevents retroactive absences).

Security
- Use HTTPS and short-lived tokens. Refresh tokens optional.
- Validate all inputs server-side. Rate-limit login/OTP endpoints.
- Prefer bearer tokens for mobile to avoid CSRF complexity.

Testing
- Use `tools/test_schedule_acceptance.php` to simulate acceptance-now test for derived-absent behavior.

Contact
- Developer notes: see `config/attendance.php`, `attendance/clock.php`, `admin/scheduling.php` for core logic references.
