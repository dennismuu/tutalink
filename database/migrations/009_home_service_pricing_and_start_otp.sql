USE tutalink;

ALTER TABLE service_categories
    ADD COLUMN service_price DECIMAL(10,2) DEFAULT NULL AFTER hourly_rate;

ALTER TABLE bookings
    ADD COLUMN booked_service_price DECIMAL(10,2) DEFAULT NULL AFTER booked_hourly_rate,
    ADD COLUMN laundry_baskets SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER booked_service_price,
    ADD COLUMN service_start_otp CHAR(6) DEFAULT NULL AFTER class_started_at,
    ADD COLUMN service_start_otp_expires_at DATETIME DEFAULT NULL AFTER service_start_otp,
    ADD COLUMN service_started_at DATETIME DEFAULT NULL AFTER service_start_otp_expires_at;
