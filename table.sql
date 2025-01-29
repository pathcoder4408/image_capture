CREATE TABLE IF NOT EXISTS form_image_capture (
 id                bigint(20)   NOT NULL auto_increment,
 activity          tinyint(1)   NOT NULL DEFAULT 1,  -- 0 if deleted
 notes             text,
 PRIMARY KEY (id)
) ENGINE=InnoDB;
