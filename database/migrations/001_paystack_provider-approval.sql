USE tutalink;

ALTER TABLE provider_profiles
    ADD COLUMN paystack_subaccount_code VARCHAR(80) NULL AFTER bio,
    ADD COLUMN approval_status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending' AFTER paystack_subaccount_code,
    ADD COLUMN approved_at DATETIME NULL AFTER approval_status;

ALTER TABLE payments
    MODIFY COLUMN gateway ENUM('paystack','mpesa','card') NOT NULL,
    ADD COLUMN reference VARCHAR(100) NULL UNIQUE AFTER gateway_reference,
    ADD COLUMN paystack_transaction_id VARCHAR(80) NULL AFTER reference,
    ADD COLUMN paid_at DATETIME NULL AFTER created_at,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER paid_at;
