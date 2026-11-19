USE tutalink;

CREATE TABLE provider_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('national_id','certificate') NOT NULL,
    stored_filename VARCHAR(80) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    review_status ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(500) DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_documents_stored_filename (stored_filename),
    KEY idx_provider_documents_provider (provider_id, document_type, review_status),
    KEY idx_provider_documents_reviewer (reviewed_by),
    CONSTRAINT fk_provider_documents_provider FOREIGN KEY (provider_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_documents_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE provider_profiles
SET approval_status = 'pending', approved_at = NULL, is_available = 0
WHERE approval_status = 'approved';
