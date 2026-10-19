CREATE TABLE IF NOT EXISTS chapter_images (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chapter_id      INT UNSIGNED      NOT NULL,
  paragraph_index SMALLINT UNSIGNED NOT NULL,
  position        ENUM('before','after') NOT NULL DEFAULT 'after',
  image_path      VARCHAR(500)      NOT NULL,
  alt_text        VARCHAR(300),
  created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chapter_para (chapter_id, paragraph_index),
  FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;
