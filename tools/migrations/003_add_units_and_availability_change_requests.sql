CREATE TABLE IF NOT EXISTS availability_change_requests (
    request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    student_id INT NOT NULL,
    term_id INT NOT NULL,
    proposed_availability JSON NOT NULL,
    status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    review_note VARCHAR(500) NULL,
    INDEX idx_availability_change_application (application_id, status),
    INDEX idx_availability_change_student (student_id, term_id)
);

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS units INT NULL AFTER year_level;
