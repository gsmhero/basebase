ALTER TABLE vendors MODIFY payout_type VARCHAR(20) NOT NULL DEFAULT 'self';

UPDATE vendors SET payout_type = 'self' WHERE payout_type NOT IN ('self', 'company', 'personal');

UPDATE vendors SET personal = NULL
