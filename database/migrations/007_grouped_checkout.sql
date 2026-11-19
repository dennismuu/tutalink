USE tutalink;

CREATE TABLE checkout_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    module ENUM('tutoring','home_services') NOT NULL,
    status ENUM('awaiting_payment','paid','refunded','cancelled') NOT NULL DEFAULT 'awaiting_payment',
    total_amount DECIMAL(10,2) NOT NULL,
    commission_amount DECIMAL(10,2) NOT NULL,
    provider_amount DECIMAL(10,2) NOT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    checkout_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkout_orders_payment_reference (payment_reference),
    KEY idx_checkout_orders_customer (customer_id, created_at),
    CONSTRAINT fk_checkout_orders_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT fk_checkout_orders_provider FOREIGN KEY (provider_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
    ADD COLUMN checkout_order_id BIGINT UNSIGNED DEFAULT NULL AFTER category_id,
    ADD COLUMN booked_hourly_rate DECIMAL(10,2) DEFAULT NULL AFTER session_hours,
    ADD KEY idx_bookings_checkout_order (checkout_order_id),
    MODIFY COLUMN status ENUM('requested','accepted','declined','linked','awaiting_payment','paid','on_the_way','in_progress','completed','cancelled') NOT NULL DEFAULT 'requested',
    ADD CONSTRAINT fk_bookings_checkout_order FOREIGN KEY (checkout_order_id) REFERENCES checkout_orders (id);

ALTER TABLE payments
    DROP INDEX reference,
    ADD KEY idx_payments_reference (reference);
