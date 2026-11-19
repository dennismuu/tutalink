USE tutalink;

CREATE TABLE tutor_teaching_levels (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tutor_teaching_levels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tutor_teaching_levels (name, sort_order) VALUES
('Primary', 1), ('Junior', 2), ('Secondary', 3), ('KCSE', 4);

CREATE TABLE provider_teaching_levels (
    provider_id BIGINT UNSIGNED NOT NULL,
    level_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (provider_id, level_id),
    KEY idx_provider_teaching_levels_level (level_id),
    CONSTRAINT fk_provider_teaching_levels_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_teaching_levels_level FOREIGN KEY (level_id) REFERENCES tutor_teaching_levels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
    ADD COLUMN teaching_level_id SMALLINT UNSIGNED DEFAULT NULL AFTER category_id,
    ADD COLUMN session_location_type ENUM('home','hall','other') NOT NULL DEFAULT 'other' AFTER service_location,
    ADD KEY idx_bookings_teaching_level (teaching_level_id),
    ADD CONSTRAINT fk_bookings_teaching_level FOREIGN KEY (teaching_level_id) REFERENCES tutor_teaching_levels (id);

CREATE TABLE revision_materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL,
    description TEXT DEFAULT NULL,
    stored_filename VARCHAR(80) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_revision_materials_active (is_active, created_at),
    UNIQUE KEY uq_revision_materials_stored_filename (stored_filename),
    CONSTRAINT fk_revision_materials_admin FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE revision_material_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('awaiting_payment','paid','failed') NOT NULL DEFAULT 'awaiting_payment',
    payment_reference VARCHAR(100) DEFAULT NULL,
    gateway_reference VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_revision_material_orders_reference (payment_reference),
    KEY idx_revision_material_orders_customer (customer_id, status),
    KEY idx_revision_material_orders_entitlement (material_id, customer_id, status),
    CONSTRAINT fk_revision_material_orders_material FOREIGN KEY (material_id) REFERENCES revision_materials (id),
    CONSTRAINT fk_revision_material_orders_customer FOREIGN KEY (customer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO booking_status_history (booking_id, changed_by, status, note)
SELECT b.id, b.customer_id, 'requested',
       'Unpaid tutoring checkout reopened as a provider request; payment is due only after tutor acceptance.'
FROM bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id AND o.module = 'tutoring'
JOIN service_categories c ON c.id = b.category_id AND c.module = 'tutoring'
WHERE o.status = 'awaiting_payment'
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.booking_id = b.id AND p.status <> 'failed'
  );

INSERT INTO notifications (user_id, booking_id, channel, subject, body)
SELECT b.provider_id, b.id, 'in_app', 'Tutoring request awaiting acceptance',
       'A tutoring request is ready for your review. The student can pay after you accept.'
FROM bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id AND o.module = 'tutoring'
JOIN service_categories c ON c.id = b.category_id AND c.module = 'tutoring'
WHERE o.status = 'awaiting_payment'
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.booking_id = b.id AND p.status <> 'failed'
  );

UPDATE bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id AND o.module = 'tutoring'
JOIN service_categories c ON c.id = b.category_id AND c.module = 'tutoring'
SET b.checkout_order_id = NULL,
    b.status = 'requested',
    b.quoted_amount = NULL,
    b.commission_amount = NULL,
    b.provider_amount = NULL
WHERE o.status = 'awaiting_payment'
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.booking_id = b.id AND p.status <> 'failed'
  );

UPDATE checkout_orders o
SET o.status = 'cancelled'
WHERE o.module = 'tutoring'
  AND o.status = 'awaiting_payment'
  AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.checkout_order_id = o.id);

COMMIT;
