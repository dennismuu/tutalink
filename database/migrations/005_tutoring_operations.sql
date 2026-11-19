USE tutalink;

ALTER TABLE service_categories
    ADD COLUMN hourly_rate DECIMAL(10,2) NULL AFTER name;

UPDATE service_categories
SET hourly_rate = 500.00
WHERE module = 'tutoring' AND hourly_rate IS NULL;

ALTER TABLE bookings
    ADD COLUMN student_count SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER session_hours,
    ADD COLUMN class_otp CHAR(6) DEFAULT NULL AFTER customer_completed_at,
    ADD COLUMN class_otp_expires_at DATETIME DEFAULT NULL AFTER class_otp,
    ADD COLUMN class_started_at DATETIME DEFAULT NULL AFTER class_otp_expires_at;

ALTER TABLE tutor_withdrawal_requests
    MODIFY COLUMN status ENUM('pending','approved','payout_requested','released','rejected') NOT NULL DEFAULT 'pending',
    ADD COLUMN approved_by BIGINT UNSIGNED DEFAULT NULL AFTER requested_at,
    ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by,
    ADD COLUMN payout_method ENUM('mobile_money','bank') DEFAULT NULL AFTER approved_at,
    ADD COLUMN payout_details_encrypted TEXT DEFAULT NULL AFTER payout_method,
    ADD CONSTRAINT fk_tutor_withdrawals_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE refund_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected','refunded') NOT NULL DEFAULT 'pending',
    resolution_note TEXT DEFAULT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    refunded_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_refund_booking (booking_id),
    KEY idx_refund_requests_status (status, requested_at),
    CONSTRAINT fk_refund_requests_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
    CONSTRAINT fk_refund_requests_requester FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_refund_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE complaints
    MODIFY COLUMN booking_id BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN module ENUM('tutoring','home_services','general') NOT NULL DEFAULT 'general' AFTER booking_id;

UPDATE complaints cp
JOIN bookings b ON b.id = cp.booking_id
JOIN service_categories c ON c.id = b.category_id
SET cp.module = c.module;
