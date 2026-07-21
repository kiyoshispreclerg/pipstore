CREATE TABLE IF NOT EXISTS site_settings_t (
  `key`    VARCHAR(50)  NOT NULL,
  lang_id  INT UNSIGNED NOT NULL,
  `value`  TEXT,
  PRIMARY KEY (`key`, lang_id),
  FOREIGN KEY (lang_id) REFERENCES languages(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4;
