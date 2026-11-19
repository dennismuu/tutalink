USE tutalink;

ALTER TABLE provider_profiles
    ADD COLUMN hourly_rate DECIMAL(10,2) NULL AFTER service_mode;
