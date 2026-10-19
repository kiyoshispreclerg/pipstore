CREATE TABLE IF NOT EXISTS chapter_audio (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chapter_id INT UNSIGNED      NOT NULL,
  para_start SMALLINT UNSIGNED NOT NULL,
  para_end   SMALLINT UNSIGNED NOT NULL,
  audio_path VARCHAR(500)      NOT NULL,
  title      VARCHAR(200),
  created_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chapter (chapter_id, para_start),
  FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;
