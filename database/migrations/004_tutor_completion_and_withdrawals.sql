USE tutalink;

ALTER TABLE bookings
    ADD COLUMN provider_completed_at DATETIME NULL AFTER provider_amount,
    ADD COLUMN customer_completed_at DATETIME NULL AFTER provider_completed_at;

UPDATE bookings b
JOIN service_categories c ON c.id = b.category_id
SET b.provider_completed_at = b.updated_at
WHERE c.module = 'tutoring'
  AND b.status = 'completed'
  AND b.provider_completed_at IS NULL;

ALTER TABLE payments
    ADD COLUMN payout_mode ENUM('direct','platform_held') NOT NULL DEFAULT 'direct' AFTER gateway;

CREATE TABLE tutor_withdrawal_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','released') NOT NULL DEFAULT 'pending',
    payout_reference VARCHAR(120) DEFAULT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_by BIGINT UNSIGNED DEFAULT NULL,
    released_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tutor_withdrawal_booking (booking_id),
    KEY idx_tutor_withdrawals_status (status, requested_at),
    CONSTRAINT fk_tutor_withdrawals_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
    CONSTRAINT fk_tutor_withdrawals_requester FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_tutor_withdrawals_releaser FOREIGN KEY (released_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
