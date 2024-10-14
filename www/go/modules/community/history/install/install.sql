-- Warning:
-- A foreign key contraint on createdBy to user and entityTypeId to core_entity
-- caused a long lock while deleting users
-- So we should not do that again!

CREATE TABLE IF NOT EXISTS `history_log_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` INT NULL,
  `description` VARCHAR(384),
  `changes` TEXT NULL,
  `createdAt` DATETIME NULL,
  `createdBy` INT NULL,
  `aclId` INT NULL,
  `removeAcl` TINYINT(1) NOT NULL DEFAULT 0,
  `entityTypeId` INT NOT NULL,
    `entityId` varchar(100) collate ascii_bin default null,
  `remoteIp` varchar(50) null,
  `requestId` varchar(190) default null,
  PRIMARY KEY (`id`),
  INDEX `fk_log_entry_core_user_idx` (`createdBy` ASC),
  INDEX `fk_log_entry_core_acl1_idx` (`aclId` ASC),
  INDEX `fk_log_entry_core_entity1_idx` (`entityTypeId` ASC),
  CONSTRAINT `fk_log_entry_core_acl1`
    FOREIGN KEY (`aclId`)
    REFERENCES `core_acl` (`id`)
    ON DELETE SET NULL
    ON UPDATE NO ACTION)
ENGINE = InnoDB;

ALTER TABLE `history_log_entry` ADD INDEX(`entityId`);

create index history_log_entry_createdAt_index
    on history_log_entry (createdAt);

create index history_log_entry_removeAcl_action_index
    on history_log_entry (removeAcl, action);
