-- Migration 005: Design & Branding Einstellungen
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('color_primary',       '#cf2e2e'),
  ('color_primary_dark',  '#a82424'),
  ('color_primary_light', '#e84444'),
  ('color_dark',          '#1a1a1a'),
  ('color_dark2',         '#2d2d2d'),
  ('color_bg',            '#f5f5f5'),
  ('app_name',            'Kameruner-Tickets'),
  ('app_slogan',          ''),
  ('app_logo',            ''),
  ('app_favicon',         ''),
  ('font_family',         'inter'),
  ('theme_version',       '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
