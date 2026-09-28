# SAMS Database & Registration Setup - Complete ✓

## What Was Fixed

1. **Created Database**: `sams` database with complete schema
2. **Fixed Column Names**:
   - Users table: `password` → `password_hash`, removed `phone_number`
   - Students table: `student_id` → `student_id_number`
   - Document uploads: Fixed to use `original_filename`, `stored_filename`, `user_id`, `mime_type`
3. **Fixed Auth**: Updated `config/auth.php` to use correct column names (`user_id`, `password_hash`, `student_id_number`)
4. **Fixed Registration**: Updated `register3.php` to use correct column names and terms query
5. **Created Seed Data**: Only admin and advisor/supervisor accounts are pre-seeded

## Test Users

Login at `/login.php` with:

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@sams.local | admin123 |
| Advisor | supervisor@sams.local | supervisor123 |

Students must register through the application flow before they can log in or apply.

## How to Test Registration

1. Go to `localhost/samss-main/register.php`
2. Fill in Step 1 (personal info)
3. Fill in Step 2 (academic info)
4. Upload documents in Step 3 (COG, ID, Photo)
5. Complete Step 4 with work location, schedule, and agreements
6. Submit - you should see a success message
7. New student account will be created in database

## Files Modified/Created

- `schema.sql` - Complete database schema
- `seed.sql` - Test user data
- `config/auth.php` - Fixed column name references
- `config/database.php` - DB connection
- `register3.php` - Fixed DB inserts and column names
- `login.php` - Auth flow

## Next Steps

After testing registration:
- Login with new credentials
- Test availability input (students/save_availability.php endpoint exists)
- Add schedule management
- Add attendance tracking
