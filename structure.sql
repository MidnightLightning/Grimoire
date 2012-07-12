SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";

--
-- Database: `grimoire`
--

-- --------------------------------------------------------

--
-- Table structure for table `grimoire`
--

DROP TABLE IF EXISTS `grimoire`;
CREATE TABLE `grimoire` (
  `name` varchar(100) DEFAULT NULL,
  `public_key` varchar(8) NOT NULL,
  `admin_key` varchar(16) NOT NULL,
  `last_viewed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`public_key`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `row`
--

DROP TABLE IF EXISTS `row`;
CREATE TABLE `row` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `gid` varchar(8) NOT NULL,
  `order` smallint(5) unsigned NOT NULL,
  `data` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `gid` (`gid`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1;
