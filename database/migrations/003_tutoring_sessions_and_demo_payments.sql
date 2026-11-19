USE tutalink;

ALTER TABLE bookings
    ADD COLUMN session_hours DECIMAL(4,2) NULL AFTER scheduled_for;

ALTER TABLE payments
    MODIFY COLUMN gateway ENUM('paystack','mpesa','card','demo') NOT NULL;
