USE tutalink;

CREATE TABLE admin_message_reads (
    admin_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id, booking_id),
    KEY idx_admin_message_reads_booking (booking_id),
    CONSTRAINT fk_admin_message_reads_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_message_reads_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_message_reads (admin_id, booking_id, last_read_message_id)
SELECT u.id, m.booking_id, MAX(m.id)
FROM users u
JOIN roles r ON r.id = u.role_id AND r.slug IN ('super_admin', 'admin1', 'admin2')
CROSS JOIN messages m
JOIN bookings b ON b.id = m.booking_id
GROUP BY u.id, m.booking_id;
