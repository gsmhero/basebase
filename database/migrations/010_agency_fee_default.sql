ALTER TABLE agents MODIFY agency_fee DECIMAL(5,2) NOT NULL DEFAULT 5;

UPDATE agents SET agency_fee = 5 WHERE agency_fee = 0;
