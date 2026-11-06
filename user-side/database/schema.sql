CREATE DATABASE IF NOT EXISTS barangay_user_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay_user_system;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(75) NOT NULL,
    last_name VARCHAR(75) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    contact_number VARCHAR(30) NOT NULL,
    barangay VARCHAR(100) NOT NULL,
    sitio VARCHAR(100) NOT NULL,
    national_id_front VARCHAR(255) NOT NULL,
    national_id_back VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    profile_picture VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    requirements TEXT NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    processing_time VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    document_name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    requirements TEXT NOT NULL,
    fee DECIMAL(10,2) NOT NULL,
    processing_time VARCHAR(150) NOT NULL,
    purpose TEXT NOT NULL,
    additional_information TEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    submission_key CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT requests_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT requests_document_fk FOREIGN KEY (document_id) REFERENCES documents(id),
    INDEX requests_user_date (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT notifications_request_fk FOREIGN KEY (request_id) REFERENCES document_requests(id),
    INDEX notifications_user_read (user_id, read_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    email_updates TINYINT(1) NOT NULL DEFAULT 1,
    in_app_notifications TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT preferences_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO documents (name, description, requirements, fee, processing_time)
SELECT 'Barangay Clearance', 'Certification of residency and clearance for local transactions.', 'Valid ID; proof of residency', 50.00, '1–2 working days'
WHERE NOT EXISTS (SELECT 1 FROM documents WHERE name = 'Barangay Clearance');
INSERT INTO documents (name, description, requirements, fee, processing_time)
SELECT 'Certificate of Residency', 'Certification that the applicant resides in the barangay.', 'Valid ID; proof of address', 30.00, '1 working day'
WHERE NOT EXISTS (SELECT 1 FROM documents WHERE name = 'Certificate of Residency');
INSERT INTO documents (name, description, requirements, fee, processing_time)
SELECT 'Certificate of Indigency', 'Certification for applicants requesting financial or social assistance.', 'Valid ID; proof of residency', 0.00, '1–2 working days'
WHERE NOT EXISTS (SELECT 1 FROM documents WHERE name = 'Certificate of Indigency');
