-- Run once against the existing user-side database, after schema.sql.
ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'resident';
ALTER TABLE documents ADD COLUMN purpose TEXT NULL;
-- Preserve request snapshots when a document is deleted.
ALTER TABLE document_requests DROP FOREIGN KEY requests_document_fk;
ALTER TABLE document_requests MODIFY document_id BIGINT UNSIGNED NULL;
ALTER TABLE document_requests ADD CONSTRAINT requests_document_fk FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL;
ALTER TABLE document_requests
 ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'Not Paid',
 ADD COLUMN payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
 ADD COLUMN pickup_date DATETIME NULL,
 ADD COLUMN processing_date DATETIME NULL,
 ADD COLUMN pickup_option VARCHAR(20) NOT NULL DEFAULT 'Not needed',
 ADD COLUMN delivery_option VARCHAR(20) NOT NULL DEFAULT 'Not needed',
 ADD COLUMN review_reason TEXT NULL,
 ADD COLUMN document_content MEDIUMTEXT NULL,
 ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
CREATE TABLE notification_priorities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;
INSERT INTO notification_priorities (name) VALUES ('Normal'), ('High'), ('Urgent');
CREATE TABLE announcements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL, title VARCHAR(150) NOT NULL, message TEXT NOT NULL,
 recipient_type VARCHAR(20) NOT NULL, priority_id BIGINT UNSIGNED NOT NULL,
 attachment_path VARCHAR(255) NULL, attachment_name VARCHAR(255) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (admin_id) REFERENCES users(id), FOREIGN KEY (priority_id) REFERENCES notification_priorities(id)
) ENGINE=InnoDB;
ALTER TABLE notifications ADD COLUMN announcement_id BIGINT UNSIGNED NULL,
 ADD CONSTRAINT notifications_announcement_fk FOREIGN KEY (announcement_id) REFERENCES announcements(id);
CREATE TABLE admin_request_reads (
 admin_id BIGINT UNSIGNED NOT NULL, request_id BIGINT UNSIGNED NOT NULL,
 read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(admin_id, request_id),
 FOREIGN KEY (admin_id) REFERENCES users(id), FOREIGN KEY (request_id) REFERENCES document_requests(id)
) ENGINE=InnoDB;
CREATE TABLE admin_audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id BIGINT UNSIGNED NULL,
 changes JSON NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (admin_id) REFERENCES users(id), INDEX audit_date(created_at)
) ENGINE=InnoDB;
CREATE TABLE email_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 recipient VARCHAR(255) NOT NULL, subject VARCHAR(150) NOT NULL, body TEXT NOT NULL,
 attempts INT NOT NULL DEFAULT 0, sent_at TIMESTAMP NULL, last_error TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX outbox_pending(sent_at, attempts)
) ENGINE=InnoDB;
