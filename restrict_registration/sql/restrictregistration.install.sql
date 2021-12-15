CREATE TABLE IF NOT EXISTS `#__plg_user_restrict_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(128) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `params` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;
