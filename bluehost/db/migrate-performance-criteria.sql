-- Configurable performance-review templates and immutable review-item snapshots.
-- Safe to run more than once on an existing GEEK HRIS database.
SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS performance_templates (
 id INT AUTO_INCREMENT PRIMARY KEY, organization_id INT NOT NULL, name VARCHAR(120) NOT NULL,
 description TEXT NULL, rating_min DECIMAL(4,2) DEFAULT 1, rating_max DECIMAL(4,2) DEFAULT 5,
 active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
 INDEX (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS performance_template_sections (
 id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, title VARCHAR(150) NOT NULL,
 description TEXT NULL, sort_order INT DEFAULT 1, INDEX (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS performance_template_items (
 id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, title VARCHAR(180) NOT NULL,
 description TEXT NULL, weight DECIMAL(6,2) NOT NULL DEFAULT 0, sort_order INT DEFAULT 1,
 employee_rates TINYINT(1) DEFAULT 1, supervisor_rates TINYINT(1) DEFAULT 1, INDEX (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS performance_review_items (
 id INT AUTO_INCREMENT PRIMARY KEY, review_id INT NOT NULL, template_item_id INT NULL,
 section_title VARCHAR(150) NOT NULL, item_title VARCHAR(180) NOT NULL, item_description TEXT NULL,
 weight DECIMAL(6,2) NOT NULL DEFAULT 0, sort_order INT DEFAULT 1,
 employee_rates TINYINT(1) DEFAULT 1, supervisor_rates TINYINT(1) DEFAULT 1,
 self_score DECIMAL(5,2) NULL, supervisor_score DECIMAL(5,2) NULL,
 employee_comment TEXT NULL, supervisor_comment TEXT NULL,
 INDEX (review_id), INDEX (template_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='performance_cycles' AND column_name='template_id')=0,
 'ALTER TABLE performance_cycles ADD COLUMN template_id INT NULL AFTER organization_id, ADD INDEX (template_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='performance_reviews' AND column_name='rating_min')=0,
 'ALTER TABLE performance_reviews ADD COLUMN rating_min DECIMAL(4,2) DEFAULT 1 AFTER engagement_id, ADD COLUMN rating_max DECIMAL(4,2) DEFAULT 5 AFTER rating_min', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add a balanced default template for organizations that do not have one yet.
INSERT INTO performance_templates (organization_id,name,description,rating_min,rating_max,active)
SELECT o.id,'Standard Performance Review','General employee performance review',1,5,1 FROM organizations o
WHERE NOT EXISTS (SELECT 1 FROM performance_templates t WHERE t.organization_id=o.id);
INSERT INTO performance_template_sections (template_id,title,description,sort_order)
SELECT t.id,'Core Performance','Standard competencies used for all employees',1 FROM performance_templates t
WHERE NOT EXISTS (SELECT 1 FROM performance_template_sections s WHERE s.template_id=t.id);
INSERT INTO performance_template_items (section_id,title,description,weight,sort_order)
SELECT s.id,x.title,x.description,20,x.ord FROM performance_template_sections s
JOIN performance_templates t ON t.id=s.template_id
JOIN (
 SELECT 'Quality of Work' title,'Accuracy, completeness, and reliability of output' description,1 ord UNION ALL
 SELECT 'Productivity','Timely completion of assigned work and goals',2 UNION ALL
 SELECT 'Communication','Clear, respectful, and effective communication',3 UNION ALL
 SELECT 'Teamwork','Cooperation, accountability, and support for colleagues',4 UNION ALL
 SELECT 'Attendance and Reliability','Punctuality, availability, and dependability',5
) x
WHERE t.name='Standard Performance Review'
  AND NOT EXISTS (SELECT 1 FROM performance_template_items i WHERE i.section_id=s.id);

UPDATE performance_cycles pc
JOIN performance_templates t ON t.organization_id=pc.organization_id AND t.active=1
SET pc.template_id=t.id
WHERE pc.template_id IS NULL;
