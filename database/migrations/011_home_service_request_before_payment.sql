USE tutalink;

START TRANSACTION;

INSERT INTO booking_status_history (booking_id, changed_by, status, note)
SELECT b.id, b.customer_id, 'requested',
       'Unpaid home-service request reopened for provider acceptance before payment.'
FROM bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id
JOIN service_categories c ON c.id = b.category_id AND c.module = 'home_services'
WHERE o.status = 'awaiting_payment'
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.booking_id = b.id AND p.status <> 'failed'
  );

INSERT INTO notifications (user_id, booking_id, channel, subject, body)
SELECT b.provider_id, b.id, 'in_app', 'Home-service request awaiting acceptance',
       'A home-service request is ready for your review. The customer can pay after you accept.'
FROM bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id
JOIN service_categories c ON c.id = b.category_id AND c.module = 'home_services'
WHERE o.status = 'awaiting_payment'
  AND NOT EXISTS (
      SELECT 1 FROM payments p
      WHERE p.booking_id = b.id AND p.status <> 'failed'
  );

UPDATE bookings b
JOIN checkout_orders o ON o.id = b.checkout_order_id
JOIN service_categories c ON c.id = b.category_id AND c.module = 'home_services'
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
WHERE o.module = 'home_services'
  AND o.status = 'awaiting_payment'
  AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.checkout_order_id = o.id);

COMMIT;
